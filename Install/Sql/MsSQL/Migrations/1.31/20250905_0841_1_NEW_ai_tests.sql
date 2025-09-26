-- UP
IF OBJECT_ID ('dbo.exf_ai_test_case', N'U') IS NULL
BEGIN
    CREATE TABLE exf_ai_test_case (
        oid binary(16) NOT NULL,
        created_on datetime NOT NULL,
        modified_on datetime NOT NULL,
        created_by_user_oid binary(16) DEFAULT NULL,
        modified_by_user_oid binary(16) DEFAULT NULL,
        ai_agent_oid binary(16) NOT NULL,
        app_oid binary(16) DEFAULT NULL,
        name nvarchar(100) NOT NULL,
        prompt nvarchar(max) NOT NULL,
        context nvarchar(max),
        automatable_flag tinyint NOT NULL DEFAULT '0',
        description nvarchar(500) DEFAULT NULL,
        locale nvarchar(50) DEFAULT NULL,
        CONSTRAINT PK_exf_ai_test_case PRIMARY KEY (oid),
        CONSTRAINT FK_exf_ai_text_case_agent FOREIGN KEY (ai_agent_oid) REFERENCES dbo.exf_ai_agent (oid),
        CONSTRAINT FK_exf_ai_text_case_app FOREIGN KEY (app_oid) REFERENCES dbo.exf_app (oid)
    )
    CREATE INDEX IX_exf_ai_test_case_agent
        ON exf_ai_test_case (ai_agent_oid)
    CREATE INDEX IX_exf_ai_text_case_app
        ON exf_ai_test_case (app_oid)
END
GO

IF OBJECT_ID ('dbo.exf_ai_test_criterion', N'U') IS NULL
BEGIN
    CREATE TABLE exf_ai_test_criterion (
         oid binary(16) NOT NULL,
         created_on datetime NOT NULL,
         modified_on datetime NOT NULL,
         created_by_user_oid binary(16) DEFAULT NULL,
         modified_by_user_oid binary(16) DEFAULT NULL,
         name nvarchar(100) NOT NULL,
         ai_test_case_oid binary(16) NOT NULL,
         expected_value nvarchar(max),
         prototype_path nvarchar(500) NOT NULL,
         config_uxon nvarchar(max),
         weight decimal(4,3) NOT NULL DEFAULT '1.000',
         description nvarchar(500) DEFAULT NULL,
         CONSTRAINT PK_exf_ai_test_criterion PRIMARY KEY (oid),
         CONSTRAINT FK_exf_ai_test_criterion_test_case FOREIGN KEY (ai_test_case_oid) REFERENCES exf_ai_test_case (oid)
    )
    CREATE INDEX IX_exf_ai_test_criterion_test_case
        ON exf_ai_test_criterion (ai_test_case_oid)
END
GO

IF OBJECT_ID ('dbo.exf_ai_test_run', N'U') IS NULL
BEGIN
    CREATE TABLE exf_ai_test_run (
        oid binary(16) NOT NULL,
        created_on datetime NOT NULL,
        modified_on datetime NOT NULL,
        created_by_user_oid binary(16) DEFAULT NULL,
        modified_by_user_oid binary(16) DEFAULT NULL,
        tested_on datetime NOT NULL,
        ai_test_case_oid binary(16) NOT NULL,
        ai_conversation_oid binary(16) DEFAULT NULL,
        status tinyint NOT NULL,
        CONSTRAINT PK_exf_ai_test_run PRIMARY KEY (oid),
        CONSTRAINT FK_exf_ai_test_run_test_case FOREIGN KEY (ai_test_case_oid) REFERENCES exf_ai_test_case (oid),
        CONSTRAINT FK_exf_ai_test_run_conversation FOREIGN KEY (ai_conversation_oid) REFERENCES exf_ai_conversation (oid)
    )
    CREATE INDEX IX_exf_ai_test_run_test_case
        ON exf_ai_test_run (ai_test_case_oid)
    CREATE INDEX IX_exf_ai_test_run_conversation
        ON exf_ai_test_run (ai_conversation_oid)
END
GO

IF OBJECT_ID ('dbo.exf_ai_test_result', N'U') IS NULL
BEGIN
    CREATE TABLE exf_ai_test_result (
        oid binary(16) NOT NULL,
        created_on datetime NOT NULL,
        modified_on datetime NOT NULL,
        created_by_user_oid binary(16) DEFAULT NULL,
        modified_by_user_oid binary(16) DEFAULT NULL,
        ai_test_run_oid binary(16) NOT NULL,
        ai_test_criterion_oid binary(16) NOT NULL,
        value nvarchar(max),
        user_rating tinyint DEFAULT NULL,
        user_feedback nvarchar(500) DEFAULT NULL,
        CONSTRAINT PK_exf_ai_test_result PRIMARY KEY (oid),
        CONSTRAINT FK_exf_ai_test_result_test_run FOREIGN KEY (ai_test_run_oid) REFERENCES exf_ai_test_run (oid),
        CONSTRAINT FK_exf_ai_test_result_text_criterion FOREIGN KEY (ai_test_criterion_oid) REFERENCES exf_ai_test_criterion (oid)
    )
    CREATE INDEX IX_exf_ai_test_result_test_run
        ON exf_ai_test_result (ai_test_run_oid)
    CREATE INDEX IX_exf_ai_test_result_text_criterion
        ON exf_ai_test_result (ai_test_criterion_oid)
END
GO

IF OBJECT_ID ('dbo.exf_ai_test_result_rating', N'U') IS NULL
BEGIN
    CREATE TABLE exf_ai_test_result_rating (
        oid binary(16) NOT NULL,
        created_on datetime NOT NULL,
        modified_on datetime NOT NULL,
        created_by_user_oid binary(16) DEFAULT NULL,
        modified_by_user_oid binary(16) DEFAULT NULL,
        ai_test_result_oid binary(16) NOT NULL,
        rating tinyint NOT NULL,
        name nvarchar(50) NOT NULL,
        raw_value nvarchar(max),
        explanation nvarchar(max),
        pros nvarchar(max),
        cons nvarchar(max),
        CONSTRAINT PK_exf_ai_test_result_rating PRIMARY KEY (oid),
        CONSTRAINT FK_exf_ai_test_result_rating_test_result FOREIGN KEY (ai_test_result_oid) REFERENCES exf_ai_test_result (oid)
    )
    CREATE INDEX IX_exf_ai_test_result_rating_test_result
        ON exf_ai_test_result_rating (ai_test_result_oid)
END

-- DOWN