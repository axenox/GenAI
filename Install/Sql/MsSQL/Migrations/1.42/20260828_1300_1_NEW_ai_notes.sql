/*
 * Create table exf_ai_note
 *
 * Stores long-term notes scoped to one AI agent and one user.
 *
 * @author GitHub Copilot
 */
-- UP

IF OBJECT_ID ('dbo.exf_ai_note', N'U') IS NULL
CREATE TABLE dbo.exf_ai_note (
  oid binary(16) NOT NULL,
  created_on datetime NOT NULL,
  modified_on datetime NOT NULL,
  created_by_user_oid binary(16),
  modified_by_user_oid binary(16),
  user_oid binary(16) NOT NULL,
  ai_agent_oid binary(16) NOT NULL,
  topic nvarchar(200) NOT NULL,
  note nvarchar(max) NOT NULL,
  CONSTRAINT PK_exf_ai_note PRIMARY KEY (oid),
  CONSTRAINT UQ_exf_ai_note_scope_topic UNIQUE (user_oid, ai_agent_oid, topic),
  INDEX IDX_dbo_exf_ai_note_user (user_oid),
  INDEX IDX_dbo_exf_ai_note_agent (ai_agent_oid),
  CONSTRAINT FK_dbo_exf_ai_note_user FOREIGN KEY (user_oid) REFERENCES dbo.exf_user (oid),
  CONSTRAINT FK_dbo_exf_ai_note_agent FOREIGN KEY (ai_agent_oid) REFERENCES dbo.exf_ai_agent (oid)
);

-- DOWN

-- Do not delete tables containing user notes!