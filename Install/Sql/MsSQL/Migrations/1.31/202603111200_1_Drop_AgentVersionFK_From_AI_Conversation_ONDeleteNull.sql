-- UP
DECLARE @fk_name NVARCHAR(128);
DECLARE @sql NVARCHAR(MAX);

SELECT TOP 1 @fk_name = fk.name
FROM sys.foreign_keys fk
         INNER JOIN sys.foreign_key_columns fkc
                    ON fk.object_id = fkc.constraint_object_id
         INNER JOIN sys.tables t_parent
                    ON fkc.parent_object_id = t_parent.object_id
         INNER JOIN sys.columns c_parent
                    ON fkc.parent_object_id = c_parent.object_id
                        AND fkc.parent_column_id = c_parent.column_id
         INNER JOIN sys.tables t_ref
                    ON fkc.referenced_object_id = t_ref.object_id
         INNER JOIN sys.columns c_ref
                    ON fkc.referenced_object_id = c_ref.object_id
                        AND fkc.referenced_column_id = c_ref.column_id
WHERE t_parent.name = 'exf_ai_agent_version'
  AND c_parent.name = 'data_connection_default_oid'
  AND t_ref.name = 'exf_data_connection'
  AND c_ref.name = 'oid';

IF @fk_name IS NOT NULL
BEGIN
    SET @sql = N'ALTER TABLE dbo.exf_ai_agent_version DROP CONSTRAINT [' + @fk_name + N']';
EXEC sp_executesql @sql;
END;

ALTER TABLE dbo.exf_ai_agent_version
ALTER COLUMN data_connection_default_oid BINARY(16) NULL;

/* Verwaiste Referenzen bereinigen */
UPDATE v
SET v.data_connection_default_oid = NULL
    FROM exf_ai_agent_version v
LEFT JOIN exf_data_connection d
ON v.data_connection_default_oid = d.oid
WHERE v.data_connection_default_oid IS NOT NULL
  AND d.oid IS NULL;

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE name = 'fk_exf_ai_agent_version_data_connection_default_oid'
)
BEGIN
ALTER TABLE dbo.exf_ai_agent_version
    ADD CONSTRAINT fk_exf_ai_agent_version_data_connection_default_oid
        FOREIGN KEY (data_connection_default_oid)
            REFERENCES exf_data_connection(oid)
            ON DELETE SET NULL;
END;

-- DOWN
DECLARE @fk_name_down NVARCHAR(128);
DECLARE @sql_down NVARCHAR(MAX);

SELECT TOP 1 @fk_name_down = fk.name
FROM sys.foreign_keys fk
         INNER JOIN sys.foreign_key_columns fkc
                    ON fk.object_id = fkc.constraint_object_id
         INNER JOIN sys.tables t_parent
                    ON fkc.parent_object_id = t_parent.object_id
         INNER JOIN sys.columns c_parent
                    ON fkc.parent_object_id = c_parent.object_id
                        AND fkc.parent_column_id = c_parent.column_id
         INNER JOIN sys.tables t_ref
                    ON fkc.referenced_object_id = t_ref.object_id
         INNER JOIN sys.columns c_ref
                    ON fkc.referenced_object_id = c_ref.object_id
                        AND fkc.referenced_column_id = c_ref.column_id
WHERE t_parent.name = 'exf_ai_agent_version'
  AND c_parent.name = 'data_connection_default_oid'
  AND t_ref.name = 'exf_data_connection'
  AND c_ref.name = 'oid';

IF @fk_name_down IS NOT NULL
BEGIN
    SET @sql_down = N'ALTER TABLE dbo.exf_ai_agent_version DROP CONSTRAINT [' + @fk_name_down + N']';
EXEC sp_executesql @sql_down;
END;