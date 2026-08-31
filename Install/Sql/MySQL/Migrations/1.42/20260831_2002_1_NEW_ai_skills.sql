/*
 * Create table exf_ai_skill
 *
 * Stores reusable AI skills, optionally scoped to an app.
 */
-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_skill` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  `app_oid` binary(16) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `alias` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `instructions` longtext DEFAULT NULL,
  `config_uxon` text DEFAULT NULL,
  `prototype_class` varchar(255) NOT NULL,
  PRIMARY KEY (`oid`) USING BTREE,
  UNIQUE KEY `exf_ai_skill_alias_app` (`alias`, `app_oid`),
  KEY `exf_ai_skill_app` (`app_oid`),
  CONSTRAINT `exf_ai_skill_app` FOREIGN KEY (`app_oid`) REFERENCES `exf_app` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables containing AI skill configurations!
