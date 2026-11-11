/*
 * Shared conversations and per-call lifecycle, without deleting history.
 * @author GitHub Copilot
 */
-- UP
ALTER TABLE exf_ai_conversation
    ADD COLUMN IF NOT EXISTS type varchar(20) NOT NULL DEFAULT 'agent',
    ADD COLUMN IF NOT EXISTS topic_id varchar(255),
    ADD COLUMN IF NOT EXISTS mcp_group_key varchar(64);
ALTER TABLE exf_ai_conversation ALTER COLUMN data TYPE text;
ALTER TABLE exf_ai_message
    ADD COLUMN IF NOT EXISTS started_on timestamp,
    ADD COLUMN IF NOT EXISTS finished_on timestamp;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = current_schema() AND table_name = 'exf_ai_tool_call'
        AND column_name = 'failed') THEN
        ALTER TABLE exf_ai_tool_call RENAME COLUMN failed TO trash_failed;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = current_schema() AND table_name = 'exf_ai_tool_call'
        AND column_name = 'state') THEN
        ALTER TABLE exf_ai_tool_call
            ADD COLUMN state varchar(20) NOT NULL DEFAULT 'IN_PROGRESS';
        UPDATE exf_ai_tool_call SET state = CASE
            WHEN trash_failed = 1 THEN 'FAILED'
            WHEN trash_failed = 0 AND result IS NOT NULL THEN 'SUCCEEDED'
            ELSE 'IN_PROGRESS' END;
    END IF;
END $$;
ALTER TABLE exf_ai_tool_call
    ADD COLUMN IF NOT EXISTS started_on timestamp,
    ADD COLUMN IF NOT EXISTS finished_on timestamp;
ALTER TABLE exf_ai_tool_call
    DROP CONSTRAINT IF EXISTS uq_exf_ai_tool_call_conversation_call;
CREATE UNIQUE INDEX IF NOT EXISTS uq_exf_ai_tool_call_message_call
    ON exf_ai_tool_call(ai_message_oid, call_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_exf_ai_conversation_mcp_group
    ON exf_ai_conversation(mcp_group_key) WHERE mcp_group_key IS NOT NULL;

-- DOWN
-- Retain audit columns and trash_failed: old writers cannot read combined data.
