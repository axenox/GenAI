CREATE TABLE IF NOT EXISTS exf_ai_agent (
    oid                 uuid            NOT NULL,
    created_on          timestamp       NOT NULL,
    modified_on         timestamp       NOT NULL,
    created_by_user_oid uuid,
    modified_by_user_oid uuid,
    app_oid             uuid,
    alias               varchar(100)    NOT NULL,
    name                varchar(100)    NOT NULL,
    description         text,
    CONSTRAINT pk_exf_ai_agent PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_agent_alias_app_oid UNIQUE (alias, app_oid)
);

CREATE TABLE exf_ai_agent_version (
    oid uuid PRIMARY KEY,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid,
    modified_by_user_oid uuid,
    ai_agent_oid uuid NOT NULL,
    version varchar(50) NOT NULL DEFAULT '1.0',
    description text,
    prototype_class varchar(255) NOT NULL,
    config_uxon text,
    data_connection_default_oid uuid,
    enabled_flag smallint NOT NULL DEFAULT 0
);

-- Foreign keys
ALTER TABLE exf_ai_agent_version
    ADD CONSTRAINT fk_ai_agent_version_agent
        FOREIGN KEY (ai_agent_oid)
            REFERENCES exf_ai_agent (oid);

ALTER TABLE exf_ai_agent_version
    ADD CONSTRAINT fk_ai_agent_version_data_connection_default
        FOREIGN KEY (data_connection_default_oid)
            REFERENCES exf_data_connection (oid);