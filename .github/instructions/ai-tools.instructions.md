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

## Error and warning handling in tools

When implementing tool `invoke(...)`, always handle failures in a way that can
be persisted consistently by the agent.

- Use platform exceptions (`ExceptionInterface`) for tool diagnostics.
- Set the exception log level where needed to indicate warning severity
  (`LoggerInterface::WARNING` for warnings).
- Log exceptions with the workbench logger.
- Return exceptions via the tool result (`AiToolResultInterface::getExceptions()`)
  so the agent can persist them in the conversation.
- If the tool handles a partial/internal failure and still returns a value,
  add the exception to the result via `AiToolResultString::addException(...)`.
- Small, safety-related or recoverable issues should usually be treated as
  warnings.
- Large failures should be logged as errors and can be treated as errors
  without requiring an explicit warning log level.
- Tools that were adjusted for this behavior should use two recognitions:
  one for security/smaller warning cases and one for error cases.

Classification in the agent is log-level based, not text based:

- log level `<= WARNING` -> persisted as warning message
- log level `> WARNING` -> persisted as error message

Recommended pattern:

- Recoverable issue: return a normal tool result and attach exception(s) with
  warning log level.
- Non-recoverable issue: throw `AiToolRuntimeError` (or another suitable
  runtime exception); it will be handled as error by default.

AI tool prototypes can be implemented in any app and should be placed in the 
`AI/Tools` folder for easy autodiscovery. 

## Global rules

- Read global instructions
  - Technical overview of the platform in `exface/core/.github/instructions/copilot-instructions.md`
  - All UXON prototypes must adhere to `exface/core/.github/instructions/uxon.instructions.md`