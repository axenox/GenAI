<?php
namespace axenox\GenAI\Interfaces;

/**
 * An output message, that contains tool calls requested by the LLM and their results
 * 
 * The text of a tool call message (`getText()`) is what the LLM said together with the
 * tool request - it may be empty (e.g. for MCP calls).
 * 
 * API adapters detect tool calls via `instanceof AiToolCallMessageInterface` on output
 * messages. Everything they need to know about the calls and results comes from
 * `AiToolCallInterface` and `AiToolResultInterface`.
 * 
 * @author Andrej Kabachnik
 */
interface AiToolCallMessageInterface extends AiMessageInterface
{
    /**
     * Returns the calls in the order requested by the LLM
     * 
     * @return AiToolCallInterface[]
     */
    public function getCalls() : array;

    /**
     * Returns the results of the calls indexed by call ID
     * 
     * @return array<string,AiToolResultInterface>
     */
    public function getToolResults() : array;

    /**
     * Returns TRUE if every call has a result
     * 
     * @return bool
     */
    public function isComplete() : bool;
}
