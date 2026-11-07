/*
 * Count real standalone errors once and retained tool warnings once.
 * Legacy wrapper pairs have no portable correlated diagnostic identities.
 */
CREATE OR ALTER VIEW dbo.exf_ai_conversation_counters AS
SELECT c.oid,
    (SELECT COUNT(*) FROM dbo.exf_ai_message m
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'error')
        AS error_count,
    (SELECT COUNT(*) FROM dbo.exf_ai_message m
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'warning')
    + COALESCE((
        SELECT COUNT(*)
        FROM dbo.exf_ai_message m
        CROSS APPLY OPENJSON(CASE WHEN ISJSON(m.data) = 1
            THEN m.data ELSE '{}' END, '$.results') r
        CROSS APPLY OPENJSON(r.value) WITH (
            warnings nvarchar(max) '$.warnings' AS JSON
        ) rw
        CROSS APPLY OPENJSON(CASE WHEN ISJSON(rw.warnings) = 1
            AND LEFT(LTRIM(rw.warnings), 1) = '['
            THEN rw.warnings ELSE '[]' END) w
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'tool'
            AND JSON_QUERY(CASE WHEN ISJSON(m.data) = 1
                THEN m.data ELSE '{}' END, '$.legacy') IS NULL
    ), 0) AS warning_count
FROM dbo.exf_ai_conversation c;
