/*
 * Add dedicated instructions column to AI agent versions
 *
 * Moves the instructions text from config_uxon JSON property
 * $.instructions into a dedicated LONGTEXT column.
 *
 * @author OpenAI
 */
-- UP
ALTER TABLE `exf_ai_agent_version`
    ADD COLUMN `instructions` LONGTEXT NOT NULL;

UPDATE `exf_ai_agent_version`
SET `instructions` = COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(`config_uxon`, '$.instructions')),
    ''
);

UPDATE `exf_ai_agent_version`
SET `config_uxon` = JSON_REMOVE(`config_uxon`, '$.instructions')
WHERE `config_uxon` IS NOT NULL
  AND JSON_CONTAINS_PATH(`config_uxon`, 'one', '$.instructions');

-- DOWN
UPDATE `exf_ai_agent_version`
SET `config_uxon` = JSON_SET(
    COALESCE(`config_uxon`, '{}'),
    '$.instructions',
    `instructions`
)
WHERE `instructions` IS NOT NULL;

ALTER TABLE `exf_ai_agent_version`
    DROP COLUMN `instructions`;
