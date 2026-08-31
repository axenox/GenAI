/*
 * Reference AI skills to agents instead of apps.
 *
 * Ambiguous legacy rows remain unassigned while their app reference is preserved.
 */
-- UP

ALTER TABLE `exf_ai_skill`
  ADD COLUMN `ai_agent_oid` binary(16) DEFAULT NULL;

UPDATE `exf_ai_skill` AS `skill`
INNER JOIN `exf_ai_agent` AS `agent`
  ON `agent`.`app_oid` = `skill`.`app_oid`
SET `skill`.`ai_agent_oid` = `agent`.`oid`
WHERE (
  SELECT COUNT(*)
  FROM `exf_ai_agent` AS `candidate`
  WHERE `candidate`.`app_oid` = `skill`.`app_oid`
) = 1;

ALTER TABLE `exf_ai_skill`
  DROP INDEX `exf_ai_skill_alias_app`,
  ADD UNIQUE KEY `exf_ai_skill_alias_ai_agent` (`alias`, `ai_agent_oid`),
  ADD KEY `exf_ai_skill_ai_agent` (`ai_agent_oid`),
  ADD CONSTRAINT `exf_ai_skill_ai_agent`
    FOREIGN KEY (`ai_agent_oid`) REFERENCES `exf_ai_agent` (`oid`);

-- DOWN

UPDATE `exf_ai_skill` AS `skill`
INNER JOIN `exf_ai_agent` AS `agent`
  ON `agent`.`oid` = `skill`.`ai_agent_oid`
SET `skill`.`app_oid` = `agent`.`app_oid`;

ALTER TABLE `exf_ai_skill`
  DROP FOREIGN KEY `exf_ai_skill_ai_agent`,
  DROP INDEX `exf_ai_skill_ai_agent`,
  DROP INDEX `exf_ai_skill_alias_ai_agent`,
  DROP COLUMN `ai_agent_oid`,
  ADD UNIQUE KEY `exf_ai_skill_alias_app` (`alias`, `app_oid`);