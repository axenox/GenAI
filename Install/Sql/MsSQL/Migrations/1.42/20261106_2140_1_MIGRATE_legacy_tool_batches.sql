/*
 * Merge only complete legacy wrapper arrays matching linked indexed call IDs.
 * Missing strings, ambiguous usage and unsupported content remain unresolved.
 * Original merged rows remain archived and original payloads in legacy DATA.
 * Requires SQL Server 2017+ with database compatibility level 130+.
 *
 * @author GitHub Copilot
 */
-- UP
IF OBJECT_ID('dbo.trash_exf_ai_message_tool_result', 'U') IS NULL
    SELECT TOP (0) * INTO dbo.trash_exf_ai_message_tool_result
    FROM dbo.exf_ai_message;

SELECT q.oid AS request_oid, r.oid AS result_oid,
    CASE WHEN ISJSON(q.data) = 1 THEN q.data ELSE '{}' END AS request_data,
    CASE WHEN ISJSON(r.data) = 1 THEN r.data ELSE '[]' END AS result_data
INTO #genai_legacy_candidates
FROM dbo.exf_ai_message q
JOIN dbo.exf_ai_message r ON r.ai_conversation_oid = q.ai_conversation_oid
    AND r.sequence_number = (
        SELECT MIN(n.sequence_number) FROM dbo.exf_ai_message n
        WHERE n.ai_conversation_oid = q.ai_conversation_oid
          AND n.sequence_number > q.sequence_number
          AND n.role NOT IN ('warning', 'error')
    )
WHERE q.role = 'tool calling' AND r.role = 'tool'
    AND ISJSON(q.data) = 1 AND LEFT(LTRIM(q.data), 1) = '{'
    AND ISJSON(r.data) = 1 AND LEFT(LTRIM(r.data), 1) = '['
    AND NOT (
        (q.cost IS NOT NULL OR q.tokens_prompt IS NOT NULL
            OR q.tokens_completion IS NOT NULL)
        AND (r.cost IS NOT NULL OR r.tokens_prompt IS NOT NULL
            OR r.tokens_completion IS NOT NULL)
    )
    AND NOT EXISTS (
        SELECT 1 FROM dbo.exf_ai_tool_call t WHERE t.ai_message_oid = r.oid
    )
    AND NOT EXISTS (
        SELECT 1 FROM OPENJSON(CASE WHEN ISJSON(q.data) = 1
            THEN q.data ELSE '{}' END)
        WHERE [key] = 'content' AND [type] NOT IN (0, 1)
    )
    -- JSON_VALUE is limited to 4,000 characters; retain larger content intact.
    AND NOT EXISTS (
        SELECT 1 FROM OPENJSON(CASE WHEN ISJSON(q.data) = 1
            THEN q.data ELSE '{}' END)
        WHERE [key] = 'content' AND [type] = 1
            AND JSON_VALUE(CASE WHEN ISJSON(q.data) = 1
                THEN q.data ELSE '{}' END, '$.content') IS NULL
    );

SELECT p.request_oid, p.result_oid, t.call_id, t.call_index,
    t.tool_name, t.tool_alias, t.arguments, t.execution_time_ms,
    t.trash_failed,
    (SELECT raw_arguments
        FROM OPENJSON(COALESCE(JSON_QUERY(p.request_data, '$.tool_calls'), '[]')) rc
        CROSS APPLY OPENJSON(CASE WHEN rc.[type] = 5
            THEN rc.value ELSE '{}' END) WITH (
                raw_arguments nvarchar(max) '$.function.arguments'
            ) ra
        WHERE TRY_CAST(rc.[key] AS int) + 1 = t.call_index)
        AS raw_arguments,
    CASE
        WHEN EXISTS (SELECT 1 FROM OPENJSON(COALESCE(
            JSON_QUERY(e.data, '$.toolResult'), '{}'))
            WHERE [key] = 'value' AND [type] = 1)
        THEN v.retained_value
        WHEN EXISTS (SELECT 1 FROM OPENJSON(e.data)
            WHERE [key] = 'toolResult' AND [type] = 1)
        THEN v.retained_string
        ELSE t.result END AS retained_result
INTO #genai_legacy_calls
FROM #genai_legacy_candidates p
JOIN dbo.exf_ai_tool_call t ON t.ai_message_oid = p.request_oid
CROSS APPLY OPENJSON(p.result_data) j
CROSS APPLY (SELECT CASE WHEN j.[type] = 5
    THEN j.value ELSE '{}' END AS data) e
CROSS APPLY OPENJSON(e.data) WITH (
    call_id nvarchar(255) '$.callId',
    retained_value nvarchar(max) '$.toolResult.value',
    retained_string nvarchar(max) '$.toolResult'
) v
WHERE j.[type] = 5 AND TRY_CAST(j.[key] AS int) + 1 = t.call_index
    AND v.call_id COLLATE Latin1_General_100_BIN2
        = t.call_id COLLATE Latin1_General_100_BIN2
    AND DATALENGTH(v.call_id) = DATALENGTH(t.call_id)
    AND EXISTS (SELECT 1 FROM OPENJSON(e.data)
        WHERE [key] = 'callId' AND [type] = 1)
    AND ISJSON(t.arguments) = 1 AND t.trash_failed IN (0, 1)
    AND NOT EXISTS (SELECT 1 FROM OPENJSON(p.request_data)
        WHERE [key] = 'tool_calls' AND [type] <> 4)
    AND (
        JSON_QUERY(p.request_data, '$.tool_calls') IS NULL
        OR (
            (SELECT COUNT(*) FROM OPENJSON(p.request_data, '$.tool_calls'))
                = (SELECT COUNT(*) FROM OPENJSON(p.result_data))
            AND EXISTS (
                SELECT 1 FROM OPENJSON(p.request_data, '$.tool_calls') rq
                CROSS APPLY OPENJSON(CASE WHEN rq.[type] = 5
                    THEN rq.value ELSE '{}' END) WITH (
                    call_id nvarchar(255) '$.id'
                ) ri
                WHERE TRY_CAST(rq.[key] AS int) + 1 = t.call_index
                    AND ri.call_id COLLATE Latin1_General_100_BIN2
                        = t.call_id COLLATE Latin1_General_100_BIN2
                    AND DATALENGTH(ri.call_id) = DATALENGTH(t.call_id)
                    AND EXISTS (SELECT 1 FROM OPENJSON(CASE WHEN rq.[type] = 5
                        THEN rq.value ELSE '{}' END)
                        WHERE [key] = 'id' AND [type] = 1)
            )
        )
    )
    AND COALESCE(JSON_QUERY(e.data, '$.toolResult.errors'), '[]') = '[]'
    AND COALESCE(JSON_QUERY(e.data, '$.toolResult.warnings'), '[]') = '[]'
    AND COALESCE(JSON_QUERY(e.data, '$.toolResult.appendix'), '[]') = '[]';

SELECT p.request_oid, p.result_oid
INTO #genai_legacy_pairs
FROM #genai_legacy_candidates p
JOIN #genai_legacy_calls c ON c.request_oid = p.request_oid
GROUP BY p.request_oid, p.result_oid, p.result_data
HAVING COUNT(*) = (SELECT COUNT(*) FROM OPENJSON(p.result_data))
    AND COUNT(DISTINCT c.call_id) = COUNT(*)
    AND COUNT(DISTINCT c.call_index) = COUNT(*)
    AND MIN(c.call_index) = 1 AND MAX(c.call_index) = COUNT(*)
    AND COUNT(c.retained_result) = COUNT(*)
    AND COUNT(*) = (
        SELECT COUNT(*) FROM dbo.exf_ai_tool_call t
        WHERE t.ai_message_oid = p.request_oid
    );

SELECT c.request_oid, c.call_index,
    CAST('"' + STRING_ESCAPE(c.call_id, 'json') + '"' AS nvarchar(max))
        AS quoted_id,
    (SELECT c.call_id, c.tool_name, c.tool_alias,
        JSON_QUERY(c.arguments) AS arguments,
        c.raw_arguments, NULL AS execution_arguments
     FOR JSON PATH, INCLUDE_NULL_VALUES, WITHOUT_ARRAY_WRAPPER) AS call_json,
    (SELECT '\axenox\GenAI\Common\AiToolResultString' AS class,
        JSON_QUERY('{"alias":"\\exface\\Core\\DataTypes\\StringDataType"}')
            AS type,
        c.retained_result AS value, CAST(c.trash_failed AS bit) AS failed,
        JSON_QUERY('[]') AS errors, JSON_QUERY('[]') AS warnings,
        JSON_QUERY('[]') AS appendix, c.execution_time_ms AS duration_ms
     FOR JSON PATH, INCLUDE_NULL_VALUES, WITHOUT_ARRAY_WRAPPER) AS result_json
INTO #genai_legacy_exports
FROM #genai_legacy_calls c
JOIN #genai_legacy_pairs p ON p.request_oid = c.request_oid;

SELECT request_oid,
    '[' + STRING_AGG(quoted_id, ',') WITHIN GROUP (
        ORDER BY call_index) + ']' AS call_order,
    '{' + STRING_AGG(quoted_id + ':' + call_json, ',') WITHIN GROUP (
        ORDER BY call_index) + '}' AS calls,
    '{' + STRING_AGG(quoted_id + ':' + result_json, ',') WITHIN GROUP (
        ORDER BY call_index) + '}' AS results
INTO #genai_legacy_payloads
FROM #genai_legacy_exports
GROUP BY request_oid;

INSERT INTO dbo.trash_exf_ai_message_tool_result
SELECT r.* FROM dbo.exf_ai_message r
JOIN #genai_legacy_pairs p ON p.result_oid = r.oid;
UPDATE q SET role = 'tool',
    data = (
        SELECT JSON_QUERY(CASE
            WHEN JSON_VALUE(pq.request_data, '$.content') IS NOT NULL
            THEN (SELECT 'text' AS type,
                JSON_VALUE(pq.request_data, '$.content') AS text
                FOR JSON PATH)
            ELSE '[]' END) AS content,
            JSON_QUERY(b.call_order) AS call_order,
            JSON_QUERY(b.calls) AS calls, JSON_QUERY(b.results) AS results,
            JSON_QUERY((SELECT
                LOWER(CONVERT(varchar(34), q.oid, 1)) AS request_uid,
                LOWER(CONVERT(varchar(34), r.oid, 1)) AS result_uid,
                q.data AS request_data, r.data AS result_data,
                q.sequence_number AS request_sequence,
                r.sequence_number AS result_sequence,
                q.message AS request_message, r.message AS result_message,
                q.cost AS [request_usage.cost],
                q.tokens_prompt AS [request_usage.tokens_prompt],
                q.tokens_completion AS [request_usage.tokens_completion],
                q.model AS [request_usage.model],
                r.cost AS [result_usage.cost],
                r.tokens_prompt AS [result_usage.tokens_prompt],
                r.tokens_completion AS [result_usage.tokens_completion],
                r.model AS [result_usage.model]
                FOR JSON PATH, INCLUDE_NULL_VALUES, WITHOUT_ARRAY_WRAPPER))
                AS legacy
        FOR JSON PATH, WITHOUT_ARRAY_WRAPPER
    ),
    cost = COALESCE(q.cost, r.cost),
    tokens_prompt = COALESCE(q.tokens_prompt, r.tokens_prompt),
    tokens_completion = COALESCE(q.tokens_completion, r.tokens_completion),
    model = COALESCE(q.model, r.model)
FROM dbo.exf_ai_message q
JOIN #genai_legacy_pairs p ON p.request_oid = q.oid
JOIN #genai_legacy_candidates pq ON pq.request_oid = q.oid
JOIN #genai_legacy_payloads b ON b.request_oid = q.oid
JOIN dbo.exf_ai_message r ON r.oid = p.result_oid;
DELETE r FROM dbo.exf_ai_message r
JOIN #genai_legacy_pairs p ON p.result_oid = r.oid;
DROP TABLE #genai_legacy_payloads;
DROP TABLE #genai_legacy_exports;
DROP TABLE #genai_legacy_pairs;
DROP TABLE #genai_legacy_calls;
DROP TABLE #genai_legacy_candidates;

-- DOWN
-- Non-reversible without operator review: originals remain in the archive
-- and combined DATA. Never overwrite subsequently completed audit messages.
