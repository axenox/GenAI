/*
 * Create table exf_ai_tool_call
 *
 * Stores one record for every tool call made in an AI conversation.
 *
 * @author GitHub Copilot
 */
-- UP

IF OBJECT_ID ('dbo.exf_ai_tool_call', N'U') IS NULL
CREATE TABLE dbo.exf_ai_tool_call (
  oid binary(16) NOT NULL,
  created_on datetime NOT NULL,
  modified_on datetime NOT NULL,
  created_by_user_oid binary(16),
  modified_by_user_oid binary(16),
  ai_conversation_oid binary(16) NOT NULL,
  ai_message_oid binary(16) NOT NULL,
  call_index int NOT NULL,
  call_id nvarchar(255) NOT NULL,
  tool_name nvarchar(255) NOT NULL,
  arguments nvarchar(max),
  result nvarchar(max),
  failed smallint,
  CONSTRAINT PK_exf_ai_tool_call PRIMARY KEY (oid),
  CONSTRAINT UQ_exf_ai_tool_call_conversation_call UNIQUE (ai_conversation_oid, call_id),
  INDEX IDX_dbo_exf_ai_tool_call_conversation (ai_conversation_oid),
  INDEX IDX_dbo_exf_ai_tool_call_message (ai_message_oid),
  INDEX IDX_dbo_exf_ai_tool_call_name (tool_name),
  CONSTRAINT FK_dbo_exf_ai_tool_call_conversation FOREIGN KEY (ai_conversation_oid) REFERENCES dbo.exf_ai_conversation (oid),
  CONSTRAINT FK_dbo_exf_ai_tool_call_message FOREIGN KEY (ai_message_oid) REFERENCES dbo.exf_ai_message (oid)
);

-- DOWN

-- Do not delete tables containing tool call history!
