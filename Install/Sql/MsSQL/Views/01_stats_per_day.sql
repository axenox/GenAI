CREATE OR ALTER VIEW exf_ai_stats_per_day AS
SELECT
    CONVERT(DATE, c.created_on) AS [date],
    a.oid AS ai_agent_oid,
    a.name AS ai_agent_name,
    NULL AS ai_agent_connection_name,
    COUNT(DISTINCT c.oid) AS count_conversations,
    COUNT(c.rating) AS count_ratings,
    AVG(c.rating) AS avg_rating,
    SUM(m.cost) AS sum_cost,
    COUNT(m.oid) AS count_messages
FROM exf_ai_conversation c
    INNER JOIN exf_ai_agent a ON a.oid = c.ai_agent_oid
    LEFT JOIN exf_ai_message m ON m.ai_conversation_oid = c.oid
GROUP BY CONVERT(DATE, c.created_on), a.oid, a.name;