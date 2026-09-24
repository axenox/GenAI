/*
 * Create table exf_ai_workflow_run_step
 *
 * Stores one row per executed node or structural grouping inside a workflow
 * run. Grouping steps (e.g. one per Jira ticket) use parent_step_oid to nest
 * their child steps, which avoids a separate item-run table in the MVP.
 *
 * Agent steps reference the ordinary AI conversation created by the agent.
 * That reference uses ON DELETE SET NULL because conversations are
 * independent audit records with their own retention policy: deleting a
 * conversation must not delete workflow history and vice versa.
 *
 * The self-reference parent_step_oid uses NO ACTION because SQL Server does
 * not allow cascading actions on self-referencing foreign keys.
 *
 * @author GitHub Copilot
 */
-- UP

IF OBJECT_ID ('dbo.exf_ai_workflow_run_step', N'U') IS NULL
CREATE TABLE dbo.exf_ai_workflow_run_step (
  oid binary(16) NOT NULL,
  created_on datetime NOT NULL,
  modified_on datetime NOT NULL,
  created_by_user_oid binary(16),
  modified_by_user_oid binary(16),
  ai_workflow_run_oid binary(16) NOT NULL,
  -- Set for child steps of a grouping step, NULL for top-level steps.
  parent_step_oid binary(16),
  -- Set for agent steps only.
  ai_conversation_oid binary(16),
  -- Primary sort key of the timeline, unique per run.
  sequence_number int NOT NULL,
  node_id nvarchar(128) NOT NULL,
  node_type nvarchar(50) NOT NULL,
  -- Business subject of the step, e.g. a Jira issue key.
  subject_key nvarchar(128),
  status nvarchar(20) NOT NULL,
  -- Business outcome, separate from the technical status.
  outcome nvarchar(50),
  -- Short orchestration message shown in the timeline.
  message nvarchar(max),
  error_message nvarchar(max),
  -- Bounded JSON metadata. Large content belongs in artifacts.
  data nvarchar(max),
  started_on datetime NOT NULL,
  finished_on datetime,
  duration_ms int,
  CONSTRAINT PK_exf_ai_workflow_run_step PRIMARY KEY (oid),
  CONSTRAINT UQ_exf_ai_workflow_run_step_sequence UNIQUE (ai_workflow_run_oid, sequence_number),
  INDEX IDX_dbo_exf_ai_workflow_run_step_run (ai_workflow_run_oid),
  INDEX IDX_dbo_exf_ai_workflow_run_step_parent (parent_step_oid),
  INDEX IDX_dbo_exf_ai_workflow_run_step_conversation (ai_conversation_oid),
  INDEX IDX_dbo_exf_ai_workflow_run_step_subject (subject_key),
  CONSTRAINT FK_dbo_exf_ai_workflow_run_step_run FOREIGN KEY (ai_workflow_run_oid) REFERENCES dbo.exf_ai_workflow_run (oid),
  CONSTRAINT FK_dbo_exf_ai_workflow_run_step_parent FOREIGN KEY (parent_step_oid) REFERENCES dbo.exf_ai_workflow_run_step (oid),
  CONSTRAINT FK_dbo_exf_ai_workflow_run_step_conversation FOREIGN KEY (ai_conversation_oid) REFERENCES dbo.exf_ai_conversation (oid) ON DELETE SET NULL
);

-- DOWN

-- Do not delete tables containing workflow run history!
