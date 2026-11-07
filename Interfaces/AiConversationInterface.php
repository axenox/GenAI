<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Exceptions\ExceptionInterface;

/**
 * Authorized, exact-version interaction history shared by agents and MCP endpoints.
 */
interface AiConversationInterface
{
    public function getConversationId() : string;
    public function getTaskHandler() : AiTaskHandlerInterface;
    /** @return string[] Message UIDs written by this instance, excluding concurrent turns. */
    public function getWrittenMessageIds() : array;
    public function getTitle() : string;
    public function setTitle(string $title) : AiConversationInterface;
    /**
     * Ordered detached history with exclusive lower and inclusive upper sequence boundaries.
     *
     * @return AiMessageInterface[]
     */
    public function getMessages(?string $type = null, ?int $afterSequence = null, ?int $throughSequence = null) : array;
    public function hasSystemPrompt() : bool;
    public function getSystemPrompt() : string;
    /** Persists instructions and returns the conversation UID for compatibility. */
    public function saveSystemPrompt(string $systemPrompt, array $data = []) : string;
    /** Persists user content and immutable attachments; returns the conversation UID. */
    public function saveUserPrompt(string $userPrompt, array $files = [], array $data = []) : string;
    /**
     * Commits batch identity before any invocation; returns the combined message UID.
     *
     * @param AiToolCallInterface[] $calls Ordered calls with unique IDs within the batch.
     */
    public function saveToolCalls(array $calls, array $data = []) : string;
    /**
     * Completes the existing message without changing its identity or initial call data.
     *
     * @param array<string, AiToolResultInterface> $results Results keyed by original call ID.
     */
    public function saveToolResults(string $messageId, array $results, array $data = []) : void;
    /** Commits an IN_PROGRESS detail before invocation; returns its UID. */
    public function saveToolCallStart(string $messageId, AiToolCallInterface $call, array $data = []) : string;
    /** Completes an existing detail; persistence errors throw without re-executing tools. */
    public function saveToolCallEnd(string $recordId, AiToolResultInterface $result, array $data = []) : void;
    public function saveResponse(AiQueryInterface $query, string $answer, ?array $fullJsonResponse = null) : void;
    /** Logs and persists an operational error; only this message receives ERROR_LOG_ID. */
    public function saveError(\Throwable $error, array $tools = [], ?array $responseJsonSchema = null, array $data = []) : ExceptionInterface;
    public function saveWarnings(array $warnings) : void;
}
