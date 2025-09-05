-- UP
    
/* New table for agent versions */
IF OBJECT_ID ('dbo.exf_ai_agent_version', N'U') IS NULL
CREATE TABLE exf_ai_agent_version (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16) DEFAULT NULL,
    modified_by_user_oid binary(16) DEFAULT NULL,
    ai_agent_oid binary(16) NOT NULL,
    version nvarchar(50) NOT NULL DEFAULT '1.0',
    description nvarchar(MAX),
    prototype_class nvarchar(255) NOT NULL,
    config_uxon nvarchar(MAX),
    data_connection_default_oid binary(16) DEFAULT NULL,
    CONSTRAINT PK_exf_ai_agent_version PRIMARY KEY (oid),
    CONSTRAINT FK_exf_ai_agent_version_agent FOREIGN KEY (ai_agent_oid) REFERENCES exf_ai_agent (oid),
    CONSTRAINT FK_exf_ai_agent_version_data_connection_default FOREIGN KEY (data_connection_default_oid) REFERENCES exf_data_connection (oid)
);

/* Indexes */
CREATE INDEX IX_exf_ai_agent_version_data_connection_default
    ON exf_ai_agent_version (data_connection_default_oid);

CREATE INDEX IX_exf_ai_agent_version_agent
    ON exf_ai_agent_version (ai_agent_oid);

/* Save agent version number in conversations */
ALTER TABLE exf_ai_conversation
    ADD ai_agent_version_no nvarchar(50) NOT NULL DEFAULT '1.0';
    
/* Create initial versions */
INSERT INTO exf_ai_agent_version
(
    oid,
    created_on,
    created_by_user_oid,
    modified_on,
    modified_by_user_oid,
    ai_agent_oid,
    version,
    prototype_class,
    config_uxon,
    data_connection_default_oid
)
SELECT
    oid,
    created_on,
    created_by_user_oid,
    modified_on,
    modified_by_user_oid,
    oid,
    '1.0',
    prototype_class,
    config_uxon,
    data_connection_default_oid
FROM
    exf_ai_agent;

/* Migrate customizing */
UPDATE  exf_customizing SET table_name = 'exf_ai_agent_version' WHERE table_name = 'exf_ai_agent';

/* Remove moved columns */
ALTER TABLE exf_ai_agent
    DROP COLUMN prototype_class,
                config_uxon;

BEGIN TRANSACTION;
BEGIN TRY
    DROP INDEX IF EXISTS IDX_dbo_exf_ai_agent_data_connection_default ON dbo.exf_ai_agent;

    -- Declare table, column, and schema names
    DECLARE @schema NVARCHAR(256) = 'dbo';
    DECLARE @table NVARCHAR(256) = 'exf_ai_agent';
    DECLARE @column NVARCHAR(256) = 'data_connection_default_oid';
    DECLARE @sql NVARCHAR(MAX);

    -- Fully qualified table name
    DECLARE @qualifiedTable NVARCHAR(MAX) = QUOTENAME(@schema) + '.' + QUOTENAME(@table);

    -- Drop Default Constraints
    SELECT @sql = STRING_AGG('ALTER TABLE ' + @qualifiedTable + ' DROP CONSTRAINT ' + QUOTENAME(name), '; ')
    FROM sys.default_constraints
    WHERE parent_object_id = OBJECT_ID(@schema + '.' + @table)
      AND COL_NAME(parent_object_id, parent_column_id) = @column;
    
    IF @sql IS NOT NULL EXEC sp_executesql @sql;
    
        -- Drop Foreign Key Constraints
    SELECT @sql = STRING_AGG('ALTER TABLE ' + @qualifiedTable + ' DROP CONSTRAINT ' + QUOTENAME(name), '; ')
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID(@schema + '.' + @table);
    
    IF @sql IS NOT NULL EXEC sp_executesql @sql;
    
        -- Drop Indexes
    SELECT @sql = STRING_AGG('DROP INDEX ' + QUOTENAME(i.name) + ' ON ' + @qualifiedTable, '; ')
    FROM sys.indexes i
             INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
    WHERE OBJECT_NAME(ic.object_id, DB_ID(@schema)) = @table
      AND COL_NAME(ic.object_id, ic.column_id) = @column;
    
    IF @sql IS NOT NULL EXEC sp_executesql @sql;
    
        -- Drop the Column
        SET @sql = 'ALTER TABLE ' + @qualifiedTable + ' DROP COLUMN ' + QUOTENAME(@column);
        IF COL_LENGTH(CONCAT(@schema, '.', @table), @column) IS NOT NULL
        EXEC sp_executesql @sql;
    
        -- Commit transaction if all operations succeed
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    -- Rollback transaction in case of an error
ROLLBACK TRANSACTION;

    -- Output error details
    THROW;
END CATCH;
         
-- DOWN