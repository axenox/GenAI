<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Common\ApiAdapters;

use axenox\GenAI\AI\Tools\GetTimeTool;
use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiRequestAdapter;
use axenox\GenAI\Common\DataQueries\AiQuery;
use axenox\GenAI\DataConnectors\ClaudeConnector;
use axenox\GenAI\DataConnectors\OpenAiConnector;
use exface\Core\CommonLogic\Selectors\DataConnectorSelector;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies strict provider schemas permit null optional arguments without relaxing required ones.
 */
class OptionalToolArgumentsTest extends TestCase
{
    /**
     * Optional values accept null while keeping their declared type and custom constraints.
     *
     * @param string $api
     * @param bool $customSchema
     * @return void
     */
    #[DataProvider('schemas')]
    public function testAdvertisesNullableOptionalArguments(string $api, bool $customSchema) : void
    {
        $workbench = new Workbench();
        $connection = ['name' => 'connection', 'required' => false];
        if ($customSchema) {
            $connection['custom_properties'] = [
                'json_schema' => '{"type":"string","enum":["main"]}'
            ];
        }
        $tool = new GetTimeTool($workbench, new UxonObject([
            'name' => 'query',
            'arguments' => [
                ['name' => 'statement', 'required' => true],
                $connection
            ]
        ]));
        $query = (new AiQuery($workbench))->addTool($tool);
        $configuration = new UxonObject(['model' => 'fixture']);
        if ($api === 'claude') {
            $connector = new ClaudeConnector(new DataConnectorSelector($workbench, ClaudeConnector::class), $configuration);
            $adapter = new ClaudeMessagesApiRequestAdapter($connector);
        } else {
            $connector = new OpenAiConnector(new DataConnectorSelector($workbench, OpenAiConnector::class), $configuration);
            $adapter = $api === 'responses'
                ? new ResponsesApiRequestAdapter($connector)
                : new CompletionsApiRequestAdapter($connector);
        }
        $request = json_decode($adapter->buildBody($query), false, 512, JSON_THROW_ON_ERROR);
        $definition = $api === 'completions' ? $request->tools[0]->function : $request->tools[0];
        $schema = $api === 'claude' ? $definition->input_schema : $definition->parameters;

        foreach ([
            ['{"statement":"SELECT 1","connection":null}', true],
            ['{"statement":"SELECT 1","connection":"main"}', true],
            ['{"statement":"SELECT 1","connection":123}', false],
            ['{"statement":null,"connection":"main"}', false],
            ['{"statement":"SELECT 1"}', false],
            ['{"statement":"SELECT 1","connection":"other"}', ! $customSchema]
        ] as [$json, $valid]) {
            $validator = new Validator();
            $value = json_decode($json);
            $validator->validate($value, $schema);
            self::assertSame($valid, $validator->isValid(), $json);
        }
    }

    /**
     * Covers every provider with inferred and custom argument schemas.
     *
     * @return iterable<string,array{string,bool}>
     */
    public static function schemas() : iterable
    {
        foreach (['completions', 'responses', 'claude'] as $api) {
            yield $api . ' inferred' => [$api, false];
            yield $api . ' custom' => [$api, true];
        }
    }
}
