<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * A container for the result of an individual tools - similar to task results in actions
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiToolResultInterface extends WorkbenchDependantInterface, iCanBeConvertedToUxon, \Stringable
{

    /**
     * Returns the retained typed value, independently of display or protocol rendering.
     * 
     * @return mixed
     */
    public function getValue() : mixed;

    /**
     * Returns durable error messages in occurrence order.
     * 
     * @return string[]
     */
    public function getErrors() : array;

    /**
     * Returns durable warning messages in occurrence order.
     * 
     * @return string[]
     */
    public function getWarnings() : array;

    /**
     * @return \Throwable[] Fresh reporting failures, separate from the tool's execution outcome.
     */
    public function getOperationalErrors() : array;

    /**
     * Marks a rejected request as failed even when its diagnostic is a warning.
     * 
     * @param bool $failed
     * @return AiToolResultInterface
     */
    public function setFailed(bool $failed) : AiToolResultInterface;

    /**
     * Returns the monotonic execution duration in milliseconds.
     * 
     * @return float|null
     */
    public function getDurationMs() : ?float;

    /**
     * Records the monotonic execution duration in milliseconds.
     * 
     * @param float $milliseconds
     * @return AiToolResultInterface
     */
    public function setDurationMs(float $milliseconds) : AiToolResultInterface;

    /**
     * Returns the tool response together with metadata intended for the LLM.
     * 
     * Metadata includes diagnostics attached to the result and may later include
     * standardized formats such as OKF without changing the raw result value.
     * 
     * @return string
     */
    public function getValueWithMetadata() : string;

    /**
     * Returns the tool response formatted as Markdown.
     * 
     * @param bool $includeMetadata Include diagnostics and other LLM-facing metadata.
     * @return string
     */
    public function getValueAsMarkdown(bool $includeMetadata = false) : string;

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
     * Returns TRUE for rejected calls or ERROR-or-higher diagnostics. Failed results remain deliverable.
     * 
     * @return bool
     */
    public function isFailed() : bool;
}