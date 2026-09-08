/*
 * Add execution time to exf_ai_tool_call.
 */
-- UP

IF OBJECT_ID('dbo.exf_ai_tool_call', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.exf_ai_tool_call', 'execution_time_ms') IS NULL
BEGIN
    ALTER TABLE dbo.exf_ai_tool_call
      ADD execution_time_ms int NULL;
END

-- DOWN

-- Intentionally kept: dropping this column would discard stored data.