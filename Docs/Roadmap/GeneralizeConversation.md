# Shared AI conversation logging

## Goals

Make `AiConversation` the shared, designer-facing log of interactions with AI, whether the AI runs through a Power UI agent or an external client such as VS Code using MCP. Designers must be able to inspect what the AI requested from the framework and what the framework returned, then improve tools and instructions based on that evidence.

The log contains typed messages. Some describe user input, system instructions, LLM answers, and usage; others describe tool execution, warnings, or errors. It prioritizes convenient representation rather than reproducing every protocol step as a separate message.

Keep three responsibilities distinct:

- `AiConversation` owns persisted interaction history and its designer-facing representation. Reading messages from the conversation, we must be able to restore all data sent to and received from conversation parties. In particular, LLM connectors must be able to regenerate all messages to send to the LLM from the conversation
    - A sub-level of the conversation is the `axenox.GenAI.AI_TOOL_CALL` object, that stores every tool call separately to support deeper introspection and statistics. So while a conversation message stores all tool calls requested by an LLM in a single response (because this is important to recreate the message for the next LLM request), the separate tool call storage will store each tool separately with its result, status, execution time and other metadata.
- `AiQuery` transfers an API-agnostic LLM exchange between an agent and its connector. It is used only when this framework calls an LLM.
- AI connectors and their API adapters translate queries into provider requests and normalize provider responses.

This is a refactoring plan, not a description of already implemented behavior. Proposed API names below are illustrative.

## Architecture

### Shared conversation ownership

Generalize `AiConversation` and the relevant factory methods to accept `AiTaskHandlerInterface`. Both agents and MCP endpoints already expose identity, exact version, configured tools, and Workbench access through that interface.

Expose the owner as `getTaskHandler()` and migrate `getAgent()` callers. Retain exact handler-version validation and authenticated-user authorization. Do not rename every existing metamodel alias merely to change the PHP contract; existing agent/version relations can initially represent both kinds of configured handler.

Record the source (`agent` or `mcp`) explicitly in a new `TYPE` attribute of the conversation. Also add a `TOPIC_ID` attribute, which holding an external identifier for the conversation: e.g. MCP session id, ticket ID for autonomous ticket workers, etc. Agents explicitly supply connection information where applicable. Message model, tokens, and cost are optional facts, not dependencies on the owner's capabilities. A connectionless conversation must not call `getConnection()` or manufacture missing LLM data.

The development-mode is independent context information, that can be set regardless of the conversation type. It allows to exclude tests and debug runs from statistics. It is not implemented properly yet, though. 

An agent conversation may contain system instructions; an MCP conversation need not contain any. System-prompt rules remain an agent-runtime concern rather than a prerequisite for shared logging.

### Typed messages

The existing AiMessageTypeDataType contains all conversation message types. Change it when adding or removing types. 

Each message has stable identity, conversation identity, sequence, timestamps, display content, and structured data. LLM participant roles and provider-specific message formats are not the definition of a conversation message.

Conversation persistence accepts message data, calls, and results only requiring `AiQueryInterface` if the message comes from the LLM and expects token counts or other metadata. 

### One message per tool request

Each time on or more tool calls are requested from the workbench, we record an conversation message of type `tool`. A tool request from an LLM may include multiple tool calls, while an MCP call will target a single tool (note sure: can there be multiple tools in an mcp calling?). A conversation message represents the entire request and the corresponding response. The individual tool calls are additionally logged in the DB as via `axenox.GenAI.AI_TOOL_CALL` object. This two-layer structure serves multiple purposes:

- keep the conversation condensed and easy to read 
- simplify reconstructing the message chain to be sent to the LLM if the conversation continues
- supporting tool statistics and analysis to continuously improve tools and analyze LLM "chain-of-though"

Create one conversation message immediately before handling the received batch of tool calls, then enrich that same message with tool results after all tools ran or failed. Do not append a separate result message. 

The lifecycle is:

1. Persist and commit the call identity, sequence,  in-progress state and tool list with arguments by calling `$conversation->saveToolCalls()`  and remember the returned message UID. 
2. Invoke each tool:
	1. Fire `OnBeforeAiToolCallEvent` 
    2. Call tool `invoke()` and record the result
	3. Fire `OnAiToolCallEvent` 
3. Update the same message with results of the tool calls including completion time, duration, diagnostics, failure state, and combined display content: `$conversation->saveToolResults()`. This should also record the individual `AI_TOOL_CALL` entries. 

An illustrative flow is:

```php
$messageId = $conversation->saveToolCalls($calls);
$results = [];
foreach ($calls as $call) {
    $results[] = $tool->invoke($arguments);
}
$conversation->saveToolResult($messageId, $results$);
```

Use the existing message type `tool` for the new combined tool messages. Serialize calls and results as UXON in separate properties of the data JSON in the message:

```
{
	calls: [{}], 
	results: [{}]
}
```

Write an SQL migration to transform existing `TOOL` and `TOOLCALLING` message pairs into combined `TOOL` steps in order to allow agents to continue future conversations after the refactoring. 

Use the existing `axenox.GenAI.AI_TOOL_CALL` object as structured execution detail linked to the combined message. These individual tool call records show status and metadata.

Retain raw client arguments separately from parsed execution arguments where they differ. Correlate results using stable message identity and call identity, not array positions. Distinguish repeated calls from retries or duplicate writes.

### Tool error handling

Since an LLM should still receive tool results even if they include errors, we need to catch all errors during tool invocation and categorize them. 

- Committing the tool message before invocation ensures it remains logged even if something goes entirely wrong. 
- Errors caught inside tools should be caught and included in the tool result. It is important to distinguish between non-critical logical errors, that result from bad calls (these can be avoided by trying to call the tool again with different arguments) and truly unexpected errors that result from broken tools. Neither should stop other tool from the same request from being executed. Instead, the broken tool result will explain the error to the caller.
- Individual AI_TOOL_CALL entries should be made for successful tool calls and for failures with corresponding results and metadata. It is OK to write them at the very end of every tool batch as they serve statics primarily.
- Errors during logging individual AI_TOOL_CALL entries are non-fatal - tool results should still be returned to the LLM. The errors should be logged using `$workbench->getLogger()->logException()`.

### Shared tool execution

Extract shared invocation, timing, event dispatch, and failure normalization from `GenericAssistant` into an executor accepting `TaskInterface`. Use it from both the agent and MCP paths without introducing a prompt dependency for MCP.

Keep conversation persistence coordinated around execution so both paths use the same start/complete semantics. Tool results continue to use `AiToolResultInterface`; do not replace `AiToolCallResponse` with another container duplicating call identity and result fields.

Split MCP argument normalization and protocol result conversion from actual invocation. The MCP handler needs access to the typed result before converting it into `CallToolResult`. Keep protocol behavior at the MCP boundary and use the official SDK.

Tool diagnostics belong to the combined execution message. Standalone warnings/errors remain available for failures outside a tool execution. Define one diagnostics owner so shared execution does not duplicate conversation entries or platform log messages.

### API-agnostic queries and typed results

Remove database history loading from `AiQuery`. Instead pass the entire `AiConversation` to the query and make it available to the LLM API, that converts the query to requests. The API implementation should decide, what conversation messages it will send to the LLM as context and what format it will use (e.g. JSON or string). 

The `AiConversation` class owns conversation storage and provides methods to fetch messages by type. 

Audit history is not automatically model context: errors, monitoring state, and presentation text must not be blindly replayed to the LLM.

Replace provider-shaped message arrays with normalized query messages, call batches, and typed tool results. Retain call IDs, ordering, batch identity, and any continuation data required by providers without making their wire formats the shared contract.

Pass `AiToolResultInterface` instances through the query instead of calling `getValueWithMetadata()` in the agent. For example:

```php
$conversation->saveToolCall($call, $result);
$query->appendToolResult($call, $result);
```

These are independent consumers of the same call/result data. Persistence must not supply the LLM-facing rendering, and query serialization must not supply the audit representation. 

For example, tool results can be represented by different classes: `AiToolResultString`, `AiToolResultData`, etc. They are serialized in different ways for different purposes:

- as string or JSON for LLM - this is controlled by the LLM API adapters
- as UXON for a completely reinstantiatable dump in the data of a conversation message. This is used to read the conversation and instatiate result objects from messages as if they were freshly received by the tool. 
- as string in conversation messages for easy reading (this is a different string, that the LLM gets!)

Provide access to original structured result values by converting them to UXON and saving in conversation message data:

```
{
	call: {
		arguments: {
		
		}
	}, 
	result: {
		class: \axenox\GenAI\Common\AI ToolResultString, 
		type: {
			// $dataType->exportUxonObject() 
		}, 
		value:, 
		appendix: {}, 
		exceptions: []
	} 
	
}
```

Use reasonable UXON depth. We do not need to introduce full UXON representations of exceptions just to fill the array here - save the messages only. Similarly, do not save entire file contents in appendix values - use links instead. In the end, LLM APIs must not care about whether a tool result is "fresh" or reconstructed from conversation data. The contents send to the LLM must be identical in both cases. 

In future we will add more complex tool results like `AiToolResultData` representing a data sheet. In this case, the result UXON should reproduce the original result object (e.g. DataSheet) when loaded from conversation message. 

The current string-returning `getValue()` cannot recover a value already flattened by the result class. 

During migration, assume all existing tool results are of type `AiToolResultString`, do not attempt to parse them. Just use the entire result as value. Do not guess whether arbitrary text is JSON.

Request adapters choose plain text, Markdown, JSON-encoded text, or supported structured content according to the actual API contract. Completions tool messages, Responses function-call output, and Claude tool-result blocks remain adapter concerns. A combined conversation message may expand into multiple protocol items.

Keep diagnostics, appendix items, and failure state separately accessible until rendering. Adapters must not mutate results, duplicate appendix content on repeated builds, or rely on implicit string conversion. Debug views and request snapshots also need explicit serialization.

### MCP conversation identity

Use the same conversation persistence and monitoring interface for MCP. Resolve a durable conversation using endpoint version, authenticated user, and MCP session context; reconstruct it within each per-call Workbench scope.

Retain only immutable identifiers across operations, not a Workbench, handler, tool, or conversation instance. Keep endpoint configuration pinned to its exact version for the process lifetime and keep `STDOUT` protocol-clean.

An MCP session is not necessarily one VS Code chat. Without a client-supplied chat identifier, label the log as an MCP session. Record the requests and results visible to the framework without claiming access to external AI instructions, user prompts, answers, or billing data.

### Usage attribution

Record usage once per LLM exchange, including exchanges that request tools without a final answer. Associate it with a suitable LLM response message or exchange record linked to the timeline; do not copy the same usage totals onto every tool message.

Distinguish unknown usage from known zero usage. MCP tool executions do not acquire invented model or token metadata merely because an external AI requested them. Preserve existing cost reporting during migration.

### Designer experience

Use one conversation monitoring experience for agent and MCP sources. Show combined tool messages with inspectable arguments, result, duration, status, and diagnostics. Refresh the same message when execution completes and distinguish in-progress, failed, and completion-unconfirmed states.

Update warning/error counters to include diagnostics embedded in tool messages without adding duplicate standalone entries. Show LLM usage only where known. Preserve old histories and filters through explicit compatibility handling.

Apply authorization, redaction, safe display rendering, payload limits, attachment access rules, and retention consistently across both sources. Large results may need bounded previews with controlled access to retained details.

## Conflicts with current code

These are implementation gaps to resolve, not competing architectural recommendations:

- `AiConversation` currently requires `AiAgentInterface` and resolves connection, development mode, and model details itself. Its constructor calls `saveNewConversation()`; `AiFactory` delegates creation to it. Remove capability assumptions from that lifecycle.
- Conversation logging methods currently accept `AiQueryInterface`, coupling persistence to LLM exchanges. Replace those inputs with normalized message and usage data.
- The agent persists tool-call requests and batched results separately, using `AiToolCallResponse`, while individual execution records are updated afterward. Replace this with the per-execution create/enrich lifecycle.
- `AiResponse::getToolCallResponses()` exposes wrapper objects to consumers. Migrate those consumers before deleting `AiToolCallResponse`.
- `AiQuery` currently loads persisted messages and uses OpenAI-shaped arrays. Move history composition out and keep provider translation in adapters.
- The agent flattens tool results before query insertion, and `AiToolResultInterface` exposes string-based value access. Introduce typed transport and structured-value access together.
- `AiToolMcpAdapter` currently combines invocation with protocol conversion, and `AiMcpTask` contains only the endpoint selector. Separate execution and add explicit correlation context.
- Existing warning/error counts depend on separate message roles. Adapt aggregation for embedded tool diagnostics.
- `isFailed()` is documented as forbidding delivery to the LLM, while the agent appends normalized failures and MCP returns error results. Resolve this contract ambiguity before unifying failure handling.

## Implementation plan

### Phase 1: Shared logging contracts

Generalize owner and factory parameters, introduce `getTaskHandler()`, define typed message inputs, and remove mandatory connection/model/query dependencies from persistence. Preserve exact-version and user checks. Add source metadata and only the schema changes required for the agreed message contract.

### Phase 2: Combined tool messages

Implement start/complete operations using stable message references and linked `AI_TOOL_CALL` detail. Commit before invocation and enrich immediately after each result. Add lifecycle state, concurrency-safe sequencing, and explicit persistence-failure behavior. Adapt monitoring and embedded diagnostic counts with the new write path.

### Phase 3: Shared execution and MCP logging

Extract the executor, adapt the agent, split MCP normalization/conversion from invocation, and introduce durable session correlation. Make both paths produce the same tool-message structure while preserving their separate task types, authorization, protocol rules, and Workbench lifecycles.

### Phase 4: Query and adapter separation

Introduce normalized query messages and structured result access, move history composition to the agent side, and update every supported request/response adapter. Keep typed results until request rendering. Preserve protocol-required call/result ordering and continuation metadata.

### Phase 5: Compatibility and completion

Migrate `AiResponse` consumers to a documented conversation-backed tool-execution read model, then remove `AiToolCallResponse` and obsolete batch-save APIs. Retain legacy history readers, preserve reporting, and update prototype documentation in both language trees when implementation changes their behavior.

Deliver each phase with focused verification before layering the next. Do not claim a performance improvement from the changed write pattern without measurements.

## Open items

### Message and storage contract

- Finalize message fields, payload versioning, lifecycle states, and the start/complete signatures.
- Assign authoritative execution fields between `AI_MESSAGE` and linked `AI_TOOL_CALL`, including diagnostic and usage aggregation.
- Specify the replacement read API for `AiResponse` consumers and compatibility for existing role values, payloads, exports, and owner accessors.

### MCP correlation and scope

- Verify which stable session, client, and request identifiers the SDK exposes, and define fallback correlation where they are absent.
- Decide how optional client chat identifiers, reconnects, rollover, and titles map to conversations without crossing user or endpoint-version boundaries.
- Define logging for rejected arguments, denied requests, unknown tools, and conversion failures, including failures rejected by the SDK before the handler runs.

### Execution and failure semantics

- Define fatal versus recoverable failures and align `isFailed()`, executor behavior, LLM error outputs, and MCP error results.
- Decide whether a small logging coordinator wraps the executor or callers own start/complete orchestration; keep the semantics identical either way.
- Specify duplicate-write behavior, call identity scope, safe completion retries, recovery of stale messages, and atomic sequence allocation under concurrency.
- Define an explicit diagnostic path when result persistence fails after a side-effecting tool completes; audit repair must not rerun the tool.

### Query, history, and usage

- Finalize structured-value access and normalized query types, including provider-specific continuation metadata carried as opaque data where necessary.
- Specify history selection and reconstruction from new combined messages and legacy split messages without replaying tool execution.
- Choose the usage attribution record and its timeline representation, preserving totals for tool-requesting and final-answer exchanges.
- Define explicit serialization for snapshots, debug views, attachments, and results that cannot be represented as plain JSON.

### Security and operations

- Finalize redaction, payload limits, retention, attachment authorization, and safe rendering policies.
- Apply ownership checks to every message creation and enrichment, including updates by message UID.
- Decide how monitoring refreshes in-progress messages and exposes completion-unconfirmed executions without misrepresenting them as safe to retry.

## Verification criteria

- Agent and MCP invocations produce the same typed tool-message structure without requiring an LLM query for MCP.
- Exactly one message is committed before each invocation; enrichment preserves its UID, sequence, and original call identity.
- Initial persistence failure prevents invocation. Thrown failures, partial batches, and failed completion updates remain diagnosable without repeated side effects.
- Concurrent calls and out-of-order results retain correct correlation, authorization, and stable timeline order.
- Arguments, typed values, diagnostics, appendices, timing, and result metadata survive persistence and response-consumer migration.
- Existing split-message histories remain readable and cost/diagnostic reports remain correct without double counting.
- History composition and final wire requests satisfy every supported LLM API, including structured results, failures, batches, and provider continuation data.
- Repeated adapter builds do not mutate results or duplicate metadata, and monitoring text is never blindly used as model context.
- MCP initialization and tool calls remain protocol-compliant, recoverable failures do not kill the process, and `STDOUT` stays clean.
- Redaction, payload limits, and authorization work consistently for both conversation sources.