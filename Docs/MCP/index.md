# Testing the MCP server

[Deutsch](index_german.md)

The diagnostic selector exposes one hard-coded `diagnostics` tool. Other selectors resolve enabled,
versioned task handlers from the ExFace model and expose only their configured tools.

## Prerequisites

- PHP 8.1 or newer must be available as `php`.
- Installed releases expose `vendor/bin/mcp` through Composer. When developing directly in this
  package, use `vendor/axenox/genai/bin/mcp` or run `bin/mcp` from the package root.
- All diagnostics must go to `STDERR`. Every non-empty `STDOUT` line is an MCP JSON-RPC message.

## Automated smoke test on Windows

Run from `vendor/axenox/genai`:

```powershell
.\Tests\Mcp\assert-smoke.ps1
```

The script initializes a session, lists and calls tools, sends an invalid tool call and then pings
the server. It asserts that every `STDOUT` line is valid JSON, `diagnostics` is callable, the bad
call returns an MCP error and the process continues serving requests.

## Workbench lifecycle benchmark

Run both lifecycle strategies in isolated PHP processes:

```powershell
php .\Tests\Mcp\benchmark-workbench.php 5
```

The `fresh` strategy starts and stops one Workbench per simulated operation. The `long-lived`
strategy starts one Workbench for all operations. The output reports total and average latency and
peak allocated memory. Treat this as a local measurement, not a reason to weaken operation
isolation; repeat it on representative systems before optimizing the lifecycle.

## Phase 2 AI tool adapter

Create an enabled test agent with selector `axenox.GenAI.mcp_phase_2_test:0.1.0` and this UXON:

```json
{
  "tools": {
    "search_model": {
      "alias": "axenox.GenAI.ModelSearchTool",
      "description": "Searches model components and their usages."
    }
  }
}
```

Then run:

```powershell
.\Tests\Mcp\assert-adapter-smoke.ps1
```

The test verifies generated schema metadata, named-to-positional argument normalization, a real
read-only `ModelSearchTool` call, SDK rejection of missing/wrong/unknown arguments, recoverable tool
errors, fresh Workbench operation scopes, process recovery and clean `STDOUT`. Pass a different
selector as `-AgentSelector` when needed. `ModelSearchTool` currently declares neither enum values
nor argument defaults, so those schema cases require a configured tool that actually publishes such
metadata.

## Phase 3 MCP endpoints

Verify the PHP interface split first:

```powershell
php .\Tests\Mcp\assert-task-handler-contract.php
```

Create an enabled agent version with:

- `PROTOTYPE_CLASS`: `axenox/genai/AI/Agents/McpServer.php`
- no data connection
- an exact semantic version such as `0.1.0`
- direct `tools` in `CONFIG_UXON`, assigned skills, or both

Assigned skills contribute their direct and nested tools. MCP does not render skill instructions or
load prompt-dependent concepts. Direct endpoint tools override same-named skill tools. Restart the
MCP process after changing tools, skills or skill assignments.

Start the endpoint with its namespaced alias and version:

```powershell
mcp-inspector php .\bin\mcp axenox.GenAI.model_development:0.1.0
```

The startup scope resolves the version constraint once. Every registered tool handler retains that
exact selector and recreates the endpoint in a fresh, OS-user-authenticated Workbench scope. Model
changes take effect only after restarting the MCP process.

List tools contributed by an assigned skill non-interactively:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.model_development:0.1.0 --method tools/list --format json
```

To test capability isolation, create a second `McpServer` endpoint with a different tool set and run
`tools/list` for both selectors. Each result must contain only that endpoint's configured tools:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.model_development:0.1.0 --method tools/list
mcp-inspector --cli php .\bin\mcp customer.App.data_tools:0.1.0 --method tools/list
```

## Raw smoke session

```powershell
Get-Content .\Tests\Mcp\smoke.jsonl | php .\bin\mcp axenox.genai:diagnostics
```

Closing `STDIN` ends the server. Protocol responses appear on `STDOUT`; PHP and server diagnostics
belong on `STDERR`.

## MCP Inspector

Install the Inspector once with Node.js 22.19 or newer:

```powershell
npm install --global @modelcontextprotocol/inspector
```

Start the web Inspector from `vendor/axenox/genai`:

```powershell
mcp-inspector php .\bin\mcp axenox.genai:diagnostics
```

Open the URL printed by the Inspector, connect, select the `diagnostics` tool and run it with an
empty argument object.

For a non-interactive check:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.genai:diagnostics --method tools/list
mcp-inspector --cli php .\bin\mcp axenox.genai:diagnostics --method tools/call --tool-name diagnostics --tool-arg emitWarning=false
```

To inspect the configured Phase 2 tool:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.mcp_phase_2_test:0.1.0 --method tools/list
mcp-inspector --cli php .\bin\mcp axenox.GenAI.mcp_phase_2_test:0.1.0 --method tools/call --tool-name search_model --tool-arg search_query=AI_AGENT
```

## VS Code

Add this server to `.vscode/mcp.json`, using the absolute path when the ExFace installation is not
the workspace root:

```json
{
  "servers": {
    "exface-diagnostics": {
      "type": "stdio",
      "command": "php",
      "args": [
        "c:/wamp/www/exface/exface/vendor/axenox/genai/bin/mcp",
        "axenox.genai:diagnostics"
      ]
    }
  }
}
```

Run **MCP: List Servers**, start `exface-diagnostics`, and inspect **Show Output**. The tool picker
must list `diagnostics`; invoking it must return `status: ok`, the endpoint selector, the PHP
version and `transport: stdio`.

For an installed GenAI release, the shorter executable path
`c:/wamp/www/exface/exface/vendor/bin/mcp` is equivalent.

Restart the server after changing PHP code. A parse error reported by the client usually means
that application output leaked to `STDOUT`.