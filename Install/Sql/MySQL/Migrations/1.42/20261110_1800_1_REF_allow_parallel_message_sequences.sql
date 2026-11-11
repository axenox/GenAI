/*
 * Allow parallel requests to persist equal message sequence numbers.
 * Keep a non-unique conversation index for the foreign key and history reads.
 * @author GitHub Copilot
 */
-- UP
DROP PROCEDURE IF EXISTS genai_allow_parallel_sequences;
CREATE PROCEDURE genai_allow_parallel_sequences()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_message'
        AND index_name = 'IDX_exf_ai_message_conversation') THEN
        CREATE INDEX IDX_exf_ai_message_conversation
            ON exf_ai_message(ai_conversation_oid);
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.statistics WHERE
        table_schema = DATABASE() AND table_name = 'exf_ai_message'
        AND index_name = 'UQ_exf_ai_message_conversation_sequence') THEN
        ALTER TABLE exf_ai_message
            DROP INDEX UQ_exf_ai_message_conversation_sequence;
    END IF;
END;
CALL genai_allow_parallel_sequences();
DROP PROCEDURE genai_allow_parallel_sequences;

-- DOWN
-- Refuse rollback if parallel writes have created duplicates; retain history.
CREATE UNIQUE INDEX UQ_exf_ai_message_conversation_sequence
    ON exf_ai_message(ai_conversation_oid, sequence_number);
