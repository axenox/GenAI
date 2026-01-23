-- UP
ALTER TABLE exf_ai_message
ADD COLUMN IF NOT EXISTS error_log_id VARCHAR(20);

-- DOWN
