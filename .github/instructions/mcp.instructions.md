---
description: "Use when working on the GenAI MCP server, MCP tool adapters, STDIO transport, endpoint configuration, or MCP tests"
name: "MCP server"
applyTo: "{AI/Agents/McpServer.php,Common/Mcp/**/*.php,Common/Tasks/AiMcpTask.php,Factories/McpFactory.php,Facades/AiMcpCliServerFacade.php,bin/mcp,Tests/**/Mcp/**,Docs/MCP/**,Docs/Translations/de/MCP/**}"
---
# MCP server

The GenAI MCP server exposes tools from one enabled, versioned `McpServer` task handler through the official `mcp/sdk` STDIO transport.

## Protocol rules

- Use the official PHP MCP SDK for protocol messages, schemas, handlers, and transport. Do not implement JSON-RPC framing manually.
- Keep `STDOUT` protocol-clean: every non-empty line must be an MCP JSON-RPC message. Send PHP errors, application output, logs, and diagnostics to `STDERR`.
- One MCP process serves one endpoint selector and advertises only tools configured directly on that endpoint or contributed by its assigned skills.
- Resolve the endpoint to its exact version when the server starts. Retain that exact selector in registered handlers so later calls cannot drift to a newly enabled version.
- Restart the MCP process after PHP code, endpoint configuration, tool, skill, or skill-assignment changes.

## Workbench lifecycle

- Do not retain a Workbench, task handler, tool instance, authentication state, or request state in the long-lived MCP server.
- The startup scope may load the endpoint and build immutable MCP definitions, but it must stop its Workbench before serving requests.
- Each tool call must start a fresh `McpWorkbenchScope`, authenticate it as the current operating-system user, recreate the exact task handler and tool, and stop the Workbench in a `finally` block.
- If startup authentication fails, stop the newly created Workbench before rethrowing the exception.

## Tool adaptation

- Keep AI tool prototypes MCP-agnostic. Convert `AiToolInterface` metadata and results at the MCP boundary.
- Generate each MCP input schema from the configured `ServiceParameterInterface` instances. Preserve custom `json_schema`, descriptions, defaults, examples, required arguments, and `additionalProperties: false`.
- Translate named MCP arguments into the positional order expected by `AiToolInterface::invoke()`. Parse provided and default values through the service parameter.
- Include tool rules in the advertised description so MCP clients receive the same usage constraints as LLM connectors.
- Return recoverable tool failures as MCP tool results. Log platform exceptions and convert them to `ToolCallException` at the handler boundary; do not leak diagnostic text into `STDOUT`.

## Tests

- Put workbench-free PHP tests under `Tests/Unit/Mcp`. Use plain values, reflection, or narrow interface mocks; do not start or mock the Workbench.
- Put tests requiring endpoint models, authentication, a real Workbench, or the STDIO process under `Tests/Integration/Mcp`.
- Run unit tests with `composer test:unit` in a standalone checkout, or with `vendor/bin/phpunit -c vendor/axenox/genai/phpunit.xml.dist --testsuite unit` from an ExFace installation.
- Run the endpoint smoke test after protocol, schema, lifecycle, or tool-invocation changes. It must cover initialization, capability listing, valid invocation, schema rejection, recoverable errors, continued operation, and clean `STDOUT`.

## Documentation

- Update `Docs/MCP/index.md` and `Docs/Translations/de/MCP/index.md` together when MCP behavior, configuration, commands, paths, or limitations change.
- Keep testing commands synchronized with `Docs/Testing/index.md`, its German counterpart, and `Tests/Unit/Readme.md`.
