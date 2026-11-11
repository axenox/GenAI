/*
 * Shared conversations and per-call lifecycle, without deleting history.
 * The legacy failed flag is retained under trash_failed.
 * @author GitHub Copilot
 */
-- UP
DROP PROCEDURE IF EXISTS genai_shared_conversation_schema;
CREATE PROCEDURE genai_shared_conversation_schema()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_conversation'
        AND column_name = 'type') THEN
        ALTER TABLE exf_ai_conversation ADD type varchar(20) NOT NULL DEFAULT 'agent';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_conversation'
        AND column_name = 'topic_id') THEN
        ALTER TABLE exf_ai_conversation ADD topic_id varchar(255) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_conversation'
        AND column_name = 'mcp_group_key') THEN
        ALTER TABLE exf_ai_conversation ADD mcp_group_key varchar(64) NULL;
    END IF;
    ALTER TABLE exf_ai_conversation MODIFY data longtext NULL;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_message'
        AND column_name = 'started_on') THEN
        ALTER TABLE exf_ai_message ADD started_on datetime(3) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_message'
        AND column_name = 'finished_on') THEN
        ALTER TABLE exf_ai_message ADD finished_on datetime(3) NULL;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
        AND column_name = 'failed') THEN
        ALTER TABLE exf_ai_tool_call RENAME COLUMN failed TO trash_failed;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
        AND column_name = 'state') THEN
        ALTER TABLE exf_ai_tool_call
            ADD state varchar(20) NOT NULL DEFAULT 'IN_PROGRESS';
        UPDATE exf_ai_tool_call SET state = CASE
            WHEN trash_failed = 1 THEN 'FAILED'
            WHEN trash_failed = 0 AND result IS NOT NULL THEN 'SUCCEEDED'
            ELSE 'IN_PROGRESS' END;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
        AND column_name = 'started_on') THEN
        ALTER TABLE exf_ai_tool_call ADD started_on datetime(3) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
        AND column_name = 'finished_on') THEN
        ALTER TABLE exf_ai_tool_call ADD finished_on datetime(3) NULL;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.statistics WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
        AND index_name = 'exf_ai_tool_call_conversation_call') THEN
        ALTER TABLE exf_ai_tool_call
            DROP INDEX exf_ai_tool_call_conversation_call;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
        AND index_name = 'UQ_exf_ai_tool_call_message_call') THEN
        CREATE UNIQUE INDEX UQ_exf_ai_tool_call_message_call
            ON exf_ai_tool_call(ai_message_oid, call_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_conversation'
        AND index_name = 'UQ_exf_ai_conversation_mcp_group') THEN
        CREATE UNIQUE INDEX UQ_exf_ai_conversation_mcp_group
            ON exf_ai_conversation(mcp_group_key);
    END IF;
END;
CALL genai_shared_conversation_schema();
DROP PROCEDURE genai_shared_conversation_schema;

-- DOWN
-- Intentionally retain the additive audit schema and trash_failed.
-- Downgrading writers after combined history exists is not lossless.
