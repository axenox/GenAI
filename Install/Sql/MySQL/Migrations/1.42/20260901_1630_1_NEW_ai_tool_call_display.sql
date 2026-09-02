/*
 * Add the formatted call to exf_ai_tool_call
 *
 * Stores the same readable tool call shown in conversation messages.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE `exf_ai_tool_call`
  ADD `call_display` mediumtext NULL AFTER `tool_name`;

-- DOWN
