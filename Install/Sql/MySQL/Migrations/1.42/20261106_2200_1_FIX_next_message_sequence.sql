/*
 * The counter holds the next free sequence, not the last allocated sequence.
 * Never move an already advanced counter backwards.
 *
 * @author GitHub Copilot
 */
-- UP
UPDATE exf_ai_conversation c
SET next_message_sequence = GREATEST(next_message_sequence, COALESCE((
    SELECT MAX(m.sequence_number) + 1 FROM exf_ai_message m
    WHERE m.ai_conversation_oid = c.oid
), 0));

-- DOWN
-- Never reclaim a sequence already reserved by a concurrent request.
