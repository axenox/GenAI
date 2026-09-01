/*
 * Create table exf_ai_feedback
 *
 * Stores feedback for an AI agent version and optional conversation.
 *
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS exf_ai_feedback (
    oid                  uuid      NOT NULL,
    created_on           timestamp NOT NULL,
    modified_on          timestamp NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    agent_version_oid    uuid      NOT NULL,
    ai_conversation_oid  uuid,
    general_feedback     text,
    suggested_tools      text,
    feedback_checked     smallint  NOT NULL DEFAULT 0,
    CONSTRAINT pk_exf_ai_feedback PRIMARY KEY (oid),
    CONSTRAINT chk_exf_ai_feedback_checked
        CHECK (feedback_checked IN (0, 1)),
    CONSTRAINT fk_exf_ai_feedback_agent_version
        FOREIGN KEY (agent_version_oid)
        REFERENCES exf_ai_agent_version (oid)
        ON DELETE RESTRICT,
    CONSTRAINT fk_exf_ai_feedback_conversation
        FOREIGN KEY (ai_conversation_oid)
        REFERENCES exf_ai_conversation (oid)
        ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_feedback_agent_version
    ON exf_ai_feedback (agent_version_oid);
CREATE INDEX IF NOT EXISTS idx_exf_ai_feedback_conversation
    ON exf_ai_feedback (ai_conversation_oid);

-- DOWN

DROP TABLE IF EXISTS exf_ai_feedback;