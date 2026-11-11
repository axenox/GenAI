<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * Retained call identity and arguments, independent of live tool configuration.
 * 
 * Implementations preserve the original request alongside separately normalized
 * positional arguments used for fresh execution.
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiToolCallInterface extends \Stringable
{
    /**
     * Returns the tool name advertised in the original request.
     * 
     * @return string
     */
    public function getToolName() : string;

    /**
     * Returns the provider or endpoint call identity used to correlate the outcome.
     * 
     * @return string
     */
    public function getCallId() : string;

    /**
     * Returns an array view of the original arguments without execution-time parsing.
     * 
     * @return array<array-key,mixed>
     */
    public function getArguments() : array;

    /**
     * Returns the SDK-visible or original JSON argument value without losing objects.
     * 
     * @return array<array-key,mixed>|\stdClass
     */
    public function getOriginalArguments() : array|\stdClass;

    /**
     * Returns original argument text where the provider supplied it.
     * 
     * @return string|null
     */
    public function getRawArguments() : ?string;

    /**
     * Returns separately parsed positional execution arguments, when available.
     * 
     * @return list<mixed>|null
     */
    public function getExecutionArguments() : ?array;

    /**
     * Records the parsed positional arguments actually passed to the tool.
     * 
     * @param list<mixed> $arguments
     * @return AiToolCallInterface
     */
    public function setExecutionArguments(array $arguments) : AiToolCallInterface;

    /**
     * Returns the historical application alias, when known.
     * 
     * @return string|null
     */
    public function getToolAlias() : ?string;

    /**
     * Records the application alias of the tool, that handles this call
     * 
     * The LLM only knows tool names. The agent resolves the alias before saving the call, so
     * the history remains meaningful even if tools are renamed later.
     * 
     * @param string|null $alias
     * @return AiToolCallInterface
     */
    public function setToolAlias(?string $alias) : AiToolCallInterface;
}