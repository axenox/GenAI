<?php
namespace axenox\GenAI\Tests\Unit\Mcp;

use axenox\GenAI\Common\Mcp\AiToolMcpAdapter;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AiToolMcpAdapterTest extends TestCase
{
    public function testCreatesDefinitionFromToolMetadataAndParameters() : void
    {
        $query = $this->createParameter(
            'search_query',
            ['type' => 'string', 'minLength' => 1],
            true,
            'Text to find',
            null,
            ['AI_AGENT']
        );
        $limit = $this->createParameter('limit', ['type' => 'integer'], false, '', 10);
        $tool = $this->createTool([$query, $limit], 'Search model components.', 'Use canonical aliases.');

        $definition = (new AiToolMcpAdapter($tool))->createDefinition();

        $this->assertSame('SearchModelComponents', $definition->name);
        $this->assertSame("Search model components.\n\nUse canonical aliases.", $definition->description);
        $this->assertSame(['search_query'], $definition->inputSchema['required']);
        $this->assertFalse($definition->inputSchema['additionalProperties']);
        $this->assertSame(
            [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Text to find',
                'examples' => ['AI_AGENT']
            ],
            $definition->inputSchema['properties']['search_query']
        );
        $this->assertSame(10, $definition->inputSchema['properties']['limit']['default']);
    }

    public function testNormalizesNamedArgumentsInParameterOrder() : void
    {
        $query = $this->createParameter('search_query', ['type' => 'string'], true);
        $query->expects($this->once())
            ->method('parseValue')
            ->with('agent')
            ->willReturn('AGENT');
        $limit = $this->createParameter('limit', ['type' => 'integer'], false, '', 10);
        $limit->expects($this->once())
            ->method('parseValue')
            ->with(2)
            ->willReturn('2');
        $adapter = new AiToolMcpAdapter($this->createTool([$query, $limit]));

        $this->assertSame(
            ['AGENT', '2'],
            $adapter->normalizeArguments(['limit' => 2, 'search_query' => 'agent'])
        );
    }

    public function testNormalizesDefaultsAndMissingRequiredArguments() : void
    {
        $query = $this->createParameter('search_query', ['type' => 'string'], true);
        $limit = $this->createParameter('limit', ['type' => 'integer'], false, '', 10);
        $limit->expects($this->once())
            ->method('parseValue')
            ->with(10)
            ->willReturn('10');
        $adapter = new AiToolMcpAdapter($this->createTool([$query, $limit]));

        $this->assertSame([null, '10'], $adapter->normalizeArguments([]));
    }

    /**
     * @param ServiceParameterInterface[] $parameters
     * @return AiToolInterface&MockObject
     */
    private function createTool(array $parameters, ?string $description = null, ?string $rules = null) : AiToolInterface
    {
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('SearchModelComponents');
        $tool->method('getDescription')->willReturn($description);
        $tool->method('getRules')->willReturn($rules);
        $tool->method('getArguments')->willReturn($parameters);

        return $tool;
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $default
     * @param mixed[]|null $examples
     * @return ServiceParameterInterface&MockObject
     */
    private function createParameter(
        string $name,
        array $schema,
        bool $required,
        string $description = '',
        $default = null,
        ?array $examples = null
    ) : ServiceParameterInterface {
        $parameter = $this->createMock(ServiceParameterInterface::class);
        $parameter->method('getName')->willReturn($name);
        $parameter->method('getCustomProperty')
            ->with('json_schema')
            ->willReturn(json_encode($schema, JSON_THROW_ON_ERROR));
        $parameter->method('isRequired')->willReturn($required);
        $parameter->method('getDescription')->willReturn($description);
        $parameter->method('hasDefaultValue')->willReturn($default !== null);
        $parameter->method('getDefaultValue')->willReturn($default);
        $parameter->method('hasExamples')->willReturn($examples !== null);
        $parameter->method('getExamples')->willReturn($examples);

        return $parameter;
    }
}