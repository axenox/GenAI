/*
 * Add the tool prototype alias to exf_ai_tool_call
 *
 * Stores the canonical namespaced alias separately from the LLM-facing name.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE dbo.exf_ai_tool_call
  ADD tool_alias nvarchar(255) NULL;

-- DOWN

-- Do not delete columns containing tool call history!
