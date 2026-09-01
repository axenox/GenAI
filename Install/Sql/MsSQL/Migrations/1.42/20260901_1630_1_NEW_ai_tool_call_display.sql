/*
 * Add the formatted call to exf_ai_tool_call
 *
 * Stores the same readable tool call shown in conversation messages.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE dbo.exf_ai_tool_call
  ADD call_display nvarchar(max) NULL;

-- DOWN

ALTER TABLE dbo.exf_ai_tool_call
  DROP COLUMN call_display;
