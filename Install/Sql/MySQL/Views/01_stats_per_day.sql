CREATE OR REPLACE VIEW exf_ai_stats_per_day AS
SELECT
    MAX(c.oid) AS oid,
    MAX(c.created_by_user_oid) AS created_by_user_oid,
    MAX(c.created_on) AS created_on,
    MAX(c.modified_by_user_oid) AS modified_on_user_oid,
    MAX(c.modified_on) AS modified_on,
    DATE(c.created_on) AS date,
    a.oid AS ai_agent_oid,
    a.name AS ai_agent_name,
    null as ai_agent_connection_name,
    COUNT(c.oid) AS count_conversation,
    COUNT(c.rating) AS count_ratings,
    AVG(c.rating) AS avg_rating,
    (SELECT SUM(m.cost) FROM exf_ai_message m WHERE m.ai_conversation_oid = c.oid) AS sum_cost,
    (SELECT COUNT(m.oid) FROM exf_ai_message m WHERE m.ai_conversation_oid = c.oid) AS count_messages
FROM exf_ai_conversation c
    INNER JOIN exf_ai_agent a ON a.oid = c.ai_agent_oid
GROUP BY DATE(c.created_on), c.ai_agent_oid