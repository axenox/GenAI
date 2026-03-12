<?php
namespace axenox\GenAI\Interfaces;

interface HttpResponseAdapterInterface
{
    public function getUsage() : array;

    /**
     * Returns the answer of the LLM as text (raw)
     *
     * @return string
     */
    public function getFullAnswer() : string;

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
}