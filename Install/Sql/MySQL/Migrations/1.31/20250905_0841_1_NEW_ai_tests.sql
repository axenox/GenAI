-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_test_case` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `ai_agent_oid` binary(16) NOT NULL,
    `app_oid` binary(16) NULL,
    `name` varchar(100) NOT NULL,
    `prompt` longtext NOT NULL,
    `context` longtext,
    `automatable_flag` tinyint NOT NULL DEFAULT 0,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `ai_agent_oid` (`ai_agent_oid`),
    KEY `app_oid` (`app_oid`),
    CONSTRAINT `exf_ai_test_case_ai_agent`
        FOREIGN KEY (`ai_agent_oid`) REFERENCES `exf_ai_agent` (`oid`),
    CONSTRAINT `exf_ai_test_case_app`
        FOREIGN KEY (`app_oid`) REFERENCES `exf_app` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `exf_ai_test_criterion` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `name` varchar(100) NOT NULL,
    `ai_test_case_oid` binary(16) NOT NULL,
    `expected_value` longtext,
    `prototype_path` varchar(500) NOT NULL,
    `config_uxon` longtext,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `ai_test_case_oid` (`ai_test_case_oid`),
    CONSTRAINT `exf_ai_test_criterion_test_case`
        FOREIGN KEY (`ai_test_case_oid`) REFERENCES `exf_ai_test_case` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `exf_ai_test_run` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `tested_on` datetime NOT NULL,
    `ai_test_case_oid` binary(16) NOT NULL,
    `ai_conversation_oid` binary(16) DEFAULT NULL,
    `status` tinyint NOT NULL,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `ai_test_case_oid` (`ai_test_case_oid`),
    KEY `ai_conversation_oid` (`ai_conversation_oid`),
    CONSTRAINT `exf_ai_test_run_test_case`
        FOREIGN KEY (`ai_test_case_oid`) REFERENCES `exf_ai_test_case` (`oid`),
    CONSTRAINT `exf_ai_test_run_conversation`
        FOREIGN KEY (`ai_conversation_oid`) REFERENCES `exf_ai_conversation` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `exf_ai_test_result` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `ai_test_run_oid` binary(16) NOT NULL,
    `ai_test_criterion_oid` binary(16) NOT NULL,
    `value` longtext NOT NULL,
    `user_rating` tinyint DEFAULT NULL,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `ai_test_run_oid` (`ai_test_run_oid`),
    KEY `ai_test_criterion_oid` (`ai_test_criterion_oid`),
    CONSTRAINT `exf_ai_test_result_test_run`
        FOREIGN KEY (`ai_test_run_oid`) REFERENCES `exf_ai_test_run` (`oid`),
    CONSTRAINT `exf_ai_test_result_test_criterion`
        FOREIGN KEY (`ai_test_criterion_oid`) REFERENCES `exf_ai_test_criterion` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- DOWN