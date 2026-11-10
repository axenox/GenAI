# Shared AI conversation logging

## Goals

Make `AiConversation` the shared, designer-facing log of interactions with AI, whether the AI runs through a Power UI agent or an external client such as VS Code using MCP. Designers must be able to inspect what the AI requested from the framework and what the framework returned, then improve tools and instructions based on that evidence.

The log contains typed messages. Some describe user input, system instructions, LLM answers, and usage; others describe tool execution, warnings, or errors. It prioritizes convenient representation rather than reproducing every protocol step as a separate message.

Keep three responsibilities distinct:

- `AiConversation` owns persisted interaction history, reconstruction of typed messages and results, and the designer-facing representation. It supplies the evidence needed to reproduce framework-visible exchanges, but does not select model context or render provider requests.
- `AiQuery` transfers one API-agnostic LLM exchange between an agent and its connector, including access to the conversation and typed results. The agent creates a new instance for every connector submission, including each continuation after tool execution. It is used only when this framework calls an LLM; it neither loads database history itself nor decides which history becomes model context.
- AI connectors and their API adapters select eligible conversation messages, translate queries into provider requests, and normalize provider responses. They own LLM-facing serialization, not persistence or monitoring text.

The linked `axenox.GenAI.AI_TOOL_CALL` object is the per-call detail and statistics layer. A conversation message retains the entire requested batch and its results so adapters can reconstruct the exchange. Individual call records expose each call's result, status, duration, and metadata without becoming a second source for reconstructing history.

This is a refactoring plan, not a description of already implemented behavior. The technical contracts below specify the implementation target; illustrative orchestration examples omit operational error handling.

## Architecture

### Shared conversation ownership

Generalize `AiConversation` and the relevant factory methods to accept `AiTaskHandlerInterface`. Both agents and MCP endpoints already expose identity, exact version, configured tools, and Workbench access through that interface.

Expose the owner as `getTaskHandler()`, remove `getAgent()`, and migrate all its callers in this refactoring rather than retain an agent-only compatibility accessor on the shared contract. Retain exact handler-version validation and authenticated-user authorization. Do not rename every existing metamodel alias merely to change the PHP contract; existing agent/version relations can initially represent both kinds of configured handler.

Record the source (`agent` or `mcp`) explicitly in a new `TYPE` attribute of the conversation. Add a `TOPIC_ID` attribute for an external correlation identifier, such as an MCP session ID or a ticket ID for an autonomous worker. `TOPIC_ID` is not an authorization credential or a globally unique conversation key: resolution must also respect handler version and authenticated user. Agents explicitly supply connection information where applicable. Message model, tokens, and cost are optional facts, not dependencies on the owner's capabilities. A connectionless conversation must not call `getConnection()` or manufacture missing LLM data.

Development mode is independent context supplied by the caller for either conversation type. It allows tests and debug runs to be excluded from statistics; completing its currently incomplete implementation remains part of this refactoring.

An agent conversation may contain system instructions; an MCP conversation need not contain any. System-prompt rules remain an agent-runtime concern rather than a prerequisite for shared logging.

### Typed messages

The existing `AiMessageTypeDataType` is the authoritative list of conversation message types. Update it when adding or removing types; do not introduce a competing list based on provider roles.

Each message has stable identity, conversation identity, sequence, timestamps, display content, and structured data. LLM participant roles and provider-specific message formats are not the definition of a conversation message.

Conversation persistence accepts normalized message data, calls, and typed results without a query. LLM-response logging may accept `AiQueryInterface` to extract response and usage metadata, but that exception must not become a dependency of tool logging or MCP. Provider SDK objects, live tools, and Workbench instances are not persistent message data.

Do not introduce payload-version fields or a versioning mechanism for `AI_MESSAGE.DATA`, including its stored result UXON and provider extensions. Existing consumers do not parse that JSON. The new reconstruction contract still reads structured data where needed; handle the planned storage-format transition through migration and explicit legacy compatibility, not schema-version markers.

### One message per tool request

Each request for one or more tool calls produces one conversation message of type `tool`. An LLM response may request multiple calls; an MCP `tools/call` request names one tool. Concurrent MCP requests remain separate requests, not one tool batch. The message represents the entire requested batch and its corresponding results. Individual calls are additionally logged through `axenox.GenAI.AI_TOOL_CALL`. This two-layer structure serves multiple purposes:

- keep the conversation condensed and easy to read 
- simplify reconstructing the message chain to be sent to the LLM if the conversation continues
- support tool statistics and analysis of observable tool use to improve tools and instructions; this is not a record of the model's private reasoning

Create one conversation message immediately before handling the received batch of tool calls, then enrich that same message with tool results after all tools ran or failed. Do not append a separate result message. 

The lifecycle is:

1. The caller asks `AiConversation::saveToolCalls()` to persist and commit the batch identity, call identities, sequence, start time, and arguments, retaining the returned message UID. The end time is initially absent; combined messages have no lifecycle-state field. Initial persistence failure prevents the entire batch from running. No database transaction remains open during execution.
2. The caller coordinates per-call persistence around the handler's shared `callTool()` method. Attempt to save and commit an individual `AI_TOOL_CALL` entry immediately before each invocation, linked to the combined message and marked `IN_PROGRESS`. If that write fails, execute the tool anyway, report the persistence error through the platform logger, and attempt to record an error message in the conversation. The handler dispatches `OnBeforeAiToolCallEvent`, invokes the tool, captures its typed result or normalizes its failure, and dispatches `OnAiToolCallEvent`. Immediately after execution, attempt to update and commit the individual entry with its result, end time, duration, diagnostics, and terminal state. A before-listener failure skips that tool; an after-listener failure preserves its result and execution outcome, as specified below.
3. After every call has returned or failed, the caller passes the correlated results to `AiConversation::saveToolResults()`. It enriches the same message with results, end time, duration, diagnostics, and combined display content, preserving its UID, sequence, and original call data. Individual call records are written around each invocation, not deferred to batch completion.

Replace the individual call's `FAILED` flag with an enum containing `IN_PROGRESS`, `SUCCEEDED`, and `FAILED`. `IN_PROGRESS` means invocation is about to begin and completion has not been recorded; after interruption it does not prove whether execution or side effects occurred. `SUCCEEDED` includes warning-only results. `FAILED` means execution returned or threw a failure, even though its error result remains deliverable to the AI. A failed post-call update leaves the stored entry `IN_PROGRESS`; never automatically re-execute the tool to repair it.

Any tool-result diagnostic at `ERROR` severity or higher makes `isFailed()` true and the individual call `FAILED`, even if useful partial content is retained. Warning-only results remain `SUCCEEDED`. This replaces the current critical-or-higher failure threshold. Operational logging errors and after-listener errors are separate diagnostics and do not change the tool's execution outcome. Failure does not prohibit delivering the result to the LLM or MCP client.

Combined tool messages track start and end times only. An absent end time means batch completion is unconfirmed, not that execution failed or that retrying is safe. Other message types do not acquire lifecycle states. Individual call states and retained results describe execution outcomes without hiding mixed success and failure within a batch.

An illustrative flow is:

```php
$messageId = $conversation->saveToolCalls($calls);
$results = [];
foreach ($calls as $call) {
	$results[$call->getCallId()] = $handler->callTool($task, $call);
}
$conversation->saveToolResults($messageId, $results);
```

Use the existing message type `tool` for the new combined tool messages. Store calls and results as arrays in separate properties of the data JSON in the message, with an explicit `call_id` in every entry. The `calls` array defines request order; correlate results by ID, never by array position. No separate order list is needed:

```json
{
    "calls": [
        {
            "call_id": "call_5GExRKnOGFplVilv2MH0bsBt",
            "tool_name": "read_vendor_file",
            "arguments": {
                "path": "exface/core/DataTypes/ComparatorDataType.php"
            }
        }
    ],
    "results": [
        {
            "call_id": "call_5GExRKnOGFplVilv2MH0bsBt",
            "class": "\\axenox\\GenAI\\Common\\AiToolResultString",
            "type": {
                "alias": "exface.Core.String"
            },
            "value": "stringified tool output",
            "failed": false,
            "appendix": {},
            "errors": [],
            "warnings": []
        ]
    }
}
```

Write an SQL migration to transform existing `TOOL` and `TOOLCALLING` message pairs into combined `TOOL` steps so agents can continue existing conversations. Preserve call IDs, batch ordering, usage attribution, and linked execution records. Merge only reliably correlated batches; do not guess pairings for incomplete or ambiguous histories. Preserve unresolved entries for viewing without blocking migration of valid histories. Unresolved legacy entries must not by themselves prevent conversation continuation: use the information still retained, without inventing missing results or rerunning tools.

Load incomplete tool messages, both legacy entries and new combined messages, as message instances retaining their stored content and correlation data, and pass them unchanged to the LLM API adapter. Expose a message-level completeness indicator such as `isBroken()` so the adapter can detect unresolved exchanges and choose whether and how its API can continue. Conversation loading must not pre-emptively flatten these messages into explanatory text, discard them, or reject continuation solely because they are incomplete. Passing them unchanged to the adapter does not require forwarding protocol-invalid wire messages unchanged to the provider. Never invent missing results or rerun tools to repair history.

The combined message is authoritative for batch identity, start/end times, original calls, reconstructable results, and embedded diagnostics. Linked `AI_TOOL_CALL` records expose per-call execution states and facts for live inspection, drill-down, and statistics. Missing detail records must not prevent reconstruction from a completed message. Do not implement automatic or manual repair of missing or incomplete individual records for now; retain conversation evidence and visible errors instead. Detailed statistics may consequently be incomplete. Any future repair must not invoke tools again.

Keep individual call records' `RESULT` human-readable, with result character length and execution duration. Preserve their existing call identity, tool name/alias, arguments, order, and combined-message link; add the agreed enum state and explicit start/end times. Store reconstructable typed result UXON only in the combined message, not as a second payload in each individual record. Detail rendering is a designer-facing representation, not the LLM adapter's serialization or a reconstruction source.

Retain raw client arguments separately from parsed execution arguments where they differ. Correlate results using stable message identity and call identity, not array positions. Distinguish repeated calls from retries or duplicate writes.

Keep completion writes simple for now: update records by their existing message and call identities without additional reads, behaviors, or a dedicated duplicate-completion/conflict-detection mechanism. Do not promise immutable first-write completion data or automatic persistence retries. Normal authorization and owner/version validation still apply. Repeated requests with new execution identities remain separate calls; matching tool names or arguments do not make them duplicates. No persistence retry or repair may invoke a tool again.

Allocate message sequence numbers atomically in short database transactions so concurrent requests in the same conversation receive a unique timeline order. Use appropriate database concurrency protection; a transaction around an unprotected read of the current maximum alone is insufficient. Ordering reflects request-message persistence, not execution completion. Commit before invoking tools and allow overlapping tool execution; never hold the sequencing transaction open across invocation.

If execution is interrupted or combined-result persistence fails, the initial message remains evidence of a requested batch with completion unconfirmed. It does not prove which calls started, succeeded, or had side effects. Never automatically rerun tools to repair the audit record.

### Tool error handling

Since an LLM should still receive tool results even if they include errors, we need to catch all errors during tool invocation and categorize them. 

- Committing the tool message before invocation ensures it remains logged even if something goes entirely wrong. 
- Tools include handled failures in typed results; the handler's `callTool()` method catches remaining invocation failures and normalizes them. Distinguish invalid calls that can be corrected from unexpected tool defects. Neither kind prevents other calls in the batch from running. Failed results remain deliverable to the LLM or MCP client.
- Write and commit individual `AI_TOOL_CALL` entries immediately before and after each invocation, retaining their link to the combined message and using the agreed three-state enum.
- Failure to create or update an individual `AI_TOOL_CALL` entry is non-fatal: execute the tool despite a failed pre-call write, retain completed results despite a failed post-call write, and report persistence errors through `$workbench->getLogger()->logException()` and a conversation error message. These are operational errors, not tool execution failures; they do not change a successful tool result to `FAILED`. Failure of either reporting path must not prevent invocation or discard completed results.
- Failure to enrich the authoritative conversation message is different from a missing individual call record. Return the actual completed in-memory results to the AI, report the persistence failure through the platform logger, and attempt a separate conversation `ERROR` message. Leave the combined message without an end time and retain whichever individual call records were saved successfully. Do not add automatic repair jobs or persistence retries. On later continuation, pass retained messages with their completeness indicator to the adapter, which decides whether and how to continue without fabricated results, repeated execution, or silent information loss.

Real errors must be visible in the conversation regardless of whether they originate in tools, execution, or logging infrastructure. Designers should not need to inspect individual call records to discover them; those records primarily support detailed analysis. Warnings caused by invalid tool use are not promoted to conversation error messages. If conversation persistence itself is unavailable, the platform logger remains the independent reporting path; do not claim that a conversation error was saved successfully.

Create a separate `ERROR` conversation message for every real error, including tool execution errors and operational logging errors. Only these `ERROR` messages receive `ERROR_LOG_ID`; combined tool messages, individual call records, warning messages, and serialized result diagnostics do not receive that field. Keep tool diagnostics embedded in the combined message for reconstruction, but correlate their separate error messages to the same diagnostic so monitoring counts each error once. Invalid-tool-use warnings remain tool diagnostics rather than separate error messages. Diagnostic correlation identifiers are distinct from `ERROR_LOG_ID`.

### Shared tool execution

Move shared tool resolution, invocation, timing, event dispatch, and failure normalization from `GenericAssistant` into `AbstractAiTaskHandler::callTool($task, $call)`, exposed through `AiTaskHandlerInterface`. Accept `TaskInterface` and return `AiToolResultInterface`. Both the agent and MCP paths call this method on their configured handler without introducing a prompt dependency for MCP. Resolve the requested tool through the handler's configured tools. Do not introduce a separate executor class; `ToolBox` remains responsible for tool collection, merging, and configuration warnings rather than runtime execution.

If an `OnBeforeAiToolCallEvent` listener throws an unexpected error, do not invoke that tool. Produce a deliverable failure result, mark the individual call `FAILED`, report the error through the platform logger and a separate conversation `ERROR` message, and continue the other calls in the batch. A before-listener may perform required preparation or checks, so its failure is not treated like a non-blocking logging failure.

If an `OnAiToolCallEvent` listener throws after execution, preserve the tool's original result and `SUCCEEDED` or `FAILED` outcome. Report the listener failure as a separate operational `ERROR` through the platform logger and conversation, and continue other calls. Do not retry the tool or turn a successful execution into a failed tool result because an after-listener failed.

Agent and MCP callers coordinate `saveToolCalls()` and `saveToolResults()` around the handler's `callTool()` method using identical batch semantics. This method owns tool resolution, invocation, timing, events, and failure normalization, not persistence or protocol rendering. `AiConversation` owns storage, reconstruction, and display rendering, not invocation. Tool results continue to use `AiToolResultInterface`. Call identity, tool identity, and arguments belong to the conversation's call data, with results correlated by call ID. Migrate consumers to that conversation-backed read contract before removing `AiToolCallResponse`; do not introduce a replacement wrapper duplicating these fields.

Split MCP argument normalization and protocol result conversion from actual invocation. The MCP handler needs access to the typed result before converting it into `CallToolResult`. Keep protocol behavior at the MCP boundary and use the official SDK.

The handler's `callTool()` method collects tool diagnostics once and returns them with the result; conversation persistence embeds them in the combined message and creates correlated standalone `ERROR` messages for real errors. Standalone warnings remain available for failures outside tool execution. Define one platform-reporting boundary so tools, events, and callers do not log the same exception repeatedly. Persistence failures are separate operational diagnostics, not invented tool failures.

### API-agnostic queries and typed results

Remove database history loading from `AiQuery`. The agent passes the entire `AiConversation` to the query. The connector's API adapter decides which eligible messages become model context and how to serialize them under the provider contract. The agent retains responsibility for runtime instructions and orchestration, not provider-specific history composition.

Create a new `AiQuery` instance every time the agent submits a request to the LLM connector. A query belongs to exactly one request/response exchange; do not append tool results to a previously submitted query and send that instance again. The conversation is the durable history shared across exchanges, not a long-lived query object.

For a new conversation, the exchange sequence is:

1. Create the first query with the system instructions and user prompt, then submit it to the connector.
2. If the response requests tools, persist that request and execute the requested batch using the shared tool lifecycle.
3. Create a second query whose available context includes the previous system/user messages, the assistant's tool-call request, and the new typed tool results. Pass result instances directly, retaining their call correlation and any provider continuation metadata.
4. Repeat with a new query for each further tool round. Each continuation adds the latest exchange to the preceding context rather than replacing earlier tool results. Stop when the response needs no further tool execution.

Adapters still own eligible-history selection and provider rendering. Bind each query to the conversation boundary used for that exchange so later history additions do not change the request represented by an earlier query. Do not copy response values, usage, costs, or connector execution state from the previous query into the next one; each query receives only its own exchange's response metadata. Earlier responses and tool results remain available as context through conversation data and typed inputs, without carrying over the previous query's mutable execution state.

The `AiConversation` class owns conversation storage and exposes an ordered `getMessages()` API returning typed message instances, optionally filtered by message type. Tool-message instances expose stored calls and reconstructed typed results correlated by call ID, plus completeness information such as `isBroken()`. Use this shared read model for adapters and response consumers without a replacement call/response wrapper. Remove the old text-returning message convenience methods and migrate their callers to typed messages with explicit display-content access.

Audit history is not automatically model context: errors, monitoring state, and presentation text must not be blindly replayed to the LLM.

Include the full eligible history by default. Do not introduce automatic truncation, summarization, or a new configurable context-budget policy in this refactoring. If the retained eligible context exceeds the target model's limit, report the limitation explicitly rather than silently dropping older information. Adapter eligibility and protocol rendering remain distinct from shortening history.

Replace provider-shaped message arrays with normalized query messages, call batches, and typed tool results. Retain call IDs, ordering, batch identity, and any continuation data required by providers without making their wire formats the shared contract.

Remove `AiToolCallResponse`. Pass `AiToolResultInterface` instances directly to `AiQuery` instead of wrapping them or calling `getValueWithMetadata()` in the agent. The query retains those instances until an API adapter renders them. Call identity and batch ordering remain separate correlation data: passing a call alongside its result does not put call metadata back into the result object or require a response wrapper. Both fresh and conversation-reconstructed results use this same query contract. For example:

```php
$conversation->saveToolResults($messageId, $results);
$query = $agent->createQuery($conversation);
foreach ($calls as $call) {
	$query->appendToolResult($messageId, $call, $results[$call->getCallId()]);
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

Exception objects are transient execution details, not part of result UXON. Retain the current plural `getExceptions()` API for compatibility: fresh results return their original exceptions, while reconstructed results return an empty array. No new singular accessor is required. Migrate framework consumers to durable `getErrors()`, `getWarnings()`, and `isFailed()` rather than using exception availability for rendering, counting, or failure handling. Do not serialize or manufacture exception objects during import.

Persist multiple error and warning messages in separate ordered collections. `getErrors()` and `getWarnings()` return message arrays for both fresh and reconstructed results, with empty arrays when no diagnostics exist. Adding a diagnostic appends to its collection without overwriting earlier messages. `AiToolRuntimeError` exceptions contribute error messages; `AiToolRuntimeWarning` exceptions contribute warning messages. The handler's `callTool()` method normalizes unexpected invocation failures into error diagnostics as well, so they are not lost merely because their original exception class differs. Classification happens while the result is fresh; restoring the message collections must not require recreating those exceptions.

Capture any remediation text included in adapter output while the result is fresh; reconstruction must not resolve it again from potentially changed message definitions. Persist failure state independently of exception availability, so `isFailed()`, diagnostic counters, monitoring, and adapter rendering remain correct when no exception object exists. Consumers must use these stable message accessors for diagnostics, not infer success from an empty exception collection. Preserve every error and warning and their order through persistence and rendering; monitoring counts the corresponding entries rather than treating each result as at most one error or warning.

Provide access to original structured result values through this export/import contract. The following is the result-class-owned UXON included in a `results` array entry. Message serialization adds the entry's `call_id` as correlation metadata; it is not part of the typed result object's export/import contract or an alternative singular call/result message schema:

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

Reconstruction covers retained, authorized framework-visible data, not external-client conversations or byte-for-byte provider envelopes. Unavailable protected or deleted payloads must be marked as unavailable for faithful replay, never silently substituted with monitoring previews. Retain complete authorized content until explicitly deleted, reuse existing protections, and apply the adapter-owned compatibility policies specified below.

Change `AiToolResultInterface::getValue()` to return the actual typed result value rather than require a string. `AiToolResultString` continues returning a string; a future DataSheet result returns its reconstructed DataSheet object. Do not introduce a competing raw-value accessor. Migrate consumers that need text to explicit rendering methods, leaving LLM-facing rendering to adapters. Export/import must preserve the original value rather than attempt to recover it after flattening.

During migration, assume all existing tool results are of type `AiToolResultString`, do not attempt to parse them. Just use the entire result as value. Do not guess whether arbitrary text is JSON.

Request adapters choose plain text, Markdown, JSON-encoded text, or supported structured content according to the actual API contract. Completions tool messages, Responses function-call output, and Claude tool-result blocks remain adapter concerns. A combined conversation message may expand into multiple protocol items.

Keep diagnostics, appendix items, and failure state separately accessible until rendering. Adapters must not mutate results, duplicate appendix content on repeated builds, or rely on implicit string conversion. Debug views and request snapshots also need explicit serialization.

### MCP conversation identity

Use the same conversation persistence and monitoring interface for MCP. Resolve a durable conversation using endpoint version, authenticated user, and MCP session context recorded through `TOPIC_ID`; reconstruct it within each per-call Workbench scope. Do not use `TOPIC_ID` alone to reuse a conversation across owners or users.

Group MCP calls into conversations using a configurable inactivity timeout, defaulting to five minutes. Each call extends the grouping window: calls at 14:00, 14:04, and 14:08 share a conversation, while a call at 14:14 starts a new one. Configure the MCP prototype through `conversation_inactivity_timeout_seconds` (default `300`) and `conversation_grouping_scope` (`session` by default, or `user`). This is neither a fixed clock bucket nor a maximum duration measured from the first call. The `session` scope groups within one MCP session; `user` groups across sessions for the same authenticated user and exact endpoint version. Authenticated-user and exact endpoint-version boundaries always apply; cross-session grouping may mix concurrent chats or clients and must not be presented as an exact VS Code chat boundary.

When a client supplies a reliable chat identifier, use it to separate conversations and do not apply the inactivity timeout. Resolve that identifier within the configured session/user scope and authenticated-user/exact endpoint-version boundaries, never as a globally unique or authorizing key. Different chat identifiers must not be merged. Time-based grouping remains the fallback when no chat identifier is available.

Retain only immutable identifiers across operations, not a Workbench, handler, tool, or conversation instance. Keep endpoint configuration pinned to its exact version for the process lifetime and keep `STDOUT` protocol-clean.

An MCP session is not necessarily one VS Code chat. Without a client-supplied chat identifier, label the log as an MCP session. Record the requests and results visible to the framework without claiming access to external AI instructions, user prompts, answers, or billing data.

Title MCP conversations using the endpoint name, first requested tool name, and conversation start time. Generate the title deterministically without an LLM call or a guessed external-chat topic.

Log rejected MCP tool attempts, including invalid arguments and unknown tools, with the received call data and corrective diagnostics where the rejection is observable. Represent the attempted request in the conversation and mark its individual call `FAILED` without invoking the tool. A rejected request is not a successful warning-only execution: its failed outcome records rejection, while ordinary invalid-use diagnostics do not produce separate `ERROR` messages. Reserve those messages for real execution or infrastructure errors. Verify which SDK rejection paths can be observed before promising coverage of requests rejected before the handler runs.

When an authenticated user has endpoint access but a tool request is denied by authorization, record a rejected attempt with a corrective diagnostic and `FAILED` call state, without a separate conversation `ERROR` message. Preserve existing security logging. Retain only information the user is authorized to see, not protected resource contents or internal authorization details. Unexpected defects in authorization machinery remain real errors. This policy does not grant conversation access to users denied endpoint access.

If converting a completed typed tool result into an MCP response fails, preserve the tool's original result and execution state. Report a separate infrastructure `ERROR` through the platform logger and conversation, and return a protocol-valid MCP error response where possible. A delivery/conversion failure does not turn successful execution into a failed tool call and must never trigger tool re-execution.

### Usage attribution

Store LLM-reported tokens and cost on every conversation message that represents an LLM response. Currently these are tool-request messages, represented by the combined tool message, and final assistant response messages. If future reasoning streams produce separate response messages with reported token or cost information, store that usage on those messages too. Usage is not restricted to these two current message types.

LLM-response logging extracts usage from the query where needed; tool-execution fields still require no query. Retain usage on the combined message when initially saving an LLM tool request, independently of whether execution or completion persistence later succeeds. Do not duplicate batch usage on linked `AI_TOOL_CALL` records or introduce a separate usage record for this purpose. When a provider reports cumulative usage across streamed messages, attribute it without counting the same usage repeatedly.

Distinguish unknown usage from known zero usage. Only LLM exchanges produce LLM usage; MCP execution does not. MCP tool messages do not acquire invented model, token, or cost metadata merely because an external AI requested them. Preserve existing cost reporting during migration so merging messages neither loses nor double-counts usage.

### Designer experience

Use one conversation monitoring experience for agent and MCP sources. Show one combined tool message per request with drill-down to linked individual call records exposing arguments, results, duration, enum state, and diagnostics. Update the same persisted message when the batch completes; manual monitoring refresh is sufficient for this refactoring, without polling or push updates. Combined messages have no persisted lifecycle state: derive completion information from start/end times and show individual `IN_PROGRESS`, `SUCCEEDED`, and `FAILED` states without concealing mixed outcomes. Missing detail records must not conceal results retained in the message. Per-call records are available before batch completion and visible on manual refresh.

When a combined message has no end time, display "completion not recorded" with its start time and available individual call states. Do not introduce an age-based stale threshold or infer that execution failed, the process died, or retrying is safe. A completeness indicator such as `isBroken()` describes missing retained data, not process liveness.

Create standalone `ERROR` messages for real errors, with `ERROR_LOG_ID` only on those messages. Update warning/error counters to account for embedded diagnostics and correlated standalone errors without counting the same diagnostic twice. Invalid-tool-use warnings remain embedded tool diagnostics. Show LLM usage only where known. Preserve old histories and filters through explicit compatibility handling.

Apply authorization, redaction, safe display rendering, payload limits, attachment access rules, and retention consistently across both sources. Large results may need bounded previews with controlled access to retained details.

Retain complete tool-result data for reconstruction. Only monitoring display may use bounded previews, with authorized access to the full retained content. A preview must not replace stored results or silently shorten adapter inputs. Do not introduce configurable truncation of retained results in this refactoring. If a database or storage limit prevents complete persistence, report a persistence error rather than mark truncated data as complete.

Do not introduce automatic expiry, retention jobs, or cleanup in this refactoring. Preserve agent and MCP conversation data and retained attachment content until explicitly deleted, subject to existing authorization and deletion rules. If required content has been deleted or is inaccessible, reconstruction must report its unavailability instead of substituting a preview.

Reuse existing authorization and sensitive-data protections without introducing a configurable automatic content-redaction engine. Do not bypass existing protections to retain complete results. Apply safe display escaping; when protected content is removed and reconstruction depends on it, expose its unavailability explicitly rather than substitute content silently.

## Reliable conversation resumption

### Required guarantee

A new user prompt must be able to continue an existing agent conversation after the original process and all its runtime objects have been discarded, including with a different supported LLM or API where its capabilities permit. Conversation storage must support semantically faithful, protocol-valid continuation, not require reconstruction of the original provider's JSON representation.

Preserve the meaning of instructions, user input, assistant answers, tool requests/results, diagnostics, and relevant attachments. Preserve exact values where meaning or correlation depends on them: identifiers, argument values and types, structured results, and attachment content. Adapters may change message grouping, role representation, text encoding, or content-block structure to satisfy the target API without losing meaningful information or duplicating history. Equivalent context does not promise the same future model answer.

Keep three goals distinct:

- Semantic conversation continuity is the default requirement, including supported cross-LLM/API continuation from completed turns.
- Resuming an unfinished provider exchange additionally requires its protocol-specific identities, ordering, signatures, or other continuation state. Some of these fields must be retained exactly and may not be portable.
- Exact historical request reproduction is outside this refactoring's scope, not a prerequisite for ordinary continuation. Do not introduce historical request/configuration snapshots solely for this purpose; preserve existing debug facilities. Required provider continuation data remains in scope.

Creating fresh queries and reconstructing tool results alone does not establish this guarantee. The message contract, adapters, and restart verification below must cover the complete context.

### Complete semantic payloads

Persist normalized model-context data separately from designer-facing display content for system/developer instructions, user prompts, assistant answers, tool requests, and tool results. Preserve meaningful structured assistant content even when the application extracts a smaller answer through a JSON answer path or renders it as Markdown. Monitoring previews must not substitute for retained content.

A combined tool message must retain the assistant content accompanying its calls, meaningful ordering, and any required continuation metadata. One designer-facing tool message can expand into different valid protocol structures. The earlier `calls` / `results` example illustrates only the execution portion, not the complete assistant-message contract. For example, a completed Claude tool exchange may become Chat Completions assistant/tool messages while preserving its text, arguments, and results without reproducing Claude's block layout.

Store the historical LLM-facing function name, original call ID, explicit call order, arguments, and batch/exchange identity. An application alias alone is insufficient if the tool later changes or disappears. Preserve JSON value types, including `{}` versus `[]` and nulls, as well as raw arguments separately from parsed execution arguments where they differ. Original argument-string formatting need not survive cross-API rendering, but its values and meaning must. For external MCP requests, the fidelity boundary is the SDK-visible argument data as specified below; do not claim to recover distinctions already discarded by the SDK. Retain original call IDs in storage; if a target API requires remapping, map calls and results consistently without changing their stored identities. Scope correlation by message or exchange as well as call ID.

### Provider-specific continuation

Use normalized messages and typed results for portable semantics, supplemented by API-namespaced data where required for provider-specific resumption. Response adapters must capture meaningful content and required continuation fields before flattening or discarding output. Persistence stores data, not live SDK objects; the corresponding API adapter interprets provider extensions.

The existing APIs impose different representations:

- Chat Completions uses assistant messages with `tool_calls` and correlated `tool` messages. Preserve semantic assistant content beyond a display-only answer and maintain valid call/result groups.
- Responses uses input/output items and may require additional continuation items or identifiers. Its current text/function-call normalization must not discard meaningful content or reasoning-related continuation data required by the selected model/API mode.
- Claude Messages uses assistant content blocks with `tool_use` and user-side `tool_result` blocks. Preserve meaningful ordering and any signatures or continuation fields required by the selected mode, without imposing that block layout on other APIs.

Provider-specific continuation data must not be fabricated or forwarded blindly to another provider. It is not a requirement to expose private model reasoning. When continuing a completed turn through a different API, use the portable semantic history; omit provider-only transport state only where it is not needed to preserve meaning or satisfy the target protocol. If required content cannot be represented, report the limitation rather than silently dropping it.

Switching providers during an unfinished exchange is a separate compatibility decision, not an automatic translation. If the selected adapter cannot faithfully represent required nonportable continuation state, return an explicit compatibility error and preserve history unchanged. Do not automatically reset the conversation, start a replacement conversation, or drop incompatible content. This decision belongs to the adapter, not a blanket rejection by the conversation loader. If remote provider state is used, define its durability and a store-backed alternative where the protocol permits one; an expiring response ID alone is not durable conversation history.

Future APIs cannot be guaranteed before their contracts are known. New adapters declare supported semantic content types and continuation capabilities. Preserve unknown extension data without claiming to understand it. API switching remains subject to authorization, owner/version checks, and target capabilities, but original wire-format equality is not a requirement.

### Attachments and historical configuration

Retain attachments through durable, authorized references with message association, meaningful position, MIME type, filename, and original content. The target adapter may use a different supported encoding or block layout, but must not associate an old file with a new prompt or replace its content with a mutable source file. Redacted, expired, inaccessible, or unsupported required attachments must produce an explicit continuation limitation.

Restore historical messages, calls, and results without resolving or invoking the original tools. Do not regenerate historical instructions or tool identities from changed configuration. Current tool availability controls new execution, not whether old context can be read. A new call targeting an unavailable tool is a new execution failure, not a reason to rewrite historical results.

Keep stored messages, result UXON, and provider extensions readable through compatible readers or migrations that preserve meaning, without introducing payload-version markers. Ordinary continuation may use a different supported model and its current request settings; it does not require historical tool-schema or configuration snapshots. If exact historical request reproduction is implemented, retain those snapshots and a compatible serializer separately, without live historical tool instances.

### Restart and incomplete histories

On a later user prompt, reload and authorize the conversation in a new Workbench scope, append the prompt once, and create a fresh query bound to that turn's context boundary. The selected adapter renders eligible prior messages and typed results in its own protocol, including prior final answers and completed tool rounds. Do not depend on old queries, cached results, or mutable handler state. Keep new response and usage metadata local to the new exchange.

History selection must retain meaningful order and protocol-valid call/result groups. Do not silently remove unmatched calls/results, invent a successful completion, or rerun tools to repair a partial batch. For both legacy and new incomplete or completion-unconfirmed histories, load retained messages unchanged and expose their completeness to the adapter. The adapter decides whether and how it can continue; the loader must not reject continuation solely because history is incomplete. Legacy readers can only recover retained content; migrations cannot invent discarded information or required provider state.

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
- `isFailed()` is documented as forbidding delivery to the LLM and currently uses a critical-or-higher threshold. Change it to the agreed `ERROR`-or-higher tool-diagnostic threshold without prohibiting delivery of failure results.

## Class structure

The following inventory groups related classes and interfaces by responsibility. "Changed" means an existing contract or implementation changes; "new" identifies an expected addition. Where no class name is agreed, the entry describes the required responsibility without prescribing another standalone class.

| Class or group | Change | Distinct role and responsibilities |
| --- | --- | --- |
| `AiConversation` / `AiConversationInterface` | Changed | Own authorized, handler-version-bound persistence and ordered history for agent and MCP conversations. Allocate message sequences, create/enrich combined tool messages, write individual call details, retain attachments, reconstruct typed messages/results, and provide designer-facing display content and diagnostic counts. Supply detached history snapshots without selecting model context, invoking tools, or rendering provider requests. |
| `AiMessage` / `AiMessageInterface` | New implementation; extended interface | Represent one persisted timeline entry, with identity, sequence, display text, structured content, ordered calls, correlated typed results, and completeness information. Own lossless `exportJson()` / `importJson()` for `AI_MESSAGE.DATA` only, including typed-result export/reconstruction. Do not perform database access or protocol rendering. |
| `AiFactory` and result reconstruction factory mapping | Changed; new mapping | Create conversations for any `AiTaskHandlerInterface` with explicit source/development/connection context. Reconstruct only trusted, supported result classes using the current Workbench and their UXON import contract, never arbitrary stored class names or original tool instances. The mapping's placement is not yet prescribed as a separate factory class. |
| `AbstractAiTaskHandler` / `AiTaskHandlerInterface` | Changed | Provide the shared `callTool()` entry point for configured-tool resolution, invocation, timing, before/after events, and failure normalization. Accept `TaskInterface` and return typed results. Do not own conversation persistence, the LLM loop, or MCP serialization. |
| `GenericAssistant` | Changed | Own agent instructions and LLM orchestration. Coordinate batch/detail persistence around inherited `callTool()`, create a fresh query for every connector submission, and retain actual results when completion logging fails. Delegate provider rendering to adapters. |
| `McpServer` | Changed | Expose connectionless endpoint configuration, including conversation grouping scope and inactivity timeout. Use the same handler execution contract without introducing prompt or LLM-connection requirements. |
| `AiToolCall` / `AiToolCallInterface` | Changed | Carry historical call identity, tool name/optional alias, original typed arguments, optional raw argument text, and separately normalized execution arguments. Keep call metadata separate from result values and correlate calls within their message/batch. |
| `AiToolResultString` / `AiToolResultInterface` and other result implementations | Changed | Own typed values, durable failure state, ordered error/warning messages, appendix references, captured remediation, and result-specific UXON export/import. Receive Workbench directly where needed; retain exceptions only for fresh execution. Require neither a live tool nor call metadata, and do not own LLM serialization. |
| AI tool implementations in GenAI, IDE, and other affected producers | Changed as required | Continue implementing the actual capability through `invoke()`. Migrate result construction to the decoupled result contract and preserve typed values and diagnostics. Do not duplicate the handler's shared invocation lifecycle or conversation logging. |
| `AiQuery` / `AiQueryInterface` | Changed | Represent exactly one connector exchange with a detached typed history snapshot, conversation access, and fresh-result overlays correlated by message/call identity. Keep request context stable and response/usage state exchange-local. Perform no database history loading or provider-specific context selection. |
| Completions, Responses, and Claude request/response adapters | Changed as a group | Request adapters select eligible context, serialize typed content/results, enforce target protocol rules, and report continuation limitations. Response adapters capture full semantic output, calls, usage, and required provider continuation data before display extraction. Neither side owns persistence or tool invocation. |
| `OpenAiConnector`, `ClaudeConnector`, and affected connector subclasses | Changed as required | Submit each fresh query through the appropriate adapters and attach only that exchange's response and usage metadata. Keep transport/provider concerns outside conversation storage and shared tool execution. |
| `AiResponse` and its response interface | Changed | Expose the conversation and only the messages generated for the response turn, including live typed overlays when persistence failed. Preserve turn boundaries and generated identities so concurrent turns do not contaminate consumers or metrics. |
| `AiToolMcpHandler` | Changed | Recreate authorized request state in a per-call Workbench scope, coordinate detail writes and combined-message completion around the handler's `callTool()`, and preserve execution results independently of persistence or delivery failures. |
| `AiToolMcpAdapter` | Changed | Retain MCP tool definitions, argument normalization, and typed-result conversion to official SDK `CallToolResult`. Stop invoking tools directly; protocol conversion happens after the handler has obtained the typed result. |
| MCP SDK event integration and operation-local correlation bridge | New integration/plumbing; class placement to be determined | Observe request/session identity, resolve conversation grouping, and commit initial messages before SDK invocation. Correlate SDK events with tool handling using request-local scalar identifiers, capture observable SDK rejections, prevent execution after initial persistence failure, and clean up operation-local state. Retain no long-lived Workbench, handler, tool, or conversation objects. |
| `AiMcpTask` | Changed | Carry explicit MCP request/correlation context needed by the per-call execution path, in addition to endpoint identity. Remain a `TaskInterface` implementation, not an agent prompt or conversation owner. |
| `AiMessageTypeDataType` and the individual-call state enum data type | Existing message type updated as needed; new state type | Keep message types authoritative, including unresolved legacy types. Define individual-call `IN_PROGRESS`, `SUCCEEDED`, and `FAILED` through the platform enum pattern. Do not introduce combined-message lifecycle states or a competing provider-role hierarchy. |
| `ToolCheckTestMetric`, `ToolResponseTestCriterion`, and related response consumers | Changed as a group | Inspect current-turn typed messages, ordered calls, results, and durable diagnostics rather than wrapper objects, flattened strings, or the whole conversation. |
| Existing conversation/detail monitoring and attachment-access components | Changed as required | Present shared agent/MCP history, mixed per-call outcomes, completion-unconfirmed messages, correlated errors, and authorized full retained content. Keep bounded display previews separate from storage and adapter inputs; reuse existing attachment storage and authorization rather than introduce another storage hierarchy. |
| `AiToolCallResponse` | Removed | Migrate transport and inspection consumers to typed results and message-owned call correlation before deletion. Do not introduce a replacement call/result wrapper. |

`AiToolResultData` is future work: define its nested `data_sheet` UXON contract here, but do not require the class to be implemented in this refactoring. `ToolBox` keeps its collection/configuration role. There is no separate tool executor, JSON helper, provider-message hierarchy, or usage-record class introduced by this design.

## Implementation plan

### Phase 1: Shared logging contracts

Generalize owner and factory parameters, introduce `getTaskHandler()`, and define typed message inputs using `AiMessageTypeDataType`. Remove mandatory connection/model/query dependencies from shared persistence, retaining the optional LLM-response query input for metadata extraction. Preserve exact-version and user checks. Add `TYPE` and `TOPIC_ID`, define their correlation scope, and make development mode explicit independent context. Establish typed-result export/import, the decoupled result constructor with all GenAI/IDE producer migrations, durable diagnostics, and message-owned lossless persistence through `exportJson()` / `importJson()` in this phase so Phase 2 never writes non-reconstructable new results. Add only the remaining schema changes required for the agreed message contract.

### Phase 2: Combined tool messages

Implement `saveToolCalls()` and `saveToolResults()` for one message per requested batch, using stable message and call identities. Commit before any invocation and enrich the same message once all calls have returned or failed. Combined messages retain start/end times without a lifecycle-state field. Replace the individual `AI_TOOL_CALL.FAILED` flag with `IN_PROGRESS`, `SUCCEEDED`, and `FAILED`; create each linked record immediately before invocation and update it immediately afterward. Persist authoritative batch results independently of per-call completion-write failures. Add concurrency-safe sequencing and distinct handling for initial, completion, and detail-write failures. Adapt monitoring, drill-down, and embedded diagnostic counts with the new write path. Deliver the split-message SQL migration with compatibility handling for incomplete histories before enabling the new reader/writer contract.

### Phase 3: Shared execution and MCP logging

Move shared tool resolution, invocation, timing, events, and failure normalization into `AbstractAiTaskHandler::callTool()`, exposed through `AiTaskHandlerInterface`; agent and MCP callers coordinate the batch persistence lifecycle around that method. Do not add a separate executor class or runtime execution responsibilities to `ToolBox`. Adapt the agent, split MCP normalization/conversion from invocation, and introduce durable session correlation through `TOPIC_ID`. Make both paths produce the same tool-message structure while preserving their separate task types, authorization, protocol rules, and Workbench lifecycles. Capture tool-specific diagnostic context during execution and keep call/result correlation in conversation data rather than introducing a new wrapper.

### Phase 4: Query and adapter separation

Integrate the Phase 1 typed-result export/import and diagnostic contracts into normalized queries and adapters. Define the nested `data_sheet` contract for future `AiToolResultData` without requiring that new result class to be implemented in this refactoring. Pass the conversation through the query and move history selection and LLM-facing serialization into API adapters, not the agent or persistence layer. Update every supported request/response adapter. Keep typed results until request rendering, deduplicate fresh and persisted data by identity, and preserve protocol-required call/result ordering and continuation metadata. Verify identical adapter output for fresh and reconstructed results.

Change agent orchestration to construct a new `AiQuery` for every connector submission, including all tool continuations. Bind each query to its exchange's context boundary, preserve cumulative eligible history and typed results, and isolate response and usage metadata between instances.

### Phase 5: Compatibility and completion

Complete direct `AiToolResultInterface` transport through `AiQuery` and its adapters, document the conversation-backed call/result read contract, and migrate `AiResponse` consumers. Then delete `AiToolCallResponse`; neither query transport nor the read API introduces a replacement wrapper. Remove obsolete split-message APIs, not the new batch-save APIs. Verify migration and compatibility readers against complete, incomplete, and ambiguous legacy histories; preserve reporting and update prototype documentation in both language trees when implementation changes their behavior.

Deliver each phase with focused verification before layering the next. Do not claim a performance improvement from the changed write pattern without measurements.

## Technical contracts

### Conversation and message API

Use the existing interfaces and extend them rather than add a second hierarchy for provider messages. Factory creation becomes:

```php
AiFactory::createConversation(
    AiTaskHandlerInterface $handler,
    ?string $conversationId = null,
    array $context = []
) : AiConversationInterface;
```

New-conversation context contains `type` (`agent` or `mcp`), optional `topic_id`, explicit `devmode`, and optional connection identity supplied by the caller. The agent factory supplies agent context; the MCP boundary supplies MCP context. Continuation validates persisted source, owner, exact version, and authenticated user instead of replacing them from caller input. Development mode is persisted independently of connection capabilities; existing-conversation context cannot silently change its statistics classification.

The shared read/write signatures are:

```php
getTaskHandler() : AiTaskHandlerInterface;
getMessages(?string $type = null, ?int $afterSequence = null, ?int $throughSequence = null) : array;
saveToolCalls(array $calls, array $data = []) : string;
saveToolResults(string $messageId, array $results, array $data = []) : void;
saveToolCallStart(string $messageId, AiToolCallInterface $call, array $data = []) : string;
saveToolCallEnd(string $recordId, AiToolResultInterface $result, array $data = []) : void;
```

- `getMessages()` returns `AiMessageInterface[]` in ascending sequence order, with an exclusive lower and inclusive upper sequence boundary. A null boundary is unbounded. Validate the optional type using `AiMessageTypeDataType`.
- `$calls` is an ordered list of `AiToolCallInterface`; retain the existing `getCallId()` name, not a competing `getId()`. IDs must be unique within a new batch, not across the whole conversation. Calls carry the historical function name, optional application alias, original typed arguments, optional raw argument text, and separately normalized execution arguments.
- `$results` maps call IDs to `AiToolResultInterface` in the PHP API. Persist it as a JSON array with explicit `call_id` metadata on each exported result entry; the runtime index does not define the storage shape. `$data` contains normalized content, provider extensions, usage where applicable, and batch/per-call execution timestamps and durations. It never contains a query, tool, Workbench, SDK object, or exception object. Validate correlation against the message's stored calls; do not pair by result-array position.
- `saveToolCalls()` returns the persisted message UID. `saveToolCallStart()` returns the detail-record UID. Each completes its short transaction before returning. Start failures throw; the caller aborts a batch for a combined-message failure but reports and proceeds for a detail-record failure.
- `saveToolResults()` and `saveToolCallEnd()` update those existing UIDs, never create replacement completion records. Persistence failures throw to the coordinating caller, which applies the agreed non-fatal completion/reporting policy without losing the in-memory results. If detail creation failed, skip its completion update rather than insert a repair record.
- Timing uses wall-clock start/end instants for inspection and monotonic elapsed milliseconds for duration. Retain timing in combined call data even when detail persistence fails. A batch end time is written only with its completed result set.

Extend `AiMessageInterface` with `getUid(): string`, `getConversationId(): string`, `getSequenceNumber(): int`, `getData(): UxonObject`, `getCalls(): array`, `getToolResults(): array`, and `isBroken(): bool`. Keep existing `getText()` for designer-facing content and `getRole()` for the stored message type; neither defines model-context content. Calls are returned in stored order, with results keyed by call ID. Message types unrelated to tool exchanges return empty call/result collections; legacy tool-request/result types retain any recoverable calls/results. Completeness checks identify missing/mismatched results, undecodable required content, and unavailable required attachments without consulting current tool configuration. An unfinished message can be broken for reconstruction while its execution is still running; this is not a process-liveness signal.

Also expose `exportJson(): string` and `importJson(string $json): void` through `AiMessageInterface`, implemented by `AiMessage` for persistence only. These methods serialize and restore the `AI_MESSAGE.DATA` payload, not the entire database row or an LLM/MCP request. `AiConversation` writes the exported JSON string and passes the stored string back to the message on loading; it remains responsible for database access, authorization, sequencing, and separately stored message fields. API adapters retain ownership of protocol serialization.

`AiResponse` exposes `getConversation(): AiConversationInterface` and `getMessages(): array` restricted to the messages generated for that response turn. Capture the turn's lower sequence boundary before saving its user prompt and its upper boundary when the response is finalized; track generated message UIDs as well so interleaved turns cannot contaminate metrics. Retain fresh typed message/result overlays in the live response if persistence failed, without claiming those results are recoverable after restart. Migrate `ToolCheckTestMetric` and `ToolResponseTestCriterion` to iterate those tool messages and calls, not the entire conversation and not a replacement wrapper. Remove `getToolCallResponses()`, `setToolCalls()`, and `AiToolCallResponse` after these consumers migrate.

### Stored fields and lossless serialization

Keep `ROLE`, `MESSAGE`, `DATA`, `SEQUENCE_NUMBER`, and existing nullable LLM metadata. Add nullable `STARTED_ON` / `FINISHED_ON` message attributes for combined-tool timing; other message types need no lifecycle fields. Add conversation `TYPE`, `TOPIC_ID`, `NEXT_MESSAGE_SEQUENCE`, and nullable `MCP_GROUP_KEY` for concurrency-safe allocation and grouping. Store grouping activity and original session/chat identifiers in conversation `DATA`; grouping must not use `MODIFIED_ON`, which also changes for titles and ratings.

Individual calls retain their current fields, with `STATE` replacing `FAILED` and explicit `STARTED_ON` / `FINISHED_ON`. Implement the three values through the platform's enum data-type pattern, not a database-vendor-specific enum. Retain `EXECUTION_TIME_MS` and human-readable `RESULT`. Replace the existing unique `(conversation, call_id)` constraint with `(message, call_id)` so clients can reuse a request/call ID in later messages without overwriting history. Continue linking each detail record to its combined message and conversation.

The combined `DATA` contains:

- `content`: ordered normalized assistant content accompanying the calls, not the monitoring Markdown.
- `calls`: an array in original request order, with `call_id`, `tool_name`, optional `tool_alias`, typed `arguments`, optional `raw_arguments`, separate `execution_arguments`, and collected timing in each entry. Array order is authoritative; do not store a separate order list.
- `results`: an array whose entries contain explicit `call_id` correlation metadata alongside result-class-owned exported UXON, including `class`, data-type UXON, retained value, `failed`, ordered `errors` / `warnings`, appendix references, and captured remediation text. Result-array order does not determine call pairing or request order.
- `provider`: data keyed by API identity (`openai.chat_completions`, `openai.responses`, `anthropic.messages`). Retain ordered provider items/blocks, response/item identities and required signatures/continuation fields without treating them as portable message roles.
- `diagnostics`: correlation entries with a stable diagnostic ID, source, severity, optional call ID, and the separate error-message UID where saved. Result diagnostics and standalone errors share that ID; `ERROR_LOG_ID` exists only on the standalone `ERROR` message, never in the embedded diagnostic.

Validate unique call IDs within each batch, unique result IDs, and that every result references a stored call. Missing results remain representable for incomplete batches. New writes reject duplicate or unknown IDs explicitly; imported malformed or legacy data remains subject to the existing completeness/compatibility rules rather than silently overwriting entries. Message import separates each result entry's `call_id` from the result UXON before reconstruction and may build an ID-keyed runtime index. This metadata does not add call identity to typed result objects or introduce a replacement response wrapper.

Ordinary messages also retain ordered normalized content independently of their display text. Content distinguishes text, structured JSON, and attachment references; system messages can retain the original instruction role where required. Preserve full structured assistant answers before extracting a configured answer path.

Implement lossless JSON encoding/decoding in `AiMessage::exportJson()` / `importJson()`: explicit JSON errors, no numeric-string coercion, and object-preserving decoding. Do not introduce a separate JSON helper or change global `UxonObject` defaults for this refactoring. Continue using `UxonObject` for message data and typed-result export/import, but do not route semantic payload serialization through `UxonObject::toJson()`, which enables `JSON_NUMERIC_CHECK`, or `UxonObject::fromJson()`, which decodes associatively. Construct exported/imported UXON from validated data while preserving nested `stdClass` objects and lists; never recursively convert arbitrary semantic objects to associative arrays. Message export includes each result type's exported UXON, and import reconstructs typed results through the validated result factory without invoking tools. Verify that the DataSheet/database persistence path retains the JSON string without re-encoding it through coercing UXON methods. Test `"00123"`, numeric-looking identifiers, `{}`, `[]`, null, booleans, nested objects, and Unicode through a message export/store/load/import round trip. Non-JSON values require their supported result type's explicit UXON export; do not serialize arbitrary PHP objects or use PHP unserialization.

`AiToolResultInterface` extends `iCanBeConvertedToUxon`, including its `getUxonSchemaClass()` requirement, and declares `getValue(): mixed`, `getErrors(): array`, `getWarnings(): array`, and `isFailed(): bool`. `getErrors()` / `getWarnings()` return ordered message strings with any captured remediation needed for stable rendering. Keep original exceptions fresh-only through `getExceptions()`. Decouple the string-result constructor to `WorkbenchInterface`, value, optional data type, appendix, and fresh exceptions; remove tool and call-argument parameters. Preserve explicit display methods for text consumers, but adapters own LLM serialization.

Add a result factory mapping trusted, supported result classes to constructors using the current Workbench. Initially support `AiToolResultString`; future result types register deliberately. Import cannot instantiate arbitrary stored class names. Keep `failed` and diagnostic messages authoritative after reconstruction, without manufacturing exceptions or resolving changed hint definitions. Rejected calls explicitly fail even when their corrective diagnostic has warning severity.

### Transactions and MCP grouping

Use DataSheets for runtime reads/writes. For message allocation, read `NEXT_MESSAGE_SEQUENCE`, then conditionally update the conversation filtered by its authorized UID and the expected counter, checking the affected-row count. Create the message with the reserved sequence in the same short transaction. On contention, roll back and re-resolve allocation with a small bounded retry; exhaustion is an explicit persistence failure. This allocation retry is not a completion retry or tool retry. Add a unique `(conversation, sequence_number)` constraint after normalizing legacy order. A transaction around an unprotected maximum-sequence read is not sufficient.

`MCP_GROUP_KEY` is a nullable unique hash of an unambiguously encoded tuple of source, authenticated user, exact endpoint-version identity, configured scope, session identity when scope is `session`, and optional chat identity. It identifies the current reusable conversation, not authorization. Historical conversation records retain their original correlation metadata when their active grouping key is cleared. Enforce uniqueness only for non-null keys; use a filtered unique index on SQL Server so unrelated inactive/agent conversations may all have null keys.

Resolve grouping and extend `last_activity_at` at request receipt, not completion. For time-only grouping, reuse when elapsed inactivity is less than the configured positive timeout; at the boundary or later, atomically clear the old active key and create a new conversation carrying that key. Use conditional updates, short transactions, and the unique key to resolve competing creators without executing any tools twice. Persist errors explicitly if bounded allocation contention cannot be resolved. Chat-identified groups do not expire. Do not let a late completion move activity backward. Validate the timeout as a positive integer and the scope as `session` or `user`; invalid configuration is an explicit configuration error.

### Verified SDK integration

The installed official SDK provides these usable boundaries:

- `Mcp\Event\RequestEvent`, `ResponseEvent`, and `ErrorEvent` expose the request and its session; register a dispatcher through `Builder::setEventDispatcher()`.
- `SessionInterface::getId()` supplies a UUID. `Request::getId()` supplies a string or integer request ID, and `getMeta()` exposes optional client metadata. Keep request-ID type as well as value; it is not a chat identifier or a globally unique execution ID.
- `Mcp\Server\Handler\Request\CallToolHandler` rejects unknown tools and invalid schemas before invoking the configured tool handler. `Mcp\Server\Protocol` dispatches returned protocol errors through `ErrorEvent`, so those rejections can be observed without replacing SDK validation.
- The explicitly registered `ToolHandlerInterface` receives only arguments and a `ClientGateway`; its gateway has no public identity accessor. Do not reflect into private gateway fields or read session-global "last active request" metadata, which can refer to another concurrent call.

Use the SDK request event to authenticate a fresh Workbench scope, resolve the conversation, and commit its initial combined message before the SDK tool invocation. Pass only scalar correlation identifiers through an operation-local bridge keyed by the actual request identity, isolated per Fiber where necessary. Close that scope before handing control back to the SDK; recreate the authorized conversation in the tool handler's existing per-call scope. The handler coordinates detail writes and combined completion around execution. SDK response/error events finalize only requests rejected before handler execution, avoiding a second completion write for handled calls. Conversion errors after execution preserve the already retained typed result and tool outcome.

Keep a request-local marker that initial authoritative persistence failed and prevent invocation for that request; do not merely report the failure and let the SDK proceed. A protocol error is returned through the official SDK path. Malformed input rejected before a `CallToolRequest` exists is not promised as a conversation tool entry: use SDK/platform diagnostics without inventing tool identity or granting endpoint access.

The SDK has no dedicated chat-ID field. Define optional client-supplied `_meta["axenox.GenAI/chat_id"]` as the explicit GenAI chat correlation contract; accept a non-empty string only. Do not infer it from progress tokens, trace IDs, request IDs, or client name/version. Clients not sending this extension use the agreed inactivity grouping; do not claim VS Code sends it automatically. Persist durable native SDK session UUIDs where available. Modern stateless SDK handling creates a fresh session per request, so that UUID is not a usable session-grouping boundary: use an immutable STDIO connection-lifetime UUID there and retain the native request session separately. Never use a per-tool-call UUID or a user-wide fallback for session grouping. Give each MCP execution its own generated call identity and retain the original typed JSON-RPC request ID separately, so a client reusing a request ID later is not mistaken for a storage retry.

Log SDK-visible MCP arguments and document the original JSON-shape limitation. The SDK message factory decodes JSON associatively, so its request object cannot preserve every nested `{}` / `[]` distinction. A transport `onMessage` callback exists for handshake-era traffic, but modern STDIO routes directly to `StatelessProtocol::handleInline()` and bypasses it. Do not introduce partial raw-transport capture, an SDK fork, or copied framing to overcome this in the current refactoring.

Retain the SDK-exposed arguments separately from normalized execution arguments, identifying their fidelity boundary as SDK-visible rather than original-wire JSON. Do not present re-encoded SDK arguments as the original raw argument text or guess lost object/list distinctions. Where a caller actually supplies original argument text, retain it as such. This limitation applies to external MCP input, not the lossless serialization and response capture required for this framework's own LLM exchanges. No Workbench, handler, tool, or conversation instance is retained in the long-lived bridge; remove request-local identifiers/data when the operation completes.

### Legacy migration and diagnostics

Migrate all supported SQL dialects and synchronize fresh-install schema definitions and model exports, using the existing request-message UID as the combined UID so linked detail records retain their message link. Preserve old result-message identity and original retained request/result data under `legacy` metadata before removing a reliably merged result row. Old `tool calling` remains a recognized legacy enum value for unresolved entries, but new writers produce only `tool` batches.

Pair only within one conversation, in stable `(sequence, created_on, UID)` order. A candidate request/result pair may have intervening standalone warnings/errors but not another tool batch, user turn, or final assistant response. Require matching explicit call IDs and order from retained payloads or unambiguous linked records; names or adjacency alone are insufficient. Preserve call-index order and do not guess a pairing when identifiers disagree, duplicate, or are absent. Leave unresolved rows and links intact.

For matched calls, use retained string results as `AiToolResultString` values without parsing JSON-looking result text. Prefer an explicitly retained string value; otherwise an unambiguously linked detail `RESULT` is a legacy string source. The current wrapper can serialize a private result object as `{}`; do not treat that empty object as a successfully retained result or invent content from monitoring Markdown. Keep such histories incomplete when no actual result string survives. Structured assistant content or attachments discarded by old code cannot be recreated by migration.

Move known usage onto the surviving response/batch message once. If both legacy rows contain usage and their attribution cannot be established, leave the pair unresolved rather than sum or discard uncertain usage. Preserve null versus known zero. Carry explicit legacy diagnostic message collections into the new result contract where correlation exists; keep uncorrelated standalone warnings/errors visible without assigning them to guessed calls. Never parse rendered diagnostic prose to manufacture exception objects or diagnostic identities. Do not create a second standalone error for an already retained legacy error.

Map old detail `FAILED = 1` to `FAILED`; `FAILED = 0` with a retained completed result to historical `SUCCEEDED`. Missing completion evidence maps to `IN_PROGRESS`. Preserve that this reflects the old critical-error threshold rather than retroactively inferring new severity from result prose. New calls use the agreed ERROR-or-higher threshold. Normalize duplicate/null legacy message sequence values deterministically while preserving their established order, then initialize the next-sequence counter and install the new uniqueness constraints.

### Query snapshots and API capability matrix

Every new query captures a detached ordered snapshot of typed conversation messages through its sequence boundary, including the completion data visible at capture time. A sequence cutoff alone is insufficient because a combined message can later be enriched without changing its sequence. Subsequent database writes or result mutations must not alter an earlier query's request context.

The query exposes `getConversation(): AiConversationInterface`, `getMessages(): array` for its captured typed inputs, and `appendToolResult(string $messageId, AiToolCallInterface $call, AiToolResultInterface $result): AiQueryInterface`. The agent obtains snapshots from the conversation; the query performs no database reads. Keep pending fresh typed results as an overlay keyed by `(message UID, call ID)` when completion persistence failed. For the same identity, a matching fresh result enriches rather than duplicates the stored message; conflicting correlation is an explicit error. Earlier exchanges remain context, but no previous response adapter, HTTP request/response, costs, or usage state is copied to the fresh query.

All three existing adapters must support completed text/structured-JSON conversations and completed tool rounds in both same-API and cross-API store-only tests:

| Target API | Instructions | Calls and results | Required continuation |
| --- | --- | --- | --- |
| Chat Completions | Native system/developer messages as supported | Assistant tool-call request plus correlated tool messages | Retained assistant content and any required same-API fields |
| Responses | Top-level `instructions` with ordered eligible input items | Function-call items plus correlated function-call-output items | Required output-item identities and continuation items, not only flattened text/calls |
| Claude Messages | Top-level `system` and valid ordered message content | Assistant `tool_use` and user-side `tool_result`, including failure indication | Required block ordering, signatures, and other same-API continuation fields |

Preserve the full semantic assistant response before display extraction. Existing Responses and Claude normalization currently drops non-text continuation information; response adapters must capture it before flattening. Provider extensions are interpreted only by their owning adapter, never blindly sent to another provider. Stable stored call IDs may be remapped for a target API using one consistent per-request call/result mapping, without modifying history.

Text and JSON semantics are the baseline cross-API matrix, not a guarantee of every provider-specific mode. Required unsupported blocks or attachments produce an explicit compatibility limitation. Chat Completions currently rejects file-bearing queries; do not silently flatten attachments to claim support. Responses and Claude retain their supported attachment paths with historical message association. Same-provider unfinished continuations require exact necessary fields; unsupported cross-provider unfinished continuations return the agreed compatibility error.

Adapters receive broken messages unchanged and choose protocol-valid treatment or an explicit compatibility error. Do not implement a loader-level fallback that rewrites all incomplete messages as text. Eligible context excludes separate monitoring/error messages by default; tool-result diagnostics remain attached to their calls. Include all eligible turns without automatic summarization or truncation.

### Attachments, monitoring, and implementation gates

Reuse `AI_MESSAGE_FILE` and its file-backed DataSheet storage for immutable retained contents rather than reread source files. Store an attachment reference with message UID, unique attachment identity/path, original filename, MIME type, position, and content digest; use generated storage names so repeated filenames cannot overwrite previous content. Authorize the owning conversation and then attachment access on each read. A filesystem path or URL alone never authorizes access. Appendices that require retained file content use the same reference contract.

Reads validate presence and retained identity/content; missing, changed, denied, or unsupported required attachments are explicit availability/compatibility failures. Attach files to their historical message and position, not the latest prompt. Reuse existing file-download authorization rather than expose unrestricted static paths. Filesystem and SQL writes are not assumed to be one atomic transaction: track exact newly created attachment paths, clean up only those on a failed message save, and never represent a missing file as a completed retained attachment.

Render diagnostics and arguments as escaped text/code. Honor declared Markdown/HTML result types only through existing safe rendering, never inject raw tool output as trusted HTML. Cap initial monitoring previews at 16,000 Unicode characters with a visible truncation notice and authorized full-content inspection. This preview limit never changes stored data, result length statistics, or adapter input. Both conversation and detail views keep real errors visible without requiring drill-down; error counters count correlated standalone ERROR entries once, warning counters include retained embedded warnings without duplicating legacy standalone entries.

Implementation must add targeted tests for the concrete contracts above: pure result round trips and serializer edge cases, per-request SDK rejection capture and context isolation, all SQL-dialect migrations, concurrent message/group allocation, current-turn response consumers, attachment association/access, and store-only adapter continuation. Keep runtime tests and bilingual prototype documentation in the implementation changes; this roadmap-only change does not claim those gates already pass.

## Open items

No unresolved behavioral choice currently blocks starting the phased implementation. Remaining work is implementation and verification against the contracts above, not permission to relax them. SDK upgrades or target models exposing unsupported required content may reveal a concrete compatibility limitation; report it explicitly rather than silently broaden support claims.

## Verification criteria

- Agent and MCP invocations produce the same typed tool-message structure without requiring an LLM query for MCP.
- Exactly one message is committed before the first invocation in each requested batch; enrichment after all calls preserves its UID, sequence, and original call identities. Separate MCP requests remain separate messages.
- Initial persistence failure prevents the entire batch from running. Caught invocation failures yield typed failure results and do not stop remaining calls. Interrupted batches and failed completion updates remain diagnosable without repeated side effects.
- An unexpected before-event-listener failure prevents the affected tool from running, yields a deliverable failure result and a `FAILED` call record when persistence succeeds, and produces a separate `ERROR` message without stopping other calls.
- An after-event-listener failure preserves the original tool result and execution state, produces a separate operational `ERROR` message, and does not stop other calls or repeat execution.
- An `ERROR`-or-higher tool diagnostic makes `isFailed()` true and the call `FAILED`, including results with useful partial content. Warning-only results remain `SUCCEEDED`; failure results remain deliverable. Operational logging and after-listener errors do not change the tool outcome.
- Individual call writes are attempted and committed immediately before invocation as `IN_PROGRESS` and immediately afterward as `SUCCEEDED` or `FAILED`, with a link to the combined message for drill-down. A failed pre-call write does not prevent invocation. Warning-only results count as `SUCCEEDED`. Combined messages store start/end times without a lifecycle state.
- Post-call detail-write failures do not discard results or invalidate a completed combined message. A failed completion update leaves the individual call `IN_PROGRESS`. Missing or incomplete individual records remain unrepaired for now; conversation evidence and visible errors are retained, and detailed statistics may be incomplete.
- A failed combined-completion write still returns actual tool results, reports the error through the platform logger and a separate conversation error where possible, and leaves the combined end time absent. There is no automatic repair or persistence retry; later loading passes incomplete messages unchanged to the adapter with a completeness indicator, without rerunning tools or silently omitting results.
- Real errors, including detail-write failures, produce separate conversation `ERROR` messages and are reported through the platform logger without requiring drill-down. Only those error messages receive `ERROR_LOG_ID`. Correlation with embedded diagnostics prevents double counting. Invalid-tool-use warnings are not promoted to errors. Reporting failures do not trigger repeated execution or recursive attempts to log their own persistence failures.
- Concurrent calls and out-of-order results retain correct correlation, authorization, and stable timeline order.
- Arguments, typed values, failure state, diagnostics, appendices, timing, and result metadata survive persistence and conversation-backed reconstruction for existing response consumers.
- Tool results round-trip through `iCanBeConvertedToUxon` export/import without invoking tools or rereading source data. When data-sheet results are introduced, their nested `data_sheet` UXON restores a DataSheet object, not a flattened string.
- Restoring and rendering a result succeeds when the originating tool has changed or is unavailable; no tool instance is created or resolved. GenAI and IDE result producers use the decoupled constructor contract.
- Fresh results may expose original exceptions; reconstructed results expose none. `getErrors()`, `getWarnings()`, failure state, diagnostic counters, and adapter-visible remediation text survive reconstruction without serialized exceptions or re-resolving message definitions. Verify multiple `AiToolRuntimeError` and `AiToolRuntimeWarning` diagnostics on one result, retaining all messages in their respective order and counting each entry once.
- Existing split-message histories remain readable and cost/diagnostic reports remain correct without double counting.
- Incomplete or ambiguous legacy entries remain viewable and do not by themselves block continuation or migration of valid histories. Continuation uses retained information without fabricated results, guessed pairings, repeated execution, or protocol-invalid call/result groups.
- Adapter-owned history selection and final wire requests satisfy every supported LLM API, including structured results, failures, batches, and provider continuation data. Conversation access through the query causes neither implicit provider formatting nor duplicate call/results.
- Repeated adapter builds do not mutate results or duplicate metadata, and monitoring text is never blindly used as model context.
- `AiQuery` receives `AiToolResultInterface` instances directly, preserves their separate call correlation, and exposes them to adapters without flattening or response wrappers. All `AiToolCallResponse` consumers are migrated and the class is removed.
- Every connector submission uses a distinct `AiQuery` instance. Verify an initial system/user exchange followed by at least two tool rounds: each continuation retains preceding eligible messages, assistant call requests, and all correlated tool results exactly once. Earlier query request context stays stable, and response, token, and cost metadata belong only to their own exchange.
- With the same adapter and context policy, fresh and reconstructed results produce identical LLM-facing content. Missing retained payloads are explicit replay limitations, not silently substituted previews.
- Store-only resumption preserves semantic content and produces protocol-valid context for each supported target API, including supported cross-API continuation from a completed turn. Different APIs need not produce identical JSON. Preserve exact provider continuation fields only where required; report unsupported mappings or unfinished-state incompatibilities explicitly.
- MCP initialization and tool calls remain protocol-compliant, recoverable failures do not kill the process, and `STDOUT` stays clean.
- Redaction, payload limits, and authorization work consistently for both conversation sources.
- `TYPE` and scoped `TOPIC_ID` correlation do not bypass owner/version/user checks. Development-mode filtering works independently for agent and MCP conversations.