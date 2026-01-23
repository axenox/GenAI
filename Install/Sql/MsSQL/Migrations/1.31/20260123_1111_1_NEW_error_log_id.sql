--UP
IF COL_LENGTH('exf_ai_message', 'error_log_id') IS NULL
BEGIN
    ALTER TABLE exf_ai_message
    ADD error_log_id VARCHAR(20) NULL;
END;

--DOWN
