/*
 * Normalize AI agent version skills into an ordered assignment table.
 *
 * Existing UXON is only changed after every skill reference was resolved.
 */
-- UP

SET @skill_schema_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_skill'
      AND index_name = 'exf_ai_skill_alias_ai_agent'
  ),
  'ALTER TABLE `exf_ai_skill` DROP INDEX `exf_ai_skill_alias_ai_agent`',
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
  'SELECT 1',
  'ALTER TABLE `exf_ai_skill` ADD COLUMN `alias_owner_type` tinyint GENERATED ALWAYS AS (CASE WHEN `app_oid` IS NOT NULL THEN 1 WHEN `ai_agent_oid` IS NOT NULL THEN 2 ELSE 0 END) STORED'
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
  'SELECT 1',
  'ALTER TABLE `exf_ai_skill` ADD COLUMN `alias_owner_oid` binary(16) GENERATED ALWAYS AS (COALESCE(`app_oid`, `ai_agent_oid`, 0x00000000000000000000000000000000)) STORED'
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
  'SELECT 1',
  'ALTER TABLE `exf_ai_skill` ADD CONSTRAINT `ck_exf_ai_skill_single_owner` CHECK (`app_oid` IS NULL OR `ai_agent_oid` IS NULL)'
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
      AND index_name = 'uq_exf_ai_skill_alias_owner'
  ),
  'SELECT 1',
  'ALTER TABLE `exf_ai_skill` ADD UNIQUE KEY `uq_exf_ai_skill_alias_owner` (`alias`, `alias_owner_type`, `alias_owner_oid`)'
);
PREPARE skill_schema_stmt FROM @skill_schema_sql;
EXECUTE skill_schema_stmt;
DEALLOCATE PREPARE skill_schema_stmt;

CREATE TABLE IF NOT EXISTS `exf_ai_agent_version_skill` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  `ai_agent_version_oid` binary(16) NOT NULL,
  `ai_skill_oid` binary(16) NOT NULL,
  `sort_index` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`oid`) USING BTREE,
  UNIQUE KEY `uq_exf_ai_agent_version_skill` (
    `ai_agent_version_oid`,
    `ai_skill_oid`
  ),
  KEY `idx_exf_ai_agent_version_skill_order` (
    `ai_agent_version_oid`,
    `sort_index`
  ),
  KEY `idx_exf_ai_agent_version_skill_skill` (`ai_skill_oid`),
  CONSTRAINT `fk_exf_ai_agent_version_skill_version`
    FOREIGN KEY (`ai_agent_version_oid`)
    REFERENCES `exf_ai_agent_version` (`oid`),
  CONSTRAINT `fk_exf_ai_agent_version_skill_skill`
    FOREIGN KEY (`ai_skill_oid`) REFERENCES `exf_ai_skill` (`oid`),
  CONSTRAINT `ck_exf_ai_agent_version_skill_sort`
    CHECK (`sort_index` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

DROP PROCEDURE IF EXISTS `migrate_ai_agent_version_skills`;

CREATE PROCEDURE `migrate_ai_agent_version_skills`()
BEGIN
  DECLARE finished int DEFAULT 0;
  DECLARE version_oid binary(16);
  DECLARE consumer_agent_oid binary(16);
  DECLARE config_text longtext;
  DECLARE skills_json longtext;
  DECLARE keys_json longtext;
  DECLARE skill_key varchar(100);
  DECLARE stored_alias varchar(255);
  DECLARE json_path varchar(512);
  DECLARE skill_value longtext;
  DECLARE key_count int;
  DECLARE key_index int;
  DECLARE matching_skill_count int;
  DECLARE matching_skill_oid binary(16);
  DECLARE source_position int;
  DECLARE error_message varchar(128);

  DECLARE versions CURSOR FOR
    SELECT `oid`, `ai_agent_oid`, `config_uxon`
    FROM `exf_ai_agent_version`
    WHERE `config_uxon` IS NOT NULL
      AND TRIM(`config_uxon`) <> '';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = 1;

  CREATE TEMPORARY TABLE `tmp_ai_agent_version_skill` (
    `ai_agent_version_oid` binary(16) NOT NULL,
    `ai_skill_oid` binary(16) NOT NULL,
    `source_position` int NOT NULL,
    `sort_index` int DEFAULT NULL,
    PRIMARY KEY (`ai_agent_version_oid`, `ai_skill_oid`)
  );

  CREATE TEMPORARY TABLE `tmp_ai_agent_version_skill_cleanup` (
    `ai_agent_version_oid` binary(16) NOT NULL,
    PRIMARY KEY (`ai_agent_version_oid`)
  );

  OPEN versions;
  version_loop: LOOP
    FETCH versions INTO version_oid, consumer_agent_oid, config_text;
    IF finished = 1 THEN
      LEAVE version_loop;
    END IF;

    IF JSON_VALID(config_text) = 1
       AND JSON_CONTAINS_PATH(config_text, 'one', '$.skills') = 1 THEN
      SET skills_json = JSON_EXTRACT(config_text, '$.skills');

      IF skills_json IS NULL OR JSON_TYPE(skills_json) = 'NULL' THEN
        ITERATE version_loop;
      END IF;
      IF JSON_TYPE(skills_json) <> 'OBJECT' THEN
        SET error_message = CONCAT(
          'Cannot migrate AI agent version ', HEX(version_oid),
          ': config_uxon.skills must be a JSON map'
        );
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = error_message;
      END IF;

      SET keys_json = JSON_KEYS(skills_json);
      SET key_count = JSON_LENGTH(keys_json);
      SET key_index = 0;

      skill_loop: WHILE key_index < key_count DO
        SET skill_key = JSON_UNQUOTE(
          JSON_EXTRACT(keys_json, CONCAT('$[', key_index, ']'))
        );
        SET json_path = CONCAT(
          '$."',
          REPLACE(REPLACE(skill_key, '\\', '\\\\'), '"', '\\"'),
          '"'
        );
        SET skill_value = JSON_EXTRACT(skills_json, json_path);

        IF JSON_TYPE(skill_value) <> 'OBJECT' THEN
          SET error_message = CONCAT(
            'Cannot migrate version ', HEX(version_oid),
            ' skill "', LEFT(skill_key, 40), '": value must be an object'
          );
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = error_message;
        END IF;

        SET stored_alias = JSON_UNQUOTE(
          JSON_EXTRACT(skill_value, '$.alias')
        );
        IF stored_alias IS NULL OR TRIM(stored_alias) = '' THEN
          SET error_message = CONCAT(
            'Cannot migrate version ', HEX(version_oid),
            ' skill "', LEFT(skill_key, 40), '": alias is missing'
          );
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = error_message;
        END IF;

        SELECT COUNT(*), MIN(skill.`oid`)
        INTO matching_skill_count, matching_skill_oid
        FROM `exf_ai_skill` skill
        LEFT JOIN `exf_app` skill_app
          ON skill_app.`oid` = skill.`app_oid`
        LEFT JOIN `exf_ai_agent` owner_agent
          ON owner_agent.`oid` = skill.`ai_agent_oid`
        LEFT JOIN `exf_app` owner_agent_app
          ON owner_agent_app.`oid` = owner_agent.`app_oid`
        WHERE skill.`alias` = skill_key
          AND (
            skill.`ai_agent_oid` IS NULL
            OR skill.`ai_agent_oid` = consumer_agent_oid
          )
          AND stored_alias = CASE
            WHEN skill.`app_oid` IS NOT NULL
              THEN CONCAT(skill_app.`app_alias`, '.', skill.`alias`)
            WHEN skill.`ai_agent_oid` IS NOT NULL
              THEN CONCAT(owner_agent_app.`app_alias`, '.', skill.`alias`)
            ELSE skill.`alias`
          END;

        IF matching_skill_count <> 1 THEN
          SET error_message = CONCAT(
            'Version ', HEX(version_oid),
            ' skill "', LEFT(skill_key, 20), '" alias "',
            LEFT(stored_alias, 30), '": found ',
            matching_skill_count, ' matches'
          );
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = error_message;
        END IF;

        SET source_position = key_index;

        INSERT INTO `tmp_ai_agent_version_skill` (
          `ai_agent_version_oid`,
          `ai_skill_oid`,
          `source_position`
        ) VALUES (
          version_oid,
          matching_skill_oid,
          source_position
        );

        SET key_index = key_index + 1;
      END WHILE;

      INSERT IGNORE INTO `tmp_ai_agent_version_skill_cleanup` (
        `ai_agent_version_oid`
      ) VALUES (version_oid);

      SET @next_skill_sort_index = -1;
      UPDATE `tmp_ai_agent_version_skill` staged
      SET `sort_index` = (@next_skill_sort_index :=
        @next_skill_sort_index + 1)
      WHERE staged.`ai_agent_version_oid` = version_oid
      ORDER BY staged.`source_position`, staged.`ai_skill_oid`;
    END IF;
  END LOOP;
  CLOSE versions;

  INSERT INTO `exf_ai_agent_version_skill` (
    `oid`,
    `created_on`,
    `modified_on`,
    `created_by_user_oid`,
    `modified_by_user_oid`,
    `ai_agent_version_oid`,
    `ai_skill_oid`,
    `sort_index`
  )
  SELECT
    UNHEX(MD5(CONCAT(
      LOWER(HEX(staged.`ai_agent_version_oid`)),
      ':',
      LOWER(HEX(staged.`ai_skill_oid`))
    ))),
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    NULL,
    NULL,
    staged.`ai_agent_version_oid`,
    staged.`ai_skill_oid`,
    staged.`sort_index`
  FROM `tmp_ai_agent_version_skill` staged
  ON DUPLICATE KEY UPDATE
    `sort_index` = VALUES(`sort_index`),
    `modified_on` = CURRENT_TIMESTAMP;

  UPDATE `exf_ai_agent_version` version
  INNER JOIN `tmp_ai_agent_version_skill_cleanup` cleanup
    ON cleanup.`ai_agent_version_oid` = version.`oid`
  SET `config_uxon` = JSON_REMOVE(
    version.`config_uxon`,
    '$.skills'
  );

  DROP TEMPORARY TABLE `tmp_ai_agent_version_skill_cleanup`;
  DROP TEMPORARY TABLE `tmp_ai_agent_version_skill`;
END;

CALL `migrate_ai_agent_version_skills`();
DROP PROCEDURE IF EXISTS `migrate_ai_agent_version_skills`;

-- DOWN

-- Keep normalized skill assignments and skill configuration constraints.
-- Deleting this configuration data during rollback would be destructive.