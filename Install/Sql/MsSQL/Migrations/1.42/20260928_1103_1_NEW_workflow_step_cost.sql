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

ALTER TABLE dbo.exf_ai_workflow_run_step
  ADD cost decimal(18,6) NOT NULL
    CONSTRAINT DF_exf_ai_workflow_run_step_cost DEFAULT 0;
GO

UPDATE step
SET step.cost = COALESCE(conversation_cost.cost, 0)
FROM dbo.exf_ai_workflow_run_step step
LEFT JOIN (
  SELECT ai_conversation_oid, SUM(cost) AS cost
  FROM dbo.exf_ai_message
  GROUP BY ai_conversation_oid
) conversation_cost
  ON conversation_cost.ai_conversation_oid = step.ai_conversation_oid;

;WITH step_descendants AS (
  SELECT parent_step_oid AS ancestor_oid, oid AS descendant_oid
  FROM dbo.exf_ai_workflow_run_step
  WHERE parent_step_oid IS NOT NULL

  UNION ALL

  SELECT parent.parent_step_oid, tree.descendant_oid
  FROM step_descendants tree
  INNER JOIN dbo.exf_ai_workflow_run_step parent
    ON parent.oid = tree.ancestor_oid
  WHERE parent.parent_step_oid IS NOT NULL
), workflow_costs AS (
  SELECT tree.ancestor_oid, SUM(descendant.cost) AS cost
  FROM step_descendants tree
  INNER JOIN dbo.exf_ai_workflow_run_step descendant
    ON descendant.oid = tree.descendant_oid
  WHERE descendant.node_type <> N'workflow'
  GROUP BY tree.ancestor_oid
)
UPDATE workflow
SET workflow.cost = COALESCE(workflow_costs.cost, 0)
FROM dbo.exf_ai_workflow_run_step workflow
LEFT JOIN workflow_costs
  ON workflow_costs.ancestor_oid = workflow.oid
WHERE workflow.node_type = N'workflow'
OPTION (MAXRECURSION 0);

-- DOWN

-- Keep the cost column because it contains workflow audit history.
