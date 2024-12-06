-- UP

ALTER TABLE `exf_ai_message`
ADD `sequence_number` int NULL,
ADD `cost_per_m_tokens` float NULL AFTER `sequence_number`;

-- DOWN

ALTER TABLE `exf_ai_message`
DROP `cost_per_m_tokens`,
DROP `sequence_number`;