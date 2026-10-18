# Shared AI conversation logging

## Goals

Make `AiConversation` the shared, designer-facing log of interactions with AI, whether the AI runs through a Power UI agent or an external client such as VS Code using MCP. Designers must be able to inspect what the AI requested from the framework and what the framework returned, then improve tools and instructions based on that evidence.

The log contains typed messages. Some describe user input, system instructions, LLM answers, and usage; others describe tool execution, warnings, or errors. It prioritizes convenient representation rather than reproducing every protocol step as a separate message.

Keep three responsibilities distinct:

- `AiConversation` owns persisted interaction history, reconstruction of typed messages and results, and the designer-facing representation. It supplies the evidence needed to reproduce framework-visible exchanges, but does not select model context or render provider requests.
- `AiQuery` transfers one API-agnostic LLM exchange between an agent and its connector, including access to the conversation and typed results. The agent creates a new instance for every connector submission, including each continuation after tool execution. It is used only when this framework calls an LLM; it neither loads database history itself nor decides which history becomes model context.
- AI connectors and their API adapters select eligible conversation messages, translate queries into provider requests, and normalize provider responses. They own LLM-facing serialization, not persistence or monitoring text.

The linked `axenox.GenAI.AI_TOOL_CALL` object is the per-call detail and statistics layer. A conversation message retains the entire requested batch and its results so adapters can reconstruct the exchange. Individual call records expose each call's result, status, duration, and metadata without becoming a second source for reconstructing history.

This is a refactoring plan, not a description of already implemented behavior. Proposed API names below are illustrative.

## Architecture

### Shared conversation ownership

Generalize `AiConversation` and the relevant factory methods to accept `AiTaskHandlerInterface`. Both agents and MCP endpoints already expose identity, exact version, configured tools, and Workbench access through that interface.

Expose the owner as `getTaskHandler()` and migrate `getAgent()` callers. Retain exact handler-version validation and authenticated-user authorization. Do not rename every existing metamodel alias merely to change the PHP contract; existing agent/version relations can initially represent both kinds of configured handler.

Record the source (`agent` or `mcp`) explicitly in a new `TYPE` attribute of the conversation. Add a `TOPIC_ID` attribute for an external correlation identifier, such as an MCP session ID or a ticket ID for an autonomous worker. `TOPIC_ID` is not an authorization credential or a globally unique conversation key: resolution must also respect handler version and authenticated user. Agents explicitly supply connection information where applicable. Message model, tokens, and cost are optional facts, not dependencies on the owner's capabilities. A connectionless conversation must not call `getConnection()` or manufacture missing LLM data.

Development mode is independent context supplied by the caller for either conversation type. It allows tests and debug runs to be excluded from statistics; completing its currently incomplete implementation remains part of this refactoring.

An agent conversation may contain system instructions; an MCP conversation need not contain any. System-prompt rules remain an agent-runtime concern rather than a prerequisite for shared logging.

### Typed messages

The existing `AiMessageTypeDataType` is the authoritative list of conversation message types. Update it when adding or removing types; do not introduce a competing list based on provider roles.

Each message has stable identity, conversation identity, sequence, timestamps, display content, and structured data. LLM participant roles and provider-specific message formats are not the definition of a conversation message.

Conversation persistence accepts normalized message data, calls, and typed results without a query. LLM-response logging may accept `AiQueryInterface` to extract response and usage metadata, but that exception must not become a dependency of tool logging or MCP. Provider SDK objects, live tools, and Workbench instances are not persistent message data.

### One message per tool request

Each request for one or more tool calls produces one conversation message of type `tool`. An LLM response may request multiple calls; an MCP `tools/call` request names one tool. Concurrent MCP requests remain separate requests, not one tool batch. The message represents the entire requested batch and its corresponding results. Individual calls are additionally logged through `axenox.GenAI.AI_TOOL_CALL`. This two-layer structure serves multiple purposes:

- keep the conversation condensed and easy to read 
- simplify reconstructing the message chain to be sent to the LLM if the conversation continues
- support tool statistics and analysis of observable tool use to improve tools and instructions; this is not a record of the model's private reasoning

Create one conversation message immediately before handling the received batch of tool calls, then enrich that same message with tool results after all tools ran or failed. Do not append a separate result message. 

The lifecycle is:

1. The caller asks `AiConversation::saveToolCalls()` to persist and commit the batch identity, call identities, sequence, start time, arguments, and in-progress state, retaining the returned message UID. Initial persistence failure prevents the entire batch from running. No database transaction remains open during execution.
2. The shared executor handles each call independently: dispatch `OnBeforeAiToolCallEvent`, invoke the tool, capture its typed result or normalize its failure, and dispatch `OnAiToolCallEvent`. Timing and diagnostics are collected per call. Event-failure semantics must be defined so listeners cannot cause duplicate execution or discard a completed result.
3. After every call has returned or failed, the caller passes the correlated results to `AiConversation::saveToolResults()`. It enriches the same message with results, completion time, duration, diagnostics, failure state, and combined display content, preserving its UID, sequence, and original call data. It also writes the individual `AI_TOOL_CALL` records on a best-effort basis.

An illustrative flow is:

```php
$messageId = $conversation->saveToolCalls($calls);
$results = [];
foreach ($calls as $call) {
	$results[$call->getId()] = $executor->execute($handler, $task, $call);
}
$conversation->saveToolResults($messageId, $results);
```

Use the existing message type `tool` for the new combined tool messages. Serialize calls and results as UXON in separate properties of the data JSON in the message:

```json
{
    "calls": {
        "call_5GExRKnOGFplVilv2MH0bsBt": "id": "call_5GExRKnOGFplVilv2MH0bsBt",
        "type": "function",
        "function": {
            "name": "read_vendor_file",
            "arguments": {
                "path": "exface/core/DataTypes/ComparatorDataType.php\"
            }
        }
    },
    "results": {
        "call_5GExRKnOGFplVilv2MH0bsBt": {
            "class": "\\axenox\\GenAI\\Common\\AiToolResultString",
            "type": {
                "alias": "exface.Core.String"
            },
            "value": "stringified tool output",
            "appendix": {},
            "errors": [],
            "warnings": []
        }
    }
}
```

Write an SQL migration to transform existing `TOOL` and `TOOLCALLING` message pairs into combined `TOOL` steps so agents can continue existing conversations. Preserve call IDs, batch ordering, usage attribution, and linked execution records. Do not guess pairings for incomplete or ambiguous histories; define explicit compatibility handling for those cases.

The combined message is authoritative for batch identity, lifecycle, original calls, reconstructable results, and embedded diagnostics. Linked `AI_TOOL_CALL` records project per-call execution facts for inspection and statistics. Missing detail records must not prevent reconstruction from the message; their repair must not invoke tools again.

Retain raw client arguments separately from parsed execution arguments where they differ. Correlate results using stable message identity and call identity, not array positions. Distinguish repeated calls from retries or duplicate writes.

If execution is interrupted or combined-result persistence fails, the initial message remains evidence of a requested batch with completion unconfirmed. It does not prove which calls started, succeeded, or had side effects. Never automatically rerun tools to repair the audit record.

### Tool error handling

Since an LLM should still receive tool results even if they include errors, we need to catch all errors during tool invocation and categorize them. 

- Committing the tool message before invocation ensures it remains logged even if something goes entirely wrong. 
- Tools include handled failures in typed results; the executor catches remaining invocation failures and normalizes them. Distinguish invalid calls that can be corrected from unexpected tool defects. Neither kind prevents other calls in the batch from running. Failed results remain deliverable to the LLM or MCP client.
- Write individual `AI_TOOL_CALL` entries for successes and failures with corresponding results and metadata. They may be written at batch completion because they primarily serve inspection and statistics.
- Failure to write individual `AI_TOOL_CALL` entries is non-fatal: return the results and report the logging failure through `$workbench->getLogger()->logException()`. Failure of that reporting path must not discard tool results either.
- Failure to enrich the authoritative conversation message is different from a missing statistics record. Preserve the completed in-memory results, report the persistence failure without rerunning tools, and expose completion as unconfirmed in monitoring. The repair and notification policy remains to be specified.

### Shared tool execution

Extract shared invocation, timing, event dispatch, and failure normalization from `GenericAssistant` into an executor accepting `TaskInterface`. Use it from both the agent and MCP paths without introducing a prompt dependency for MCP.

Agent and MCP callers coordinate `saveToolCalls()` and `saveToolResults()` around the shared executor using identical batch semantics. The executor owns invocation, timing, events, and failure normalization, not persistence or protocol rendering. `AiConversation` owns storage, reconstruction, and display rendering, not invocation. Tool results continue to use `AiToolResultInterface`. Call identity, tool identity, and arguments belong to the conversation's call data, with results correlated by call ID. Migrate consumers to that conversation-backed read contract before removing `AiToolCallResponse`; do not introduce a replacement wrapper duplicating these fields.

Split MCP argument normalization and protocol result conversion from actual invocation. The MCP handler needs access to the typed result before converting it into `CallToolResult`. Keep protocol behavior at the MCP boundary and use the official SDK.

The executor collects tool diagnostics once and returns them with the result; conversation persistence embeds them in the combined message. Standalone warnings/errors remain available for failures outside tool execution. Define one platform-reporting boundary so tools, events, and callers do not log the same exception repeatedly. Persistence failures are separate operational diagnostics, not invented tool failures.

### API-agnostic queries and typed results

Remove database history loading from `AiQuery`. The agent passes the entire `AiConversation` to the query. The connector's API adapter decides which eligible messages become model context and how to serialize them under the provider contract. The agent retains responsibility for runtime instructions and orchestration, not provider-specific history composition.

Create a new `AiQuery` instance every time the agent submits a request to the LLM connector. A query belongs to exactly one request/response exchange; do not append tool results to a previously submitted query and send that instance again. The conversation is the durable history shared across exchanges, not a long-lived query object.

For a new conversation, the exchange sequence is:

1. Create the first query with the system instructions and user prompt, then submit it to the connector.
2. If the response requests tools, persist that request and execute the requested batch using the shared tool lifecycle.
3. Create a second query whose available context includes the previous system/user messages, the assistant's tool-call request, and the new typed tool results. Pass result instances directly, retaining their call correlation and any provider continuation metadata.
4. Repeat with a new query for each further tool round. Each continuation adds the latest exchange to the preceding context rather than replacing earlier tool results. Stop when the response needs no further tool execution.

Adapters still own eligible-history selection and provider rendering. Bind each query to the conversation boundary used for that exchange so later history additions do not change the request represented by an earlier query. Do not copy response values, usage, costs, or connector execution state from the previous query into the next one; each query receives only its own exchange's response metadata. Earlier responses and tool results remain available as context through conversation data and typed inputs, without carrying over the previous query's mutable execution state.

The `AiConversation` class owns conversation storage and provides methods to fetch messages by type. 

Audit history is not automatically model context: errors, monitoring state, and presentation text must not be blindly replayed to the LLM.

Replace provider-shaped message arrays with normalized query messages, call batches, and typed tool results. Retain call IDs, ordering, batch identity, and any continuation data required by providers without making their wire formats the shared contract.

Remove `AiToolCallResponse`. Pass `AiToolResultInterface` instances directly to `AiQuery` instead of wrapping them or calling `getValueWithMetadata()` in the agent. The query retains those instances until an API adapter renders them. Call identity and batch ordering remain separate correlation data: passing a call alongside its result does not put call metadata back into the result object or require a response wrapper. Both fresh and conversation-reconstructed results use this same query contract. For example:

```php
$conversation->saveToolResults($messageId, $results);
$query = $agent->createQuery($conversation);
foreach ($calls as $call) {
	$query->appendToolResult($call, $results[$call->getId()]);
}
```

Here, `createQuery()` illustrates constructing a fresh query for the next exchange, not retrieving or resetting the previous query. The factory signature is illustrative; the new query must retain access to all preceding eligible context as described above.

These are independent consumers of the same call/result data. Persistence must not supply the LLM-facing rendering, and query serialization must not supply the audit representation. Adapters must include each call/result exactly once when combining conversation-backed history with fresh query results; the message and call identities define the overlap.

For example, tool results can be represented by different classes: `AiToolResultString`, `AiToolResultData`, etc. They are serialized in different ways for different purposes:

- LLM-facing text or structured content, controlled by the API adapter;
- reconstructable UXON in conversation message data, exported by the result type and restored through a validated result factory;
- designer-facing display text, rendered by the conversation independently of LLM-facing content.

Tool result objects must implement `exface\Core\Interfaces\iCanBeConvertedToUxon`; make this a shared requirement by extending it from `AiToolResultInterface`. Each result class owns its `exportUxonObject()` and `importUxonObject()` implementation, including any nested typed value. Conversation persistence stores that exported UXON. Reconstruction validates the stored result class, creates the appropriate instance with its required dependencies, and imports the UXON without invoking the tool. LLM adapters consume the reconstructed typed result through the same contract as a fresh result.

Tool results must not require or retain an `AiToolInterface` instance. Inject the current Workbench directly where needed for data-type or nested-value reconstruction; do not obtain it through a tool. Remove the result's `getTool()` requirement and constructor dependency, and keep call arguments and tool identity in the call data rather than the result. Stored results must remain reconstructable even if the originating tool's implementation or configuration has changed, or the tool is no longer installed or configured. Reconstruction requires the supported result class and its value dependencies, not the original tool. Capture tool-specific error context during execution instead of looking up a tool when importing or rendering history.

Exception objects are transient execution details, not part of result UXON. `getException()` returns an original exception only for a fresh result that has one; reconstructed results return `null`. The current plural `getExceptions()` API must follow the same fresh-only rule while it remains available, returning an empty array after reconstruction. Do not serialize or manufacture exception objects during import.

Persist multiple error and warning messages in separate ordered collections. `getErrors()` and `getWarnings()` return message arrays for both fresh and reconstructed results, with empty arrays when no diagnostics exist. Adding a diagnostic appends to its collection without overwriting earlier messages. `AiToolRuntimeError` exceptions contribute error messages; `AiToolRuntimeWarning` exceptions contribute warning messages. The executor normalizes unexpected invocation failures into error diagnostics as well, so they are not lost merely because their original exception class differs. Classification happens while the result is fresh; restoring the message collections must not require recreating those exceptions.

Capture any remediation text included in adapter output while the result is fresh; reconstruction must not resolve it again from potentially changed message definitions. Persist failure state independently of exception availability, so `isFailed()`, diagnostic counters, monitoring, and adapter rendering remain correct when no exception object exists. Consumers must use these stable message accessors for diagnostics, not infer success from an empty exception collection. Preserve every error and warning and their order through persistence and rendering; monitoring counts the corresponding entries rather than treating each result as at most one error or warning.

Provide access to original structured result values through this export/import contract. The following is the value at `results[callId]`, not an alternative singular call/result message schema:

```json
{
    "class": "\\axenox\\GenAI\\Common\\AiToolResultString",
    "type": {
        "alias": "exface.Core.String"
    },
    "value": "stringified tool output",
    "errors": [],
    "warnings": []
}
```

The `type` property holds the data type's exported UXON. The result contract must also retain failure state and every other field that influences adapter output. Reconstruction must validate supported result classes rather than instantiate arbitrary classes named in stored data.

The planned `AiToolResultData` for tools such as `DataSheetReadTool` exports the data sheet under `data_sheet` instead of flattening it into a string `value`:

```json
{
    "class": "\\axenox\\GenAI\\Common\\AiToolResultData",
    "type": {
        "alias": "exface.Core.DataSheet"
    },
    "data_sheet": {
        "object_alias": "exface.Core.PAGE",
        "rows": [
            {"UID": "...", "NAME": "..."}
        ]
    }
}
```

Here, `type` describes the result's data type, while `data_sheet` contains the DataSheet's own exported UXON. The result class uses that UXON to reconstruct the DataSheet object on import, preserving the retained object identity, columns, rows, and other exported sheet configuration rather than reading the current data source again. The example shows only the object alias and rows for brevity. Adapters decide how to render that DataSheet for the LLM; neither persistence nor import pre-renders it.

Use reasonable UXON depth: retain diagnostic message arrays through the `errors` and `warnings` properties, not serialized exception objects or an `exceptions` payload. The examples omit other optional metadata for brevity; every result type uses the same diagnostic contract. Store large appendix content as authorized attachment references rather than inline file contents. References must retain access to the original content whenever that content affects LLM output; a display link alone is not a reconstruction strategy. Given the same adapter and context policy, fresh and reconstructed results must produce identical LLM-facing content.

Reconstruction covers retained, authorized framework-visible data, not external-client conversations or byte-for-byte provider envelopes. Redacted or expired payloads must be marked as unavailable for faithful replay, never silently substituted with monitoring previews. Exact retention, redaction, and replay policy remains an open decision.

The current string-returning `getValue()` cannot recover a value already flattened by the result class. 

During migration, assume all existing tool results are of type `AiToolResultString`, do not attempt to parse them. Just use the entire result as value. Do not guess whether arbitrary text is JSON.

Request adapters choose plain text, Markdown, JSON-encoded text, or supported structured content according to the actual API contract. Completions tool messages, Responses function-call output, and Claude tool-result blocks remain adapter concerns. A combined conversation message may expand into multiple protocol items.

Keep diagnostics, appendix items, and failure state separately accessible until rendering. Adapters must not mutate results, duplicate appendix content on repeated builds, or rely on implicit string conversion. Debug views and request snapshots also need explicit serialization.

### MCP conversation identity

Use the same conversation persistence and monitoring interface for MCP. Resolve a durable conversation using endpoint version, authenticated user, and MCP session context recorded through `TOPIC_ID`; reconstruct it within each per-call Workbench scope. Do not use `TOPIC_ID` alone to reuse a conversation across owners or users.

Retain only immutable identifiers across operations, not a Workbench, handler, tool, or conversation instance. Keep endpoint configuration pinned to its exact version for the process lifetime and keep `STDOUT` protocol-clean.

An MCP session is not necessarily one VS Code chat. Without a client-supplied chat identifier, label the log as an MCP session. Record the requests and results visible to the framework without claiming access to external AI instructions, user prompts, answers, or billing data.

### Usage attribution

Record usage once per LLM exchange, including exchanges that request tools without a final answer. LLM-response logging extracts usage from the query where needed and associates it with one response message or exchange record linked to the timeline. If the combined tool message represents that response, retain usage once at batch level, never on every linked `AI_TOOL_CALL` record. Its tool-execution fields still require no query. Finalize the attribution location before migrating histories so merging messages does not lose or double-count cost.

Distinguish unknown usage from known zero usage. MCP tool executions do not acquire invented model or token metadata merely because an external AI requested them. Preserve existing cost reporting during migration.

### Designer experience

Use one conversation monitoring experience for agent and MCP sources. Show one combined tool message per request with inspectable per-call arguments, results, duration, status, and diagnostics. Refresh the same message when the batch completes and distinguish in-progress, failed, and completion-unconfirmed states. Batch status must not conceal mixed success and failure; missing statistics records must not conceal results retained in the message. Per-call live refresh before batch completion is not required by this write lifecycle.

Update warning/error counters to include diagnostics embedded in tool messages without adding duplicate standalone entries. Show LLM usage only where known. Preserve old histories and filters through explicit compatibility handling.

Apply authorization, redaction, safe display rendering, payload limits, attachment access rules, and retention consistently across both sources. Large results may need bounded previews with controlled access to retained details.

## Reliable conversation resumption

### Required guarantee

A new user prompt must be able to continue an existing agent conversation after the original process and all its runtime objects have been discarded, including with a different supported LLM or API where its capabilities permit. Conversation storage must support semantically faithful, protocol-valid continuation, not require reconstruction of the original provider's JSON representation.

Preserve the meaning of instructions, user input, assistant answers, tool requests/results, diagnostics, and relevant attachments. Preserve exact values where meaning or correlation depends on them: identifiers, argument values and types, structured results, and attachment content. Adapters may change message grouping, role representation, text encoding, or content-block structure to satisfy the target API without losing meaningful information or duplicating history. Equivalent context does not promise the same future model answer.

Keep three goals distinct:

- Semantic conversation continuity is the default requirement, including supported cross-LLM/API continuation from completed turns.
- Resuming an unfinished provider exchange additionally requires its protocol-specific identities, ordering, signatures, or other continuation state. Some of these fields must be retained exactly and may not be portable.
- Exact historical request reproduction is an optional diagnostic capability, not a prerequisite for ordinary continuation. It requires compatible serialization and snapshots of request configuration beyond the conversation's semantic content.

Creating fresh queries and reconstructing tool results alone does not establish this guarantee. The message contract, adapters, and restart verification below must cover the complete context.

### Complete semantic payloads

Persist normalized model-context data separately from designer-facing display content for system/developer instructions, user prompts, assistant answers, tool requests, and tool results. Preserve meaningful structured assistant content even when the application extracts a smaller answer through a JSON answer path or renders it as Markdown. Monitoring previews must not substitute for retained content.

A combined tool message must retain the assistant content accompanying its calls, meaningful ordering, and any required continuation metadata. One designer-facing tool message can expand into different valid protocol structures. The earlier `calls` / `results` example illustrates only the execution portion, not the complete assistant-message contract. For example, a completed Claude tool exchange may become Chat Completions assistant/tool messages while preserving its text, arguments, and results without reproducing Claude's block layout.

Store the historical LLM-facing function name, original call ID, explicit call order, arguments, and batch/exchange identity. An application alias alone is insufficient if the tool later changes or disappears. Preserve JSON value types, including `{}` versus `[]` and nulls, as well as raw arguments separately from parsed execution arguments where they differ. Original argument-string formatting need not survive cross-API rendering, but its values and meaning must. Retain original call IDs in storage; if a target API requires remapping, map calls and results consistently without changing their stored identities. Scope correlation by message or exchange as well as call ID.

### Provider-specific continuation

Use normalized messages and typed results for portable semantics, supplemented by versioned, API-namespaced data where required for provider-specific resumption. Response adapters must capture meaningful content and required continuation fields before flattening or discarding output. Persistence stores data, not live SDK objects; the corresponding API adapter interprets provider extensions.

The existing APIs impose different representations:

- Chat Completions uses assistant messages with `tool_calls` and correlated `tool` messages. Preserve semantic assistant content beyond a display-only answer and maintain valid call/result groups.
- Responses uses input/output items and may require additional continuation items or identifiers. Its current text/function-call normalization must not discard meaningful content or reasoning-related continuation data required by the selected model/API mode.
- Claude Messages uses assistant content blocks with `tool_use` and user-side `tool_result` blocks. Preserve meaningful ordering and any signatures or continuation fields required by the selected mode, without imposing that block layout on other APIs.

Provider-specific continuation data must not be fabricated or forwarded blindly to another provider. It is not a requirement to expose private model reasoning. When continuing a completed turn through a different API, use the portable semantic history; omit provider-only transport state only where it is not needed to preserve meaning or satisfy the target protocol. If required content cannot be represented, report the limitation rather than silently dropping it.

Switching providers during an unfinished exchange is a separate compatibility decision, not an automatic translation. Require an explicit recovery or rejection when continuation state is nonportable. If remote provider state is used, define its durability and a store-backed alternative where the protocol permits one; an expiring response ID alone is not durable conversation history.

Future APIs cannot be guaranteed before their contracts are known. New adapters declare supported payload versions, semantic content types, and continuation capabilities. Preserve unknown extension data without claiming to understand it. API switching remains subject to authorization, owner/version checks, and target capabilities, but original wire-format equality is not a requirement.

### Attachments and historical configuration

Retain attachments through durable, authorized references with message association, meaningful position, MIME type, filename, and original content. The target adapter may use a different supported encoding or block layout, but must not associate an old file with a new prompt or replace its content with a mutable source file. Redacted, expired, inaccessible, or unsupported required attachments must produce an explicit continuation limitation.

Restore historical messages, calls, and results without resolving or invoking the original tools. Do not regenerate historical instructions or tool identities from changed configuration. Current tool availability controls new execution, not whether old context can be read. A new call targeting an unavailable tool is a new execution failure, not a reason to rewrite historical results.

Version stored messages, result UXON, and provider extensions, with compatible readers or migrations that preserve meaning. Ordinary continuation may use a different supported model and its current request settings; it does not require historical tool-schema or configuration snapshots. If exact historical request reproduction is implemented, retain those snapshots and a compatible serializer separately, without live historical tool instances.

### Restart and incomplete histories

On a later user prompt, reload and authorize the conversation in a new Workbench scope, append the prompt once, and create a fresh query bound to that turn's context boundary. The selected adapter renders eligible prior messages and typed results in its own protocol, including prior final answers and completed tool rounds. Do not depend on old queries, cached results, or mutable handler state. Keep new response and usage metadata local to the new exchange.

History selection must retain meaningful order and protocol-valid call/result groups. Do not silently remove unmatched calls/results, invent a successful completion, or rerun tools to repair a partial batch. Incomplete or completion-unconfirmed histories require explicit recovery or incompatibility handling before continuation. Legacy readers can only recover retained content; migrations cannot invent discarded information or required provider state.

### Store-only verification gate

Run this gate for Chat Completions, Responses, and Claude Messages, including supported cross-API transitions from completed turns:

1. Define expected semantic content for system/user messages, assistant text accompanying multiple calls, at least two tool rounds, and a final assistant answer. Include diagnostics, structured values, and attachments supported by the target API.
2. Persist the conversation and required continuation data. Discard all original queries, handlers, results, tools, and Workbench instances.
3. In a new Workbench scope, reload the conversation and reconstruct typed inputs exclusively from storage. Do not invoke tools or reread source data. Render context using the original API and, separately, a different supported API after a completed turn.
4. Assert preservation of instructions, user/assistant content, argument/result values and types, meaningful order, attachment associations, and one-to-one call/result correlation. Validate each target protocol, including top-level `instructions` or `system` where applicable. Use explicit semantic assertions and protocol fixtures, not equality between different APIs' JSON layouts or expectations of identical model answers.
5. Append a new user prompt and verify that eligible prior context and the prompt appear exactly once. Exercise another tool round and verify correct exchange-local response, token, and cost attribution. Check exact continuation fields where required for same-provider unfinished exchanges.
6. Repeat after changing or removing the original tool. Cover legacy histories, mixed success/failure batches, missing attachments, incomplete execution, incompatible versions, unsupported content, and nonportable provider state. Require either faithful continuation or an explicit limitation, without repeated side effects or silent information loss.

Same-adapter fresh/restored JSON equality remains a useful deterministic regression test under unchanged context and serialization rules; it is not the cross-API acceptance criterion. Exact historical request reproduction may have separate optional snapshot tests. Protocol-valid fixtures and focused live-provider checks where available establish API compatibility; same-process tool-loop tests alone do not establish store-only resumption. The implementation phases and final verification must include this restart gate.

## Conflicts with current code

These are implementation gaps to resolve, not competing architectural recommendations:

- `AiConversation` currently requires `AiAgentInterface` and resolves connection, development mode, and model details itself. Its constructor calls `saveNewConversation()`; `AiFactory` delegates creation to it. Remove capability assumptions from that lifecycle.
- Conversation logging methods currently accept `AiQueryInterface`, coupling persistence to LLM exchanges. Remove that requirement from shared message and tool APIs; retain it only where LLM-response logging extracts query metadata.
- The agent persists tool-call requests and batched results separately, using `AiToolCallResponse`, while individual execution records are updated afterward. Replace the split messages with one create/enrich lifecycle per requested batch, correlating call data and typed results by call ID.
- `AiResponse::getToolCallResponses()` exposes wrapper objects to consumers. Migrate them to the conversation-backed call/result read contract before removing `AiToolCallResponse`, without introducing a duplicate wrapper.
- `AiQuery` currently loads persisted messages and uses OpenAI-shaped arrays. Give it conversation access without database-loading logic; adapters select history and translate it into provider requests.
- The agent currently extends a query across tool rounds. Replace that reuse with a new `AiQuery` for every connector submission, carrying forward eligible conversation context and typed tool results but no previous response or usage state.
- The agent flattens tool results before query insertion, and `AiToolResultInterface` exposes string-based value access. Pass result instances directly to `AiQuery`, remove `AiToolCallResponse` from the transport path, and introduce structured-value access. Keep call correlation separate from the result value.
- `AiToolResultString` requires a live tool for Workbench access and error naming, and the result interface exposes `getTool()` and call arguments. Remove these tool/call dependencies from results, inject Workbench directly, and retain historical tool identity in call data.
- Diagnostics currently depend on `getExceptions()` and dynamically resolved remediation hints. Introduce fresh-only exception access and persistent `getErrors()` / `getWarnings()` message arrays, populated from `AiToolRuntimeError` and `AiToolRuntimeWarning` respectively; migrate rendering, counters, and failure checks so restored results need no exception objects or current message definitions.
- `AiToolMcpAdapter` currently combines invocation with protocol conversion, and `AiMcpTask` contains only the endpoint selector. Separate execution and add explicit correlation context.
- Existing warning/error counts depend on separate message roles. Adapt aggregation for embedded tool diagnostics.
- `isFailed()` is documented as forbidding delivery to the LLM, while the agent appends normalized failures and MCP returns error results. Resolve this contract ambiguity before unifying failure handling.

## Implementation plan

### Phase 1: Shared logging contracts

Generalize owner and factory parameters, introduce `getTaskHandler()`, and define typed message inputs using `AiMessageTypeDataType`. Remove mandatory connection/model/query dependencies from shared persistence, retaining the optional LLM-response query input for metadata extraction. Preserve exact-version and user checks. Add `TYPE` and `TOPIC_ID`, define their correlation scope, and make development mode explicit independent context. Add only the remaining schema changes required for the agreed message contract.

### Phase 2: Combined tool messages

Implement `saveToolCalls()` and `saveToolResults()` for one message per requested batch, using stable message and call identities. Commit before any invocation and enrich the same message once all calls have returned or failed. Persist authoritative result data independently of best-effort `AI_TOOL_CALL` detail writes. Add lifecycle state, concurrency-safe sequencing, and distinct handling for initial, completion, and statistics-write failures. Adapt monitoring and embedded diagnostic counts with the new write path. Deliver the split-message SQL migration with compatibility handling for incomplete histories before enabling the new reader/writer contract.

### Phase 3: Shared execution and MCP logging

Extract the executor for invocation, timing, events, and failure normalization; agent and MCP callers coordinate the batch persistence lifecycle around it. Adapt the agent, split MCP normalization/conversion from invocation, and introduce durable session correlation through `TOPIC_ID`. Make both paths produce the same tool-message structure while preserving their separate task types, authorization, protocol rules, and Workbench lifecycles. Capture tool-specific diagnostic context during execution and keep call/result correlation in conversation data rather than introducing a new wrapper.

### Phase 4: Query and adapter separation

Introduce normalized query messages, structured result access, and result UXON export/import through `iCanBeConvertedToUxon` as a requirement of `AiToolResultInterface`. Decouple result construction from tool instances, inject Workbench directly, and migrate GenAI and IDE tool constructors accordingly. Keep exceptions fresh-only and persist multiple diagnostic messages for `getErrors()` and `getWarnings()`, classifying `AiToolRuntimeError` as errors and `AiToolRuntimeWarning` as warnings, including any rendered remediation text and independent failure state. Define the nested `data_sheet` contract for future `AiToolResultData` without requiring that new result class to be implemented in this refactoring. Pass the conversation through the query and move history selection and LLM-facing serialization into API adapters, not the agent or persistence layer. Update every supported request/response adapter. Keep typed results until request rendering, deduplicate fresh and persisted data by identity, and preserve protocol-required call/result ordering and continuation metadata. Verify identical adapter output for fresh and reconstructed results.

Change agent orchestration to construct a new `AiQuery` for every connector submission, including all tool continuations. Bind each query to its exchange's context boundary, preserve cumulative eligible history and typed results, and isolate response and usage metadata between instances.

### Phase 5: Compatibility and completion

Complete direct `AiToolResultInterface` transport through `AiQuery` and its adapters, document the conversation-backed call/result read contract, and migrate `AiResponse` consumers. Then delete `AiToolCallResponse`; neither query transport nor the read API introduces a replacement wrapper. Remove obsolete split-message APIs, not the new batch-save APIs. Verify migration and compatibility readers against complete, incomplete, and ambiguous legacy histories; preserve reporting and update prototype documentation in both language trees when implementation changes their behavior.

Deliver each phase with focused verification before layering the next. Do not claim a performance improvement from the changed write pattern without measurements.

## Open items

### Message and storage contract

- Finalize message fields, payload versioning, lifecycle states, and the start/complete signatures.
- Finalize per-call projection fields and statistics-repair behavior, keeping `AI_MESSAGE` authoritative for reconstruction and embedded diagnostics. Choose the single usage attribution location.
- Specify the conversation-backed call/result read API replacing `AiToolCallResponse` for consumers and compatibility for existing role values, payloads, exports, and owner accessors.
- Specify migration pairing rules and the compatibility path for incomplete or ambiguous legacy split messages without guessing correlations.

### MCP correlation and scope

- Verify which stable session, client, and request identifiers the SDK exposes, and define fallback correlation where they are absent.
- Decide how optional client chat identifiers, reconnects, rollover, and titles map to conversations without crossing user or endpoint-version boundaries.
- Define logging for rejected arguments, denied requests, unknown tools, and conversion failures, including failures rejected by the SDK before the handler runs.

### Execution and failure semantics

- Define process-level interruptions versus caught invocation failures. Align `isFailed()` with deliverable failure results, executor behavior, LLM error outputs, and MCP error results; caught tool failures must not stop the rest of the batch.
- Finalize the shared start/complete caller contract and event-listener failure semantics, including failures before invocation and after a side effect has completed.
- Specify duplicate-write behavior, call identity scope, safe completion retries, recovery of stale messages, and atomic sequence allocation under concurrency.
- Define an explicit diagnostic path when result persistence fails after a side-effecting tool completes; audit repair must not rerun the tool.

### Query, history, and usage

- Finalize structured-value access and normalized query types, including provider-specific continuation metadata carried as opaque data where necessary.
- Finalize the fresh-only exception accessor migration from the current plural API and compatibility for legacy diagnostic payloads. The new contract uses ordered `errors` and `warnings` message arrays through `getErrors()` and `getWarnings()`, independent of exception objects and live tools.
- Specify adapter-owned history selection and conversation-owned reconstruction from new combined messages and legacy split messages without replaying tool execution. Define how adapters deduplicate fresh query results already present in the conversation.
- Define supported cross-LLM/API transitions from completed turns, semantic content mappings, and explicit recovery or rejection for nonportable unfinished exchanges. Treat exact historical request reproduction as optional diagnostics, not a continuation requirement.
- Choose the usage attribution record and its timeline representation, preserving totals for tool-requesting and final-answer exchanges.
- Define explicit serialization for snapshots, debug views, attachments, and results that cannot be represented as plain JSON.

### Security and operations

- Finalize redaction, payload limits, retention, attachment authorization, and safe rendering policies, including explicit replay-unavailable states when required content has been redacted or expired.
- Apply ownership checks to every message creation and enrichment, including updates by message UID.
- Decide how monitoring refreshes in-progress messages and exposes completion-unconfirmed executions without misrepresenting them as safe to retry.

## Verification criteria

- Agent and MCP invocations produce the same typed tool-message structure without requiring an LLM query for MCP.
- Exactly one message is committed before the first invocation in each requested batch; enrichment after all calls preserves its UID, sequence, and original call identities. Separate MCP requests remain separate messages.
- Initial persistence failure prevents the entire batch from running. Caught invocation failures yield typed failure results and do not stop remaining calls. Interrupted batches and failed completion updates remain diagnosable without repeated side effects.
- Statistics-write failures do not discard results or invalidate a completed message. Both successful and failed calls are represented in detail records when their writes succeed; missing records can be repaired without invocation.
- Concurrent calls and out-of-order results retain correct correlation, authorization, and stable timeline order.
- Arguments, typed values, failure state, diagnostics, appendices, timing, and result metadata survive persistence and conversation-backed reconstruction for existing response consumers.
- Tool results round-trip through `iCanBeConvertedToUxon` export/import without invoking tools or rereading source data. When data-sheet results are introduced, their nested `data_sheet` UXON restores a DataSheet object, not a flattened string.
- Restoring and rendering a result succeeds when the originating tool has changed or is unavailable; no tool instance is created or resolved. GenAI and IDE result producers use the decoupled constructor contract.
- Fresh results may expose original exceptions; reconstructed results expose none. `getErrors()`, `getWarnings()`, failure state, diagnostic counters, and adapter-visible remediation text survive reconstruction without serialized exceptions or re-resolving message definitions. Verify multiple `AiToolRuntimeError` and `AiToolRuntimeWarning` diagnostics on one result, retaining all messages in their respective order and counting each entry once.
- Existing split-message histories remain readable and cost/diagnostic reports remain correct without double counting.
- Adapter-owned history selection and final wire requests satisfy every supported LLM API, including structured results, failures, batches, and provider continuation data. Conversation access through the query causes neither implicit provider formatting nor duplicate call/results.
- Repeated adapter builds do not mutate results or duplicate metadata, and monitoring text is never blindly used as model context.
- `AiQuery` receives `AiToolResultInterface` instances directly, preserves their separate call correlation, and exposes them to adapters without flattening or response wrappers. All `AiToolCallResponse` consumers are migrated and the class is removed.
- Every connector submission uses a distinct `AiQuery` instance. Verify an initial system/user exchange followed by at least two tool rounds: each continuation retains preceding eligible messages, assistant call requests, and all correlated tool results exactly once. Earlier query request context stays stable, and response, token, and cost metadata belong only to their own exchange.
- With the same adapter and context policy, fresh and reconstructed results produce identical LLM-facing content. Missing retained payloads are explicit replay limitations, not silently substituted previews.
- Store-only resumption preserves semantic content and produces protocol-valid context for each supported target API, including supported cross-API continuation from a completed turn. Different APIs need not produce identical JSON. Preserve exact provider continuation fields only where required; report unsupported mappings or unfinished-state incompatibilities explicitly.
- MCP initialization and tool calls remain protocol-compliant, recoverable failures do not kill the process, and `STDOUT` stays clean.
- Redaction, payload limits, and authorization work consistently for both conversation sources.
- `TYPE` and scoped `TOPIC_ID` correlation do not bypass owner/version/user checks. Development-mode filtering works independently for agent and MCP conversations.