# Outlook

The following features are not scheduled yet, but were used to verify the selected structures. They are sketched here to check that the refactored model supports them without breaking its rules.

## Conversation summaries

After a conversation has run for a while, its history can be compacted into a summary that replaces older messages in the model context.

- New message type `SUMMARY`, an instructions message (`isInstructions()`). Adapters send it like a system prompt without any changes.
- The agent (or a compaction skill) creates it through the conversation, e.g. `saveSummary(string $text, AiMessageInterface $lastCoveredMessage)`.
- A subtype exposes the covered range:

  ```php
  interface AiSummaryMessageInterface extends AiMessageInterface
  {
      /** Sequence number of the last message replaced by this summary */
      public function getCoversUntilSequenceNumber() : int;
  }
  ```

- `getMessagesActive()` omits all messages covered by the latest summary. `getMessages()` still returns them, so designers see the full history and the summary in between.
- The conversation may drop adapter data of covered messages.

Open question: should a summary also supersede the original system prompt, or be sent in addition to it? Keeping the system prompt preserves the agent's configured instructions; replacing it keeps the context smallest.

## Reasoning

Reasoning models offer two different things, which must be handled separately:

| What | Example | Purpose | Where it goes |
|---|---|---|---|
| Readable reasoning | OpenAI reasoning summaries, Claude (summarized) thinking text | Explaining to humans how the model arrived at its answer | Chat display and conversation log |
| Native continuation data | Encrypted reasoning items, signed thinking blocks | Better results when the same model continues | Optional adapter data |

Most APIs only return a summary or an encrypted form of the actual reasoning, so "readable reasoning" is always an approximation.

**Showing reasoning in the `AiChat` widget, but not sending it as model context** is the right core idea. Readable reasoning is not something the user or the model said, and replaying it to a different model would add noise.

Recommendation: still **save it**, as a designer-only message rather than discarding it after display:

- Designers use the conversation log to understand why an agent behaved as it did. Reasoning is often the most useful evidence for improving instructions and tools.
- If it is not saved, it disappears from the chat when the user reloads the page, while the rest of the conversation stays.
- The context roles already support this: a new message type `REASONING` is a designer-only message (all issers `false`). It is saved and visible to designers, but never sent to any LLM. Adapters need no changes.

Sketch:

- A generic request setting for reasoning effort, e.g. constants of `AiReasoningEffortDataType` (`NONE`, `LOW`, `MEDIUM`, `HIGH`), configured on the agent or connection and passed on the query. Each adapter maps it to its native parameters. Adapters of APIs without reasoning ignore it and add a warning to the query.
- The response adapter reports readable reasoning as plain text through the query (e.g. `getReasoningText()`), and native continuation data as adapter data on the response message.
- `saveResponse()` / `saveToolCalls()` save a `REASONING` message directly before the response message.
- `AiResponse` exposes the reasoning of the current turn. The `AiChat` widget renders it as a collapsible "Thinking" block above the answer, including after reloads.
- Showing reasoning **while** the model is still working requires streaming responses, which GenAI does not support yet. Until then, reasoning is shown when the response arrives.
- Reasoning text is treated like other message content regarding permissions and retention.

## Questions and confirmations

Agents should be able to ask the user for a decision or for permission before doing something, e.g. before writing data.

### How this works in coding assistants

In assistants like GitHub Copilot, both cases are **tool calls**:

- **Questions:** the model calls a tool like `ask_user` with a message and a small form schema as arguments. Instead of running code, the client shows the form and waits. The user's answer becomes the **tool result**, and the model continues.
- **Permissions:** the model calls a regular tool (e.g. running a command). Before executing it, the client checks a policy and asks the user. If the user declines, the tool is not run and the result tells the model it was declined.

The LLM API sees nothing special: a tool call followed later by its result.

### Fit with our model

Questions are **handled by a tool**, but **saved as a special message**. Designers see each question - and its answer - as a separate item with its own type, not as one call among many in a tool batch.

- **Questions** use a built-in tool, e.g. `AskUserTool`. Its arguments describe the question and the possible answers. Its result is the user's answer.
- **Confirmations** use a tool option, e.g. `requires_confirmation` in the tool UXON. Before calling such a tool, the agent asks the user. A declined call gets a failed result ("declined by user") without executing the tool.

New message types:

| Message type | Context role | Content |
|---|---|---|
| `QUESTION` | Output | The question call of the LLM and the user's answer as its result |
| `CONFIRMATION` | Designers only | The agent's permission request for a specific tool call and the user's decision |

A question message is a subtype of the tool call message, so adapters send it like any other tool call and result - no adapter changes:

```php
interface AiQuestionMessageInterface extends AiToolCallMessageInterface
{
    public function getQuestion() : string;
    public function isAnswered() : bool;
    public function getAnswer() : ?string;
}
```

The conversation splits the batch when saving: if the LLM requests a question together with other tools, the other calls are saved in a `TOOL` message and each question in its own `QUESTION` message. Adapters then send two consecutive assistant tool turns instead of one. APIs accept this, because each turn is followed by its own results. Adapter data of that response no longer matches the split messages and is ignored, as intended.

A confirmation is not an LLM tool call - the LLM never sees it. It is a designer-only message documenting who allowed or declined which call. The confirmed call itself remains in its `TOOL` message, with either its real result or the "declined" result.

### Waiting for the user

The new part is **waiting across requests**. Today the whole tool loop runs within one PHP request. A question or confirmation must pause the run instead:

1. The agent saves the tool batch and the question or confirmation messages. It runs all other calls of the batch and keeps their results.
2. The pending call gets a new state, e.g. `AiToolCallStateDataType::WAITING_FOR_USER`. Unlike `IN_PROGRESS`, it means the run deliberately stopped, not that it was interrupted.
3. The agent returns an `AiResponse` marked as waiting, carrying the open question or confirmation. The `AiChat` widget renders a form or buttons instead of an answer.
4. The user's answer starts a new request. The conversation stores the answer in the waiting message and completes the related tool calls. The agent continues the tool loop from `getMessagesActive()`.

Rules:

- While a message is waiting, no LLM request is made for this conversation. A new user prompt either answers the question or explicitly cancels it (the waiting call gets a "cancelled" result).
- Adapter data of the paused response remains usable when the run continues with the same adapter and the messages still match, and is ignored otherwise.
- Adapters need no changes.

This is the conversation-level counterpart of the `human_gate` node planned in the [agentic workflow roadmap](Agentic_workflows.md). A waiting workflow step could reuse the same waiting message.