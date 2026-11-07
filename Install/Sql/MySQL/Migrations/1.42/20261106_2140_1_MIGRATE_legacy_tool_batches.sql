/*
 * Conservatively combine legacy wrapper batches with explicitly matching IDs.
 * Linked details are the request identity source. Their indexed call order
 * must match every result-wrapper ID; retained request IDs may not disagree.
 * Missing strings, ambiguous usage, malformed data and attachments are kept.
 * Full merged result rows are archived; their identities/raw data are retained.
 * Requires MySQL 8.0+ or MariaDB 10.6+ (JSON_TABLE and window functions).
 *
 * @author GitHub Copilot
 */
-- UP
-- BATCH-DELIMITER /;\R/

ALTER TABLE exf_ai_message MODIFY data longtext NULL;
ALTER TABLE exf_ai_conversation MODIFY data mediumtext NULL;
CREATE TABLE IF NOT EXISTS trash_exf_ai_message_tool_result
    LIKE exf_ai_message;
CREATE TEMPORARY TABLE genai_legacy_candidates AS
SELECT q.oid AS request_oid, r.oid AS result_oid,
    IF(JSON_VALID(q.data), q.data, '{}') AS request_data,
    IF(JSON_VALID(r.data), r.data, '[]') AS result_data
FROM exf_ai_message q
JOIN exf_ai_message r ON r.ai_conversation_oid = q.ai_conversation_oid
    AND r.sequence_number = (
        SELECT MIN(n.sequence_number) FROM exf_ai_message n
        WHERE n.ai_conversation_oid = q.ai_conversation_oid
          AND n.sequence_number > q.sequence_number
          AND n.role NOT IN ('warning', 'error')
    )
WHERE q.role = 'tool calling' AND r.role = 'tool'
    AND JSON_VALID(q.data) AND JSON_VALID(r.data)
    AND JSON_TYPE(IF(JSON_VALID(q.data), q.data, 'null')) = 'OBJECT'
    AND JSON_TYPE(IF(JSON_VALID(r.data), r.data, 'null')) = 'ARRAY'
    AND JSON_LENGTH(IF(JSON_VALID(r.data), r.data, '[]')) > 0
    AND NOT (
        (q.cost IS NOT NULL OR q.tokens_prompt IS NOT NULL
            OR q.tokens_completion IS NOT NULL)
        AND (r.cost IS NOT NULL OR r.tokens_prompt IS NOT NULL
            OR r.tokens_completion IS NOT NULL)
    )
    AND NOT EXISTS (
        SELECT 1 FROM exf_ai_tool_call t WHERE t.ai_message_oid = r.oid
    )
    AND (JSON_EXTRACT(IF(JSON_VALID(q.data), q.data, '{}'), '$.content') IS NULL
        OR JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(q.data), q.data, '{}'),
            '$.content')) IN ('NULL', 'STRING'));

CREATE TEMPORARY TABLE genai_legacy_calls AS
SELECT p.request_oid, p.result_oid, t.call_id, t.call_index,
    t.tool_name, t.tool_alias, t.arguments, t.execution_time_ms,
    t.trash_failed,
    CASE WHEN JSON_TYPE(JSON_EXTRACT(p.request_data,
        CONCAT('$.tool_calls[', t.call_index - 1, '].function.arguments'))) = 'STRING'
        THEN JSON_UNQUOTE(JSON_EXTRACT(p.request_data,
            CONCAT('$.tool_calls[', t.call_index - 1, '].function.arguments')))
        ELSE NULL END AS raw_arguments,
    CASE WHEN JSON_TYPE(JSON_EXTRACT(p.result_data,
        CONCAT('$[', t.call_index - 1, '].toolResult.value'))) = 'STRING'
        THEN JSON_UNQUOTE(JSON_EXTRACT(p.result_data,
            CONCAT('$[', t.call_index - 1, '].toolResult.value')))
        WHEN JSON_TYPE(JSON_EXTRACT(p.result_data,
            CONCAT('$[', t.call_index - 1, '].toolResult'))) = 'STRING'
        THEN JSON_UNQUOTE(JSON_EXTRACT(p.result_data,
            CONCAT('$[', t.call_index - 1, '].toolResult')))
        ELSE t.result END AS retained_result
FROM genai_legacy_candidates p
JOIN exf_ai_tool_call t ON t.ai_message_oid = p.request_oid
JOIN JSON_TABLE(p.result_data, '$[*]' COLUMNS (
    ordinal FOR ORDINALITY,
    call_id varchar(255) PATH '$.callId' NULL ON ERROR
)) j ON j.ordinal = t.call_index AND BINARY j.call_id = BINARY t.call_id
WHERE JSON_VALID(t.arguments)
    AND JSON_TYPE(t.arguments) IN ('OBJECT', 'ARRAY')
    AND t.trash_failed IN (0, 1)
    AND JSON_TYPE(JSON_EXTRACT(p.result_data,
        CONCAT('$[', t.call_index - 1, '].callId'))) = 'STRING'
    AND (
        JSON_EXTRACT(p.request_data, '$.tool_calls') IS NULL
        OR (
            JSON_TYPE(JSON_EXTRACT(p.request_data, '$.tool_calls')) = 'ARRAY'
            AND JSON_LENGTH(p.request_data, '$.tool_calls')
                = JSON_LENGTH(p.result_data)
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT(p.request_data,
                CONCAT('$.tool_calls[', t.call_index - 1, '].id')))
                = BINARY t.call_id
            AND JSON_TYPE(JSON_EXTRACT(p.request_data,
                CONCAT('$.tool_calls[', t.call_index - 1, '].id'))) = 'STRING'
        )
    )
    -- Do not relocate uncorrelated legacy diagnostics or private result objects.
    AND COALESCE(JSON_LENGTH(p.result_data,
        CONCAT('$[', t.call_index - 1, '].toolResult.errors')), 0) = 0
    AND COALESCE(JSON_LENGTH(p.result_data,
        CONCAT('$[', t.call_index - 1, '].toolResult.warnings')), 0) = 0
    AND COALESCE(JSON_LENGTH(p.result_data,
        CONCAT('$[', t.call_index - 1, '].toolResult.appendix')), 0) = 0;

CREATE TEMPORARY TABLE genai_legacy_pairs AS
SELECT p.request_oid, p.result_oid,
    JSON_OBJECT(
        'content', IF(JSON_TYPE(JSON_EXTRACT(p.request_data, '$.content'))
            = 'STRING', JSON_ARRAY(JSON_OBJECT('type', 'text', 'text',
                JSON_UNQUOTE(JSON_EXTRACT(p.request_data, '$.content')))),
            JSON_ARRAY()),
        'call_order', JSON_EXTRACT(p.result_data, '$[*].callId'),
        'calls', JSON_OBJECTAGG(c.call_id, JSON_OBJECT(
            'call_id', c.call_id, 'tool_name', c.tool_name,
            'tool_alias', c.tool_alias, 'arguments',
                JSON_EXTRACT(c.arguments, '$'),
            'raw_arguments', c.raw_arguments, 'execution_arguments', NULL)),
        'results', JSON_OBJECTAGG(c.call_id, JSON_OBJECT(
            'class', '\\axenox\\GenAI\\Common\\AiToolResultString',
            'type', JSON_OBJECT('alias', '\\exface\\Core\\DataTypes\\StringDataType'),
            'value', c.retained_result,
            'failed', JSON_EXTRACT(IF(c.trash_failed = 1, 'true', 'false'), '$'),
            'errors', JSON_ARRAY(), 'warnings', JSON_ARRAY(),
            'appendix', JSON_ARRAY(), 'duration_ms', c.execution_time_ms)),
        'legacy', JSON_OBJECT(
            'request_uid', CONCAT('0x', LOWER(HEX(p.request_oid))),
            'result_uid', CONCAT('0x', LOWER(HEX(p.result_oid))),
            'request_data', q.data, 'result_data', r.data,
            'request_sequence', q.sequence_number,
            'result_sequence', r.sequence_number,
            'request_message', q.message, 'result_message', r.message,
            'request_usage', JSON_OBJECT('cost', q.cost,
                'tokens_prompt', q.tokens_prompt,
                'tokens_completion', q.tokens_completion, 'model', q.model),
            'result_usage', JSON_OBJECT('cost', r.cost,
                'tokens_prompt', r.tokens_prompt,
                'tokens_completion', r.tokens_completion, 'model', r.model))
    ) AS combined_data
FROM genai_legacy_candidates p
JOIN genai_legacy_calls c ON c.request_oid = p.request_oid
JOIN exf_ai_message q ON q.oid = p.request_oid
JOIN exf_ai_message r ON r.oid = p.result_oid
GROUP BY p.request_oid, p.result_oid, p.request_data, p.result_data,
    q.sequence_number, r.sequence_number, q.message, r.message,
    q.data, r.data, q.cost, q.tokens_prompt, q.tokens_completion, q.model,
    r.cost, r.tokens_prompt, r.tokens_completion, r.model
HAVING COUNT(*) = JSON_LENGTH(p.result_data)
    AND COUNT(DISTINCT c.call_id) = COUNT(*)
    AND COUNT(DISTINCT c.call_index) = COUNT(*)
    AND MIN(c.call_index) = 1 AND MAX(c.call_index) = COUNT(*)
    AND COUNT(c.retained_result) = COUNT(*)
    AND COUNT(*) = (
        SELECT COUNT(*) FROM exf_ai_tool_call t
        WHERE t.ai_message_oid = p.request_oid
    );

-- Archive before removing only explicitly, completely correlated result rows.
INSERT INTO trash_exf_ai_message_tool_result
SELECT r.* FROM exf_ai_message r
JOIN genai_legacy_pairs p ON p.result_oid = r.oid;
UPDATE exf_ai_message q
JOIN genai_legacy_pairs p ON p.request_oid = q.oid
JOIN exf_ai_message r ON r.oid = p.result_oid
SET q.role = 'tool', q.data = p.combined_data,
    q.cost = COALESCE(q.cost, r.cost),
    q.tokens_prompt = COALESCE(q.tokens_prompt, r.tokens_prompt),
    q.tokens_completion = COALESCE(q.tokens_completion, r.tokens_completion),
    q.model = COALESCE(q.model, r.model);
DELETE r FROM exf_ai_message r
JOIN genai_legacy_pairs p ON p.result_oid = r.oid;
DROP TEMPORARY TABLE genai_legacy_pairs;
DROP TEMPORARY TABLE genai_legacy_calls;
DROP TEMPORARY TABLE genai_legacy_candidates;

-- DOWN
-- Non-reversible without operator review: originals remain in the archive
-- and combined DATA. Never overwrite subsequently completed audit messages.
