/*
 * Allow parallel requests to persist equal message sequence numbers.
 * @author GitHub Copilot
 */
-- UP
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE
    object_id = OBJECT_ID('dbo.exf_ai_message')
    AND name = 'IDX_exf_ai_message_conversation')
    CREATE INDEX IDX_exf_ai_message_conversation
        ON dbo.exf_ai_message(ai_conversation_oid);
IF EXISTS (SELECT 1 FROM sys.key_constraints WHERE
    parent_object_id = OBJECT_ID('dbo.exf_ai_message')
    AND name = 'UQ_exf_ai_message_conversation_sequence')
    ALTER TABLE dbo.exf_ai_message
        DROP CONSTRAINT UQ_exf_ai_message_conversation_sequence;
ELSE IF EXISTS (SELECT 1 FROM sys.indexes WHERE
    object_id = OBJECT_ID('dbo.exf_ai_message')
    AND name = 'UQ_exf_ai_message_conversation_sequence')
    DROP INDEX UQ_exf_ai_message_conversation_sequence ON dbo.exf_ai_message;

-- DOWN
-- Refuse rollback if parallel writes have created duplicates; retain history.
CREATE UNIQUE INDEX UQ_exf_ai_message_conversation_sequence
    ON dbo.exf_ai_message(ai_conversation_oid, sequence_number);
