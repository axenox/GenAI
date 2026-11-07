/*
 * Count real standalone errors once and retained tool warnings once.
 * Legacy wrapper pairs have no portable correlated diagnostic identities.
 */
CREATE OR REPLACE VIEW exf_ai_conversation_counters AS
SELECT c.oid,
    (SELECT COUNT(*) FROM exf_ai_message m
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'error')
        AS error_count,
    (SELECT COUNT(*) FROM exf_ai_message m
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'warning')
    + COALESCE((
        SELECT SUM(CASE WHEN JSON_TYPE(w.warnings) = 'ARRAY'
            THEN JSON_LENGTH(w.warnings) ELSE 0 END)
        FROM exf_ai_message m
        JOIN JSON_TABLE(JSON_EXTRACT(
            IF(JSON_VALID(m.data), m.data, '{}'), '$.results'),
            '$.*' COLUMNS (warnings JSON PATH '$.warnings' NULL ON ERROR)
        ) w
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'tool'
            AND JSON_CONTAINS_PATH(
                IF(JSON_VALID(m.data), m.data, '{}'), 'one', '$.legacy') = 0
    ), 0) AS warning_count
FROM exf_ai_conversation c;
