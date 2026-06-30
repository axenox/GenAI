/*
 * Create table exf_ai_autonomous
 *
 * Stores autonomous agent configurations: an agent linked to an optional
 * scheduler that triggers an automated "flow" without user interaction.
 *
 * @author OpenAI
 */
-- UP

IF OBJECT_ID ('dbo.exf_ai_autonomous', N'U') IS NULL 
CREATE TABLE dbo.exf_ai_autonomous (
  oid binary(16) NOT NULL,
  created_on datetime NOT NULL,
  modified_on datetime NOT NULL,
  created_by_user_oid binary(16),
  modified_by_user_oid binary(16),
  app_oid binary(16),
  agent_oid binary(16) NOT NULL,
  scheduler_oid binary(16),
  description nvarchar(max),
  flow nvarchar(max),
  PRIMARY KEY (oid),
  CONSTRAINT UQ_exf_ai_autonomous_app_oid UNIQUE (app_oid),
  INDEX IDX_dbo_exf_ai_autonomous_app (app_oid),
  INDEX IDX_dbo_exf_ai_autonomous_agent (agent_oid),
  INDEX IDX_dbo_exf_ai_autonomous_scheduler (scheduler_oid),
  CONSTRAINT FK_dbo_exf_ai_autonomous_app FOREIGN KEY (app_oid) REFERENCES dbo.exf_app (oid),
  CONSTRAINT FK_dbo_exf_ai_autonomous_agent FOREIGN KEY (agent_oid) REFERENCES dbo.exf_ai_agent (oid),
  CONSTRAINT FK_dbo_exf_ai_autonomous_scheduler FOREIGN KEY (scheduler_oid) REFERENCES dbo.exf_scheduler (oid)
) ;

-- DOWN

-- Do not delete tables!
