<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolCriticalError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Interfaces\Exceptions\AuthorizationExceptionInterface;
use exface\Core\Interfaces\WorkbenchInterface;
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
     * 
     * @param AiToolInterface $tool
     * @return void
     */
    public function __construct(AiToolInterface $tool)
    {
        $this->tool = $tool;
    }

    /**
     * Builds the immutable MCP definition advertised for the configured tool.
     * 
     * @return Tool
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
     * Converts a completed typed result without executing a tool or consulting exceptions.
     * 
     * @param AiToolResultInterface $result
     * @return CallToolResult
     */
    public static function convertResult(AiToolResultInterface $result) : CallToolResult
    {
        $diagnostics = [];
        foreach (['error' => $result->getErrors(), 'warning' => $result->getWarnings()] as $level => $messages) {
            foreach ($messages as $message) {
                $diagnostics[] = ['level' => $level, 'message' => $message];
            }

        }

        $meta = [];
        if ($diagnostics !== []) {
            $meta['diagnostics'] = $diagnostics;
        }
        if ($result->getAppendix() !== []) {
            $meta['appendix'] = $result->getAppendix();
        }
        $text = $result->getValueWithMetadata();
        if ($result->getAppendix() !== []) {
            $text .= "\n\n" . implode("\n\n", array_unique($result->getAppendix()));
        }

        return new CallToolResult(
            [new TextContent($text)],
            $result->isFailed(),
            null,
            $meta !== [] ? $meta : null
        );
    }

    /**
     * Creates a corrective rejection without resolving tool-specific errors or message definitions.
     * 
     * @param WorkbenchInterface $workbench
     * @param string $warning
     * @return AiToolResultInterface
     */
    public static function createRejectedResult(WorkbenchInterface $workbench, string $warning) : AiToolResultInterface
    {
        $result = new AiToolResultString($workbench, '');
        $result->importUxonObject(new UxonObject(['warnings' => [$warning], 'failed' => true]));
        return $result;
    }

    /**
     * Finds an actual permission denial, not an indeterminate authorization machinery failure.
     * 
     * @param \Throwable $error
     * @return AuthorizationExceptionInterface|null
     */
    public static function getAuthorizationDenial(\Throwable $error) : ?AuthorizationExceptionInterface
    {
        while ($error instanceof AiToolCriticalError && $error->getPrevious() !== null) {
            $error = $error->getPrevious();
        }
        if ($error instanceof AuthorizationExceptionInterface && !$error->getPermission()->isIndeterminate()) {
            return $error;
        }
        return null;
    }

    /**
     * Removes authorization details and partial content while retaining unrelated durable diagnostics.
     * Returns the sanitized result and original denials for the independent security logger.
     * 
     * @return array{AiToolResultInterface, AuthorizationExceptionInterface[]}
     * 
     * @param AiToolResultInterface $result
     */
    public static function normalizeAuthorizationResult(AiToolResultInterface $result) : array
    {
        $denials = [];
        $errors = $result->getErrors();
        $warnings = $result->getWarnings();
        $errorIndex = 0;
        $warningIndex = 0;
        foreach ($result->getExceptions() as $exception) {
            $isError = \exface\Core\DataTypes\LogLevelDataType::compareLogLevels(
                $exception->getLogLevel(), \exface\Core\Interfaces\Log\LoggerInterface::ERROR
            ) >= 0;
            $denial = self::getAuthorizationDenial($exception);
            if ($denial !== null) {
                $denials[] = $denial;
                if ($isError) {
                    unset($errors[$errorIndex]);
                } else {
                    unset($warnings[$warningIndex]);
                }
            }
            $isError ? $errorIndex++ : $warningIndex++;
        }
        if ($denials === []) {
            return [$result, []];
        }
        $warnings[] = 'You are not authorized to call this tool. Choose an allowed tool or request access.';
        $sanitized = new AiToolResultString($result->getWorkbench(), '');
        $sanitized->importUxonObject(new UxonObject([
            'errors' => array_values($errors), 'warnings' => array_values($warnings), 'failed' => true
        ]));
        if ($result->getDurationMs() !== null) {
            $sanitized->setDurationMs($result->getDurationMs());
        }
        return [$sanitized, $denials];
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
            if ($parameter->isRequired() && !$parameter->hasDefaultValue()) {
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
     * 
     * @param ServiceParameterInterface $parameter
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
            $default = $parameter->getDefaultValue();
            $schema['default'] = $default instanceof UxonObject ? $default->toArray() : $default;
        }
        if ($parameter->hasExamples() && ! array_key_exists('examples', $schema)) {
            $schema['examples'] = $parameter->getExamples();
        }

        if (!$parameter->isRequired()) {
            $annotations = array_intersect_key($schema, array_flip(['title', 'description', 'default', 'examples']));
            $schema = $annotations + ['anyOf' => [$schema, ['type' => 'null']]];
        }

        return $schema;
    }
}