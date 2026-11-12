/*
 * Search GenAI UXON models and Markdown instructions.
 *
 * The result follows the common model-search view structure used by the
 * exface.Core and axenox.ETL apps.
 */
CREATE OR REPLACE VIEW exf_ai_model_search (
    uxon,
    object_name,
    attribute_name,
    instance_name,
    instance_alias,
    oid,
    table_name,
    app_oid,
    component
) AS

/* Agent (version) config UXON */
SELECT
    av.config_uxon AS uxon,
    'AI agent' AS object_name,
    'Configuration' AS attribute_name,
    CONCAT(a.name, ' v', av.version) AS instance_name,
    CONCAT(
        (CASE
            WHEN a.app_oid IS NOT NULL THEN CONCAT(app.app_alias, '.', a.alias)
            ELSE a.alias
        END),
        ':',
        av.version
    ) AS instance_alias,
    av.oid AS oid,
    'exf_ai_agent_version' AS table_name,
    a.app_oid AS app_oid,
    'ai_agent_version' AS component
FROM exf_ai_agent_version AS av
INNER JOIN exf_ai_agent AS a ON a.oid = av.ai_agent_oid
LEFT JOIN exf_app app ON app.oid = a.app_oid
WHERE av.config_uxon IS NOT NULL

UNION ALL

/* Agent (version) instructions */
SELECT
    av.instructions AS uxon,
    'AI agent' AS object_name,
    'Instructions' AS attribute_name,
    CONCAT(a.name, ' v', av.version) AS instance_name,
    CONCAT(
        (CASE
            WHEN a.app_oid IS NOT NULL THEN CONCAT(app.app_alias, '.', a.alias)
            ELSE a.alias
        END),
        ':',
        av.version
    ) AS instance_alias,
    av.oid AS oid,
    'exf_ai_agent_version' AS table_name,
    a.app_oid AS app_oid,
    'ai_agent_version' AS component
FROM exf_ai_agent_version AS av
INNER JOIN exf_ai_agent AS a ON a.oid = av.ai_agent_oid
LEFT JOIN exf_app app ON app.oid = a.app_oid
WHERE av.instructions IS NOT NULL

UNION ALL

SELECT
    tc.config_uxon AS uxon,
    'AI test criterion' AS object_name,
    'Configuration' AS attribute_name,
    tc.name AS instance_name,
    NULL AS instance_alias,
    tc.oid AS oid,
    'exf_ai_test_criterion' AS table_name,
    tcase.app_oid AS app_oid,
    'ai_test_criterion' AS component
FROM exf_ai_test_criterion AS tc
INNER JOIN exf_ai_test_case AS tcase ON tcase.oid = tc.ai_test_case_oid
WHERE tc.config_uxon IS NOT NULL

UNION ALL

SELECT
    s.config_uxon AS uxon,
    'AI skill' AS object_name,
    'Configuration' AS attribute_name,
    s.name AS instance_name,
    s.alias AS instance_alias,
    s.oid AS oid,
    'exf_ai_skill' AS table_name,
    s.app_oid AS app_oid,
    'ai_skill' AS component
FROM exf_ai_skill AS s
WHERE s.config_uxon IS NOT NULL

UNION ALL

SELECT
    s.instructions AS uxon,
    'AI skill' AS object_name,
    'Instructions' AS attribute_name,
    s.name AS instance_name,
    s.alias AS instance_alias,
    s.oid AS oid,
    'exf_ai_skill' AS table_name,
    s.app_oid AS app_oid,
    'ai_skill' AS component
FROM exf_ai_skill AS s
WHERE s.instructions IS NOT NULL;
