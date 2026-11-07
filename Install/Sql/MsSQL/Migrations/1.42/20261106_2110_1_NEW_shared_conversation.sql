/*
 * Shared agent/MCP conversation lifecycle and concurrency constraints.
 * Historical failure flags remain available under trash_failed.
 * DOWN is intentionally non-destructive: migrated audit history is retained.
 *
 * @author GitHub Copilot
 */
-- UP
ALTER TABLE dbo.exf_ai_conversation ADD
    type nvarchar(20) NOT NULL
        CONSTRAINT DF_exf_ai_conversation_type DEFAULT 'agent',
    topic_id nvarchar(255) NULL,
    next_message_sequence int NOT NULL
        CONSTRAINT DF_exf_ai_conversation_sequence DEFAULT 0,
    mcp_group_key nvarchar(64) NULL;
GO
-- SQL Server otherwise permits only one null in a unique index.
CREATE UNIQUE INDEX UQ_exf_ai_conversation_mcp_group
    ON dbo.exf_ai_conversation (mcp_group_key)
    WHERE mcp_group_key IS NOT NULL;
ALTER TABLE dbo.exf_ai_message ADD
    started_on datetime NULL,
    finished_on datetime NULL;
EXEC sp_rename 'dbo.exf_ai_tool_call.failed', 'trash_failed', 'COLUMN';
ALTER TABLE dbo.exf_ai_tool_call ADD
    state nvarchar(20) NOT NULL
        CONSTRAINT DF_exf_ai_tool_call_state DEFAULT 'IN_PROGRESS',
    started_on datetime NULL,
    finished_on datetime NULL;
GO
UPDATE dbo.exf_ai_tool_call
SET state = CASE
    WHEN trash_failed = 1 THEN 'FAILED'
    WHEN trash_failed = 0 AND result IS NOT NULL THEN 'SUCCEEDED'
    ELSE 'IN_PROGRESS' END;
ALTER TABLE dbo.exf_ai_tool_call
    DROP CONSTRAINT UQ_exf_ai_tool_call_conversation_call;
ALTER TABLE dbo.exf_ai_tool_call
    ADD CONSTRAINT UQ_exf_ai_tool_call_message_call
        UNIQUE (ai_message_oid, call_id);
WITH ordered AS (
    SELECT oid, ROW_NUMBER() OVER (
        PARTITION BY ai_conversation_oid
        ORDER BY CASE WHEN sequence_number IS NULL THEN 0 ELSE 1 END,
            sequence_number, created_on, oid
    ) AS normalized_sequence
    FROM dbo.exf_ai_message
)
UPDATE m SET sequence_number = s.normalized_sequence
FROM dbo.exf_ai_message m JOIN ordered s ON s.oid = m.oid;
ALTER TABLE dbo.exf_ai_message ALTER COLUMN sequence_number int NOT NULL;
ALTER TABLE dbo.exf_ai_message
    ADD CONSTRAINT UQ_exf_ai_message_conversation_sequence
        UNIQUE (ai_conversation_oid, sequence_number);
UPDATE c SET next_message_sequence = COALESCE((
    SELECT MAX(m.sequence_number) + 1 FROM dbo.exf_ai_message m
    WHERE m.ai_conversation_oid = c.oid
), 0)
FROM dbo.exf_ai_conversation c;

-- DOWN
-- Non-destructive: a shared conversation can contain reused legacy call IDs.
-- Restoring conversation-wide uniqueness would lose that audit history.
