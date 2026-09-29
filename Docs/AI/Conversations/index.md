# Conversations

[Deutsch](index_german.md)

A conversation is a persisted exchange between an individual participant and exactly one executing AI. The individual participant is usually a user, but it may also be another AI or an orchestrating agent.

## Agent and agent-version assignment

Every conversation belongs to exactly one agent and exactly one agent version. This assignment is immutable after the conversation is created. It keeps the system prompt, tools, model configuration, and responses attributable to the agent version that actually produced them.

An initialized conversation has **exactly one system prompt**. On its first run, `AiAgentInterface::renderSystemPrompt()` renders that prompt as a string and `saveSystemPrompt()` persists it. It is immutable afterwards. Every later run exclusively loads the persisted prompt through `AiConversationInterface::getSystemPrompt()`; the agent does not render it again.

Runtime instances of tools assigned to the agent version may be reconstructed for a new request. This does not render the prompt again and does not change the persisted system prompt.

This invariant is one reason a conversation cannot be shared by several agents or agent versions: another agent would have different instructions, skills, concepts, or tool rules but could not replace the conversation's immutable system prompt.

`AiFactory::createConversationFromPrompt()` decides from the prompt whether to create a new conversation or restore a known one. For a new conversation it delegates to `createConversation()`; Power UI generates the UID while persisting the record. `createConversationFromUid()` explicitly restores a known conversation. The conversation lazily loads its persisted assignment and verifies that:

- the conversation exists and belongs to the authenticated user;
- the stored agent UID matches the executing agent;
- the stored version number matches the executing agent version;
- the related agent version has a valid UID.

An incorrect agent or version assignment raises `AiConversationAgentVersionMismatchError`. Its error dialog includes a `Conversation assignment` tab that compares the stored and requested assignments or explains an invalid persisted version relation. A conversation that does not exist or is not accessible to the user continues to raise `AiConversationNotFoundError`.

`AiConversationInterface` exposes the assignment:

- `getAgent()` returns the executing `AiAgentInterface`;
- `getAgentVersionUID()` returns the UID of the exact assigned agent version;
- `getTitle()` loads the current title;
- `setTitle()` overwrites the title;
- `getConversationId()` returns the conversation UID.

The `AiConversation` constructor receives only the `AiAgentInterface` and conversation UID. It lazily loads the title and agent-version UID from its persisted record and does not retain the current prompt. This keeps the persisted conversation independent of an individual request and concrete agent implementations.

A new conversation initially has an empty title. When the first user prompt is saved, `saveUserPrompt()` derives the initial title from it. An explicit `setTitle()` call overwrites the existing title at any time.

## Messages

Every persisted message conceptually consists of a content string and structured data. Content is stored in `AI_MESSAGE.MESSAGE`; additional data is serialized into `AI_MESSAGE.DATA`. The role, user, model, and sequence number complete the record.

`saveSystemPrompt()` and `saveUserPrompt()` receive content and data directly. Specialized methods for tool calls, tool responses, agent responses, warnings, and errors derive the same two components from their runtime objects.

`saveSystemPrompt()` ignores further save attempts once a system prompt exists. Every subsequent message receives the next sequence number.

The next sequence number belongs to conversation state. Its internal value starts as `null`. On first access, `getSequenceNumber()` loads the highest persisted `SEQUENCE_NUMBER` and returns its successor; later access uses the cached value. While saving, `incrementSequenceNumber()` returns the current number and then advances the local counter. The factory therefore neither loads nor passes sequence state to the constructor.

## Multi-agent workflows

A single conversation must not be shared by multiple agents or agent versions. An orchestrated workflow therefore creates a separate conversation for every participating agent and connects them through the parent workflow or timeline.
//TODO
