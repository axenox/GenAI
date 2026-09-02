/*
 * Add the tool prototype alias to exf_ai_tool_call
 *
 * Stores the canonical namespaced alias separately from the LLM-facing name.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE exf_ai_tool_call
    ADD COLUMN tool_alias varchar(255);

-- DOWN

-- Do not delete columns containing tool call history!
