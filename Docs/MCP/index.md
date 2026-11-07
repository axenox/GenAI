# MCP server

[Deutsch](../Translations/de/MCP/index.md)

The GenAI MCP server exposes tools from enabled, versioned `McpServer` task handlers in the ExFace
model. Each process serves one endpoint selector and advertises only the tools configured directly
on that endpoint or contributed by its assigned skills.

## Prerequisites

- PHP 8.1 or newer must be available as `php`.
- Installed releases expose `vendor/bin/mcp` through Composer. When developing directly in this
  package, use `vendor/axenox/genai/bin/mcp` or run `bin/mcp` from the package root.
- All application and diagnostic output must go to `STDERR`. Every non-empty `STDOUT` line is an
  MCP JSON-RPC message.

## Configure an endpoint

Create an enabled agent version with:

- `PROTOTYPE_CLASS`: `axenox/genai/AI/Agents/McpServer.php`
- no data connection
- an exact semantic version such as `0.1.0`
- direct `tools` in `CONFIG_UXON`, assigned skills, or both

For example:

```json
{
  "tools": {
    "SearchModelComponents": {
      "alias": "axenox.GenAI.ModelSearchTool",
      "description": "Searches model components and their usages."
    }
  },
  "conversation_inactivity_timeout_seconds": 300,
  "conversation_grouping_scope": "session"
}
```

Assigned skills contribute their direct and nested tools. MCP does not render skill instructions or
load prompt-dependent concepts. Direct endpoint tools override same-named skill tools.

The startup scope resolves the version constraint once. Every registered tool handler retains that
exact selector and recreates the endpoint in a fresh, OS-user-authenticated Workbench scope. Restart
the MCP process after changing tools, skills, skill assignments, or endpoint configuration.

## Shared conversation log

MCP requests use the same conversation monitoring as agents, without an LLM connection. A combined
tool message is committed before the SDK invokes any tool. Individual records show `IN_PROGRESS`,
`SUCCEEDED`, or `FAILED`; completion enriches that original message. No external prompts,
instructions, answers, billing, tokens, or model usage are inferred.

`conversation_inactivity_timeout_seconds` must be a positive integer (default `300`).
`conversation_grouping_scope` is `session` (default) or `user`. Every received call extends the
inactivity window; at the timeout boundary a new conversation begins. User scope can mix concurrent
clients or chats. Authenticated user and exact endpoint version always separate groups.

Clients may supply a non-empty string in tool-call `_meta["axenox.GenAI/chat_id"]`. Distinct IDs
separate conversations within the configured scope; these groups do not expire through inactivity.
The ID is correlation, not authorization. VS Code is not assumed to send this extension.
Without it, the title describes an MCP session using endpoint, first requested tool and start time,
not a guessed external-chat topic. Handshake sessions retain the native SDK UUID; modern stateless
requests use the STDIO connection-lifetime UUID for grouping and retain their native request session
separately. JSON-RPC IDs retain their integer/string type; every execution gets a fresh call ID.

Unknown tools and invalid schemas observable as SDK `CallToolRequest` objects are logged as failed
attempts with corrective warnings, while the SDK's rejection response remains unchanged.
Empty required values rejected during platform argument parsing also receive corrective warnings,
not standalone infrastructure errors.
Authorization denials likewise remain failed attempts, not standalone errors. Real defects and
infrastructure failures produce separate `ERROR` messages. If initial authoritative logging fails,
an official SDK error prevents invocation. Conversion failure preserves the completed result and
execution state, returns an MCP error, and never re-executes the tool. Each scope authenticates and
stops its Workbench; the long-lived server keeps only definitions and scalar correlation identifiers.

Retained arguments are **SDK-visible**, not original wire JSON: associative SDK decoding loses some
nested `{}`/`[]` distinctions. Normalized positional execution arguments are retained separately.
There is no raw-framing interception, copied protocol implementation, or SDK fork. Malformed input
rejected before a tool request exists is not promised as a conversation entry.

## Start and inspect the endpoint

Run the endpoint with its namespaced alias and version:

```powershell
mcp-inspector php .\bin\mcp axenox.GenAI.ide_mcp_server
```

Install the Inspector once with Node.js 22.19 or newer if necessary:

```powershell
npm install --global @modelcontextprotocol/inspector
```

List and call tools non-interactively:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.ide_mcp_server --method tools/list --format json
mcp-inspector --cli php .\bin\mcp axenox.GenAI.ide_mcp_server --method tools/call --tool-name SearchModelComponents --tool-arg search_query=AI_AGENT
```

To verify capability isolation, create a second `McpServer` endpoint with a different tool set and
run `tools/list` for both selectors. Each result must contain only that endpoint's configured tools:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.ide_mcp_server --method tools/list
mcp-inspector --cli php .\bin\mcp customer.App.data_tools:0.1.0 --method tools/list
```

## Automated verification

Run the workbench-free MCP unit tests:

```shell
composer test:unit
```

See [Testing](../Testing/index.md) for the complete test strategy and the command used inside an
ExFace installation.

For the `ide_mcp_server` endpoint exposing the `SearchModelComponents` tool shown above, run:

```powershell
.\Tests\PHPUnit\Integration\Mcp\assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

The smoke test verifies generated schema metadata, named-to-positional argument normalization, a
real read-only `ModelSearchTool` call, SDK rejection of missing, incorrectly typed and unknown
arguments, recoverable tool errors, process recovery and clean `STDOUT`. `ModelSearchTool` currently
declares neither enum values nor argument defaults, so those schema cases require a configured tool
that publishes such metadata.

The underlying JSON-RPC session can also be piped into the endpoint directly:

```powershell
Get-Content .\Tests\PHPUnit\Integration\Mcp\endpoint-smoke.jsonl | php .\bin\mcp axenox.GenAI.ide_mcp_server
```

Closing `STDIN` ends the server. Protocol responses appear on `STDOUT`; PHP and server diagnostics
belong on `STDERR`.

## VS Code

Add the endpoint to `.vscode/mcp.json`, using the absolute path when the ExFace installation is not
the workspace root:

```json
{
  "servers": {
    "exface-ide": {
      "type": "stdio",
      "command": "php",
      "args": [
        "c:/wamp/www/exface/exface/vendor/axenox/genai/bin/mcp",
        "axenox.GenAI.ide_mcp_server"
      ]
    }
  }
}
```

Run **MCP: List Servers**, start `exface-ide`, and inspect **Show Output**. The tool
picker must list exactly the endpoint's configured tools.

For an installed GenAI release, the shorter executable path
`c:/wamp/www/exface/exface/vendor/bin/mcp` is equivalent. Restart the server after changing PHP code
or model configuration. A parse error reported by the client usually means that application output
leaked to `STDOUT`.