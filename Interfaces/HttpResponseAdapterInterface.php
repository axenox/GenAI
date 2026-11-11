<?php
namespace axenox\GenAI\Interfaces;

/**
 * Derives a generic response message and generic usage values from the native response of one LLM API
 * 
 * Response adapters normalize when a response arrives, so that every stored message can be replayed
 * by any request adapter:
 * 
 * - Unfinished answers keep the produced text and report `AiFinishReasonDataType::TRUNCATED`.
 * - Generated files are attached to the response message as `FileInfoInterface` objects.
 * - Citations, annotations and refusals are converted to text where meaningful. Otherwise they are
 * dropped and a warning is reported via `getWarnings()`.
 * - Tool calls become `AiToolCallInterface` objects with object (named) arguments.
 * - Native data, that improves quality when the same API continues, may be added to the message
 * as opaque adapter data under a key owned by the adapter.
 * 
 * Response adapters know nothing about persistence or other APIs.
 * 
 * @author Andrej Kabachnik
 */
interface HttpResponseAdapterInterface
{
    /**
     * Returns the generic response message - an `AiToolCallMessageInterface` if the LLM requested tools
     * 
     * @return AiMessageInterface
     */
    public function getResponseMessage() : AiMessageInterface;

    /**
     * Returns the reason, why the LLM stopped generating - one of the AiFinishReasonDataType values
     *
     * @return string
     */
    public function getFinishReason() : string;

    /**
     * Returns the name of the model, that produced the response, if reported
     * 
     * @return string|null
     */
    public function getModelName() : ?string;

    /**
     * Returns the number of tokens in the prompt
     *
     * @return int
     */
    public function getTokensInPrompt() : int;

    /**
     * Returns the number of tokens in the answer
     *
     * @return int
     */
    public function getTokensInAnswer() : int;

    /**
     * Returns the native usage information of the provider - e.g. for cost calculation formulas
     * 
     * @return array<array-key,mixed>
     */
    public function getUsage() : array;

    /**
     * Returns warnings about response content, that had to be dropped while normalizing
     * 
     * @return string[]
     */
    public function getWarnings() : array;

    /**
     * Determines whether the provider response indicates an error state.
     *
     * Returns TRUE if an unexpected or problematic finish reason/status was detected.
     * Returns FALSE when the finish reason/status is known and acceptable.
     *
     * @return bool
     */
    public function isError() : bool;

    /**
     * Maps a generic exception to a provider-specific exception if possible.
     *
     * Implementations can inspect provider response data and return a more
     * specific error (for example overload, refusal, invalid request, etc.).
     * If no mapping is possible, return a generic provider query error.
     *
     * @param AiQueryInterface $query
     * @param \Exception $e
     * @return \Exception
     */
    public function enrichError(AiQueryInterface $query, \Exception $e) : \Exception;
}
