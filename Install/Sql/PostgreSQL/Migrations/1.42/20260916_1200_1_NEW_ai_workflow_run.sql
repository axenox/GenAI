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

CREATE TABLE IF NOT EXISTS exf_ai_workflow_run (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    -- Alias of the workflow. Constant while the graph is still hardcoded.
    workflow_alias       varchar(128) NOT NULL,
    title                varchar(250) NOT NULL,
    status               varchar(20)  NOT NULL,
    -- Business outcome, separate from the technical status.
    outcome              varchar(50),
    -- Reserved for later idempotency checks. Not enforced yet.
    correlation_key      varchar(128),
    -- PowerUI execution identity of the run.
    user_oid             uuid,
    started_on           timestamp    NOT NULL,
    finished_on          timestamp,
    error_message        text,
    CONSTRAINT pk_exf_ai_workflow_run PRIMARY KEY (oid),
    CONSTRAINT fk_exf_ai_workflow_run_user
        FOREIGN KEY (user_oid) REFERENCES exf_user (oid)
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_run_alias
    ON exf_ai_workflow_run (workflow_alias);
CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_run_status
    ON exf_ai_workflow_run (status);
CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_run_started
    ON exf_ai_workflow_run (started_on);
CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_run_correlation
    ON exf_ai_workflow_run (correlation_key);

-- DOWN

-- Do not delete tables containing workflow run history!
