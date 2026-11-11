<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * A tool is a function, that the LLM can call to interact with our system.
 * 
 * A tool has a name and an `invoke()` method, which receives arguments provided by the LLM and
 * some context information - namely the task handler and the current task. If the tool is called directly by an LLM, the
 * task is the AI prompt, but tools can also be called by other parts of the system - e.g. an MCP server - where the
 * passed task would be something else.
 *
 * Tools can return different data. Each tool has an expected return data type. The concrete result of a tool call is
 * an `AiToolResultInterface` object, which gives subsequent components access to the result data, the arguments,
 * warning and errors that occurred while processing and other metadata.
 *
 * Tools can basically do anything, but they must derive all required information from the input of `invoke()`. Thus,
 * tools are stateless!
 * 
 * Tools must advertise their arguments, usage rules and return data type. The corresponding methods `getArguments()`,
 * `getRules()` and `getReturnDataType()` should be used by AI connectors to "explain" the tool to the LLM.
 *
 * @author Andrej Kabachnik
 *
 */
interface AiToolInterface extends iCanBeConvertedToUxon, WorkbenchDependantInterface
{
    /**
     * Executes the tool with positional arguments returned by normalizeArguments().
     *
     * @param AiTaskHandlerInterface $agent
     * @param TaskInterface $task
     * @param list<mixed> $arguments
     * @return AiToolResultInterface
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $task, array $arguments) : AiToolResultInterface;

    /**
     * Summary of getArguments
     * @return \exface\Core\Interfaces\Actions\ServiceParameterInterface[]
     */
    public function getArguments() : array;

    /**
     * Binds named arguments or a positional list to the configured parameters and parses values.
     *
     * Only omitted parameters receive defaults. Omitted optional parameters without a default
     * become null. Required parameters without a value and unknown/excess arguments are rejected.
     * Explicit null is preserved for optional parameters and rejected for required parameters.
     * Structured UXON values become arrays without modifying the original arguments.
     *
     * @param array<array-key,mixed> $arguments
     * @return list<mixed>
     */
    public function normalizeArguments(array $arguments) : array;

    /**
     * 
     * @return string
     */
    public function getName() : string;

    /**
     * Returns the canonical namespaced alias of the tool prototype.
     *
     * @return string
     */
    public function getAliasWithNamespace() : string;

    /**
     * 
     * @return string|null
     */
    public function getDescription() : ?string;

    /**
     * @return DataTypeInterface
     */
    public function getReturnDataType() : DataTypeInterface;

    /**
     * Returns important rules the LLM must know to use this tool.
     *
     * These rules will always be included in the tool description even if not explicitly added by the designer of
     * the agent.
     *
     * @return string|null
     */
    public function getRules() : ?string;
}