/*
 * Create reusable AI skills and ordered AI agent version assignments.
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
    `description` text,
    `instructions` longtext,
    `config_uxon` text,
    `prototype_class` varchar(255) NOT NULL,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `exf_ai_skill_app` (`app_oid`),
    CONSTRAINT `exf_ai_skill_app` FOREIGN KEY (`app_oid`) REFERENCES `exf_app` (`oid`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `exf_ai_agent_version_skill` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `ai_agent_version_oid` binary(16) NOT NULL,
    `ai_skill_oid` binary(16) NOT NULL,
    `description` longtext,
    PRIMARY KEY (`oid`) USING BTREE,
    UNIQUE KEY `uq_exf_ai_agent_version_skill` (`ai_agent_version_oid`,`ai_skill_oid`),
    KEY `idx_exf_ai_agent_version_skill_skill` (`ai_skill_oid`),
    CONSTRAINT `fk_exf_ai_agent_version_skill_skill` FOREIGN KEY (`ai_skill_oid`) REFERENCES `exf_ai_skill` (`oid`),
    CONSTRAINT `fk_exf_ai_agent_version_skill_version` FOREIGN KEY (`ai_agent_version_oid`) REFERENCES `exf_ai_agent_version` (`oid`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables containing AI skill configurations!