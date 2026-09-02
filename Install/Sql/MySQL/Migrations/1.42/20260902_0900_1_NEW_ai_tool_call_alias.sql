/*
 * Add the tool prototype alias to exf_ai_tool_call
 *
 * Stores the canonical namespaced alias separately from the LLM-facing name.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE `exf_ai_tool_call`
  ADD `tool_alias` varchar(255) NULL AFTER `tool_name`;

-- DOWN

-- Do not delete columns containing tool call history!
