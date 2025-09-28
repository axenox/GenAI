-- UP

ALTER TABLE exf_ai_agent_version
    ADD enabled_flag TINYINT NOT NULL DEFAULT '0';
EXEC sys.sp_executesql @query = N'UPDATE exf_ai_agent_version SET enabled_flag = 1';

-- DOWN

-- Do not delete new columns!