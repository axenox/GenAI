/*
 * Merge only explicitly correlated legacy tool batches.
 * Keep the result UID and MESSAGE unchanged; archive the request under DATA.legacy.
 * Ambiguous usage, identifiers, arguments or absent values leave rows intact.
 *
 * Prerequisite: the shared-conversation schema migration has added lifecycle
 * columns and replaced conversation-wide call-ID uniqueness with message scope.
 * Run with conversation writers stopped and take a backup before installation.
 *
 * Before: q.role = 'tool calling', q.data.tool_calls = ordered requests;
 *         r.role = 'tool', r.data = ordered callId/toolResult wrappers.
 * After:  r keeps its oid, role, MESSAGE and timeline position. Its DATA contains
 *         content[], calls[], results[] and legacy snapshots of both old rows.
 *         Call details point to r; q is deleted only after its data is retained.
 *
 * No tools or providers are invoked. Display text is never used to invent a
 * result. Unresolved pairs remain visible but may still prevent continuation.
 * Non-tool messages and standalone warning/error rows are not rewritten.
 * Sequence normalization is handled by the following migration, not this one.
 * @author GitHub Copilot
 */
-- UP
-- 1. Find adjacent request/result messages within each conversation, ignoring
-- standalone diagnostics between them. Sequence, creation time and UID provide
-- deterministic ordering; a different intervening non-diagnostic row breaks a pair.
-- Require equally sized, non-empty request/result arrays and portable request
-- content (absent, null or text). Reject pairs with usage on BOTH sides: even
-- zero is known usage, and adding or selecting such values would be ambiguous.
-- Snapshot the original payloads and row metadata before any persistent update.
DROP TEMPORARY TABLE IF EXISTS genai_legacy_pairs;
CREATE TEMPORARY TABLE genai_legacy_pairs AS
SELECT q.oid AS request_oid, r.oid AS result_oid,
    q.data AS request_data, r.data AS result_data,
    JSON_LENGTH(q.data, '$.tool_calls') AS call_count,
    JSON_OBJECT('request_data', JSON_EXTRACT(q.data, '$'),
        'result_data', JSON_EXTRACT(r.data, '$'),
        'request_message_uid', CONCAT('0x', LOWER(HEX(q.oid))),
        'result_message_uid', CONCAT('0x', LOWER(HEX(r.oid))),
        'request_row', JSON_OBJECT(
            'oid', CONCAT('0x', LOWER(HEX(q.oid))),
            'ai_conversation_oid', CONCAT('0x', LOWER(HEX(q.ai_conversation_oid))),
            'message', q.message, 'data', q.data, 'role', q.role,
            'created_on', q.created_on, 'modified_on', q.modified_on,
            'created_by_user_oid', HEX(q.created_by_user_oid),
            'modified_by_user_oid', HEX(q.modified_by_user_oid),
            'sequence_number', q.sequence_number, 'model', q.model,
            'tokens_prompt', q.tokens_prompt,
            'tokens_completion', q.tokens_completion, 'cost', q.cost,
            'finish_reason', q.finish_reason,
            'error_log_id', q.error_log_id,
            'started_on', q.started_on, 'finished_on', q.finished_on),
        'result_row', JSON_OBJECT(
            'oid', CONCAT('0x', LOWER(HEX(r.oid))),
            'ai_conversation_oid', CONCAT('0x', LOWER(HEX(r.ai_conversation_oid))),
            'message', r.message, 'data', r.data, 'role', r.role,
            'created_on', r.created_on, 'modified_on', r.modified_on,
            'created_by_user_oid', HEX(r.created_by_user_oid),
            'modified_by_user_oid', HEX(r.modified_by_user_oid),
            'sequence_number', r.sequence_number, 'model', r.model,
            'tokens_prompt', r.tokens_prompt,
            'tokens_completion', r.tokens_completion, 'cost', r.cost,
            'finish_reason', r.finish_reason,
            'error_log_id', r.error_log_id,
            'started_on', r.started_on, 'finished_on', r.finished_on))
        AS legacy_data
FROM (
    SELECT oid, LEAD(oid) OVER (PARTITION BY ai_conversation_oid
        ORDER BY sequence_number, created_on, oid) AS next_oid
    FROM exf_ai_message WHERE role NOT IN ('warning', 'error')
) timeline
JOIN exf_ai_message q ON q.oid = timeline.oid
JOIN exf_ai_message r ON r.oid = timeline.next_oid
WHERE q.role = 'tool calling' AND r.role = 'tool'
    AND JSON_VALID(q.data) AND JSON_VALID(r.data)
    AND JSON_TYPE(JSON_EXTRACT(q.data, '$.tool_calls')) = 'ARRAY'
    AND JSON_TYPE(JSON_EXTRACT(r.data, '$')) = 'ARRAY'
    AND JSON_LENGTH(q.data, '$.tool_calls') > 0
    AND JSON_LENGTH(q.data, '$.tool_calls') = JSON_LENGTH(r.data)
    AND (JSON_EXTRACT(q.data, '$.content') IS NULL
        OR JSON_TYPE(JSON_EXTRACT(q.data, '$.content')) IN ('NULL', 'STRING'))
    AND NOT (
        (q.tokens_prompt IS NOT NULL OR q.tokens_completion IS NOT NULL
            OR q.cost IS NOT NULL)
        AND (r.tokens_prompt IS NOT NULL OR r.tokens_completion IS NOT NULL
            OR r.cost IS NOT NULL));

-- 2. Expand both arrays by the same ordinal. Detail rows are optional and can
-- belong to either old message, but must match both call ID and 1-based index.
-- Prefer a string toolResult.value, then a string toolResult, then the detail's
-- stored result. Empty strings and numeric-looking strings remain strings.
-- Decode JSON-encoded arguments while also retaining their original spelling;
-- only objects/lists are accepted, never scalar JSON or guessed empty arguments.
DROP TEMPORARY TABLE IF EXISTS genai_legacy_calls;
CREATE TEMPORARY TABLE genai_legacy_calls AS
SELECT p.request_oid, p.result_oid, p.call_count, j.ord,
    j.call_id, s.call_id AS result_call_id,
    COALESCE(
        IF(JSON_TYPE(JSON_EXTRACT(s.result_json, '$.value')) = 'STRING',
            JSON_UNQUOTE(JSON_EXTRACT(s.result_json, '$.value')), NULL),
        IF(JSON_TYPE(s.result_json) = 'STRING',
            JSON_UNQUOTE(s.result_json), NULL), d.result) AS retained_value,
    JSON_OBJECT('call_id', j.call_id, 'tool_name', j.tool_name,
        'arguments', CASE WHEN JSON_TYPE(j.arguments_json) = 'STRING'
            THEN JSON_EXTRACT(
                IF(JSON_VALID(JSON_UNQUOTE(j.arguments_json)),
                    JSON_UNQUOTE(j.arguments_json), 'null'), '$')
            ELSE j.arguments_json END,
        'raw_arguments', IF(JSON_TYPE(j.arguments_json) = 'STRING',
            JSON_UNQUOTE(j.arguments_json), NULL),
        'tool_alias', d.tool_alias,
        'started_on', d.started_on, 'finished_on', d.finished_on,
        'duration_ms', d.execution_time_ms) AS call_data,
    CASE WHEN JSON_TYPE(j.arguments_json) IN ('OBJECT', 'ARRAY')
        OR (JSON_TYPE(j.arguments_json) = 'STRING'
            AND JSON_TYPE(JSON_EXTRACT(
                IF(JSON_VALID(JSON_UNQUOTE(j.arguments_json)),
                    JSON_UNQUOTE(j.arguments_json), 'null'), '$')) IN ('OBJECT', 'ARRAY'))
        THEN 1 ELSE 0 END AS arguments_valid,
    s.result_json, d.state, d.execution_time_ms
FROM genai_legacy_pairs p
JOIN JSON_TABLE(p.request_data, '$.tool_calls[*]' COLUMNS(
    ord FOR ORDINALITY, call_id varchar(255) PATH '$.id',
    tool_name varchar(255) PATH '$.function.name',
    arguments_json JSON PATH '$.function.arguments')) j
JOIN JSON_TABLE(p.result_data, '$[*]' COLUMNS(
    ord FOR ORDINALITY, call_id varchar(255) PATH '$.callId',
    result_json JSON PATH '$.toolResult')) s ON s.ord = j.ord
LEFT JOIN exf_ai_tool_call d ON
    d.ai_message_oid IN (p.request_oid, p.result_oid)
    AND BINARY d.call_id = BINARY j.call_id AND d.call_index = j.ord;

-- Materialize validity: MySQL cannot reopen one temporary table twice.
-- 3. Accept the WHOLE batch only if every ordinal has a unique, non-empty ID,
-- the result ID matches exactly (binary comparison), a result string exists,
-- arguments are valid and a tool name is present. COALESCE makes unknown
-- comparisons reject the batch rather than disappear from the MIN aggregate.
DROP TEMPORARY TABLE IF EXISTS genai_legacy_valid;
CREATE TEMPORARY TABLE genai_legacy_valid AS
    SELECT request_oid FROM genai_legacy_calls
    GROUP BY request_oid
    HAVING COUNT(*) = MAX(call_count)
        AND COUNT(DISTINCT BINARY call_id) = MAX(call_count)
        AND MIN(COALESCE(call_id IS NOT NULL AND call_id <> ''
            AND BINARY call_id = BINARY result_call_id
            AND retained_value IS NOT NULL
            AND arguments_valid = 1
            AND JSON_TYPE(JSON_EXTRACT(call_data, '$.tool_name')) = 'STRING'
            AND JSON_UNQUOTE(JSON_EXTRACT(call_data, '$.tool_name')) <> '', 0)) = 1
;
-- Reject every uncertain batch, not merely the uncertain call.
-- Delete candidates only from staging, not from history. Also reject any pair
-- with a linked detail that cannot be represented in the reconstructed batch.
DELETE p FROM genai_legacy_pairs p
LEFT JOIN genai_legacy_valid valid ON valid.request_oid = p.request_oid
WHERE valid.request_oid IS NULL
    OR EXISTS (SELECT 1 FROM exf_ai_tool_call d
        WHERE d.ai_message_oid IN (p.request_oid, p.result_oid)
        AND NOT EXISTS (SELECT 1 FROM genai_legacy_calls c
            WHERE c.request_oid = p.request_oid
                AND BINARY c.call_id = BINARY d.call_id
                AND c.ord = d.call_index));

-- 4. Build the new ordered lists. The full window frame gives every staged
-- call the complete batch; DISTINCT leaves one aggregate row per request.
-- All results use AiToolResultString, preserving JSON-looking output as text.
-- Retain failure state, diagnostic arrays, appendix and known execution time.
-- Omit unknown result duration instead of passing null to its typed setter.
-- These embedded diagnostics are replay data, not additional warning/error rows.
DROP TEMPORARY TABLE IF EXISTS genai_legacy_merged;
CREATE TEMPORARY TABLE genai_legacy_merged AS
SELECT DISTINCT c.request_oid,
    JSON_ARRAYAGG(c.call_data) OVER w AS calls_data,
    JSON_ARRAYAGG(JSON_MERGE_PATCH(JSON_OBJECT(
        'call_id', c.call_id,
        'class', '\\axenox\\GenAI\\Common\\AiToolResultString',
        'type', JSON_OBJECT('alias', 'exface.Core.String'),
        'value', c.retained_value,
        'failed', JSON_EXTRACT(IF(
            c.state = 'FAILED'
            OR JSON_EXTRACT(c.result_json, '$.failed') = TRUE,
            'true', 'false'), '$'),
        'errors', IF(JSON_TYPE(JSON_EXTRACT(c.result_json, '$.errors')) = 'ARRAY',
            JSON_EXTRACT(c.result_json, '$.errors'), JSON_ARRAY()),
        'warnings', IF(
            JSON_TYPE(JSON_EXTRACT(c.result_json, '$.warnings')) = 'ARRAY',
            JSON_EXTRACT(c.result_json, '$.warnings'), JSON_ARRAY()),
        'appendix', IF(JSON_TYPE(JSON_EXTRACT(c.result_json, '$.appendix')) = 'ARRAY',
            JSON_EXTRACT(c.result_json, '$.appendix'), JSON_ARRAY())
    ), IF(c.execution_time_ms IS NULL, JSON_OBJECT(),
        JSON_OBJECT('duration_ms', c.execution_time_ms)))) OVER w AS results_data
FROM genai_legacy_calls c
JOIN genai_legacy_pairs p ON p.request_oid = c.request_oid
WINDOW w AS (PARTITION BY c.request_oid ORDER BY c.ord
    ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING);

-- 5. Enrich ONLY the surviving result row. MESSAGE is deliberately not assigned.
-- Request text becomes semantic content, not the result's display text.
-- Preserve both originals under legacy, including the request being removed.
-- Transfer usage/model/finish reason with request-first fallback, not addition,
-- so the exchange is counted once and known zero values remain known.
-- Batch timing spans request creation through result completion.
UPDATE exf_ai_message r
JOIN genai_legacy_pairs p ON p.result_oid = r.oid
JOIN exf_ai_message q ON q.oid = p.request_oid
JOIN genai_legacy_merged b ON b.request_oid = p.request_oid
SET r.data = JSON_OBJECT(
        'content', IF(JSON_TYPE(JSON_EXTRACT(p.request_data, '$.content'))
            = 'STRING', JSON_ARRAY(JSON_OBJECT('type', 'text',
                'text', JSON_UNQUOTE(
                    JSON_EXTRACT(p.request_data, '$.content')))), JSON_ARRAY()),
        'calls', JSON_EXTRACT(b.calls_data, '$'),
        'results', JSON_EXTRACT(b.results_data, '$'),
        'legacy', JSON_EXTRACT(p.legacy_data, '$')),
    r.started_on = COALESCE(q.started_on, q.created_on),
    r.finished_on = COALESCE(r.finished_on, r.created_on),
    r.tokens_prompt = COALESCE(q.tokens_prompt, r.tokens_prompt),
    r.tokens_completion = COALESCE(q.tokens_completion, r.tokens_completion),
    r.cost = COALESCE(q.cost, r.cost),
    r.model = COALESCE(q.model, r.model),
    r.finish_reason = COALESCE(q.finish_reason, r.finish_reason);

-- Relink all retained details before removing the archived request row.
-- 6. Move request-linked detail foreign keys to the result UID first; details
-- already linked to the result need no change. Only accepted request rows are
-- removed. Their original identities and text remain inspectable in DATA.legacy.
UPDATE exf_ai_tool_call d
JOIN genai_legacy_pairs p ON p.request_oid = d.ai_message_oid
SET d.ai_message_oid = p.result_oid;
DELETE q FROM exf_ai_message q
JOIN genai_legacy_pairs p ON p.request_oid = q.oid;
-- Staging tables are session-local. A completed rerun finds no matching old
-- request rows; this is not a recovery mechanism for a partially failed install.
DROP TEMPORARY TABLE genai_legacy_merged;
DROP TEMPORARY TABLE genai_legacy_calls;
DROP TEMPORARY TABLE genai_legacy_valid;
DROP TEMPORARY TABLE genai_legacy_pairs;

-- DOWN
-- The original rows and payloads remain in DATA.legacy.
-- Do not split completed shared batches or reintroduce duplicate usage.
-- Automatic DOWN would also need to restore detail links and sequence positions
-- without overwriting later activity. Recovery therefore requires operator review.
