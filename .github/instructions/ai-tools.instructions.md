---
description: "Use when working on AI tools"
name: "AI tools"
applyTo: "AI/Tools/*.php"
---
# AI tools

## Summary

AI agents running on our no-code platform can use tools. Designers define a 
set of available tools for every agent in its UXON configuration. Each tool 
is based on a UXON prototype class. The tool prototype implements the inner 
logic (e.g. reading a file), while the configuration for a specific agent 
will describe the tool in the context of that agents use case: e.g. what 
files are for, from which folders they can be read, etc. As always, the 
prototype defines, what configuration is possible.

The main idea is to provide tool skeletons for app designers to us in their 
agents for specific tasks.

## Examples

The agent below has a tool to get the DDL statement for a table in an SQL DB.

```
{
  "instructions": "You help analyse and modify an SQL DB",
  "tools": {
    "get_sql_create_table": {
      "description": "Returns the DDL statement to create the given table including foreign keys and constraints",
      "arguments": [
        {
          "name": "table_name",
          "description": "Name of the table without schema prefix",
          "data_type": {
            "alias": "exface.Core.String"
          }
        },
        {
          "name": "schema",
          "data_type": {
            "alias": "exface.Core.String"
          }
        }
      ]
    }
  }
}
```

## Implementation details

AI tool prototypes must implement `axenox\genai\Interfaces\AiToolInterface`.
Most of them extend `axenox\genai\Common\AbstractAiTool` to 
share common structures.

The main methods to implement are:

- `invoke($agent, $prompt, $arguments)` - actually run the tool
- `getReturnDataType()` - important for good formatting/escaping of the result
- `getArgumentsTemplates()`, which returns the generic JSON schema for all 
  possible arguments. This is supposed to be refined in every agent using 
  the tool.
- `getRules()` if the tool has configured boundaries or workflow rules that
  the LLM must see at runtime. Rules should be derived from the current tool
  configuration where possible, for example base folders, allowed path
  patterns, scopes, security checks or configured limits. Keep the tool
  description focused on what the tool is for; put usage constraints and
  must-follow behavior into rules so concepts such as tool introductions can
  render them separately.

## Error and warning handling in tools

When implementing `invoke(...)`, handle failures so they can be persisted
consistently by the agent.

General rules:

- Use platform exceptions (`ExceptionInterface`) for tool diagnostics.
- Always log exceptions with the workbench logger.
- Return exceptions via the tool result (`AiToolResultInterface::getExceptions()`).
- If a tool continues after a partial/internal failure, attach that exception
  to the result via `AiToolResultString::addException(...)`.

Severity model:

- Small, security-related or recoverable issues should usually be treated as warnings.
- Large failures should be treated as errors.
- Set the log level explicitly only for warnings (`LoggerInterface::WARNING`).
  Error handling can use the default error behavior.

Classification in the agent is log-level based (not text based):

- log level `<= WARNING` -> persisted as warning message
- log level `> WARNING` -> persisted as error message

Recommended pattern:

- Recoverable issue: return a normal tool result and attach warning exception(s).
- Non-recoverable issue: throw `AiToolRuntimeError` (or another suitable runtime exception);
  it will be handled as error by default.

AI tool prototypes can be implemented in any app and should be placed in the 
`AI/Tools` folder for easy autodiscovery. 

## Documentation maintenance

- Whenever a tool prototype is added, changed, renamed, or removed, update the tool documentation in `Docs/AI/Tools/index.md` in the same change.
- Keep the English `Docs/AI/Tools/index.md` and German `Docs/AI/Tools/index_german.md` versions synchronized. Every content or navigation change must be applied to both language versions in the same change.
- Keep the documented purpose, use cases, unsuitable use cases, configuration, arguments, result, limitations, alias, and UXON prototype link consistent with the implementation.
- Changes to shared tool behavior or configuration, such as `AbstractAiTool` or `FileAccessToolTrait`, must also be reflected in every affected tool section.
- Tools provided by other apps must be documented in the owning app. The GenAI documentation only lists prototypes supplied by `axenox.GenAI` and states that further tools can exist in other apps.

## Global rules

- Read global instructions
  - Technical overview of the platform in `exface/core/.github/instructions/copilot-instructions.md`
  - All UXON prototypes must adhere to `exface/core/.github/instructions/uxon.instructions.md`