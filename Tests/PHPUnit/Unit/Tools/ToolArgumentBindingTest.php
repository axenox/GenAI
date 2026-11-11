<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Tools;

use axenox\GenAI\AI\Tools\CommandLineTool;
use axenox\GenAI\AI\Tools\GetTimeTool;
use axenox\GenAI\AI\Tools\GitTool;
use axenox\GenAI\AI\Tools\UxonAutosuggestTool;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the argument contract shared by all tool callers without starting a Workbench.
 */
class ToolArgumentBindingTest extends TestCase
{
    /**
     * Both calling conventions bind and parse values in configured parameter order.
     *
     * @param array<array-key,mixed> $arguments
     * @return void
     */
    #[DataProvider('orderedArguments')]
    public function testBindsAndParsesArguments(array $arguments) : void
    {
        $query = $this->parameter('query', true);
        $query->expects(self::once())->method('parseValue')->with('agent')->willReturn('AGENT');
        $limit = $this->parameter('limit', false, 10);
        $limit->expects(self::once())->method('parseValue')->with(2)->willReturn('2');

        self::assertSame(['AGENT', '2'], $this->tool([$query, $limit])->normalizeArguments($arguments));
    }

    /**
     * @return iterable<string,array{array<array-key,mixed>}>
     */
    public static function orderedArguments() : iterable
    {
        yield 'named in reverse order' => [['limit' => 2, 'query' => 'agent']];
        yield 'positional' => [['agent', 2]];
    }

    /**
     * Omitted defaults are parsed, including falsy values and defaults on required parameters.
     *
     * @param mixed $default
     * @param mixed $expected
     * @return void
     */
    #[DataProvider('defaults')]
    public function testAppliesDefaultsOnlyWhenOmitted(mixed $default, mixed $expected) : void
    {
        $parameter = $this->parameter('value', false, $default);
        if (is_scalar($default)) {
            $parameter->expects(self::once())->method('parseValue')->with($default)->willReturn($expected);
        } else {
            $parameter->expects(self::never())->method('parseValue');
        }
        $tool = $this->tool([$parameter]);
        self::assertSame([$expected], $tool->normalizeArguments([]));
        self::assertSame([null], $tool->normalizeArguments(['value' => null]));

        $required = $this->parameter('value', true, 'default');
        $required->method('parseValue')->willReturn('parsed default');
        self::assertSame(['parsed default'], $this->tool([$required])->normalizeArguments([]));
    }

    /**
     * @return iterable<string,array{mixed,mixed}>
     */
    public static function defaults() : iterable
    {
        yield 'optional without default' => [null, null];
        yield 'zero' => [0, '0'];
        yield 'false' => [false, '0'];
        yield 'empty string' => ['', ''];
        yield 'empty array' => [[], []];
        yield 'structured object' => [(object) ['nested' => (object) ['code' => '00123']], ['nested' => ['code' => '00123']]];
    }

    /**
     * Supplied falsy values are parsed instead of being replaced by defaults.
     *
     * @param mixed $value
     * @return void
     */
    #[DataProvider('falsyValues')]
    public function testPreservesSuppliedFalsyValues(mixed $value) : void
    {
        $parameter = $this->parameter('value', false, 'default');
        $parameter->expects(self::once())->method('parseValue')->with($value)->willReturn('parsed');
        self::assertSame(['parsed'], $this->tool([$parameter])->normalizeArguments(['value' => $value]));
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function falsyValues() : iterable
    {
        yield 'zero' => [0];
        yield 'false' => [false];
        yield 'empty string' => [''];
    }

    /**
     * Missing or explicitly null required values fail even when null could otherwise use a default.
     *
     * @param array<array-key,mixed> $arguments
     * @param mixed $default
     * @return void
     */
    #[DataProvider('missingRequiredArguments')]
    public function testRejectsMissingRequiredValues(array $arguments, mixed $default) : void
    {
        $parameter = $this->parameter('value', true, $default);
        $parameter->expects(self::never())->method('parseValue');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Missing required argument "value".');
        $this->tool([$parameter])->normalizeArguments($arguments);
    }

    /**
     * @return iterable<string,array{array<array-key,mixed>,mixed}>
     */
    public static function missingRequiredArguments() : iterable
    {
        yield 'omitted' => [[], null];
        yield 'explicit null' => [['value' => null], null];
        yield 'null does not use default' => [['value' => null], 'default'];
    }

    /**
     * Unknown names and excess positional values cannot be silently ignored.
     *
     * @param array<array-key,mixed> $arguments
     * @return void
     */
    #[DataProvider('unknownArguments')]
    public function testRejectsUnknownArguments(array $arguments) : void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown arguments:');
        $this->tool([$this->parameter('value')])->normalizeArguments($arguments);
    }

    /**
     * @return iterable<string,array{array<array-key,mixed>}>
     */
    public static function unknownArguments() : iterable
    {
        yield 'unknown name' => [['unknown' => 1]];
        yield 'excess positional value' => [[null, 1]];
        yield 'mixed keys' => [['value' => null, 0 => 1]];
    }

    /**
     * Parameter validation failures reach the caller instead of producing fallback values.
     *
     * @return void
     */
    public function testPropagatesParameterValidationFailure() : void
    {
        $parameter = $this->parameter('value', true);
        $parameter->method('parseValue')->willThrowException(new UnexpectedValueException('Invalid value'));
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid value');
        $this->tool([$parameter])->normalizeArguments(['value' => 'invalid']);
    }

    /**
     * Defaults previously hidden in invoke() are now available to binding and metadata consumers.
     *
     * @return void
     */
    public function testConcreteDefaultsAreDeclaredByParameters() : void
    {
        $workbench = new Workbench();
        foreach ([new CommandLineTool($workbench), new GitTool($workbench)] as $tool) {
            self::assertSame(['git status', '.'], $tool->normalizeArguments(['command' => 'git status']));
            self::assertSame(['git status', null], $tool->normalizeArguments(['command' => 'git status', 'folder' => null]));
        }
        $parameters = (new UxonAutosuggestTool($workbench))->getArguments();
        self::assertSame([], $parameters[1]->getDefaultValue());
        self::assertSame('', $parameters[3]->getDefaultValue());
    }

    /**
     * Overriding one parameter must retain the other prototype parameters and their defaults.
     *
     * @return void
     */
    public function testPartialArgumentOverridesRetainCompleteTemplates() : void
    {
        $tool = new CommandLineTool(new Workbench(), new UxonObject([
            'arguments' => [['name' => 'cli', 'description' => 'Configured command']]
        ]));
        self::assertCount(2, $tool->getArguments());
        self::assertSame('Configured command', $tool->getArguments()[0]->getDescription());
        self::assertTrue($tool->getArguments()[0]->isRequired());
        self::assertSame(['git status', '.'], $tool->normalizeArguments(['cli' => 'git status']));
    }

    /**
     * UXON configuration wraps structured defaults in UxonObject, not stdClass.
     *
     * @return void
     */
    public function testStructuredDefaultsImportedFromUxonBecomeArrays() : void
    {
        $configuration = new UxonObject([
            'arguments' => [[
                'name' => 'uxon',
                'default_value' => ['foo' => '00123', 'nested' => ['enabled' => false], 'empty' => []]
            ]]
        ]);
        $tool = new UxonAutosuggestTool(new Workbench(), $configuration);
        self::assertInstanceOf(UxonObject::class, $tool->getArguments()[0]->getDefaultValue());
        self::assertSame(
            [['foo' => '00123', 'nested' => ['enabled' => false], 'empty' => []], [], null, '', null, null, null],
            $tool->normalizeArguments([])
        );
        self::assertInstanceOf(UxonObject::class, $tool->getArguments()[0]->getDefaultValue());
    }

    /**
     * Uses the inherited normalizer with only parameter metadata substituted.
     *
     * @param ServiceParameterInterface[] $parameters
     * @return AiToolInterface
     */
    private function tool(array $parameters) : AiToolInterface
    {
        $tool = $this->getMockBuilder(GetTimeTool::class)
            ->setConstructorArgs([new Workbench()])
            ->onlyMethods(['getArguments'])
            ->getMock();
        $tool->method('getArguments')->willReturn($parameters);
        return $tool;
    }

    /**
     * Supplies a narrow parameter double to verify binding separately from data-type implementation.
     *
     * @param string $name
     * @param bool $required
     * @param mixed $default
     * @return ServiceParameterInterface
     */
    private function parameter(string $name, bool $required = false, mixed $default = null) : ServiceParameterInterface
    {
        $parameter = $this->createMock(ServiceParameterInterface::class);
        $parameter->method('getName')->willReturn($name);
        $parameter->method('isRequired')->willReturn($required);
        $parameter->method('getDefaultValue')->willReturn($default);
        return $parameter;
    }
}
