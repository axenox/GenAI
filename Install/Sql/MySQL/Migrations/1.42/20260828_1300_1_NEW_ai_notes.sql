/*
 * Create table exf_ai_note
 *
 * Stores long-term notes scoped to one AI agent and one user.
 *
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_note` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  `user_oid` binary(16) NOT NULL,
  `ai_agent_oid` binary(16) NOT NULL,
  `topic` varchar(200) NOT NULL,
  `note` mediumtext NOT NULL,
  PRIMARY KEY (`oid`) USING BTREE,
  UNIQUE KEY `exf_ai_note_scope_topic` (`user_oid`, `ai_agent_oid`, `topic`),
  KEY `exf_ai_note_user` (`user_oid`),
  KEY `exf_ai_note_agent` (`ai_agent_oid`),
  CONSTRAINT `exf_ai_note_user` FOREIGN KEY (`user_oid`) REFERENCES `exf_user` (`oid`),
  CONSTRAINT `exf_ai_note_agent` FOREIGN KEY (`ai_agent_oid`) REFERENCES `exf_ai_agent` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables containing user notes!