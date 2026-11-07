/*
 * Historic malformed JSON remains visible but cannot break monitoring.
 */
CREATE OR REPLACE FUNCTION genai_monitor_json(value text) RETURNS jsonb AS $$
BEGIN
    RETURN value::jsonb;
EXCEPTION WHEN invalid_text_representation THEN
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE VIEW exf_ai_conversation_counters AS
SELECT c.oid,
    (SELECT COUNT(*) FROM exf_ai_message m
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'error')
        AS error_count,
    (SELECT COUNT(*) FROM exf_ai_message m
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'warning')
    + COALESCE((
        SELECT SUM(CASE WHEN jsonb_typeof(r.value->'warnings') = 'array'
            THEN jsonb_array_length(r.value->'warnings') ELSE 0 END)
        FROM exf_ai_message m
        CROSS JOIN LATERAL jsonb_each(CASE
            WHEN jsonb_typeof(genai_monitor_json(m.data)->'results') = 'object'
            THEN genai_monitor_json(m.data)->'results' ELSE '{}'::jsonb END) r
        WHERE m.ai_conversation_oid = c.oid AND m.role = 'tool'
            AND NOT COALESCE(genai_monitor_json(m.data) ? 'legacy', false)
    ), 0) AS warning_count
FROM exf_ai_conversation c;
