/*
 * Link workflow runs to their autonomous configuration
 *
 * A run started from an autonomous configuration references it, so the UI can
 * show the configured flow diagram next to the recorded step timeline.
 *
 * The column is nullable because a workflow run may be started outside an
 * autonomous configuration, and because not every autonomous agent runs a
 * workflow at all. Existing rows cannot be backfilled: there is no key to
 * derive the configuration from.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE dbo.exf_ai_workflow_run
  ADD ai_autonomous_oid binary(16) NULL;
GO

ALTER TABLE dbo.exf_ai_workflow_run
  ADD CONSTRAINT FK_dbo_exf_ai_workflow_run_autonomous
    FOREIGN KEY (ai_autonomous_oid) REFERENCES dbo.exf_ai_autonomous (oid);
GO

CREATE INDEX IDX_dbo_exf_ai_workflow_run_autonomous
  ON dbo.exf_ai_workflow_run (ai_autonomous_oid);

-- DOWN

-- Keep the column because it contains workflow audit history.
