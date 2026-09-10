/*
 * Add execution time to exf_ai_tool_call.
 */
-- UP

ALTER TABLE IF EXISTS exf_ai_tool_call
    ADD COLUMN IF NOT EXISTS execution_time_ms integer;

-- DOWN

-- Intentionally kept: dropping this column would discard stored data.