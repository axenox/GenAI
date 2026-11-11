<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Filesystem\FileInfoInterface;

/**
 * One message of an AI conversation - provider-independent facts about what was said.
 * 
 * A conversation mimics the typical chat structure: the system prompt is a message, every user prompt
 * is a message, every LLM response is a message - including those with tool calls. Diagnostic messages
 * (warnings, errors) are messages too, but they are only visible to designers.
 * 
 * Messages know neither database rows nor API formats:
 * 
 * - Only the conversation (`AiConversationInterface`) knows how messages are saved and loaded.
 * - API adapters derive their requests from messages using only the context role issers
 * (`isInstructions()`, `isInput()`, `isOutput()`), `getText()`, `getAttachments()` and the
 * `AiToolCallMessageInterface` subtype. They never use `getType()`.
 * 
 * At most one of the context role issers returns `TRUE`. If none does, the message is meant for
 * designers only and is never sent to an LLM.
 * 
 * Generic messages are always sufficient to continue a conversation with any API. Adapters may
 * keep optional opaque data with a message (see `getAdapterData()`) to improve quality when the same
 * adapter continues, but nothing may depend on it.
 * 
 * @author Andrej Kabachnik
 */
interface AiMessageInterface
{
    /**
     * Returns the UID of the message or an empty string if it was not saved yet
     * 
     * @return string
     */
    public function getUid() : string;

    /**
     * Returns the UID of the conversation or an empty string if the message was not saved yet
     * 
     * @return string
     */
    public function getConversationId() : string;

    /**
     * Returns the position of the message in the conversation timeline
     * 
     * @return int
     */
    public function getSequenceNumber() : int;

    /**
     * Returns the AiMessageTypeDataType value - for designers and persistence, never used by API adapters
     * 
     * @return string
     */
    public function getType() : string;

    /**
     * Returns TRUE if this message contains instructions for the model - e.g. a system prompt
     * 
     * @return bool
     */
    public function isInstructions() : bool;

    /**
     * Returns TRUE if this message is something the user said
     * 
     * @return bool
     */
    public function isInput() : bool;

    /**
     * Returns TRUE if this message is something the model said - including tool requests and their results
     * 
     * @return bool
     */
    public function isOutput() : bool;

    /**
     * Returns what was said - the model context, always text
     * 
     * A structured answer (JSON mode) is the JSON text produced by the LLM. For tool call messages, this is
     * the text the LLM said together with the tool request (may be empty).
     * 
     * @return string
     */
    public function getText() : string;

    /**
     * Returns the files attached to this message
     * 
     * Attachments, that cannot be loaded anymore, are still returned, but their `exists()` method
     * returns FALSE.
     * 
     * @return FileInfoInterface[]
     */
    public function getAttachments() : array;

    /**
     * Returns a human-readable representation for designers - not model context
     * 
     * @return string
     */
    public function toMarkdown() : string;

    /**
     * Returns optional opaque data of the API adapter owning the given key or NULL if there is no such data
     * 
     * Adapter data is never required: every request can be built from generic messages alone. An adapter
     * reads only its own key and uses the data only if it still matches the generic message.
     * 
     * @param string $key
     * @return array<array-key,mixed>|null
     */
    public function getAdapterData(string $key) : ?array;
}
