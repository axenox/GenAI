# Conversations

[Deutsch](../../Translations/de/AI/Conversations/index.md)

A conversation is a persisted exchange between an individual participant and exactly one executing task handler. Its type is `agent` for model-backed conversations or `mcp` for MCP tool activity. The individual participant is usually a user, but it may also be another AI or an orchestrating agent.

## Agent and agent-version assignment

Every conversation belongs to exactly one agent and exactly one agent version. This assignment is immutable after the conversation is created. It keeps the system prompt, tools, model configuration, and responses attributable to the agent version that actually produced them.

An initialized agent conversation has **exactly one system prompt**. On its first run, `AiAgentInterface::renderSystemPrompt()` renders that prompt as a string and `saveSystemPrompt()` persists it. It is immutable afterwards. Every later run exclusively loads the persisted prompt through `AiConversationInterface::getSystemPrompt()`; the agent does not render it again.

Runtime instances of tools assigned to the agent version may be reconstructed for a new request. This does not render the prompt again and does not change the persisted system prompt.

This invariant is one reason a conversation cannot be shared by several agents or agent versions: another agent would have different instructions, skills, concepts, or tool rules but could not replace the conversation's immutable system prompt.

`AiFactory::createConversationFromPrompt()` decides from the prompt whether to create a new conversation or restore a known one. For a new conversation it delegates to `createConversation()`; Power UI generates the UID while persisting the record. `createConversationFromUid()` explicitly restores a known conversation. The conversation lazily loads its persisted assignment and verifies that:

- the conversation exists and belongs to the authenticated user;
- the stored agent UID matches the executing agent;
- the stored version number matches the executing agent version;
- the related agent version has a valid UID.

An incorrect agent or version assignment raises `AiConversationAgentVersionMismatchError`. Its error dialog includes a `Conversation assignment` tab that compares the stored and requested assignments or explains an invalid persisted version relation. A conversation that does not exist or is not accessible to the user continues to raise `AiConversationNotFoundError`.

`AiConversationInterface` exposes the assignment:

- `getTaskHandler()` returns the executing `AiTaskHandlerInterface`;
- `getTitle()` loads the current title;
- `setTitle()` overwrites the title;
- `getConversationId()` returns the conversation UID.

The `AiConversation` constructor receives the `AiTaskHandlerInterface` and conversation UID. It lazily loads the title and agent-version UID from its persisted record and does not retain the current prompt. This keeps the persisted conversation independent of an individual request and concrete agent implementations.

A new conversation initially has an empty title. When the first user prompt is saved, `saveUserPrompt()` derives the initial title from it. An explicit `setTitle()` call overwrites the existing title at any time.

## Messages

Every message has stable identity, conversation identity, sequence, display text and structured
data. `AiMessageInterface` exposes these through `getUid()`, `getConversationId()`,
`getSequenceNumber()`, `getText()`, `getRole()` and `getData()`. Display text is stored in
`AI_MESSAGE.MESSAGE`; full reconstructable payloads are stored in `AI_MESSAGE.DATA`.
Monitoring previews are never substituted for model context.

`getMessages($type, $afterSequence, $throughSequence)` returns typed messages with optional type
and sequence bounds. Each tool batch exposes ordered `getCalls()` and call-ID-keyed
`getToolResults()`. `saveToolCalls()` records a requested batch; `saveToolResults()` completes it.
Per-call start/end records provide monitoring detail without becoming a second history source.

Normalized model content lives in `data.content`; exact API continuation fields live in
`data.provider.completions`, `.responses` or `.claude`. Model, tokens and costs belong to their
own exchange. Missing usage remains unknown (`null`), not an invented zero.

`isBroken()` marks incomplete or unavailable history. Adapters explicitly reject unsupported
replay rather than inventing results, silently dropping content or rerunning historical tools.
Completed text/JSON exchanges can resume through another supported API; provider-specific
continuation fields are reused only by their originating API.

`saveSystemPrompt()` ignores further save attempts once a system prompt exists. Every subsequent message receives the next sequence number.

Sequence allocation belongs to conversation persistence, not to queries or provider adapters.
`AiResponse::getMessages()` captures only the current execution; the conversation retains the
complete history. Storage failures do not erase actual in-memory tool results or trigger automatic
retries of writes or execution.

## Multi-agent workflows

A single conversation must not be shared by multiple agents or agent versions. An orchestrated workflow therefore creates a separate conversation for every participating agent and connects them through the parent workflow or timeline.
//TODO
