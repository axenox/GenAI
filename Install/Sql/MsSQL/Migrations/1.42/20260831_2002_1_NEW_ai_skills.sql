/*
 * Create table exf_ai_skill
 *
 * Stores reusable AI skills assigned to an AI agent.
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
  ai_agent_oid binary(16),
  name nvarchar(100) NOT NULL,
  alias nvarchar(100) NOT NULL,
  description nvarchar(max),
  instructions nvarchar(max),
  config_uxon nvarchar(max),
  prototype_class nvarchar(255) NOT NULL,
  CONSTRAINT PK_exf_ai_skill PRIMARY KEY (oid),
  INDEX IDX_dbo_exf_ai_skill_app (app_oid),
  INDEX IDX_dbo_exf_ai_skill_ai_agent (ai_agent_oid),
  CONSTRAINT FK_dbo_exf_ai_skill_app FOREIGN KEY (app_oid) REFERENCES dbo.exf_app (oid),
  CONSTRAINT FK_dbo_exf_ai_skill_ai_agent FOREIGN KEY (ai_agent_oid) REFERENCES dbo.exf_ai_agent (oid)
);

CREATE UNIQUE INDEX UQ_exf_ai_skill_alias_ai_agent
  ON dbo.exf_ai_skill (alias, ai_agent_oid)
  WHERE ai_agent_oid IS NOT NULL;

-- DOWN

-- Do not delete tables containing AI skill configurations!
