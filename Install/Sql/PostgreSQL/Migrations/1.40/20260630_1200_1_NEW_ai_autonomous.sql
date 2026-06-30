/*
 * Create table exf_ai_autonomous
 *
 * Stores autonomous agent configurations: an agent linked to an optional
 * scheduler that triggers an automated "flow" without user interaction.
 *
 * @author OpenAI
 */
-- UP

CREATE TABLE IF NOT EXISTS exf_ai_autonomous (
    oid                  uuid        NOT NULL,
    created_on           timestamp   NOT NULL,
    modified_on          timestamp   NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    app_oid              uuid,
    agent_oid            uuid        NOT NULL,
    scheduler_oid        uuid,
    description          text,
    flow                 text,
    CONSTRAINT pk_exf_ai_autonomous PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_autonomous_app_oid UNIQUE (app_oid),
    CONSTRAINT fk_exf_ai_autonomous_app
        FOREIGN KEY (app_oid) REFERENCES exf_app (oid),
    CONSTRAINT fk_exf_ai_autonomous_agent
        FOREIGN KEY (agent_oid) REFERENCES exf_ai_agent (oid),
    CONSTRAINT fk_exf_ai_autonomous_scheduler
        FOREIGN KEY (scheduler_oid) REFERENCES exf_scheduler (oid)
);

-- DOWN

-- Do not delete tables!
