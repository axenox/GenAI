/*
 * Allow parallel requests to persist equal message sequence numbers.
 * @author GitHub Copilot
 */
-- UP
CREATE INDEX IF NOT EXISTS idx_exf_ai_message_conversation
    ON exf_ai_message(ai_conversation_oid);
ALTER TABLE exf_ai_message
    DROP CONSTRAINT IF EXISTS uq_exf_ai_message_conversation_sequence;
DROP INDEX IF EXISTS uq_exf_ai_message_conversation_sequence;

-- DOWN
-- Refuse rollback if parallel writes have created duplicates; retain history.
CREATE UNIQUE INDEX uq_exf_ai_message_conversation_sequence
    ON exf_ai_message(ai_conversation_oid, sequence_number);
