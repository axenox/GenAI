/*
 * Shared conversations and per-call lifecycle, without deleting history.
 * @author GitHub Copilot
 */
-- UP
IF COL_LENGTH('dbo.exf_ai_conversation', 'type') IS NULL
    ALTER TABLE dbo.exf_ai_conversation ADD type nvarchar(20) NOT NULL
        CONSTRAINT DF_exf_ai_conversation_type DEFAULT 'agent';
IF COL_LENGTH('dbo.exf_ai_conversation', 'topic_id') IS NULL
    ALTER TABLE dbo.exf_ai_conversation ADD topic_id nvarchar(255) NULL;
IF COL_LENGTH('dbo.exf_ai_conversation', 'mcp_group_key') IS NULL
    ALTER TABLE dbo.exf_ai_conversation ADD mcp_group_key nvarchar(64) NULL;
ALTER TABLE dbo.exf_ai_conversation ALTER COLUMN data nvarchar(max) NULL;
IF COL_LENGTH('dbo.exf_ai_message', 'started_on') IS NULL
    ALTER TABLE dbo.exf_ai_message ADD started_on datetime2(3) NULL;
IF COL_LENGTH('dbo.exf_ai_message', 'finished_on') IS NULL
    ALTER TABLE dbo.exf_ai_message ADD finished_on datetime2(3) NULL;
IF COL_LENGTH('dbo.exf_ai_tool_call', 'failed') IS NOT NULL
    EXEC sp_rename 'dbo.exf_ai_tool_call.failed', 'trash_failed', 'COLUMN';
IF COL_LENGTH('dbo.exf_ai_tool_call', 'state') IS NULL
BEGIN
    ALTER TABLE dbo.exf_ai_tool_call ADD state nvarchar(20) NOT NULL
        CONSTRAINT DF_exf_ai_tool_call_state DEFAULT 'IN_PROGRESS';
    EXEC sp_executesql N'UPDATE dbo.exf_ai_tool_call SET state = CASE
        WHEN trash_failed = 1 THEN ''FAILED''
        WHEN trash_failed = 0 AND result IS NOT NULL THEN ''SUCCEEDED''
        ELSE ''IN_PROGRESS'' END';
END;
IF COL_LENGTH('dbo.exf_ai_tool_call', 'started_on') IS NULL
    ALTER TABLE dbo.exf_ai_tool_call ADD started_on datetime2(3) NULL;
IF COL_LENGTH('dbo.exf_ai_tool_call', 'finished_on') IS NULL
    ALTER TABLE dbo.exf_ai_tool_call ADD finished_on datetime2(3) NULL;
IF EXISTS (SELECT 1 FROM sys.key_constraints WHERE
    parent_object_id = OBJECT_ID('dbo.exf_ai_tool_call')
    AND name = 'UQ_exf_ai_tool_call_conversation_call')
    ALTER TABLE dbo.exf_ai_tool_call
        DROP CONSTRAINT UQ_exf_ai_tool_call_conversation_call;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE
    object_id = OBJECT_ID('dbo.exf_ai_tool_call')
    AND name = 'UQ_exf_ai_tool_call_message_call')
    CREATE UNIQUE INDEX UQ_exf_ai_tool_call_message_call
        ON dbo.exf_ai_tool_call(ai_message_oid, call_id);
-- SQL Server requires filtering: a plain unique index permits only one NULL.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE
    object_id = OBJECT_ID('dbo.exf_ai_conversation')
    AND name = 'UQ_exf_ai_conversation_mcp_group')
    CREATE UNIQUE INDEX UQ_exf_ai_conversation_mcp_group
        ON dbo.exf_ai_conversation(mcp_group_key) WHERE mcp_group_key IS NOT NULL;

-- DOWN
-- Retain audit columns and trash_failed: old writers cannot read combined data.
