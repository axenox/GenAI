<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Tools;

use axenox\GenAI\AI\Tools\ModelComponentInfoTool;
use axenox\GenAI\AI\Tools\ModelSearchTool;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\ModelComponentDataType;
use exface\Core\Exceptions\DataTypes\DataTypeValidationError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Checks registry-backed component argument types without invoking model queries.
 */
class ModelComponentArgumentsTest extends TestCase
{
    private string $directory;
    private Workbench $workbench;
    private Filesystem $filesystem;

    /**
     * {@inheritDoc}
     * @see \PHPUnit\Framework\TestCase::setUp()
     */
    protected function setUp() : void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'genai-component-arguments-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->directory);
        $this->workbench = new Workbench([
            'SERVER.INSTALLATION_NAME' => 'model-component-arguments-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
        (new \ReflectionProperty(Workbench::class, 'installation_path'))->setValue($this->workbench, $this->directory);
        $this->filesystem->dumpFile(
            $this->directory . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ComponentRegistry.config.json',
            '{"COMPONENTS":{"object":{},"agent":{}},"SOURCES":{"exface.Core":["object"],"axenox.GenAI":["agent"]}}'
        );
    }

    /**
     * {@inheritDoc}
     * @see \PHPUnit\Framework\TestCase::tearDown()
     */
    protected function tearDown() : void
    {
        $this->filesystem->remove($this->directory);
        parent::tearDown();
    }

    /**
     * Both tools advertise installed component values through the shared data type.
     *
     * @return void
     */
    public function testUsesSharedEnumAndBindsRegisteredComponents() : void
    {
        $info = new ModelComponentInfoTool($this->workbench);
        $search = new ModelSearchTool($this->workbench);
        foreach ([$info, $search] as $tool) {
            foreach ($tool->getArguments() as $parameter) {
                if ($parameter->getName() === 'component') {
                    self::assertInstanceOf(ModelComponentDataType::class, $parameter->getDataType());
                    self::assertSame(['object', 'agent'], $parameter->getDataType()->getValues());
                }
            }
        }
        self::assertSame(['agent', 'test.App.AGENT'], $info->normalizeArguments([
            'component' => 'agent',
            'selector' => 'test.App.AGENT'
        ]));
        self::assertSame(['query', 'agent', null, null], $search->normalizeArguments([
            'search_query' => 'query',
            'component' => 'agent'
        ]));
    }

    /**
     * Component arguments continue to reject values absent from the registry.
     *
     * @return void
     */
    public function testRejectsUnregisteredComponentArguments() : void
    {
        $tool = new ModelComponentInfoTool($this->workbench);
        $this->expectException(DataTypeValidationError::class);
        $tool->normalizeArguments(['component' => 'unknown', 'selector' => 'test.App.UNKNOWN']);
    }

    /**
     * Existing argument customization can still restrict component enum values.
     *
     * @return void
     */
    public function testSupportsConfiguredComponentSubset() : void
    {
        $tool = new ModelComponentInfoTool($this->workbench, new UxonObject([
            'arguments' => [
                ['data_type' => [
                    'alias' => ModelComponentDataType::class,
                    'values' => ['agent' => 'agent']
                ]]
            ]
        ]));
        self::assertSame(['agent'], $tool->getArguments()[0]->getDataType()->getValues());
        $this->expectException(DataTypeValidationError::class);
        $tool->normalizeArguments(['component' => 'object', 'selector' => 'test.App.OBJECT']);
    }
}
