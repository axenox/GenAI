/*
 * Create table exf_ai_workflow_run
 *
 * Stores one row per invocation of an agentic workflow. A run is the root of
 * the workflow timeline that is rendered as one large conversation.
 *
 * The run is closed in a finally block, so a row must never stay in status
 * "running" after the process ended.
 *
 * @author GitHub Copilot
 */
-- UP

IF OBJECT_ID ('dbo.exf_ai_workflow_run', N'U') IS NULL
CREATE TABLE dbo.exf_ai_workflow_run (
  oid binary(16) NOT NULL,
  created_on datetime NOT NULL,
  modified_on datetime NOT NULL,
  created_by_user_oid binary(16),
  modified_by_user_oid binary(16),
  -- Alias of the workflow. Constant while the graph is still hardcoded.
  workflow_alias nvarchar(128) NOT NULL,
  title nvarchar(250) NOT NULL,
  status nvarchar(20) NOT NULL,
  -- Business outcome, separate from the technical status.
  outcome nvarchar(50),
  -- Reserved for later idempotency checks. Not enforced yet.
  correlation_key nvarchar(128),
  -- PowerUI execution identity of the run.
  user_oid binary(16),
  started_on datetime NOT NULL,
  finished_on datetime,
  error_message nvarchar(max),
  CONSTRAINT PK_exf_ai_workflow_run PRIMARY KEY (oid),
  INDEX IDX_dbo_exf_ai_workflow_run_alias (workflow_alias),
  INDEX IDX_dbo_exf_ai_workflow_run_status (status),
  INDEX IDX_dbo_exf_ai_workflow_run_started (started_on),
  INDEX IDX_dbo_exf_ai_workflow_run_correlation (correlation_key),
  CONSTRAINT FK_dbo_exf_ai_workflow_run_user FOREIGN KEY (user_oid) REFERENCES dbo.exf_user (oid)
);

-- DOWN

-- Do not delete tables containing workflow run history!
