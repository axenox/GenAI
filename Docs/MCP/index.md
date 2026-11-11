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
  "conversation_inactivity_timeout_seconds": 300,
  "conversation_grouping_scope": "session",
  "devmode": false,
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

## Conversation logging and grouping

MCP uses the shared conversation monitor without an LLM connection. Each `tools/call` request
creates one `tool` message **before** SDK validation or invocation, then enriches that same message
with its typed result. Concurrent requests remain separate messages. Detail records show
`IN_PROGRESS`, `SUCCEEDED`, or `FAILED`; an unfinished combined message means completion was not
recorded, not that retrying is safe.

Parallel requests may have the same message sequence number. Each conversation instance reads
the current maximum when loading its metadata and increments locally; no shared sequence counter
is written. History sorts by sequence and then message UID. Message and call identities, not
sequence numbers, correlate results.
This is not a global chronological order: a request that loaded an older maximum can save
later with a lower sequence than another request. Use the recorded timestamps to inspect timing.

Conversations have `TYPE = mcp`, an explicit `TOPIC_ID`, and a deterministic title containing the
endpoint name, first requested tool, and start time. They contain only framework-visible requests
and results, not external AI instructions, user prompts, answers, token counts, or billing data.
Without an explicit chat identifier, interpret the log as an **MCP session**, not one VS Code chat.

| UXON property | Default | Meaning |
| --- | --- | --- |
| `conversation_inactivity_timeout_seconds` | `300` | Positive integer inactivity window, extended at each request receipt. At the boundary, a new conversation starts. |
| `conversation_grouping_scope` | `"session"` | `"session"` separates MCP sessions; `"user"` combines sessions for the authenticated user and exact endpoint version. |
| `devmode` | `false` | Classify new conversations as development activity, independently of any model connection. Existing conversations keep their classification. |

For example, requests at 14:00, 14:04, and 14:08 share a conversation; at 14:14 a new one starts.
User grouping may combine simultaneous clients and chats. Endpoint-version and authenticated-user
boundaries always apply. `TOPIC_ID` is correlation metadata, never an authorization credential.
Unique active grouping keys and conditional DataSheet updates serialize group allocation without
keeping a transaction open while tools execute. Activity is independent of title/rating changes.

Clients may explicitly supply a non-empty string in `_meta["axenox.GenAI/chat_id"]`. Different chat
IDs never merge within the configured scope; chat-identified groups do not expire. VS Code is not
assumed to send this extension automatically. Do not use progress tokens, request IDs, or client
names as chat identifiers. For modern stateless STDIO requests, one process/connection UUID
provides the session-grouping boundary; the SDK's per-request native session UUID is retained
separately. JSON-RPC request IDs keep their string/integer type and are not execution identities.

Observable unknown-tool and schema rejections are retained as failed attempts with corrective
warning diagnostics, without invoking the tool or producing separate conversation errors.
Tool authorization denials follow the same policy, without retaining protected details or
granting endpoint access. Malformed input rejected before an SDK `CallToolRequest` exists is not
promised as a tool entry.

The initial combined-message write is mandatory: if it fails, invocation is blocked. Detail or
completion-write failures are reported independently and do not discard completed in-memory
results. A conversion/delivery error preserves the original execution outcome. No logging failure
causes automatic re-execution, persistence repair, or a retry of side effects. Real execution and
infrastructure errors appear as separate conversation errors when storage remains available.

### Argument fidelity and lifecycle

Argument binding, defaults and parameter parsing belong to the tool's shared
`normalizeArguments()` method, called by the task handler, not the MCP adapter. Only omitted
parameters receive defaults. Omitted optional parameters without defaults become `null`;
explicit `null` is preserved for optional parameters and rejected for required parameters.
Required parameters with defaults may be omitted. The advertised MCP schema follows this policy,
retaining custom schemas while allowing `null` for optional parameters. Unknown arguments,
missing required values and parameter parsing failures prevent invocation.

Original SDK-visible named arguments and separately parsed positional execution arguments are
retained. The official SDK decodes JSON associatively, so nested `{}` and `[]` distinctions may
already be lost before GenAI sees a request. Re-encoded SDK arguments are not original raw JSON.
GenAI neither forks SDK framing nor claims original-wire fidelity for this input.

SDK events open an authenticated receipt scope, persist the request, and close that Workbench.
An operation-local bridge binds scalar correlation data to the actual request and execution
Fiber. Invocation opens a new authenticated Workbench and reloads the authorized conversation.
No Workbench, task handler, tool, or conversation instance is retained in the long-lived bridge;
operation data is removed when the SDK returns its response/error. The official SDK remains
responsible for schema validation and protocol responses.

Tools receive an `axenox\GenAI\Common\Mcp\AiMcpTask`, extending Core's `GenericTask`.
Its optional third constructor argument is a `UxonObject`, imported through the inherited
`importUxonObject()` method. Typed properties with protected, UXON-annotated setters expose
`endpoint_selector`, `conversation_id`, `message_id`, `call_id`, `mcp_session_id`,
`mcp_request_id`, and `mcp_chat_id`. Export retains these values and generic task input.
The invocation handler explicitly maps trusted bridge metadata to this UXON; client tool arguments
are not imported as task configuration. Optional IDs default to `null`, and request IDs retain
their string/integer type.

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
.\Tests\Integration\Mcp\assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

The smoke test verifies generated schema metadata, named-to-positional argument normalization, a
real read-only `ModelSearchTool` call, SDK rejection of missing, incorrectly typed and unknown
arguments, recoverable tool errors, process recovery and clean `STDOUT`. `ModelSearchTool` currently
declares neither enum values nor argument defaults, so those schema cases require a configured tool
that publishes such metadata.

`McpConversationContractTest` additionally covers grouping boundaries, explicit-chat separation,
request/Fiber isolation, blocked initial persistence, official SDK pre-invocation rejections, and
conversion from durable diagnostics. These workbench-free checks do not prove database allocation
under contention or authorization. Apply the conversation schema/model migrations before the live
smoke test; otherwise tool calls are intentionally blocked rather than executed without an audit.
Verify persisted initial/completed messages, rejected attempts, shared grouping and overlapping
requests in the configured installation as well.

The underlying JSON-RPC session can also be piped into the endpoint directly:

```powershell
Get-Content .\Tests\Integration\Mcp\endpoint-smoke.jsonl | php .\bin\mcp axenox.GenAI.ide_mcp_server
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