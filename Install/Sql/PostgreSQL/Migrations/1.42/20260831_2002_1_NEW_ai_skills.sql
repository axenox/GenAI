/*
 * Create table exf_ai_skill
 *
 * Stores reusable AI skills assigned to an AI agent.
 */
-- UP

CREATE TABLE IF NOT EXISTS exf_ai_skill (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    app_oid              uuid,
    ai_agent_oid         uuid,
    name                 varchar(100) NOT NULL,
    alias                varchar(100) NOT NULL,
    description          text,
    instructions         text,
    config_uxon          text,
    prototype_class      varchar(255) NOT NULL,
    CONSTRAINT pk_exf_ai_skill PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_skill_alias_ai_agent UNIQUE (alias, ai_agent_oid),
    CONSTRAINT fk_exf_ai_skill_app
        FOREIGN KEY (app_oid) REFERENCES exf_app (oid),
    CONSTRAINT fk_exf_ai_skill_ai_agent
        FOREIGN KEY (ai_agent_oid) REFERENCES exf_ai_agent (oid)
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_skill_app
    ON exf_ai_skill (app_oid);

CREATE INDEX IF NOT EXISTS idx_exf_ai_skill_ai_agent
    ON exf_ai_skill (ai_agent_oid);

-- DOWN

-- Do not delete tables containing AI skill configurations!
