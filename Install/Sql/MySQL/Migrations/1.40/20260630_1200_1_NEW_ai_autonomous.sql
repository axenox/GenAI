/*
 * Create table exf_ai_autonomous
 *
 * Stores autonomous agent configurations: an agent linked to an optional
 * scheduler that triggers an automated "flow" without user interaction.
 *
 * @author OpenAI
 */
-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_autonomous` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  `app_oid` binary(16) DEFAULT NULL,
  `agent_oid` binary(16) NOT NULL,
  `scheduler_oid` binary(16) DEFAULT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `flow` text,
  PRIMARY KEY (`oid`) USING BTREE,
  UNIQUE KEY `alias_app_oid` (`app_oid`) USING BTREE,
  KEY `exf_ai_autonomous_app` (`app_oid`) USING BTREE,
  KEY `exf_ai_autonomous_agent` (`agent_oid`),
  KEY `exf_ai_autonomous_scheduler` (`scheduler_oid`),
  CONSTRAINT `exf_ai_autonomous_app` FOREIGN KEY (`app_oid`) REFERENCES `exf_app` (`oid`),
  CONSTRAINT `exf_ai_autonomous_agent` FOREIGN KEY (`agent_oid`) REFERENCES `exf_ai_agent` (`oid`),
  CONSTRAINT `exf_ai_autonomous_scheduler` FOREIGN KEY (`scheduler_oid`) REFERENCES `exf_scheduler` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables!
