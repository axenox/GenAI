/*
 * Create reusable AI skills and ordered AI agent version assignments.
 */
-- UP

IF OBJECT_ID(N'dbo.exf_ai_skill', N'U') IS NULL
CREATE TABLE dbo.exf_ai_skill (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16),
    modified_by_user_oid binary(16),
    app_oid binary(16),
    name nvarchar(100) NOT NULL,
    alias nvarchar(100) NOT NULL,
    description nvarchar(max),
    instructions nvarchar(max),
    config_uxon nvarchar(max),
    prototype_class nvarchar(255) NOT NULL,
    PRIMARY KEY (oid),
    INDEX idx_exf_ai_skill_app (app_oid),
    CONSTRAINT fk_exf_ai_skill_app
        FOREIGN KEY (app_oid) REFERENCES dbo.exf_app (oid)
);

IF OBJECT_ID(N'dbo.exf_ai_agent_version_skill', N'U') IS NULL
CREATE TABLE dbo.exf_ai_agent_version_skill (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16),
    modified_by_user_oid binary(16),
    ai_agent_version_oid binary(16) NOT NULL,
    ai_skill_oid binary(16) NOT NULL,
    description nvarchar(max),
    PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_agent_version_skill
        UNIQUE (ai_agent_version_oid, ai_skill_oid),
    INDEX idx_exf_ai_agent_version_skill_skill (ai_skill_oid),
    CONSTRAINT fk_exf_ai_agent_version_skill_skill
        FOREIGN KEY (ai_skill_oid) REFERENCES dbo.exf_ai_skill (oid),
    CONSTRAINT fk_exf_ai_agent_version_skill_version
        FOREIGN KEY (ai_agent_version_oid)
        REFERENCES dbo.exf_ai_agent_version (oid)
);

-- DOWN

-- Do not delete tables containing AI skill configurations!