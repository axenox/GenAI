<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;

/**
 * Converts an MCP-agnostic AI tool into MCP metadata and execution results.
 */
class AiToolMcpAdapter
{
    private AiToolInterface $tool;

    /**
     * Creates an adapter for a configured AI tool instance.
     */
    public function __construct(AiToolInterface $tool)
    {
        $this->tool = $tool;
    }

    /**
     * Builds the immutable MCP definition advertised for the configured tool.
     */
    public function createDefinition() : Tool
    {
        $description = trim((string) $this->tool->getDescription());
        $rules = trim((string) $this->tool->getRules());
        if ($rules !== '') {
            $description = trim($description . "\n\n" . $rules);
        }

        return new Tool(
            name: $this->tool->getName(),
            title: $this->tool->getName(),
            inputSchema: $this->buildInputSchema(),
            description: $description !== '' ? $description : null,
            annotations: null
        );
    }

    /**
     * Invokes the AI tool with validated, positional arguments and converts its result to MCP.
     *
    * @param AiTaskHandlerInterface $agent Task handler owning the configured tool.
     * @param TaskInterface $task Current MCP operation task.
     * @param array<string, mixed> $namedArguments Named arguments received from MCP.
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $task, array $namedArguments) : CallToolResult
    {
        $result = $this->tool->invoke($agent, $task, $this->normalizeArguments($namedArguments));
        $isError = $result->isFailed();
        $diagnostics = [];

        foreach ($result->getExceptions() as $exception) {
            $diagnostics[] = [
                'level' => $exception->getLogLevel(),
                'message' => $exception->getMessage()
            ];
            if (LogLevelDataType::compareLogLevels($exception->getLogLevel(), LoggerInterface::WARNING) > 0) {
                $isError = true;
            }
        }

        $meta = [];
        if ($diagnostics !== []) {
            $meta['diagnostics'] = $diagnostics;
        }
        if ($result->getAppendix() !== []) {
            $meta['appendix'] = $result->getAppendix();
        }

        return new CallToolResult(
            [new TextContent($result->getValueWithMetadata())],
            $isError,
            null,
            $meta !== [] ? $meta : null
        );
    }

    /**
     * Orders named MCP arguments according to the existing positional AI tool contract.
     *
     * @param array<string, mixed> $namedArguments
     * @return array<int, mixed>
     */
    public function normalizeArguments(array $namedArguments) : array
    {
        $positionalArguments = [];
        foreach ($this->tool->getArguments() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $namedArguments)) {
                $positionalArguments[] = $parameter->parseValue($namedArguments[$name]);
            } elseif ($parameter->hasDefaultValue()) {
                $positionalArguments[] = $parameter->parseValue($parameter->getDefaultValue());
            } else {
                $positionalArguments[] = null;
            }
        }

        return $positionalArguments;
    }

    /**
     * Builds the object-typed JSON Schema validated by the MCP SDK before invocation.
     *
     * @return array<string, mixed>
     */
    private function buildInputSchema() : array
    {
        $properties = [];
        $required = [];

        foreach ($this->tool->getArguments() as $parameter) {
            $properties[$parameter->getName()] = $this->buildParameterSchema($parameter);
            if ($parameter->isRequired()) {
                $required[] = $parameter->getName();
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false
        ];
    }

    /**
     * Converts one service parameter to its general-purpose JSON Schema representation.
     *
     * @return array<string, mixed>
     */
    private function buildParameterSchema(ServiceParameterInterface $parameter) : array
    {
        $customSchema = $parameter->getCustomProperty('json_schema');
        if (is_string($customSchema) && trim($customSchema) !== '') {
            $schema = json_decode($customSchema, true, 512, JSON_THROW_ON_ERROR);
        } else {
            $schema = JsonDataType::convertDataTypeToJsonSchemaType($parameter->getDataType());
        }

        if ($parameter->getDescription() !== '' && ! array_key_exists('description', $schema)) {
            $schema['description'] = $parameter->getDescription();
        }
        if ($parameter->hasDefaultValue() && ! array_key_exists('default', $schema)) {
            $schema['default'] = $parameter->getDefaultValue();
        }
        if ($parameter->hasExamples() && ! array_key_exists('examples', $schema)) {
            $schema['examples'] = $parameter->getExamples();
        }

        return $schema;
    }
}