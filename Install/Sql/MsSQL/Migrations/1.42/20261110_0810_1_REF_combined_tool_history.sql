/*
 * Merge only explicit, ordered legacy call/result identities.
 * Keep result UID and MESSAGE; original rows survive under DATA.legacy.
 *
 * Prerequisite: the shared-conversation schema migration has added lifecycle
 * columns and changed call-ID uniqueness to message scope.
 * Requires OPENJSON support (database compatibility level 130 or higher).
 * Run with conversation writers stopped and take a backup before installation.
 *
 * Before: q.role = 'tool calling', q.data.tool_calls = ordered requests;
 *         r.role = 'tool', r.data = ordered callId/toolResult wrappers.
 * After:  r keeps its oid, role, MESSAGE and timeline position. Its DATA contains
 *         content[], calls[], results[] and snapshots of both original rows.
 *         Request-linked details move to r before q is deleted.
 *
 * No tools or providers are invoked; display text is not a source of results.
 * Uncertain pairs remain untouched and may still block continuation. Other
 * message text and standalone diagnostics are unchanged. Sequence normalization
 * belongs to the following migration.
 * @author GitHub Copilot
 */
-- UP
-- 1. Find adjacent non-diagnostic rows within each conversation. Sequence,
-- creation time and UID provide deterministic order; intervening warning/error
-- rows are ignored, but any other intervening message prevents pairing.
-- Require valid JSON, request/result arrays and absent/null/text request content.
-- Reject known usage on both sides (zero counts as known), since selecting or
-- adding it could misrepresent costs. Snapshot complete rows, including NULLs,
-- before modifying history. # tables are local to the migration connection.
WITH timeline AS (
    SELECT oid, LEAD(oid) OVER (PARTITION BY ai_conversation_oid
        ORDER BY sequence_number, created_on, oid) AS next_oid
    FROM dbo.exf_ai_message WHERE role NOT IN ('warning', 'error')
)
SELECT q.oid AS request_oid, r.oid AS result_oid,
    q.data AS request_data, r.data AS result_data,
    (SELECT q.* FOR JSON PATH, INCLUDE_NULL_VALUES,
        WITHOUT_ARRAY_WRAPPER) AS request_row,
    (SELECT r.* FOR JSON PATH, INCLUDE_NULL_VALUES,
        WITHOUT_ARRAY_WRAPPER) AS result_row
INTO #genai_legacy_pairs
FROM timeline t
JOIN dbo.exf_ai_message q ON q.oid = t.oid
JOIN dbo.exf_ai_message r ON r.oid = t.next_oid
WHERE q.role = 'tool calling' AND r.role = 'tool'
    AND ISJSON(q.data) = 1 AND ISJSON(r.data) = 1
    AND LEFT(LTRIM(r.data), 1) = '['
    AND LEFT(LTRIM(JSON_QUERY(q.data, '$.tool_calls')), 1) = '['
    AND NOT ((q.tokens_prompt IS NOT NULL
        OR q.tokens_completion IS NOT NULL OR q.cost IS NOT NULL)
        AND (r.tokens_prompt IS NOT NULL
        OR r.tokens_completion IS NOT NULL OR r.cost IS NOT NULL))
    AND NOT EXISTS (SELECT 1 FROM OPENJSON(q.data) c
        WHERE c.[key] = 'content' AND c.type NOT IN (0, 1));

-- 2. OPENJSON keys are zero-based; detail call_index is one-based. Match wrapper
-- positions first, then validate IDs for the whole batch. Optional details must
-- match both ID (binary collation) and index and may belong to either old row.
-- Accept object/list arguments directly or from encoded JSON, retaining the raw
-- argument string. Scalar/null arguments are not replaced with guessed objects.
-- Prefer string toolResult.value, then string toolResult, then the detail value;
-- OPENJSON avoids JSON_VALUE's scalar length limit for retained result strings.
SELECT p.request_oid, p.result_oid, CONVERT(int, q.[key]) + 1 AS ord,
    (SELECT COUNT(*) FROM OPENJSON(p.request_data, '$.tool_calls'))
        AS call_count,
    (SELECT COUNT(*) FROM OPENJSON(p.result_data)) AS result_count,
    a.call_id, b.call_id AS result_call_id, a.tool_name,
    CASE WHEN args.type IN (4, 5) THEN args.value
        WHEN args.type = 1 AND ISJSON(args.value) = 1
            AND LEFT(LTRIM(args.value), 1) IN ('{', '[') THEN args.value END AS arguments,
    CASE WHEN args.type = 1 THEN args.value END AS raw_arguments,
    COALESCE(v.retained_value,
        CASE WHEN tr.type = 1 THEN tr.value END, d.result) AS retained_value,
    CASE WHEN ISJSON(tr.value) = 1 THEN tr.value ELSE '{}' END AS result_data,
    d.state, d.tool_alias, d.started_on, d.finished_on, d.execution_time_ms
INTO #genai_legacy_calls
FROM #genai_legacy_pairs p
CROSS APPLY OPENJSON(p.request_data, '$.tool_calls') q
CROSS APPLY (SELECT * FROM OPENJSON(p.result_data)
    WHERE [key] = q.[key]) s
CROSS APPLY OPENJSON(q.value) WITH (
    call_id nvarchar(255) '$.id',
    tool_name nvarchar(255) '$.function.name') a
CROSS APPLY OPENJSON(s.value) WITH (call_id nvarchar(255) '$.callId') b
OUTER APPLY (SELECT value, type FROM OPENJSON(q.value, '$.function')
    WHERE [key] = 'arguments') args
OUTER APPLY (SELECT value, type FROM OPENJSON(s.value)
    WHERE [key] = 'toolResult') tr
OUTER APPLY (SELECT value AS retained_value
    FROM OPENJSON(CASE WHEN ISJSON(tr.value) = 1 THEN tr.value ELSE '{}' END)
    WHERE [key] = 'value' AND type = 1) v
LEFT JOIN dbo.exf_ai_tool_call d
    ON d.ai_message_oid IN (p.request_oid, p.result_oid)
    AND d.call_id COLLATE Latin1_General_100_BIN2
        = a.call_id COLLATE Latin1_General_100_BIN2
    AND d.call_index = CONVERT(int, q.[key]) + 1;

-- 3. Reject an entire staged pair unless counts agree, IDs are unique/non-empty
-- and match exactly, names/arguments are usable and every result string exists.
-- CASE treats unknown comparisons as invalid. Also reject any pair with linked
-- details that would not be represented. This deletes candidates, not messages.
DELETE p FROM #genai_legacy_pairs p WHERE NOT EXISTS (
    SELECT 1 FROM #genai_legacy_calls c WHERE c.request_oid = p.request_oid
    GROUP BY c.request_oid HAVING COUNT(*) = MAX(call_count)
        AND MAX(call_count) = MAX(result_count) AND MAX(call_count) > 0
        AND COUNT(DISTINCT call_id COLLATE Latin1_General_100_BIN2)
            = MAX(call_count)
        AND MIN(CASE WHEN call_id IS NOT NULL AND call_id <> ''
            AND call_id COLLATE Latin1_General_100_BIN2
                = result_call_id COLLATE Latin1_General_100_BIN2
            AND retained_value IS NOT NULL AND arguments IS NOT NULL
            AND tool_name IS NOT NULL AND tool_name <> '' THEN 1 ELSE 0 END) = 1
) OR EXISTS (
    SELECT 1 FROM dbo.exf_ai_tool_call d
    WHERE d.ai_message_oid IN (p.request_oid, p.result_oid)
        AND NOT EXISTS (SELECT 1 FROM #genai_legacy_calls c
            WHERE c.request_oid = p.request_oid
                AND c.call_id COLLATE Latin1_General_100_BIN2
                    = d.call_id COLLATE Latin1_General_100_BIN2
                AND c.ord = d.call_index));

-- 4. Build ordered calls/results directly into the surviving RESULT row.
-- JSON_QUERY embeds arrays/objects rather than escaped JSON strings. Each result
-- is AiToolResultString: JSON-looking output stays text. Retain failure state,
-- diagnostics, appendix and known duration; omit unknown result duration because
-- the loader uses a non-nullable setter. No standalone diagnostics are generated.
-- MESSAGE, UID and role are deliberately absent from the update assignments.
-- Request text becomes semantic content and both original rows remain in legacy.
-- Move usage/model/finish reason with request-first fallback, not addition, so
-- known zeros survive and an exchange is counted once. Timing spans both rows.
UPDATE r SET
    data = (
        SELECT JSON_QUERY(CASE WHEN content.value IS NULL THEN '[]'
            ELSE (SELECT 'text' AS type, content.value AS text
                FOR JSON PATH) END) AS content,
            JSON_QUERY((SELECT c.call_id, c.tool_name,
                JSON_QUERY(c.arguments) AS arguments, c.raw_arguments,
                c.tool_alias, c.started_on, c.finished_on,
                c.execution_time_ms AS duration_ms
                FROM #genai_legacy_calls c WHERE c.request_oid = q.oid
                ORDER BY c.ord FOR JSON PATH, INCLUDE_NULL_VALUES)) AS calls,
            JSON_QUERY((SELECT c.call_id,
                '\axenox\GenAI\Common\AiToolResultString' AS class,
                JSON_QUERY('{"alias":"exface.Core.String"}') AS type,
                c.retained_value AS value,
                CONVERT(bit, CASE WHEN c.state = 'FAILED'
                    OR JSON_VALUE(c.result_data, '$.failed') = 'true'
                    THEN 1 ELSE 0 END) AS failed,
                JSON_QUERY(COALESCE(JSON_QUERY(c.result_data, '$.errors'),
                    '[]')) AS errors,
                JSON_QUERY(COALESCE(JSON_QUERY(c.result_data, '$.warnings'),
                    '[]')) AS warnings,
                JSON_QUERY(COALESCE(JSON_QUERY(c.result_data, '$.appendix'),
                    '[]')) AS appendix,
                c.execution_time_ms AS duration_ms
                FROM #genai_legacy_calls c WHERE c.request_oid = q.oid
                ORDER BY c.ord FOR JSON PATH)) AS results,
            JSON_QUERY((SELECT JSON_QUERY(p.request_data) AS request_data,
                JSON_QUERY(p.result_data) AS result_data,
                JSON_QUERY(p.request_row) AS request_row,
                JSON_QUERY(p.result_row) AS result_row,
                CONVERT(varchar(34), p.request_oid, 1) AS request_message_uid,
                CONVERT(varchar(34), p.result_oid, 1) AS result_message_uid
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS legacy
        FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
    started_on = COALESCE(q.started_on, q.created_on),
    finished_on = COALESCE(r.finished_on, r.created_on),
    tokens_prompt = COALESCE(q.tokens_prompt, r.tokens_prompt),
    tokens_completion = COALESCE(q.tokens_completion, r.tokens_completion),
    cost = COALESCE(q.cost, r.cost),
    model = COALESCE(q.model, r.model),
    finish_reason = COALESCE(q.finish_reason, r.finish_reason)
FROM dbo.exf_ai_message q
JOIN #genai_legacy_pairs p ON p.request_oid = q.oid
JOIN dbo.exf_ai_message r ON r.oid = p.result_oid
OUTER APPLY (SELECT value FROM OPENJSON(p.request_data)
    WHERE [key] = 'content' AND type = 1) content;

-- 5. Move request-linked detail foreign keys before deleting accepted requests.
-- Details already attached to the result remain unchanged. The deleted request's
-- original UID, text, usage and payload remain inspectable under DATA.legacy.
UPDATE d SET ai_message_oid = p.result_oid
FROM dbo.exf_ai_tool_call d
JOIN #genai_legacy_pairs p ON p.request_oid = d.ai_message_oid;
DELETE q FROM dbo.exf_ai_message q
JOIN #genai_legacy_pairs p ON p.request_oid = q.oid;
-- A completed rerun cannot match already-converted requests because they no
-- longer exist. This does not substitute for recovery after a partial failure.
DROP TABLE #genai_legacy_calls;
DROP TABLE #genai_legacy_pairs;

-- DOWN
-- Original row snapshots remain in DATA.legacy. Do not duplicate usage.
-- No automatic split: restoring rows, links and old sequence positions could
-- conflict with later activity. Rollback/recovery requires operator review.
