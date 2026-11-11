<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolCallInterface;
use exface\Core\DataTypes\StringDataType;
use JsonSerializable;

/**
 * Retains a requested tool call independently of its live tool configuration.
 * 
 * Original arguments remain unchanged when positional execution arguments are parsed.
 * 
 * @author GitHub Copilot
 */
class AiToolCall implements AiToolCallInterface, JsonSerializable
{
    private $toolName = null;

    private $callId = null;

    private $arguments = [];
    private ?string $rawArguments;
    private ?array $executionArguments;
    private ?string $toolAlias;

    /**
     * Creates historical call data independently of live tool configuration.
     * 
     * @param string $toolName
     * @param string $callId
     * @param array<array-key,mixed>|\stdClass $arguments Original arguments as received from the provider.
     * @param string|null $rawArguments
     * @param list<mixed>|null $executionArguments
     * @param string|null $toolAlias
     * @return void
     */
    public function __construct(string $toolName, string $callId, array|\stdClass $arguments, ?string $rawArguments = null, ?array $executionArguments = null, ?string $toolAlias = null)
    {
        $this->toolName = $toolName;
        $this->callId = $callId;
        $this->arguments = $arguments;
        $this->rawArguments = $rawArguments;
        $this->executionArguments = $executionArguments;
        $this->toolAlias = $toolAlias;
    }

    /**
     * Returns the retained call fields for explicit JSON serialization.
     * 
     * @return mixed
     */
    public function jsonSerialize() : mixed
    {
        return get_object_vars($this);
    }
    
    /**
     * Returns the tool name advertised in the original request.
     * 
     * @return string
     */
    public function getToolName() : string
    {
        return $this->toolName;
    }

    /**
     * Returns the provider or endpoint call identity used to correlate the outcome.
     * 
     * @return string
     */
    public function getCallId() : string
    {
        return $this->callId;
    }
    
    /**
     * Returns an array view of the original arguments without execution-time parsing.
     * 
     * @return array<array-key,mixed>
     */
    public function getArguments() : array
    {
        return (array) $this->arguments;
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallInterface::getOriginalArguments()
     */
    public function getOriginalArguments() : array|\stdClass
    {
        return $this->arguments;
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallInterface::getRawArguments()
     */
    public function getRawArguments() : ?string
    {
        return $this->rawArguments;
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallInterface::getExecutionArguments()
     */
    public function getExecutionArguments() : ?array
    {
        return $this->executionArguments;
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallInterface::setExecutionArguments()
     */
    public function setExecutionArguments(array $arguments) : AiToolCallInterface
    {
        $this->executionArguments = $arguments;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallInterface::getToolAlias()
     */
    public function getToolAlias() : ?string
    {
        return $this->toolAlias;
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallInterface::setToolAlias()
     */
    public function setToolAlias(?string $alias) : AiToolCallInterface
    {
        $this->toolAlias = $alias;
        return $this;
    }

    /**
     * Renders the call name and original arguments for display.
     * 
     * @return string
     */
    public function __toString(): string
    {
        $args = [];
        $delim = ', ';
        foreach ($this->getArguments() as $arg) {
            switch (true) {
                case $arg instanceof \stdClass:
                case is_array($arg): 
                    $args[] = StringDataType::indent(json_encode($arg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\t");
                    $delim = ",\n\t";
                    break;
                case is_bool($arg):
                    $args[] = $arg ? 'true' : 'false';
                    break;
                default:
                    $args[] = $arg;
                    break;
            }
        }
        return $this->getToolName() . '(' . implode($delim, $args) . ')';
    }
}