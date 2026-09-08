/*
 * Add response length to exf_ai_tool_call
 *
 * Stores the result size in characters to make tool-call monitoring easier
 * and backfills the size for existing tool calls.
 * DOWN keeps the column, since dropping it would discard stored data.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE IF EXISTS exf_ai_tool_call
    ADD COLUMN IF NOT EXISTS result_length_chars integer;

UPDATE exf_ai_tool_call
SET result_length_chars = CHAR_LENGTH(result)
WHERE result_length_chars IS NULL;

-- DOWN

-- Intentionally kept: dropping this column would discard stored data.