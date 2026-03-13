-- UP

ALTER TABLE exf_ai_message
    DROP COLUMN IF EXISTS cost_per_m_tokens;
         
-- DOWN

ALTER TABLE exf_ai_message
    ADD COLUMN cost_per_m_tokens FLOAT NULL;