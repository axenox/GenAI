/*
 * Rename ModelUxonPrototypeTool aliases in AI agent configurations
 *
 * @author Andrej Kabachnik
 */
-- UP
UPDATE [exf_ai_agent_version]
SET [config_uxon] = REPLACE(
    CAST([config_uxon] AS NVARCHAR(MAX)),
    'axenox.GenAI.ModelUxonPrototypeTool',
    'axenox.GenAI.ModelPrototypeInfoTool'
)
WHERE CAST([config_uxon] AS NVARCHAR(MAX))
    LIKE '%axenox.GenAI.ModelUxonPrototypeTool%';

-- DOWN
UPDATE [exf_ai_agent_version]
SET [config_uxon] = REPLACE(
    CAST([config_uxon] AS NVARCHAR(MAX)),
    'axenox.GenAI.ModelPrototypeInfoTool',
    'axenox.GenAI.ModelUxonPrototypeTool'
)
WHERE CAST([config_uxon] AS NVARCHAR(MAX))
    LIKE '%axenox.GenAI.ModelPrototypeInfoTool%';