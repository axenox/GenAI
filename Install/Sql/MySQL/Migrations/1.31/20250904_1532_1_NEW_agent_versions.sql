-- UP
    
/* New table for agent versions */
CREATE TABLE IF NOT EXISTS `exf_ai_agent_version` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `ai_agent_oid` binary(16) NOT NULL,
    `version` varchar(50) NOT NULL DEFAULT '1.0',
    `description` mediumtext CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
    `prototype_class` varchar(255) NOT NULL,
    `config_uxon` mediumtext CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
    `data_connection_default_oid` binary(16) DEFAULT NULL,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `exf_ai_agent_version_data_connection_default` (`data_connection_default_oid`),
    KEY `exf_ai_agent_version_agent` (`ai_agent_oid`),
    CONSTRAINT `exf_ai_agent_version_agent` FOREIGN KEY (`ai_agent_oid`) REFERENCES `exf_ai_agent` (`oid`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `exf_ai_agent_version_data_connection_default` FOREIGN KEY (`data_connection_default_oid`) REFERENCES `exf_data_connection` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci ROW_FORMAT=DYNAMIC;

/* Save agent version number in conversations */
ALTER TABLE `exf_ai_conversation`
    ADD COLUMN `ai_agent_version_no` VARCHAR(50) NOT NULL DEFAULT '1.0' AFTER `ai_agent_oid`;
    
/* Create initial versions */
INSERT INTO exf_ai_agent_version
(
    oid,
    created_on,
    created_by_user_oid,
    modified_on,
    modified_by_user_oid,
    ai_agent_oid,
    `version`,
    prototype_class,
    config_uxon,
    data_connection_default_oid
)
SELECT
    oid,
    created_on,
    created_by_user_oid,
    modified_on,
    modified_by_user_oid,
    oid,
    '1.0',
    prototype_class,
    config_uxon,
    data_connection_default_oid
FROM
    exf_ai_agent;

/* Migrate customizing */
UPDATE  exf_customizing SET `table_name` = 'exf_ai_agent_version' WHERE `table_name` = 'exf_ai_agent';

/* Remove moved columns */
ALTER TABLE `exf_ai_agent`
DROP FOREIGN KEY `exf_ai_agent_data_connection_default`,
    DROP COLUMN `data_connection_default_oid`;
ALTER TABLE exf_ai_agent
    DROP prototype_class,
    DROP config_uxon;
         
-- DOWN