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

IF OBJECT_ID('dbo.exf_ai_tool_call', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.exf_ai_tool_call', 'result_length_chars') IS NULL
BEGIN
    ALTER TABLE dbo.exf_ai_tool_call
      ADD result_length_chars int NULL;
END

IF OBJECT_ID('dbo.exf_ai_tool_call', 'U') IS NOT NULL
BEGIN
    UPDATE dbo.exf_ai_tool_call
    SET result_length_chars = LEN(result + N'#') - 1
    WHERE result_length_chars IS NULL;
END

-- DOWN

-- Intentionally kept: dropping this column would discard stored data.