CREATE OR REPLACE VIEW exf_ai_stats_per_day AS
SELECT
    -- PostgreSQL has no built-in MAX for UUIDs; preserve the UUID result types.
    MAX(c.oid::text)::uuid AS oid,
    MAX(c.created_by_user_oid::text)::uuid AS created_by_user_oid,
    MAX(c.created_on) AS created_on,
    MAX(c.modified_by_user_oid::text)::uuid AS modified_by_user_oid,
    MAX(c.modified_on) AS modified_on,
    c.created_on::date AS date,
    a.oid AS ai_agent_oid,
    a.name AS ai_agent_name,
    NULL::text AS ai_agent_connection_name,
    COUNT(c.oid) AS count_conversation,
    COUNT(c.rating) AS count_ratings,
    AVG(c.rating) AS avg_rating,
    SUM(m.sum_cost) AS sum_cost,
    COALESCE(SUM(m.count_messages), 0)::bigint AS count_messages
FROM exf_ai_conversation c
    INNER JOIN exf_ai_agent a ON a.oid = c.ai_agent_oid
    -- Aggregate messages first so each conversation contributes its rating only once.
    LEFT JOIN (
        SELECT ai_conversation_oid, SUM(cost) AS sum_cost, COUNT(*) AS count_messages
        FROM exf_ai_message
        GROUP BY ai_conversation_oid
    ) m ON m.ai_conversation_oid = c.oid
GROUP BY c.created_on::date, a.oid, a.name;
