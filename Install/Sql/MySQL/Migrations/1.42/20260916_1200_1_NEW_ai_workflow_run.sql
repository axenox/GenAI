/*
 * Create table exf_ai_workflow_run
 *
 * Stores one row per invocation of an agentic workflow. A run is the root of
 * the workflow timeline that is rendered as one large conversation.
 *
 * The run is closed in a finally block, so a row must never stay in status
 * "running" after the process ended.
 *
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_workflow_run` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  -- Alias of the workflow. Constant while the graph is still hardcoded.
  `workflow_alias` varchar(128) NOT NULL,
  `title` varchar(250) NOT NULL,
  `status` varchar(20) NOT NULL,
  -- Business outcome, separate from the technical status.
  `outcome` varchar(50) DEFAULT NULL,
  -- Reserved for later idempotency checks. Not enforced yet.
  `correlation_key` varchar(128) DEFAULT NULL,
  -- PowerUI execution identity of the run.
  `user_oid` binary(16) DEFAULT NULL,
  `started_on` datetime NOT NULL,
  `finished_on` datetime DEFAULT NULL,
  `error_message` mediumtext,
  PRIMARY KEY (`oid`) USING BTREE,
  KEY `exf_ai_workflow_run_alias` (`workflow_alias`),
  KEY `exf_ai_workflow_run_status` (`status`),
  KEY `exf_ai_workflow_run_started` (`started_on`),
  KEY `exf_ai_workflow_run_correlation` (`correlation_key`),
  CONSTRAINT `exf_ai_workflow_run_user` FOREIGN KEY (`user_oid`)
    REFERENCES `exf_user` (`oid`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables containing workflow run history!
