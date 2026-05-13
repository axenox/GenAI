/*
 * Add dedicated instructions column to AI agent versions
 *
 * Moves the instructions text from config_uxon JSON property
 * $.instructions into a dedicated column.
 *
 * @author OpenAI
 */
-- UP
ALTER TABLE exf_ai_agent_version
    ADD COLUMN instructions TEXT NOT NULL DEFAULT '';

UPDATE exf_ai_agent_version
SET instructions = COALESCE(config_uxon::json ->> 'instructions', '');

UPDATE exf_ai_agent_version
SET config_uxon = (config_uxon::jsonb - 'instructions')::text
WHERE config_uxon IS NOT NULL
  AND config_uxon::jsonb ? 'instructions';

ALTER TABLE exf_ai_agent_version
    ALTER COLUMN instructions DROP DEFAULT;

-- DOWN
UPDATE exf_ai_agent_version
SET config_uxon = jsonb_set(
    COALESCE(config_uxon::jsonb, '{}'::jsonb),
    '{instructions}',
    to_jsonb(instructions)
)::text
WHERE instructions IS NOT NULL;

ALTER TABLE exf_ai_agent_version
    DROP COLUMN instructions;
