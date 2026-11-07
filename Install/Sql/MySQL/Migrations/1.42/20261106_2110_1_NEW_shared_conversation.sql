/*
 * Shared agent/MCP conversation lifecycle and concurrency constraints.
 * Legacy sequences are ordered by sequence, creation time and original UID.
 * Historical failure flags remain available under trash_failed.
 * DOWN is intentionally non-destructive: migrated audit history is retained.
 *
 * @author GitHub Copilot
 */
-- UP
-- BATCH-DELIMITER /;\R/

ALTER TABLE exf_ai_conversation
    ADD type varchar(20) NOT NULL DEFAULT 'agent',
    ADD topic_id varchar(255) NULL,
    ADD next_message_sequence int NOT NULL DEFAULT 0,
    ADD mcp_group_key varchar(64) NULL;
CREATE UNIQUE INDEX UQ_exf_ai_conversation_mcp_group
    ON exf_ai_conversation (mcp_group_key);

ALTER TABLE exf_ai_message
    ADD started_on datetime NULL,
    ADD finished_on datetime NULL;
ALTER TABLE exf_ai_tool_call
    CHANGE failed trash_failed smallint NULL,
    ADD state varchar(20) NOT NULL DEFAULT 'IN_PROGRESS',
    ADD started_on datetime NULL,
    ADD finished_on datetime NULL;
UPDATE exf_ai_tool_call
SET state = CASE
    WHEN trash_failed = 1 THEN 'FAILED'
    WHEN trash_failed = 0 AND result IS NOT NULL THEN 'SUCCEEDED'
    ELSE 'IN_PROGRESS' END;
ALTER TABLE exf_ai_tool_call
    DROP INDEX exf_ai_tool_call_conversation_call,
    ADD CONSTRAINT UQ_exf_ai_tool_call_message_call
        UNIQUE (ai_message_oid, call_id);

-- A temporary mapping prevents transient collisions during renumbering.
CREATE TEMPORARY TABLE genai_message_sequence AS
SELECT oid, ROW_NUMBER() OVER (
    PARTITION BY ai_conversation_oid
    ORDER BY CASE WHEN sequence_number IS NULL THEN 0 ELSE 1 END,
        sequence_number, created_on, oid
) AS normalized_sequence
FROM exf_ai_message;
UPDATE exf_ai_message m
JOIN genai_message_sequence s ON s.oid = m.oid
SET m.sequence_number = s.normalized_sequence;
DROP TEMPORARY TABLE genai_message_sequence;
ALTER TABLE exf_ai_message
    MODIFY sequence_number int NOT NULL,
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
