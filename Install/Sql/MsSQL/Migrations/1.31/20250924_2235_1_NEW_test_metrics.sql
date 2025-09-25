-- UP

ALTER TABLE exf_ai_test_case
    ADD description NVARCHAR(500) NULL;
ALTER TABLE exf_ai_test_case
    ADD locale NVARCHAR(50) NULL;

ALTER TABLE exf_ai_test_criterion
    ADD weight DECIMAL(4,3) NOT NULL DEFAULT '1';
ALTER TABLE exf_ai_test_criterion
    ADD description NVARCHAR(500) NULL DEFAULT NULL;

ALTER TABLE exf_ai_agent_version
    ADD enabled_flag TINYINT NOT NULL DEFAULT '0';
UPDATE exf_ai_agent_version SET enabled_flag = 1;

ALTER TABLE exf_ai_test_result
    ALTER COLUMN value NVARCHAR(max) NULL;
ALTER TABLE exf_ai_test_result
    ADD user_feedback NVARCHAR(500) NULL;

-- DOWN

-- Do not delete new columns!