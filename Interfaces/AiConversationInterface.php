<?php
namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Common\AiToolCallResponse;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;

/**
 * Represents a persisted conversation between two participants A and B.
 *
 * In the AI runtime one participant is the owning agent and the other is typically a user or an
 * orchestrating agent. A conversation belongs to exactly one agent and agent version. Multi-agent
 * interactions use separate child conversations connected by an orchestration timeline.
 *
 * The conversation owns persistence and retrieval of the messages exchanged by its participants.
 */
interface AiConversationInterface
{
    /**
    * Returns the persisted conversation ID supplied by the factory.
     *
     * @return string
     */
    public function getConversationId() : string;

    /**
     * Returns the agent participating in this conversation.
     */
    public function getAgent() : AiAgentInterface;

    /**
     * Returns the persisted conversation title.
     */
    public function getTitle() : string;

    /**
     * Overwrites the persisted conversation title.
     */
    public function setTitle(string $title) : AiConversationInterface;

    /**
     * Returns the UID of the exact agent version assigned to this conversation.
     */
    public function getAgentVersionUID() : string;

    /**
     * Returns the next sequence number, loading it from persistence on first access.
     */
    public function getSequenceNumber() : int;

    /**
     * Returns TRUE when the conversation already contains its system prompt.
     */
    public function hasSystemPrompt() : bool;

    /**
     * Returns the conversation's single persisted system prompt.
     */
    public function getSystemPrompt() : string;

    /**
     * Persists the rendered system prompt for the current conversation.
     *
    * @param string $systemPrompt Rendered system prompt text.
    * @param array $data Normalized message metadata and payload.
     *
     * @return string Conversation ID used for the stored message.
     */
    public function saveSystemPrompt(string $systemPrompt, array $data = []) : string;

    /**
     * Persists the current user prompt for the conversation.
     *
      * @param string $userPrompt User message text.
      * @param array $files Files attached to the message.
      * @param array $data Additional message information.
     *
     * @return string Conversation ID used for the stored message.
     */
     public function saveUserPrompt(string $userPrompt, array $files = [], array $data = []) : string;

    /**
     * Persists an assistant tool-call request message.
     *
     * @param AiQueryInterface $query Query containing tool calls and token/cost metadata.
     */
    public function saveToolCallRequest(AiQueryInterface $query) : void;

    /**
     * Persists the final assistant response message.
     *
     * @param AiQueryInterface $query Query containing completion metadata.
     * @param string $answer Resolved assistant answer to display.
     * @param array|null $fullJsonResponse Optional raw JSON response payload.
     */
    public function saveResponse(AiQueryInterface $query, string $answer, ?array $fullJsonResponse = null) : void;

    /**
     * Persists tool execution responses.
     *
      * @param AiQueryInterface $query Query that triggered tool execution.
      * @param AiToolCallResponse[] $responses Tool execution responses.
     *
     * @return AiToolCallResponse[]|null
     */
    public function saveToolResponses(AiQueryInterface $query, array $responses) : ?array;

    /**
     * Splits tool exceptions by severity and persists them.
     *
     * @param array $exceptions Exception payloads attached by tools.
     */
    public function saveExceptions(array $exceptions) : void;

    /**
     * Persists a fatal conversation error as an ERROR message.
     *
     * @param \Throwable $error Original error.
     * @param array $tools Tool metadata to include in payload.
     * @param array|null $responseJsonSchema Optional JSON schema metadata.
     * @return ExceptionInterface Normalized platform exception.
     */
    public function saveError(\Throwable $error, array $tools = [], ?array $responseJsonSchema = null) : ExceptionInterface;

    /**
     * Persists warning payloads as WARNING messages.
     *
     * @param array $warnings Warning payloads from connector/tools.
     */
    public function saveWarnings(array $warnings) : void;

    /**
     * Retrieves all user messages from the conversation.
     *
     * @return array Array of user message strings.
     */
    public function getUserMessages() : array;

    /**
     * Retrieves all assistant messages from the conversation.
     *
     * @return array Array of assistant message strings.
     */
    public function getAssistantMessages() : array;

    /**
     * Retrieves all tool messages from the conversation.
     *
     * @return array Array of tool message strings.
     */
    public function getToolMessages() : array;

    /**
     * Retrieves all tool calling messages from the conversation.
     *
     * @return array Array of tool calling message strings.
     */
    public function getToolCallingMessages() : array;

    /**
     * Retrieves all warning messages from the conversation.
     *
     * @return array Array of warning message strings.
     */
    public function getWarningMessages() : array;

    /**
     * Retrieves all error messages from the conversation.
     *
     * @return array Array of error message strings.
     */
    public function getErrorMessages() : array;
}