/*
 * Add an optional long-text description to AI agent version skills.
 */
-- UP

ALTER TABLE exf_ai_agent_version_skill
    ADD COLUMN IF NOT EXISTS description text NULL;

-- DOWN
-- ALTER TABLE exf_ai_agent_version_skill DROP COLUMN IF EXISTS description;