/*
 * Create table exf_ai_feedback
 *
 * Stores feedback for an AI agent version and optional conversation.
 *
 * @author GitHub Copilot
 */
-- UP

IF OBJECT_ID ('dbo.exf_ai_feedback', N'U') IS NULL
CREATE TABLE dbo.exf_ai_feedback (
  oid binary(16) NOT NULL,
  created_on datetime NOT NULL,
  modified_on datetime NOT NULL,
  created_by_user_oid binary(16),
  modified_by_user_oid binary(16),
  agent_version_oid binary(16) NOT NULL,
  ai_conversation_oid binary(16),
  general_feedback nvarchar(max),
  suggested_tools nvarchar(max),
  feedback_checked tinyint NOT NULL DEFAULT 0,
  CONSTRAINT PK_exf_ai_feedback PRIMARY KEY (oid),
  INDEX IX_exf_ai_feedback_agent_version (agent_version_oid),
  INDEX IX_exf_ai_feedback_conversation (ai_conversation_oid),
  CONSTRAINT CK_exf_ai_feedback_checked
    CHECK (feedback_checked IN (0, 1)),
  CONSTRAINT FK_exf_ai_feedback_agent_version
    FOREIGN KEY (agent_version_oid)
    REFERENCES dbo.exf_ai_agent_version (oid)
    ON DELETE NO ACTION,
  CONSTRAINT FK_exf_ai_feedback_conversation
    FOREIGN KEY (ai_conversation_oid)
    REFERENCES dbo.exf_ai_conversation (oid)
    ON DELETE SET NULL
);

-- DOWN

DROP TABLE IF EXISTS dbo.exf_ai_feedback;