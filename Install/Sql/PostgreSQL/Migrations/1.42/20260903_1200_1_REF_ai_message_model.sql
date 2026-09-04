/*
 * Move the model column from AI conversations to messages
 */
-- UP

ALTER TABLE exf_ai_message
    ADD COLUMN model varchar(100) NULL;

UPDATE exf_ai_message AS m
SET model = c.model
FROM exf_ai_conversation AS c
WHERE c.oid = m.ai_conversation_oid;

ALTER TABLE exf_ai_conversation
    DROP COLUMN model;

-- DOWN

ALTER TABLE exf_ai_conversation
    ADD COLUMN model varchar(100) NULL;

UPDATE exf_ai_conversation AS c
SET model = (
    SELECT MAX(m.model)
    FROM exf_ai_message AS m
    WHERE m.ai_conversation_oid = c.oid
);

ALTER TABLE exf_ai_message
    DROP COLUMN model;