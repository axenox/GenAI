<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * A container for the result of an individual tools - similar to task results in actions
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiToolResultInterface extends WorkbenchDependantInterface, \Stringable
{

    /**
     * @return AiToolInterface
     */
    public function getTool(): AiToolInterface;

    /**
     * @return array
     */
    public function getArguments(): array;
    
    /**
     * Returns the tool response as a string
     * 
     * @return string
     */
    public function getValue() : string;

    /**
     * Returns the tool response formatted as Markdown
     * 
     * @return string
     */
    public function getValueAsMarkdown() : string;

    /**
     * Returns the data type of the tool response
     * 
     * @return DataTypeInterface
     */
    public function getValueDataType() : DataTypeInterface;

    /**
     * Returns an array of additional instructions to be included in the tool response (e.g. explanations, etc.)
     *
     * Each tool can produce multiple appendix items. However, since LLM can call multiple tools at once, duplicate
     * appendix items may happen easily. Thus, appendix items are collected from all tool calls and duplicates are
     * removed before sending them to the LLM - that is the reason for having an array here instead of a string.
     *
     * @return string[]
     */
    public function getAppendix() : array;

    /**
     * Returns all stored exceptions for this tool response.
     *
     * Consumers classify severity via ExceptionInterface::getLogLevel()
     * (e.g. warning vs error persistence).
     *
     * @return ExceptionInterface[]
     */
    public function getExceptions() : array;

    /**
     * Adds a non-critical error or warning to the tool result
     * 
     * @param \Throwable $exception
     * @return AiToolResultInterface
     */
    public function addException(\Throwable $exception) : AiToolResultInterface;

    /**
     * Returns TRUE if the tool could not be executed (= result should not be sent to LLM) and FALSE otherwise
     * 
     * @return bool
     */
    public function isFailed() : bool;
}