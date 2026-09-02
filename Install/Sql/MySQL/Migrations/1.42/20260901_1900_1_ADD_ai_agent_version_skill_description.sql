/*
 * Add an optional long-text description to AI agent version skills.
 */
-- UP

SET @agent_version_skill_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_agent_version_skill'
      AND column_name = 'description'
  ),
  'SELECT 1',
  'ALTER TABLE `exf_ai_agent_version_skill` ADD COLUMN `description` LONGTEXT NULL'
);
PREPARE agent_version_skill_stmt FROM @agent_version_skill_sql;
EXECUTE agent_version_skill_stmt;
DEALLOCATE PREPARE agent_version_skill_stmt;

-- DOWN
-- ALTER TABLE `exf_ai_agent_version_skill` DROP COLUMN `description`;