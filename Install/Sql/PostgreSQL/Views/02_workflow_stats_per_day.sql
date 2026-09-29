/*
 * Statistics per day over agentic workflow runs.
 *
 * The inner query calculates the metrics of a single run: its total cost and
 * its duration. Workflow steps are excluded from the cost because their own
 * cost already aggregates their children. The outer query aggregates those
 * per-run values per day, keeping the aggregation of an aggregate in two
 * separate query levels so the definition stays portable.
 *
 * Only finished runs are included, so a run that is still in progress cannot
 * distort the averages with its partial cost and unknown duration.
 */
CREATE OR REPLACE VIEW exf_ai_workflow_stats_per_day AS
SELECT
    MAX(per_run.oid) AS oid,
    MAX(per_run.created_by_user_oid) AS created_by_user_oid,
    MAX(per_run.created_on) AS created_on,
    MAX(per_run.modified_by_user_oid) AS modified_by_user_oid,
    MAX(per_run.modified_on) AS modified_on,
    per_run.date AS date,
    per_run.workflow_alias AS workflow_alias,
    COUNT(per_run.oid) AS count_runs,
    AVG(per_run.cost) AS avg_cost,
    MAX(per_run.cost) AS max_cost,
    SUM(per_run.cost) AS sum_cost,
    AVG(per_run.duration_minutes) AS avg_duration_minutes,
    MAX(per_run.duration_minutes) AS max_duration_minutes
FROM (
    SELECT
        r.oid,
        r.created_by_user_oid,
        r.created_on,
        r.modified_by_user_oid,
        r.modified_on,
        r.started_on::date AS date,
        r.workflow_alias,
        COALESCE((
            SELECT SUM(s.cost)
            FROM exf_ai_workflow_run_step s
            WHERE s.ai_workflow_run_oid = r.oid
              AND s.node_type <> 'workflow'
        ), 0) AS cost,
        (EXTRACT(EPOCH FROM (r.finished_on - r.started_on)) / 60) AS duration_minutes
    FROM exf_ai_workflow_run r
    WHERE r.finished_on IS NOT NULL
) per_run
GROUP BY per_run.date, per_run.workflow_alias
