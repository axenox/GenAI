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

CREATE TABLE exf_ai_conversation (
                                     oid uuid PRIMARY KEY,
                                     created_on timestamp NOT NULL,
                                     modified_on timestamp NOT NULL,
                                     created_by_user_oid uuid,
                                     modified_by_user_oid uuid,
                                     ai_agent_oid uuid NOT NULL,
                                     user_oid uuid,
                                     title varchar(100),
                                     meta_object_oid uuid,
                                     context_data_uxon text,
                                     data text,
                                     page_uid uuid,
                                     ai_agent_version_no varchar(50) NOT NULL DEFAULT '1.0',
                                     rating smallint,
                                     rating_feedback text,
                                     devmode smallint DEFAULT 0,
                                     connection_oid uuid,
                                     model varchar(100)
);

CREATE TABLE exf_ai_message (
                                oid uuid PRIMARY KEY,
                                created_on timestamp NOT NULL,
                                modified_on timestamp NOT NULL,
                                created_by_user_oid uuid,
                                modified_by_user_oid uuid,
                                ai_conversation_oid uuid NOT NULL,
                                role varchar(30) NOT NULL,
                                message text NOT NULL,
                                data text,
                                tokens_prompt integer,
                                tokens_completion integer,
                                cost numeric(18, 6),
                                sequence_number integer,
                                cost_per_m_tokens double precision,
                                finish_reason varchar(20)
);

CREATE TABLE exf_ai_test_case (
                                  oid uuid PRIMARY KEY,
                                  created_on timestamp NOT NULL,
                                  modified_on timestamp NOT NULL,
                                  created_by_user_oid uuid,
                                  modified_by_user_oid uuid,
                                  ai_agent_oid uuid NOT NULL,
                                  app_oid uuid,
                                  name varchar(100) NOT NULL,
                                  prompt text NOT NULL,
                                  context text,
                                  automatable_flag smallint NOT NULL DEFAULT 0,
                                  description varchar(500),
                                  locale varchar(50)
);

CREATE TABLE exf_ai_test_run (
                                 oid uuid PRIMARY KEY,
                                 created_on timestamp NOT NULL,
                                 modified_on timestamp NOT NULL,
                                 created_by_user_oid uuid,
                                 modified_by_user_oid uuid,
                                 tested_on timestamp NOT NULL,
                                 ai_test_case_oid uuid NOT NULL,
                                 ai_conversation_oid uuid,
                                 status smallint NOT NULL
);

CREATE TABLE exf_ai_test_criterion (
                                       oid uuid PRIMARY KEY,
                                       created_on timestamp NOT NULL,
                                       modified_on timestamp NOT NULL,
                                       created_by_user_oid uuid,
                                       modified_by_user_oid uuid,
                                       name varchar(100) NOT NULL,
                                       ai_test_case_oid uuid NOT NULL,
                                       expected_value text,
                                       prototype_path varchar(500) NOT NULL,
                                       config_uxon text,
                                       weight numeric(4, 3) NOT NULL DEFAULT 1.000,
                                       description varchar(500)
);

CREATE TABLE exf_ai_test_result (
                                    oid uuid PRIMARY KEY,
                                    created_on timestamp NOT NULL,
                                    modified_on timestamp NOT NULL,
                                    created_by_user_oid uuid,
                                    modified_by_user_oid uuid,
                                    ai_test_run_oid uuid NOT NULL,
                                    ai_test_criterion_oid uuid NOT NULL,
                                    value text,
                                    user_rating smallint,
                                    user_feedback varchar(500)
);

CREATE TABLE exf_ai_test_result_rating (
                                           oid uuid PRIMARY KEY,
                                           created_on timestamp NOT NULL,
                                           modified_on timestamp NOT NULL,
                                           created_by_user_oid uuid,
                                           modified_by_user_oid uuid,
                                           ai_test_result_oid uuid NOT NULL,
                                           rating smallint NOT NULL,
                                           name varchar(50) NOT NULL,
                                           raw_value text,
                                           explanation text,
                                           pros text,
                                           cons text
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

ALTER TABLE exf_ai_conversation
    ADD CONSTRAINT fk_exf_ai_conversation_agent
        FOREIGN KEY (ai_agent_oid)
            REFERENCES exf_ai_agent (oid);

ALTER TABLE exf_ai_conversation
    ADD CONSTRAINT fk_exf_ai_conversation_user
        FOREIGN KEY (user_oid)
            REFERENCES exf_user (oid);

ALTER TABLE exf_ai_conversation
    ADD CONSTRAINT fk_exf_ai_conversation_connection
        FOREIGN KEY (connection_oid)
            REFERENCES exf_data_connection (oid);

ALTER TABLE exf_ai_message
    ADD CONSTRAINT fk_exf_ai_message_conversation
        FOREIGN KEY (ai_conversation_oid)
            REFERENCES exf_ai_conversation (oid);

ALTER TABLE exf_ai_test_case
    ADD CONSTRAINT fk_exf_ai_test_case_agent
        FOREIGN KEY (ai_agent_oid)
            REFERENCES exf_ai_agent (oid);

ALTER TABLE exf_ai_test_case
    ADD CONSTRAINT fk_exf_ai_test_case_app
        FOREIGN KEY (app_oid)
            REFERENCES exf_app (oid);

ALTER TABLE exf_ai_test_run
    ADD CONSTRAINT fk_exf_ai_test_run_test_case
        FOREIGN KEY (ai_test_case_oid)
            REFERENCES exf_ai_test_case (oid);

ALTER TABLE exf_ai_test_run
    ADD CONSTRAINT fk_exf_ai_test_run_conversation
        FOREIGN KEY (ai_conversation_oid)
            REFERENCES exf_ai_conversation (oid);

ALTER TABLE exf_ai_test_criterion
    ADD CONSTRAINT fk_exf_ai_test_criterion_test_case
        FOREIGN KEY (ai_test_case_oid)
            REFERENCES exf_ai_test_case (oid);

ALTER TABLE exf_ai_test_result
    ADD CONSTRAINT fk_exf_ai_test_result_test_run
        FOREIGN KEY (ai_test_run_oid)
            REFERENCES exf_ai_test_run (oid);

ALTER TABLE exf_ai_test_result
    ADD CONSTRAINT fk_exf_ai_test_result_criterion
        FOREIGN KEY (ai_test_criterion_oid)
            REFERENCES exf_ai_test_criterion (oid);

ALTER TABLE exf_ai_test_result_rating
    ADD CONSTRAINT fk_exf_ai_test_result_rating_result
        FOREIGN KEY (ai_test_result_oid)
            REFERENCES exf_ai_test_result (oid);
