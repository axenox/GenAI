/*
 * Merge only explicit, ordered legacy call/result identities.
 * Keep result UID and MESSAGE; preserve original rows in legacy metadata.
 *
 * Prerequisite: the shared-conversation schema migration has added lifecycle
 * columns and changed call-ID uniqueness to message scope.
 * Run with conversation writers stopped and take a backup before installation.
 *
 * Before: q.role = 'tool calling', q.data.tool_calls = ordered requests;
 *         r.role = 'tool', r.data = ordered callId/toolResult wrappers.
 * After:  r keeps its oid, role, MESSAGE and timeline position. Its DATA contains
 *         content[], calls[], results[] and snapshots of both original rows.
 *         Request-linked details move to r before q is deleted.
 *
 * No tools or providers are invoked and no result is inferred from display text.
 * Unresolved pairs remain unchanged and can still block continuation. Non-tool
 * messages and standalone diagnostics are untouched. The next migration handles
 * sequence normalization separately.
 * @author GitHub Copilot
 */
-- UP
-- 1. Parse historic JSON without making malformed payloads installation errors.
-- NULL means "not usable as migration evidence", not an invented empty payload.
-- The helper lives only in this connection's temporary schema. ON COMMIT DROP
-- staging tables below require the statements to share the migration transaction.
CREATE OR REPLACE FUNCTION pg_temp.genai_json(value text) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    RETURN value::jsonb;
EXCEPTION WHEN invalid_text_representation THEN RETURN NULL;
END $$;

-- 2. Pair adjacent non-diagnostic rows inside each conversation. Null sequences
-- come first; creation time and UID break ties deterministically. Warnings and
-- errors may sit between a request and its result without breaking the pair.
-- Reject usage on both sides, including known zero values: it is unclear which
-- exchange those values represent. Require equally sized, non-empty arrays and
-- absent/null/text request content. Snapshot complete original rows before edits.
CREATE TEMP TABLE genai_legacy_pairs ON COMMIT DROP AS
WITH timeline AS (
    SELECT oid, lead(oid) OVER (PARTITION BY ai_conversation_oid
        ORDER BY sequence_number NULLS FIRST, created_on, oid) AS next_oid
    FROM exf_ai_message WHERE role NOT IN ('warning', 'error')
), payloads AS (
    SELECT q.oid AS request_oid, r.oid AS result_oid,
        pg_temp.genai_json(q.data) AS request_data,
        pg_temp.genai_json(r.data) AS result_data,
        to_jsonb(q) AS request_row, to_jsonb(r) AS result_row
    FROM timeline t
    JOIN exf_ai_message q ON q.oid = t.oid
    JOIN exf_ai_message r ON r.oid = t.next_oid
    WHERE q.role = 'tool calling' AND r.role = 'tool'
        AND NOT ((q.tokens_prompt IS NOT NULL
            OR q.tokens_completion IS NOT NULL OR q.cost IS NOT NULL)
            AND (r.tokens_prompt IS NOT NULL
            OR r.tokens_completion IS NOT NULL OR r.cost IS NOT NULL))
)
SELECT * FROM payloads WHERE jsonb_typeof(request_data->'tool_calls') = 'array'
    AND jsonb_typeof(result_data) = 'array'
    AND jsonb_array_length(request_data->'tool_calls') > 0
    AND jsonb_array_length(request_data->'tool_calls')
        = jsonb_array_length(result_data)
    AND (request_data->'content' IS NULL
        OR jsonb_typeof(request_data->'content') IN ('null', 'string'));

-- 3. Match requests and result wrappers by ordinal, then verify their IDs below.
-- Optional details may belong to either old row and must match ID AND index.
-- Use a string toolResult.value, a direct string toolResult, or the detail's
-- retained result, in that order. Never use MESSAGE as a substitute.
-- Decode encoded argument JSON and keep its raw string for audit; preserve
-- objects/lists and numeric-looking result strings without type coercion.
CREATE TEMP TABLE genai_legacy_calls ON COMMIT DROP AS
SELECT p.request_oid, p.result_oid, q.ord,
    jsonb_array_length(p.request_data->'tool_calls') AS call_count,
    q.call->>'id' AS call_id, r.result->>'callId' AS result_call_id,
    q.call#>>'{function,name}' AS tool_name,
    CASE WHEN jsonb_typeof(q.call#>'{function,arguments}') = 'string'
        THEN pg_temp.genai_json(q.call#>>'{function,arguments}')
        ELSE q.call#>'{function,arguments}' END AS arguments,
    CASE WHEN jsonb_typeof(q.call#>'{function,arguments}') = 'string'
        THEN q.call#>>'{function,arguments}' END AS raw_arguments,
    COALESCE(CASE WHEN jsonb_typeof(r.result#>'{toolResult,value}') = 'string'
        THEN r.result#>>'{toolResult,value}' END,
        CASE WHEN jsonb_typeof(r.result->'toolResult') = 'string'
        THEN r.result->>'toolResult' END, d.result) AS retained_value,
    r.result->'toolResult' AS result_data,
    d.state, d.tool_alias, d.started_on, d.finished_on, d.execution_time_ms
FROM genai_legacy_pairs p
CROSS JOIN LATERAL jsonb_array_elements(p.request_data->'tool_calls')
    WITH ORDINALITY q(call, ord)
JOIN LATERAL jsonb_array_elements(p.result_data)
    WITH ORDINALITY r(result, ord) ON r.ord = q.ord
LEFT JOIN exf_ai_tool_call d
    ON d.ai_message_oid IN (p.request_oid, p.result_oid)
    AND d.call_id = q.call->>'id' AND d.call_index = q.ord;

-- 4. Remove uncertain candidates from staging only. Every call must have a unique
-- non-empty ID matching its result, a name, object/list arguments and a retained
-- string value. COALESCE prevents bool_and from ignoring unknown comparisons.
-- Reject the entire pair if any linked detail would be left unrepresented.
DELETE FROM genai_legacy_pairs p WHERE NOT EXISTS (
    SELECT 1 FROM genai_legacy_calls c WHERE c.request_oid = p.request_oid
    GROUP BY c.request_oid HAVING count(*) = max(call_count)
        AND count(DISTINCT call_id) = max(call_count)
        AND bool_and(COALESCE(call_id IS NOT NULL AND call_id <> ''
            AND call_id = result_call_id AND retained_value IS NOT NULL
            AND jsonb_typeof(arguments) IN ('object', 'array') AND tool_name IS NOT NULL
            AND tool_name <> '', false))
) OR EXISTS (
    SELECT 1 FROM exf_ai_tool_call d
    WHERE d.ai_message_oid IN (p.request_oid, p.result_oid)
        AND NOT EXISTS (SELECT 1 FROM genai_legacy_calls c
            WHERE c.request_oid = p.request_oid AND c.call_id = d.call_id
                AND c.ord = d.call_index)
);

-- 5. Produce ordered calls[] and results[] for the shared typed-message loader.
-- Results always use AiToolResultString, even when their value looks like JSON.
-- Preserve failure flags, errors, warnings, appendix and known elapsed time.
-- Strip absent result metadata so null duration never reaches a typed setter.
-- Embedded diagnostics are retained for replay; no extra diagnostic rows are made.
CREATE TEMP TABLE genai_legacy_merged ON COMMIT DROP AS
SELECT c.request_oid,
    jsonb_agg(jsonb_build_object(
        'call_id', c.call_id, 'tool_name', c.tool_name,
        'arguments', c.arguments, 'raw_arguments', c.raw_arguments,
        'tool_alias', c.tool_alias, 'started_on', c.started_on,
        'finished_on', c.finished_on, 'duration_ms', c.execution_time_ms)
        ORDER BY c.ord) AS calls_data,
    jsonb_agg(jsonb_strip_nulls(jsonb_build_object(
        'call_id', c.call_id,
        'class', '\axenox\GenAI\Common\AiToolResultString',
        'type', jsonb_build_object('alias', 'exface.Core.String'),
        'value', c.retained_value,
        'failed', COALESCE(c.state = 'FAILED', false)
            OR COALESCE(c.result_data->'failed' = 'true'::jsonb, false),
        'errors', CASE WHEN jsonb_typeof(c.result_data->'errors') = 'array'
            THEN c.result_data->'errors' ELSE '[]'::jsonb END,
        'warnings', CASE WHEN jsonb_typeof(c.result_data->'warnings') = 'array'
            THEN c.result_data->'warnings' ELSE '[]'::jsonb END,
        'appendix', CASE WHEN jsonb_typeof(c.result_data->'appendix') = 'array'
            THEN c.result_data->'appendix' ELSE '[]'::jsonb END,
        'duration_ms', c.execution_time_ms))
        ORDER BY c.ord) AS results_data
FROM genai_legacy_calls c
JOIN genai_legacy_pairs p ON p.request_oid = c.request_oid
GROUP BY c.request_oid;

-- 6. Update the RESULT row, leaving its UID, MESSAGE and role unchanged.
-- Semantic content comes from the request; display text is not model context.
-- Preserve both original rows/payloads under legacy before removing the request.
-- Move usage/model/finish reason with request-first fallback, never summation,
-- keeping known zeros and avoiding double-counting. Timing spans the old pair.
UPDATE exf_ai_message r SET
    data = jsonb_build_object(
        'content', CASE WHEN jsonb_typeof(p.request_data->'content') = 'string'
            THEN jsonb_build_array(jsonb_build_object('type', 'text',
                'text', p.request_data->>'content')) ELSE '[]'::jsonb END,
        'calls', b.calls_data, 'results', b.results_data,
        'legacy', jsonb_build_object('request_data', p.request_data,
            'request_row', p.request_row, 'result_data', p.result_data,
            'result_row', p.result_row, 'request_message_uid', p.request_oid,
            'result_message_uid', p.result_oid)
        )::text,
    started_on = COALESCE(q.started_on, q.created_on),
    finished_on = COALESCE(r.finished_on, r.created_on),
    tokens_prompt = COALESCE(q.tokens_prompt, r.tokens_prompt),
    tokens_completion = COALESCE(q.tokens_completion, r.tokens_completion),
    cost = COALESCE(q.cost, r.cost),
    model = COALESCE(q.model, r.model),
    finish_reason = COALESCE(q.finish_reason, r.finish_reason)
FROM genai_legacy_pairs p
JOIN exf_ai_message q ON q.oid = p.request_oid
JOIN genai_legacy_merged b ON b.request_oid = p.request_oid
WHERE r.oid = p.result_oid;

-- 7. Relink detail foreign keys before deleting accepted request rows. Details
-- already belonging to the result stay there. Unaccepted history is not deleted.
UPDATE exf_ai_tool_call d SET ai_message_oid = p.result_oid
FROM genai_legacy_pairs p WHERE d.ai_message_oid = p.request_oid;
DELETE FROM exf_ai_message q USING genai_legacy_pairs p
WHERE q.oid = p.request_oid;
-- A completed rerun has no converted request rows left to match. Explicitly
-- remove staging objects/helper; originals remain in the surviving DATA.legacy.
DROP TABLE genai_legacy_merged, genai_legacy_calls, genai_legacy_pairs;
DROP FUNCTION pg_temp.genai_json(text);

-- DOWN
-- Original row snapshots remain in DATA.legacy. Do not duplicate usage.
-- No automatic split: restoring rows, links and old sequence positions could
-- conflict with later activity. Rollback/recovery requires operator review.
