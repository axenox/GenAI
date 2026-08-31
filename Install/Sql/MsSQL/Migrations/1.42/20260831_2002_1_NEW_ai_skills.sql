/*
 * Create table exf_ai_skill
 *
 * Stores reusable AI skills, optionally scoped to an app.
 */
-- UP

IF OBJECT_ID ('dbo.exf_ai_skill', N'U') IS NULL
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
  CONSTRAINT PK_exf_ai_skill PRIMARY KEY (oid),
  CONSTRAINT UQ_exf_ai_skill_alias_app UNIQUE (alias, app_oid),
  INDEX IDX_dbo_exf_ai_skill_app (app_oid),
  CONSTRAINT FK_dbo_exf_ai_skill_app FOREIGN KEY (app_oid) REFERENCES dbo.exf_app (oid)
);

-- DOWN

-- Do not delete tables containing AI skill configurations!
