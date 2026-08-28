/*
 * Create table exf_ai_note
 *
 * Stores long-term notes scoped to one AI agent and one user.
 *
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS exf_ai_note (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    user_oid             uuid         NOT NULL,
    ai_agent_oid         uuid         NOT NULL,
    topic                varchar(200) NOT NULL,
    note                 text         NOT NULL,
    CONSTRAINT pk_exf_ai_note PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_note_scope_topic
        UNIQUE (user_oid, ai_agent_oid, topic),
    CONSTRAINT fk_exf_ai_note_user
        FOREIGN KEY (user_oid) REFERENCES exf_user (oid),
    CONSTRAINT fk_exf_ai_note_agent
        FOREIGN KEY (ai_agent_oid) REFERENCES exf_ai_agent (oid)
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_note_user
    ON exf_ai_note (user_oid);
CREATE INDEX IF NOT EXISTS idx_exf_ai_note_agent
    ON exf_ai_note (ai_agent_oid);

-- DOWN

-- Do not delete tables containing user notes!