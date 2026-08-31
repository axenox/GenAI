/*
 * Create table exf_ai_skill
 *
 * Stores reusable AI skills, optionally scoped to an app.
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
    CONSTRAINT pk_exf_ai_skill PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_skill_alias_app UNIQUE (alias, app_oid),
    CONSTRAINT fk_exf_ai_skill_app
        FOREIGN KEY (app_oid) REFERENCES exf_app (oid)
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_skill_app
    ON exf_ai_skill (app_oid);

-- DOWN

-- Do not delete tables containing AI skill configurations!
