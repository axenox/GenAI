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
     * Returns the actual typed tool response value.
     * 
     * @return mixed
     */
    public function getValue() : mixed;

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
     * Returns transient execution exceptions. Restored results return an empty array.
     *
     * @return ExceptionInterface[]
     */
    public function getExceptions() : array;

    /** @return string[] Durable error messages, including captured remediation hints. */
    public function getErrors() : array;

    /** @return string[] Durable warning messages, including captured remediation hints. */
    public function getWarnings() : array;

    /**
     * Adds an error or warning to the tool result. ERROR and higher levels mark it failed.
     * 
     * @param \Throwable $exception
     * @return AiToolResultInterface
     */
    public function addException(\Throwable $exception) : AiToolResultInterface;

    /**
     * Returns TRUE if execution failed. Failed results still carry useful diagnostics.
     * 
     * @return bool
     */
    public function isFailed() : bool;

    public function setFailed(bool $failed) : self;

    public function getDurationMs() : ?float;

    public function setDurationMs(float $milliseconds) : AiToolResultInterface;
}