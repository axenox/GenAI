/*
 * Shared agent/MCP conversation lifecycle and concurrency constraints.
 * Historical failure flags remain available under trash_failed.
 * DOWN is intentionally non-destructive: migrated audit history is retained.
 *
 * @author GitHub Copilot
 */
-- UP
ALTER TABLE exf_ai_conversation
    ADD COLUMN type varchar(20) NOT NULL DEFAULT 'agent',
    ADD COLUMN topic_id varchar(255),
    ADD COLUMN next_message_sequence integer NOT NULL DEFAULT 0,
    ADD COLUMN mcp_group_key varchar(64);
CREATE UNIQUE INDEX UQ_exf_ai_conversation_mcp_group
    ON exf_ai_conversation (mcp_group_key);
ALTER TABLE exf_ai_message
    ADD COLUMN started_on timestamp,
    ADD COLUMN finished_on timestamp;
ALTER TABLE exf_ai_tool_call RENAME COLUMN failed TO trash_failed;
ALTER TABLE exf_ai_tool_call
    ADD COLUMN state varchar(20) NOT NULL DEFAULT 'IN_PROGRESS',
    ADD COLUMN started_on timestamp,
    ADD COLUMN finished_on timestamp;
UPDATE exf_ai_tool_call
SET state = CASE
    WHEN trash_failed = 1 THEN 'FAILED'
    WHEN trash_failed = 0 AND result IS NOT NULL THEN 'SUCCEEDED'
    ELSE 'IN_PROGRESS' END;
ALTER TABLE exf_ai_tool_call
    DROP CONSTRAINT uq_exf_ai_tool_call_conversation_call,
    ADD CONSTRAINT UQ_exf_ai_tool_call_message_call
        UNIQUE (ai_message_oid, call_id);
WITH ordered AS (
    SELECT oid, ROW_NUMBER() OVER (
        PARTITION BY ai_conversation_oid
        ORDER BY CASE WHEN sequence_number IS NULL THEN 0 ELSE 1 END,
            sequence_number, created_on, oid
    ) AS normalized_sequence
    FROM exf_ai_message
)
UPDATE exf_ai_message m SET sequence_number = s.normalized_sequence
FROM ordered s WHERE s.oid = m.oid;
ALTER TABLE exf_ai_message
    ALTER COLUMN sequence_number SET NOT NULL,
    ADD CONSTRAINT UQ_exf_ai_message_conversation_sequence
        UNIQUE (ai_conversation_oid, sequence_number);
UPDATE exf_ai_conversation c
SET next_message_sequence = COALESCE((
    SELECT MAX(m.sequence_number) + 1 FROM exf_ai_message m
    WHERE m.ai_conversation_oid = c.oid
), 0);

-- DOWN
-- Non-destructive: a shared conversation can contain reused legacy call IDs.
-- Restoring conversation-wide uniqueness would lose that audit history.
