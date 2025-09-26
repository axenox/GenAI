-- UP

IF COL_LENGTH('dbo.exf_ai_test_case', 'description') IS NULL
ALTER TABLE exf_ai_test_case
    ADD description NVARCHAR(500) NULL;
IF COL_LENGTH('dbo.exf_ai_test_case', 'locale') IS NULL
ALTER TABLE exf_ai_test_case
    ADD locale NVARCHAR(50) NULL;

IF COL_LENGTH('dbo.exf_ai_test_criterion', 'weight') IS NULL
ALTER TABLE exf_ai_test_criterion
    ADD weight DECIMAL(4,3) NOT NULL DEFAULT '1';

IF COL_LENGTH('dbo.exf_ai_test_criterion', 'description') IS NULL
ALTER TABLE exf_ai_test_criterion
    ADD description NVARCHAR(500) NULL DEFAULT NULL;

IF COL_LENGTH('dbo.exf_ai_agent_version', 'enabled_flag') IS NULL
BEGIN
    ALTER TABLE exf_ai_agent_version
        ADD enabled_flag TINYINT NOT NULL DEFAULT '0';

    EXEC sys.sp_executesql @query = N'UPDATE exf_ai_agent_version SET enabled_flag = 1';
END

IF COL_LENGTH('dbo.exf_ai_test_result', 'value') IS NULL
ALTER TABLE exf_ai_test_result
    ALTER COLUMN value NVARCHAR(max) NULL;
          
IF COL_LENGTH('dbo.exf_ai_test_result', 'user_feedback') IS NULL
ALTER TABLE exf_ai_test_result
    ADD user_feedback NVARCHAR(500) NULL;

-- DOWN

-- Do not delete new columns!