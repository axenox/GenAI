<?php
namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;

interface HttpResponseAdapterInterface
{
    
    public function getUsage() : array;

    /**
     * Returns the answer of the LLM as text (raw)
     *
     * @return string
     */
    public function getAnswerRaw() : string;

    /**
     * Returns the structured data returned by an LLM if it runs in JSON mode
     *
     * @return array|null
     */
    public function getAnswerJson() : ?array;

    /**
     * Returns FALSE if the LLM is not done yet and this is just a partial response
     *
     * @return bool
     */
    public function isFinished() : bool;

    /**
     *
     * @return int
     */
    public function getTokensInPrompt() : int;

    /**
     *
     * @return int
     */
    public function getTokensInAnswer() : int;

    /**
     *
     * @return string
     */
    public function getFinishReason() : string;

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
     * Checks if the request has tool calls
     *
     * @return bool
     */
    public function hasToolCalls() : bool;

    /**
     * Full request for tool calling
     *
     * @return array
     */
    public function getResponseMessage() : array;

    /**
     * Requested Tool Calls
     *
     * @return AiToolCallInterface[]
     */
    public function getToolCalls() : array;

    /**
     * Maps a generic exception to a provider-specific exception if possible.
     *
     * Implementations can inspect provider response data and return a more
     * specific error (for example overload, refusal, invalid request, etc.).
     * If no mapping is possible, return a generic provider query error.
     *
     * @param OpenAiApiDataQuery $query
     * @param \Exception $e
     * @return \Exception
     */
    public function enrichError(OpenAiApiDataQuery $query, \Exception $e) : \Exception;

}