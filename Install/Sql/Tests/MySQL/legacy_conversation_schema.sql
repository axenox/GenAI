-- Relevant pre-unification schema; the runner prefixes all three table names.
CREATE TABLE exf_ai_conversation (
    oid binary(16) NOT NULL PRIMARY KEY,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    ai_agent_oid binary(16) NOT NULL,
    user_oid binary(16) NULL,
    title varchar(100) NULL,
    data text NULL
) ENGINE=InnoDB;

CREATE TABLE exf_ai_message (
    oid binary(16) NOT NULL PRIMARY KEY,
    ai_conversation_oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16) NULL,
    modified_by_user_oid binary(16) NULL,
    sequence_number int NULL,
    role varchar(30) NOT NULL,
    message longtext NOT NULL,
    data longtext NULL,
    model varchar(100) NULL,
    tokens_prompt int NULL,
    tokens_completion int NULL,
    cost decimal(18,6) NULL,
    finish_reason varchar(100) NULL,
    error_log_id varchar(100) NULL,
    KEY exf_ai_message_conversation (ai_conversation_oid),
    FOREIGN KEY (ai_conversation_oid) REFERENCES exf_ai_conversation (oid)
) ENGINE=InnoDB;

CREATE TABLE exf_ai_tool_call (
    oid binary(16) NOT NULL PRIMARY KEY,
    ai_conversation_oid binary(16) NOT NULL,
    ai_message_oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    call_index int NOT NULL,
    call_id varchar(255) NOT NULL,
    tool_name varchar(255) NOT NULL,
    tool_alias varchar(255) NULL,
    arguments longtext NULL,
    result longtext NULL,
    execution_time_ms decimal(18,3) NULL,
    failed smallint NULL,
    UNIQUE KEY exf_ai_tool_call_conversation_call (ai_conversation_oid, call_id),
    KEY exf_ai_tool_call_conversation (ai_conversation_oid),
    KEY exf_ai_tool_call_message (ai_message_oid),
    FOREIGN KEY (ai_conversation_oid) REFERENCES exf_ai_conversation (oid),
    FOREIGN KEY (ai_message_oid) REFERENCES exf_ai_message (oid)
) ENGINE=InnoDB;
