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
     * @return string
     */
    public function getAnswer() : string;

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
     * @return void
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
     * @return string
     */
    public function getUserPrompt() : string;

    /**
     * 
     * @return void
     */
    public function getSystemPrompt() : ?string;

    /**
     * 
     * @return void
     */
    public function getTitle() : ?string;

    /**
     * 
     * @return string
     */
    public function getFinishReason() : string;

    /**
     * 
     * @return string
     */
    public function getRawAnswer() : string;
}