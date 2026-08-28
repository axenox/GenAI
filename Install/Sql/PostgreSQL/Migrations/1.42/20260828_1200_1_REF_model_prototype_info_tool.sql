/*
 * Rename ModelUxonPrototypeTool aliases in AI agent configurations
 *
 * @author Andrej Kabachnik
 */
-- UP
UPDATE exf_ai_agent_version
SET config_uxon = REPLACE(
    config_uxon,
    'axenox.GenAI.ModelUxonPrototypeTool',
    'axenox.GenAI.ModelPrototypeInfoTool'
)
WHERE config_uxon LIKE '%axenox.GenAI.ModelUxonPrototypeTool%';

-- DOWN
UPDATE exf_ai_agent_version
SET config_uxon = REPLACE(
    config_uxon,
    'axenox.GenAI.ModelPrototypeInfoTool',
    'axenox.GenAI.ModelUxonPrototypeTool'
)
WHERE config_uxon LIKE '%axenox.GenAI.ModelPrototypeInfoTool%';