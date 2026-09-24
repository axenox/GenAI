/*
 * Create table exf_ai_workflow_run_step
 *
 * Stores one row per executed node or structural grouping inside a workflow
 * run. Grouping steps (e.g. one per Jira ticket) use parent_step_oid to nest
 * their child steps, which avoids a separate item-run table in the MVP.
 *
 * Agent steps reference the ordinary AI conversation created by the agent.
 * That reference uses ON DELETE SET NULL because conversations are
 * independent audit records with their own retention policy: deleting a
 * conversation must not delete workflow history and vice versa.
 *
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_workflow_run_step` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  `ai_workflow_run_oid` binary(16) NOT NULL,
  -- Set for child steps of a grouping step, NULL for top-level steps.
  `parent_step_oid` binary(16) DEFAULT NULL,
  -- Set for agent steps only.
  `ai_conversation_oid` binary(16) DEFAULT NULL,
  -- Primary sort key of the timeline, unique per run.
  `sequence_number` int NOT NULL,
  `node_id` varchar(128) NOT NULL,
  `node_type` varchar(50) NOT NULL,
  -- Business subject of the step, e.g. a Jira issue key.
  `subject_key` varchar(128) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  -- Business outcome, separate from the technical status.
  `outcome` varchar(50) DEFAULT NULL,
  -- Short orchestration message shown in the timeline.
  `message` mediumtext,
  `error_message` mediumtext,
  -- Bounded JSON metadata. Large content belongs in artifacts.
  `data` mediumtext,
  `started_on` datetime NOT NULL,
  `finished_on` datetime DEFAULT NULL,
  `duration_ms` int DEFAULT NULL,
  PRIMARY KEY (`oid`) USING BTREE,
  UNIQUE KEY `exf_ai_workflow_run_step_sequence`
    (`ai_workflow_run_oid`, `sequence_number`),
  KEY `exf_ai_workflow_run_step_run` (`ai_workflow_run_oid`),
  KEY `exf_ai_workflow_run_step_parent` (`parent_step_oid`),
  KEY `exf_ai_workflow_run_step_conversation` (`ai_conversation_oid`),
  KEY `exf_ai_workflow_run_step_subject` (`subject_key`),
  CONSTRAINT `exf_ai_workflow_run_step_run`
    FOREIGN KEY (`ai_workflow_run_oid`)
    REFERENCES `exf_ai_workflow_run` (`oid`),
  CONSTRAINT `exf_ai_workflow_run_step_parent`
    FOREIGN KEY (`parent_step_oid`)
    REFERENCES `exf_ai_workflow_run_step` (`oid`),
  CONSTRAINT `exf_ai_workflow_run_step_conversation`
    FOREIGN KEY (`ai_conversation_oid`)
    REFERENCES `exf_ai_conversation` (`oid`) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables containing workflow run history!
