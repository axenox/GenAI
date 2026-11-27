<?php
namespace axenox\GenAI\Tests\PHPUnit\Integration;

use axenox\GenAI\Common\Mcp\McpWorkbenchScope;
use axenox\GenAI\Exceptions\AiConnectionNotFoundError;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the factory without sending requests to an LLM or modifying installation configuration.
 *
 * Set GENAI_TEST_CONNECTIONLESS_AGENT to an enabled agent with no connection in any version
 * or in its UXON, and GENAI_TEST_LLM_CONNECTION to the alias or UID of a configured LLM connection.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AiFactoryDefaultLlmConnectionTest extends TestCase
{
    /**
     * Resolves the default or reports why the agent cannot use it.
     *
     * @param string|null $default
     * @param string|null $expectedError
     * @return void
     */
    #[DataProvider('provideDefaults')]
    public function testDefaultLlmConnection(?string $default, ?string $expectedError) : void
    {
        $agentSelector = getenv('GENAI_TEST_CONNECTIONLESS_AGENT');
        if ($agentSelector === false || $agentSelector === '') {
            self::markTestSkipped('Set GENAI_TEST_CONNECTIONLESS_AGENT to an installed connectionless agent.');
        }
        if ($expectedError === null) {
            $default = getenv('GENAI_TEST_LLM_CONNECTION');
            if ($default === false || $default === '') {
                self::markTestSkipped('Set GENAI_TEST_LLM_CONNECTION to an installed LLM connection.');
            }
        }

        $workbench = McpWorkbenchScope::start();
        try {
            $versions = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_AGENT_VERSION');
            $versions->getFilters()->addConditionFromString(
                'AI_AGENT__ALIAS_WITH_NS',
                explode(':', $agentSelector, 2)[0],
                ComparatorDataType::EQUALS
            );
            $versions->getColumns()->addMultiple(['DATA_CONNECTION', 'CONFIG_UXON']);
            $this->assertAgentVersionsHaveNoConnection($versions);

            $workbench->getApp('axenox.GenAI')->getConfig()->setOption('DEFAULT_LLM_CONNECTION', $default);
            if ($expectedError === UnexpectedValueException::class) {
                $this->expectException($expectedError);
                $this->expectExceptionMessage('Invalid DEFAULT_LLM_CONNECTION in axenox.GenAI.config.json');
            }
            $agent = AiFactory::createAgentFromString($workbench, $agentSelector);
            if ($expectedError === AiConnectionNotFoundError::class) {
                $this->expectException($expectedError);
                $agent->getConnection();
                return;
            }
            self::assertSame(
                DataConnectionFactory::createFromModel($workbench, $default)->getId(),
                $agent->getConnection()->getId()
            );
            $this->assertAgentVersionsHaveNoConnection($versions);
        } finally {
            McpWorkbenchScope::stop($workbench);
        }
    }

    /**
     * Checks that the fixture cannot trigger the factory's persistent inheritance path.
     *
     * @param DataSheetInterface $versions
     * @return void
     */
    private function assertAgentVersionsHaveNoConnection(DataSheetInterface $versions) : void
    {
        $versions->dataRead();
        self::assertFalse($versions->isEmpty(), 'The test agent must exist.');
        foreach ($versions->getRows() as $row) {
            self::assertContains($row['DATA_CONNECTION'], [null, '']);
            $uxon = UxonObject::fromAnything($row['CONFIG_UXON']);
            self::assertFalse($uxon->findPropertyKey('data_connection_alias'));
        }
    }

    /**
     * Supplies a valid, blank, unknown, and non-LLM connection case.
     *
     * @return array<string,array{string|null, class-string|null}>
     */
    public static function provideDefaults() : array
    {
        return [
            'configured LLM' => [null, null],
            'blank default' => ['', AiConnectionNotFoundError::class],
            'unknown selector' => ['axenox.GenAI.TEST_NONEXISTENT_LLM_CONNECTION', UnexpectedValueException::class],
            'non-LLM connector' => ['exface.Core.METAMODEL_DB', UnexpectedValueException::class],
        ];
    }
}
