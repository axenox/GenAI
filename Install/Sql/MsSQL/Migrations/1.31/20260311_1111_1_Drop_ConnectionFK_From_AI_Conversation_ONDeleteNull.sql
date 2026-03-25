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
WHERE t_parent.name = 'exf_ai_conversation'
  AND c_parent.name = 'connection_oid'
  AND t_ref.name = 'exf_data_connection'
  AND c_ref.name = 'oid';

IF @fk_name IS NOT NULL
BEGIN
    SET @sql = N'ALTER TABLE dbo.exf_ai_conversation DROP CONSTRAINT [' + @fk_name + N']';
EXEC sp_executesql @sql;
END;

ALTER TABLE dbo.exf_ai_conversation
ALTER COLUMN connection_oid BINARY(16) NULL;

/* Verwaiste Referenzen bereinigen */
UPDATE c
SET c.connection_oid = NULL
    FROM exf_ai_conversation c
LEFT JOIN exf_data_connection d
ON c.connection_oid = d.oid
WHERE c.connection_oid IS NOT NULL
  AND d.oid IS NULL;

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE name = 'fk_exf_ai_conversation_connection_oid'
)
BEGIN
ALTER TABLE dbo.exf_ai_conversation
    ADD CONSTRAINT fk_exf_ai_conversation_connection_oid
        FOREIGN KEY (connection_oid)
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
WHERE t_parent.name = 'exf_ai_conversation'
  AND c_parent.name = 'connection_oid'
  AND t_ref.name = 'exf_data_connection'
  AND c_ref.name = 'oid';

IF @fk_name_down IS NOT NULL
BEGIN
    SET @sql_down = N'ALTER TABLE dbo.exf_ai_conversation DROP CONSTRAINT [' + @fk_name_down + N']';
EXEC sp_executesql @sql_down;
END;