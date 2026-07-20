<?php
namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Common\AiToolCallResponse;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;

/**
 * Handles persistence and bookkeeping for an AI conversation.
 *
 * Implementations keep track of the conversation ID and store prompts,
 * responses, tool calls, warnings and errors in persistence.
 */
interface AiConversationInterface
{
    /**
     * Returns the active conversation ID, creating one if necessary.
     *
     * @return string
     */
    public function getConversationId() : string;

    /**
     * Persists the rendered system prompt for the current conversation.
     *
     * @param AiQueryInterface $query Query used to initialize message sequence number.
     * @param string $systemPrompt Rendered system prompt text.
     * @param AiToolInterface[] $tools Tool definitions to include in message metadata.
     * @param array|null $responseJsonSchema Optional JSON response schema metadata.
     *
     * @return string Conversation ID used for the stored message.
     */
    public function saveSystemPrompt(AiQueryInterface $query, string $systemPrompt, array $tools = [], ?array $responseJsonSchema = null) : string;

    /**
     * Persists the current user prompt for the conversation.
     *
     * @param AiQueryInterface $query Query containing the current user prompt.
     *
     * @return string Conversation ID used for the stored message.
     */
    public function saveUserPrompt(AiQueryInterface $query) : string;

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
     * @param string|null $systemPrompt Optional system prompt snapshot.
     * @param array $tools Tool metadata to include in payload.
     * @param array|null $responseJsonSchema Optional JSON schema metadata.
     *
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
     * Retrieves all system messages from the conversation.
     *
     * @return array Array of system message strings.
     */
    public function getSystemMessages() : array;

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