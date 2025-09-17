-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_test_result_rating` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `ai_test_result_oid` binary(16) NOT NULL,
    `rating` tinyint NOT NULL,
    `name` varchar(50) NOT NULL,
    `raw_value` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
    `explanation` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
    `pros` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
    `cons` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `ai_test_result_oid` (`ai_test_result_oid`),
    CONSTRAINT `exf_ai_test_result_rating_ibfk_1` FOREIGN KEY (`ai_test_result_oid`) REFERENCES `exf_ai_test_result` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete new tables!