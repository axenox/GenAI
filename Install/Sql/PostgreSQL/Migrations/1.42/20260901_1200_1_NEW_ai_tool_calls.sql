/*
 * Create table exf_ai_tool_call
 *
 * Stores one record for every tool call made in an AI conversation.
 *
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS exf_ai_tool_call (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    ai_conversation_oid  uuid         NOT NULL,
    ai_message_oid       uuid         NOT NULL,
    call_index           integer      NOT NULL,
    call_id              varchar(255) NOT NULL,
    tool_name            varchar(255) NOT NULL,
    arguments            text,
    result               text,
    failed               smallint,
    CONSTRAINT pk_exf_ai_tool_call PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_tool_call_conversation_call
        UNIQUE (ai_conversation_oid, call_id),
    CONSTRAINT fk_exf_ai_tool_call_conversation
        FOREIGN KEY (ai_conversation_oid) REFERENCES exf_ai_conversation (oid),
    CONSTRAINT fk_exf_ai_tool_call_message
        FOREIGN KEY (ai_message_oid) REFERENCES exf_ai_message (oid)
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_tool_call_conversation
    ON exf_ai_tool_call (ai_conversation_oid);
CREATE INDEX IF NOT EXISTS idx_exf_ai_tool_call_message
    ON exf_ai_tool_call (ai_message_oid);
CREATE INDEX IF NOT EXISTS idx_exf_ai_tool_call_name
    ON exf_ai_tool_call (tool_name);

-- DOWN

-- Do not delete tables containing tool call history!
