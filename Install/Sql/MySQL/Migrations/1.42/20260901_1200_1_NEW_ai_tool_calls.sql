/*
 * Create table exf_ai_tool_call
 *
 * Stores one record for every tool call made in an AI conversation.
 *
 * @author GitHub Copilot
 */
-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_tool_call` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  `ai_conversation_oid` binary(16) NOT NULL,
  `ai_message_oid` binary(16) NOT NULL,
  `call_index` int NOT NULL,
  `call_id` varchar(255) NOT NULL,
  `tool_name` varchar(255) NOT NULL,
  `arguments` mediumtext,
  `result` mediumtext,
  `failed` smallint DEFAULT NULL,
  PRIMARY KEY (`oid`) USING BTREE,
  UNIQUE KEY `exf_ai_tool_call_conversation_call` (`ai_conversation_oid`, `call_id`),
  KEY `exf_ai_tool_call_conversation` (`ai_conversation_oid`),
  KEY `exf_ai_tool_call_message` (`ai_message_oid`),
  KEY `exf_ai_tool_call_name` (`tool_name`),
  CONSTRAINT `exf_ai_tool_call_conversation` FOREIGN KEY (`ai_conversation_oid`) REFERENCES `exf_ai_conversation` (`oid`),
  CONSTRAINT `exf_ai_tool_call_message` FOREIGN KEY (`ai_message_oid`) REFERENCES `exf_ai_message` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables containing tool call history!
