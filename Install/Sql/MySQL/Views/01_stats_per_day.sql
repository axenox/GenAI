CREATE OR UPDATE VIEW exf_ai_stats_per_day AS
SELECT
    DATE(c.created_on) AS date,
    a.oid AS ai_agent_oid,
    a.name AS ai_agent_name,
    null as ai_agent_connection_name,
    COUNT(c.oid) AS count_conversations,
    COUNT(c.rating) AS count_ratings,
    AVG(c.rating) AS avg_rating,
    (SELECT SUM(m.cost) FROM exf_ai_message m WHERE m.ai_conversation_oid = c.oid) AS sum_cost,
    (SELECT COUNT(m.oid) FROM exf_ai_message m WHERE m.ai_conversation_oid = c.oid) AS count_messages
FROM exf_ai_conversation c
    INNER JOIN exf_ai_agent a ON a.oid = c.ai_agent_oid
GROUP BY DATE(c.created_on), c.ai_agent_oid