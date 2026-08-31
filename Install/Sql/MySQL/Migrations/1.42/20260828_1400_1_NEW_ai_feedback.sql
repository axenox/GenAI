/*
 * Create table exf_ai_feedback
 *
 * Stores feedback for an AI agent version and optional conversation.
 *
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_feedback` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  `agent_version_oid` binary(16) NOT NULL,
  `ai_conversation_oid` binary(16) DEFAULT NULL,
  `general_feedback` text,
  `suggested_tools` text,
  `feedback_checked` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`oid`) USING BTREE,
  KEY `exf_ai_feedback_agent_version` (`agent_version_oid`),
  KEY `exf_ai_feedback_conversation` (`ai_conversation_oid`),
  CONSTRAINT `chk_exf_ai_feedback_checked`
    CHECK (`feedback_checked` IN (0, 1)),
  CONSTRAINT `exf_ai_feedback_agent_version`
    FOREIGN KEY (`agent_version_oid`)
    REFERENCES `exf_ai_agent_version` (`oid`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `exf_ai_feedback_conversation`
    FOREIGN KEY (`ai_conversation_oid`)
    REFERENCES `exf_ai_conversation` (`oid`)
    ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- DOWN

DROP TABLE IF EXISTS `exf_ai_feedback`;