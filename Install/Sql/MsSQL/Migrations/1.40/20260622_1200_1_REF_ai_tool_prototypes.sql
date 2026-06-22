/*
 * Rename AI tool prototypes in exf_ai_agent_version.config_uxon
 *
 * The tool prototype classes were renamed, so all references inside the
 * config_uxon JSON column must be updated accordingly:
 *   ImportTool          -> DataSheetImportTool
 *   ReadDataSheetTool   -> DataSheetReadTool
 *   ReadFileTool        -> FileReadTool
 *   SearchFilesTool     -> FileSearchTool
 *   WriteFileTool       -> FileWriteTool
 *   ReadFolderTool      -> FolderReadTool
 *   GetObjectTool       -> ModelObjectInfoTool
 *   GetUxonPrototypeTool -> ModelUxonPrototypeTool
 *   GetWidgetTool       -> ModelWidgetTypeInfoTool
 *   GetCodeTool         -> FileReadTool (GetCodeTool is obsolete and removed)
 *
 * NOTE: GetCodeTool and ReadFileTool both map to FileReadTool, so the DOWN
 * script cannot distinguish them. DOWN reverts FileReadTool back to
 * ReadFileTool only - the obsolete GetCodeTool is not restored.
 *
 * @author OpenAI
 */
-- UP
UPDATE [exf_ai_agent_version]
SET [config_uxon] =
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
        CAST([config_uxon] AS NVARCHAR(MAX)),
        'ImportTool', 'DataSheetImportTool'),
        'ReadDataSheetTool', 'DataSheetReadTool'),
        'ReadFileTool', 'FileReadTool'),
        'GetCodeTool', 'FileReadTool'),
        'SearchFilesTool', 'FileSearchTool'),
        'WriteFileTool', 'FileWriteTool'),
        'ReadFolderTool', 'FolderReadTool'),
        'GetObjectTool', 'ModelObjectInfoTool'),
        'GetUxonPrototypeTool', 'ModelUxonPrototypeTool'),
        'GetWidgetTool', 'ModelWidgetTypeInfoTool')
WHERE [config_uxon] IS NOT NULL;

-- DOWN
UPDATE [exf_ai_agent_version]
SET [config_uxon] =
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
        CAST([config_uxon] AS NVARCHAR(MAX)),
        'DataSheetImportTool', 'ImportTool'),
        'DataSheetReadTool', 'ReadDataSheetTool'),
        'FileReadTool', 'ReadFileTool'),
        'FileSearchTool', 'SearchFilesTool'),
        'FileWriteTool', 'WriteFileTool'),
        'FolderReadTool', 'ReadFolderTool'),
        'ModelObjectInfoTool', 'GetObjectTool'),
        'ModelUxonPrototypeTool', 'GetUxonPrototypeTool'),
        'ModelWidgetTypeInfoTool', 'GetWidgetTool')
WHERE [config_uxon] IS NOT NULL;
