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
    public function getAnswer() : string;
    
    public function isFinished() : bool;

    public function getCostPerMTokens() : ?float;

    public function getTokensInPrompt() : int;

    public function getTokensInAnswer() : int;
    public function getSequenceNumber() : int;
    public function getUserPrompt() : string;
    public function getSystemPrompt() : ?string;
    public function getAgentId() : ?string;
}