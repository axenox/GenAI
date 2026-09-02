/*
 * Move agent-owned AI skills to their agent app and remove agent ownership.
 *
 * Validation prevents unresolved owners and alias collisions before data changes.
 */
-- UP

DROP PROCEDURE IF EXISTS `remove_ai_agent_from_skill`;

CREATE PROCEDURE `remove_ai_agent_from_skill`()
BEGIN
  DECLARE invalid_owner_count int DEFAULT 0;
  DECLARE alias_collision_count int DEFAULT 0;
  DECLARE error_message varchar(128);

  SELECT COUNT(*)
  INTO invalid_owner_count
  FROM `exf_ai_skill` skill
  LEFT JOIN `exf_ai_agent` agent
    ON agent.`oid` = skill.`ai_agent_oid`
  WHERE skill.`ai_agent_oid` IS NOT NULL
    AND (agent.`oid` IS NULL OR agent.`app_oid` IS NULL);

  IF invalid_owner_count > 0 THEN
    SET error_message = CONCAT(
      'Cannot remove AI skill agent ownership: ',
      invalid_owner_count,
      ' skill owner(s) have no agent app'
    );
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = error_message;
  END IF;

  SELECT COUNT(*)
  INTO alias_collision_count
  FROM `exf_ai_skill` skill
  INNER JOIN `exf_ai_agent` owner_agent
    ON owner_agent.`oid` = skill.`ai_agent_oid`
  INNER JOIN `exf_ai_skill` other_skill
    ON other_skill.`oid` <> skill.`oid`
   AND other_skill.`alias` = skill.`alias`
  LEFT JOIN `exf_ai_agent` other_agent
    ON other_agent.`oid` = other_skill.`ai_agent_oid`
  WHERE skill.`ai_agent_oid` IS NOT NULL
    AND (
      (
        other_skill.`ai_agent_oid` IS NULL
        AND other_skill.`app_oid` = owner_agent.`app_oid`
      )
      OR (
        other_skill.`ai_agent_oid` IS NOT NULL
        AND other_agent.`app_oid` = owner_agent.`app_oid`
      )
    );

  IF alias_collision_count > 0 THEN
    SET error_message = CONCAT(
      'Cannot remove AI skill agent ownership: ',
      alias_collision_count,
      ' app alias collision(s) detected'
    );
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = error_message;
  END IF;

  UPDATE `exf_ai_skill` skill
  INNER JOIN `exf_ai_agent` agent
    ON agent.`oid` = skill.`ai_agent_oid`
  SET skill.`app_oid` = agent.`app_oid`,
      skill.`ai_agent_oid` = NULL
  WHERE skill.`ai_agent_oid` IS NOT NULL;
END;

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND column_name = 'ai_agent_oid'
  ),
  'CALL `remove_ai_agent_from_skill`()',
  'SELECT 1'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

DROP PROCEDURE IF EXISTS `remove_ai_agent_from_skill`;

SELECT IFNULL(
  CONCAT(
    'ALTER TABLE `exf_ai_skill` ',
    GROUP_CONCAT(
      CONCAT('DROP FOREIGN KEY `', constraint_name, '`')
      SEPARATOR ', '
    )
  ),
  'SELECT 1'
)
INTO @skill_schema_sql
FROM (
  SELECT DISTINCT constraint_name
  FROM information_schema.key_column_usage
  WHERE constraint_schema = DATABASE()
    AND table_name = 'exf_ai_skill'
    AND column_name = 'ai_agent_oid'
    AND referenced_table_name IS NOT NULL
) skill_foreign_keys;
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

SELECT IFNULL(
  CONCAT(
    'ALTER TABLE `exf_ai_skill` ',
    GROUP_CONCAT(
      CONCAT('DROP INDEX `', index_name, '`')
      SEPARATOR ', '
    )
  ),
  'SELECT 1'
)
INTO @skill_schema_sql
FROM (
  SELECT DISTINCT index_name
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'exf_ai_skill'
    AND column_name = 'ai_agent_oid'
    AND index_name <> 'PRIMARY'
) skill_agent_indexes;
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND index_name = 'uq_exf_ai_skill_alias_owner'
  ),
  'ALTER TABLE `exf_ai_skill` DROP INDEX `uq_exf_ai_skill_alias_owner`',
  'SELECT 1'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND constraint_name = 'ck_exf_ai_skill_single_owner'
      AND constraint_type = 'CHECK'
  ),
  'ALTER TABLE `exf_ai_skill` DROP CHECK `ck_exf_ai_skill_single_owner`',
  'SELECT 1'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND column_name = 'alias_owner_type'
  ),
  'ALTER TABLE `exf_ai_skill` DROP COLUMN `alias_owner_type`',
  'SELECT 1'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND column_name = 'alias_owner_oid'
  ),
  'ALTER TABLE `exf_ai_skill` DROP COLUMN `alias_owner_oid`',
  'SELECT 1'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND column_name = 'ai_agent_oid'
  ),
  'ALTER TABLE `exf_ai_skill` DROP COLUMN `ai_agent_oid`',
  'SELECT 1'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND column_name = 'alias_scope_oid'
  ),
  'SELECT 1',
  'ALTER TABLE `exf_ai_skill` ADD COLUMN `alias_scope_oid` binary(16) GENERATED ALWAYS AS (COALESCE(`app_oid`, 0x00000000000000000000000000000000)) STORED'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND index_name = 'uq_exf_ai_skill_alias_scope'
  ),
  'SELECT 1',
  'ALTER TABLE `exf_ai_skill` ADD UNIQUE KEY `uq_exf_ai_skill_alias_scope` (`alias`, `alias_scope_oid`)'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

-- DOWN

-- Keep app ownership and the app/global uniqueness model.
-- Reconstructing agent ownership would assign skills arbitrarily.