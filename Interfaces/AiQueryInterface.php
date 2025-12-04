<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\DataSources\DataQueryInterface;

/**
 * Common interface for queries for LLM connectors
 * 
 * The connector takes care of sending messages and receiving responses from a specific LLM.
 * Each type of LLM API requires a spearate AiQuery class, that will extract from its raw response
 * (typically a JSON) all information required by the agents.
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiQueryInterface extends DataQueryInterface
{
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
     * @return float|null
     */
    public function getCostPerMTokens() : ?float;

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
     * @return int
     */
    public function getSequenceNumber() : int;

    /**
     * 
     * @return bool
     */
    public function hasResponse() : bool;

    /**
     * 
     * @return string
     */
    public function getUserPrompt() : string;

    /**
     * 
     * @return string|null
     */
    public function getSystemPrompt() : ?string;

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