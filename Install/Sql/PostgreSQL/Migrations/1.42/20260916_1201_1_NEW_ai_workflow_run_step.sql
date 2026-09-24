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
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS exf_ai_workflow_run_step (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    ai_workflow_run_oid  uuid         NOT NULL,
    -- Set for child steps of a grouping step, NULL for top-level steps.
    parent_step_oid      uuid,
    -- Set for agent steps only.
    ai_conversation_oid  uuid,
    -- Primary sort key of the timeline, unique per run.
    sequence_number      int          NOT NULL,
    node_id              varchar(128) NOT NULL,
    node_type            varchar(50)  NOT NULL,
    -- Business subject of the step, e.g. a Jira issue key.
    subject_key          varchar(128),
    status               varchar(20)  NOT NULL,
    -- Business outcome, separate from the technical status.
    outcome              varchar(50),
    -- Short orchestration message shown in the timeline.
    message              text,
    error_message        text,
    -- Bounded JSON metadata. Large content belongs in artifacts.
    data                 text,
    started_on           timestamp    NOT NULL,
    finished_on          timestamp,
    duration_ms          int,
    CONSTRAINT pk_exf_ai_workflow_run_step PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_workflow_run_step_sequence
        UNIQUE (ai_workflow_run_oid, sequence_number),
    CONSTRAINT fk_exf_ai_workflow_run_step_run
        FOREIGN KEY (ai_workflow_run_oid)
        REFERENCES exf_ai_workflow_run (oid),
    CONSTRAINT fk_exf_ai_workflow_run_step_parent
        FOREIGN KEY (parent_step_oid)
        REFERENCES exf_ai_workflow_run_step (oid),
    CONSTRAINT fk_exf_ai_workflow_run_step_conversation
        FOREIGN KEY (ai_conversation_oid)
        REFERENCES exf_ai_conversation (oid) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_run_step_run
    ON exf_ai_workflow_run_step (ai_workflow_run_oid);
CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_run_step_parent
    ON exf_ai_workflow_run_step (parent_step_oid);
CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_run_step_conversation
    ON exf_ai_workflow_run_step (ai_conversation_oid);
CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_run_step_subject
    ON exf_ai_workflow_run_step (subject_key);

-- DOWN

-- Do not delete tables containing workflow run history!
