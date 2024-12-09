-- UP

ALTER TABLE dbo.exf_ai_message
ADD sequence_number int NULL,
ADD cost_per_m_tokens float NULL;

-- DOWN

ALTER TABLE dbo.exf_ai_message
DROP COLUMN sequence_number;

ALTER TABLE dbo.exf_ai_message
DROP COLUMN cost_per_m_tokens;