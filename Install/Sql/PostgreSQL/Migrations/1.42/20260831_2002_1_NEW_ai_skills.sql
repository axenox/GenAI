/*
 * Create reusable AI skills and ordered AI agent version assignments.
 */
-- UP

CREATE TABLE IF NOT EXISTS exf_ai_skill (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    app_oid              uuid,
    name                 varchar(100) NOT NULL,
    alias                varchar(100) NOT NULL,
    description          text,
    instructions         text,
    config_uxon          text,
    prototype_class      varchar(255) NOT NULL,
    PRIMARY KEY (oid),
    CONSTRAINT exf_ai_skill_app
        FOREIGN KEY (app_oid) REFERENCES exf_app (oid)
);

CREATE INDEX IF NOT EXISTS exf_ai_skill_app
    ON exf_ai_skill (app_oid);

CREATE TABLE IF NOT EXISTS exf_ai_agent_version_skill (
    oid                  uuid      NOT NULL,
    created_on           timestamp NOT NULL,
    modified_on          timestamp NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    ai_agent_version_oid uuid      NOT NULL,
    ai_skill_oid         uuid      NOT NULL,
    description          text,
    PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_agent_version_skill
        UNIQUE (ai_agent_version_oid, ai_skill_oid),
    CONSTRAINT fk_exf_ai_agent_version_skill_skill
        FOREIGN KEY (ai_skill_oid) REFERENCES exf_ai_skill (oid),
    CONSTRAINT fk_exf_ai_agent_version_skill_version
        FOREIGN KEY (ai_agent_version_oid) REFERENCES exf_ai_agent_version (oid)
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_agent_version_skill_skill
    ON exf_ai_agent_version_skill (ai_skill_oid);

-- DOWN

-- Do not delete tables containing AI skill configurations!