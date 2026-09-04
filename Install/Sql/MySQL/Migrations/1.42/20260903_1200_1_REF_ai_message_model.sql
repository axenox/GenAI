/*
 * Move the model column from AI conversations to messages
 */
-- UP

ALTER TABLE `exf_ai_message`
    ADD COLUMN `model` varchar(100) NULL;

UPDATE `exf_ai_message` AS m
INNER JOIN `exf_ai_conversation` AS c
    ON c.`oid` = m.`ai_conversation_oid`
SET m.`model` = c.`model`;

ALTER TABLE `exf_ai_conversation`
    DROP COLUMN `model`;

-- DOWN

ALTER TABLE `exf_ai_conversation`
    ADD COLUMN `model` varchar(100) NULL;

UPDATE `exf_ai_conversation` AS c
LEFT JOIN (
    SELECT `ai_conversation_oid`, MAX(`model`) AS `model`
    FROM `exf_ai_message`
    GROUP BY `ai_conversation_oid`
) AS m ON m.`ai_conversation_oid` = c.`oid`
SET c.`model` = m.`model`;

ALTER TABLE `exf_ai_message`
    DROP COLUMN `model`;