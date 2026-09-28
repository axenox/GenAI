/*
 * Add persisted cost to workflow run steps
 *
 * Existing agent steps are backfilled from their conversation messages.
 * Existing workflow steps receive the sum of all non-workflow descendants,
 * so nested workflows are rolled up without counting costs twice.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE exf_ai_workflow_run_step
  ADD COLUMN cost numeric(18, 6) NOT NULL DEFAULT 0;

UPDATE exf_ai_workflow_run_step step
SET cost = COALESCE(conversation_cost.cost, 0)
FROM (
  SELECT ai_conversation_oid, SUM(cost) AS cost
  FROM exf_ai_message
  GROUP BY ai_conversation_oid
) conversation_cost
WHERE conversation_cost.ai_conversation_oid = step.ai_conversation_oid;

WITH RECURSIVE step_descendants AS (
  SELECT parent_step_oid AS ancestor_oid, oid AS descendant_oid
  FROM exf_ai_workflow_run_step
  WHERE parent_step_oid IS NOT NULL

  UNION ALL

  SELECT parent.parent_step_oid, tree.descendant_oid
  FROM step_descendants tree
  INNER JOIN exf_ai_workflow_run_step parent
    ON parent.oid = tree.ancestor_oid
  WHERE parent.parent_step_oid IS NOT NULL
), workflow_costs AS (
  SELECT tree.ancestor_oid, SUM(descendant.cost) AS cost
  FROM step_descendants tree
  INNER JOIN exf_ai_workflow_run_step descendant
    ON descendant.oid = tree.descendant_oid
  WHERE descendant.node_type <> 'workflow'
  GROUP BY tree.ancestor_oid
)
UPDATE exf_ai_workflow_run_step workflow
SET cost = COALESCE((
  SELECT workflow_costs.cost
  FROM workflow_costs
  WHERE workflow_costs.ancestor_oid = workflow.oid
), 0)
WHERE workflow.node_type = 'workflow';

-- DOWN

-- Keep the cost column because it contains workflow audit history.
