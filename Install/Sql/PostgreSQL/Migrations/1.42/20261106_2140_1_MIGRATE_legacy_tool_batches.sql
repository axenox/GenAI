/*
 * Merge only complete legacy wrapper arrays matching linked indexed call IDs.
 * Missing strings, ambiguous usage and unsupported content remain unresolved.
 * Original merged rows remain archived and original payloads in legacy DATA.
 *
 * @author GitHub Copilot
 */
-- UP
CREATE TABLE IF NOT EXISTS trash_exf_ai_message_tool_result
    (LIKE exf_ai_message INCLUDING ALL);

-- Invalid historic JSON is not an installation failure or a pairing hint.
CREATE OR REPLACE FUNCTION genai_legacy_json(value text) RETURNS jsonb AS $$
BEGIN
    RETURN value::jsonb;
EXCEPTION WHEN invalid_text_representation THEN
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TEMPORARY TABLE genai_legacy_candidates AS
SELECT q.oid AS request_oid, r.oid AS result_oid,
    genai_legacy_json(q.data) AS request_data,
    genai_legacy_json(r.data) AS result_data
FROM exf_ai_message q
JOIN exf_ai_message r ON r.ai_conversation_oid = q.ai_conversation_oid
    AND r.sequence_number = (
        SELECT MIN(n.sequence_number) FROM exf_ai_message n
        WHERE n.ai_conversation_oid = q.ai_conversation_oid
          AND n.sequence_number > q.sequence_number
          AND n.role NOT IN ('warning', 'error')
    )
WHERE q.role = 'tool calling' AND r.role = 'tool'
    AND jsonb_typeof(genai_legacy_json(q.data)) = 'object'
    AND jsonb_typeof(genai_legacy_json(r.data)) = 'array'
    AND NOT (
        (q.cost IS NOT NULL OR q.tokens_prompt IS NOT NULL
            OR q.tokens_completion IS NOT NULL)
        AND (r.cost IS NOT NULL OR r.tokens_prompt IS NOT NULL
            OR r.tokens_completion IS NOT NULL)
    )
    AND NOT EXISTS (
        SELECT 1 FROM exf_ai_tool_call t WHERE t.ai_message_oid = r.oid
    )
    AND (genai_legacy_json(q.data)->'content' IS NULL
        OR jsonb_typeof(genai_legacy_json(q.data)->'content')
            IN ('null', 'string'));

CREATE TEMPORARY TABLE genai_legacy_calls AS
SELECT p.request_oid, p.result_oid, t.call_id, t.call_index,
    t.tool_name, t.tool_alias, t.arguments, t.execution_time_ms,
    t.trash_failed,
    CASE WHEN jsonb_typeof(p.request_data->'tool_calls'
        ->(t.call_index - 1)->'function'->'arguments') = 'string'
        THEN p.request_data->'tool_calls'
            ->(t.call_index - 1)->'function'->>'arguments'
        ELSE NULL END AS raw_arguments,
    CASE WHEN jsonb_typeof(j.value->'toolResult'->'value') = 'string'
        THEN j.value->'toolResult'->>'value'
        WHEN jsonb_typeof(j.value->'toolResult') = 'string'
        THEN j.value->>'toolResult'
        ELSE t.result END AS retained_result
FROM genai_legacy_candidates p
JOIN exf_ai_tool_call t ON t.ai_message_oid = p.request_oid
CROSS JOIN LATERAL jsonb_array_elements(p.result_data)
    WITH ORDINALITY j(value, ordinal)
WHERE j.ordinal = t.call_index AND j.value->>'callId' = t.call_id
    AND jsonb_typeof(j.value->'callId') = 'string'
    AND jsonb_typeof(genai_legacy_json(t.arguments)) IN ('object', 'array')
    AND t.trash_failed IN (0, 1)
    AND (
        p.request_data->'tool_calls' IS NULL
        OR (
            jsonb_typeof(p.request_data->'tool_calls') = 'array'
            AND jsonb_array_length(CASE
                WHEN jsonb_typeof(p.request_data->'tool_calls') = 'array'
                THEN p.request_data->'tool_calls' ELSE '[]'::jsonb END)
                = jsonb_array_length(p.result_data)
            AND p.request_data->'tool_calls'->(t.call_index - 1)->>'id'
                = t.call_id
            AND jsonb_typeof(p.request_data->'tool_calls'
                ->(t.call_index - 1)->'id') = 'string'
        )
    )
    AND COALESCE(j.value->'toolResult'->'errors', '[]'::jsonb) = '[]'::jsonb
    AND COALESCE(j.value->'toolResult'->'warnings', '[]'::jsonb) = '[]'::jsonb
    AND COALESCE(j.value->'toolResult'->'appendix', '[]'::jsonb) = '[]'::jsonb;

CREATE TEMPORARY TABLE genai_legacy_pairs AS
SELECT p.request_oid, p.result_oid,
    jsonb_build_object(
        'content', CASE WHEN jsonb_typeof(p.request_data->'content') = 'string'
            THEN jsonb_build_array(jsonb_build_object(
                'type', 'text', 'text', p.request_data->>'content'))
            ELSE '[]'::jsonb END,
        'call_order', jsonb_agg(c.call_id ORDER BY c.call_index),
        'calls', jsonb_object_agg(c.call_id, jsonb_build_object(
            'call_id', c.call_id, 'tool_name', c.tool_name,
            'tool_alias', c.tool_alias, 'arguments',
                genai_legacy_json(c.arguments),
            'raw_arguments', c.raw_arguments, 'execution_arguments', NULL)),
        'results', jsonb_object_agg(c.call_id, jsonb_build_object(
            'class', '\axenox\GenAI\Common\AiToolResultString',
            'type', jsonb_build_object(
                'alias', '\exface\Core\DataTypes\StringDataType'),
            'value', c.retained_result, 'failed', c.trash_failed = 1,
            'errors', '[]'::jsonb, 'warnings', '[]'::jsonb,
            'appendix', '[]'::jsonb, 'duration_ms', c.execution_time_ms)),
        'legacy', jsonb_build_object(
            'request_uid', '0x' || replace(p.request_oid::text, '-', ''),
            'result_uid', '0x' || replace(p.result_oid::text, '-', ''),
            'request_data', q.data, 'result_data', r.data,
            'request_sequence', q.sequence_number,
            'result_sequence', r.sequence_number,
            'request_message', q.message, 'result_message', r.message,
            'request_usage', jsonb_build_object('cost', q.cost,
                'tokens_prompt', q.tokens_prompt,
                'tokens_completion', q.tokens_completion, 'model', q.model),
            'result_usage', jsonb_build_object('cost', r.cost,
                'tokens_prompt', r.tokens_prompt,
                'tokens_completion', r.tokens_completion, 'model', r.model))
    )::text AS combined_data
FROM genai_legacy_candidates p
JOIN genai_legacy_calls c ON c.request_oid = p.request_oid
JOIN exf_ai_message q ON q.oid = p.request_oid
JOIN exf_ai_message r ON r.oid = p.result_oid
GROUP BY p.request_oid, p.result_oid, p.request_data, p.result_data,
    q.oid, r.oid
HAVING COUNT(*) = jsonb_array_length(p.result_data)
    AND COUNT(DISTINCT c.call_id) = COUNT(*)
    AND COUNT(DISTINCT c.call_index) = COUNT(*)
    AND MIN(c.call_index) = 1 AND MAX(c.call_index) = COUNT(*)
    AND COUNT(c.retained_result) = COUNT(*)
    AND COUNT(*) = (
        SELECT COUNT(*) FROM exf_ai_tool_call t
        WHERE t.ai_message_oid = p.request_oid
    );

INSERT INTO trash_exf_ai_message_tool_result
SELECT r.* FROM exf_ai_message r
JOIN genai_legacy_pairs p ON p.result_oid = r.oid;
UPDATE exf_ai_message q
SET role = 'tool', data = p.combined_data,
    cost = COALESCE(q.cost, r.cost),
    tokens_prompt = COALESCE(q.tokens_prompt, r.tokens_prompt),
    tokens_completion = COALESCE(q.tokens_completion, r.tokens_completion),
    model = COALESCE(q.model, r.model)
FROM genai_legacy_pairs p JOIN exf_ai_message r ON r.oid = p.result_oid
WHERE q.oid = p.request_oid;
DELETE FROM exf_ai_message r USING genai_legacy_pairs p
WHERE p.result_oid = r.oid;
DROP TABLE genai_legacy_pairs;
DROP TABLE genai_legacy_calls;
DROP TABLE genai_legacy_candidates;
DROP FUNCTION genai_legacy_json(text);

-- DOWN
-- Non-reversible without operator review: originals remain in the archive
-- and combined DATA. Never overwrite subsequently completed audit messages.
