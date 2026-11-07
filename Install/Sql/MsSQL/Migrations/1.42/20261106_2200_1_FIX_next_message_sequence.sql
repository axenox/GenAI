/*
 * The counter holds the next free sequence, not the last allocated sequence.
 * Never move an already advanced counter backwards.
 *
 * @author GitHub Copilot
 */
-- UP
UPDATE c SET next_message_sequence = s.next_sequence
FROM dbo.exf_ai_conversation c
CROSS APPLY (
    SELECT COALESCE(MAX(m.sequence_number) + 1, 0) AS next_sequence
    FROM dbo.exf_ai_message m WHERE m.ai_conversation_oid = c.oid
) s
WHERE c.next_message_sequence < s.next_sequence;

-- DOWN
-- Never reclaim a sequence already reserved by a concurrent request.
