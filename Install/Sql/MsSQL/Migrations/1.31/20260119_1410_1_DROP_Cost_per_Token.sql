--UP

--Down

IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE Name = N'cost_per_m_tokens'
      AND Object_ID = Object_ID(N'dbo.exf_ai_message')
)
BEGIN
    ALTER TABLE dbo.exf_ai_message
    DROP COLUMN cost_per_m_tokens;
END
