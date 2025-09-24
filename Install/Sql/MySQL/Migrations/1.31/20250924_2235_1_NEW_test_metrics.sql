-- UP

ALTER TABLE `exf_ai_test_case`
    ADD COLUMN `description` VARCHAR(500) NULL;
ALTER TABLE `exf_ai_test_case`
    ADD COLUMN `locale` VARCHAR(50) NULL;

ALTER TABLE `exf_ai_test_criterion`
    ADD COLUMN `weight` DECIMAL(4,3) NOT NULL DEFAULT '1',
    ADD COLUMN `description` VARCHAR(500) NULL DEFAULT NULL;

ALTER TABLE `exf_ai_agent_version`
    ADD COLUMN `enabled_flag` TINYINT NOT NULL DEFAULT '0';
UPDATE exf_ai_agent_version SET enabled_flag = 1;

ALTER TABLE `exf_ai_test_result`
    CHANGE COLUMN `value` `value` LONGTEXT NULL;
ALTER TABLE `exf_ai_test_result`
    ADD COLUMN `user_feedback` VARCHAR(500) NULL;

-- DOWN

-- Do not delete new columns!