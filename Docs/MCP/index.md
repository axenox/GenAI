# MCP server

[Deutsch](index_german.md)

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
  }
}
```

Assigned skills contribute their direct and nested tools. MCP does not render skill instructions or
load prompt-dependent concepts. Direct endpoint tools override same-named skill tools.

The startup scope resolves the version constraint once. Every registered tool handler retains that
exact selector and recreates the endpoint in a fresh, OS-user-authenticated Workbench scope. Restart
the MCP process after changing tools, skills, skill assignments, or endpoint configuration.

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

Verify the PHP task-handler contract:

```powershell
php .\Tests\Mcp\assert-task-handler-contract.php
```

For the `ide_mcp_server` endpoint exposing the `SearchModelComponents` tool shown above, run:

```powershell
.\Tests\Mcp\assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

The smoke test verifies generated schema metadata, named-to-positional argument normalization, a
real read-only `ModelSearchTool` call, SDK rejection of missing, incorrectly typed and unknown
arguments, recoverable tool errors, process recovery and clean `STDOUT`. `ModelSearchTool` currently
declares neither enum values nor argument defaults, so those schema cases require a configured tool
that publishes such metadata.

The underlying JSON-RPC session can also be piped into the endpoint directly:

```powershell
Get-Content .\Tests\Mcp\endpoint-smoke.jsonl | php .\bin\mcp axenox.GenAI.ide_mcp_server
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