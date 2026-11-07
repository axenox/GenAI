<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\ApiAdapters;

use axenox\GenAI\Common\AiMessage;
use axenox\GenAI\Common\AiPayload;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\ApiAdapters\AiContextRenderer;
use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiResponseAdapter;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiResponseAdapter;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiResponseAdapter;
use axenox\GenAI\Common\DataQueries\AiQuery;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\DataConnectors\ClaudeConnector;
use axenox\GenAI\AI\Agents\GenericAssistant;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiConversationInterface;
use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\UxonObject;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ExchangeFixtureAssistant extends GenericAssistant
{
    public function __construct(Workbench $workbench)
    {
        $this->workbench = $workbench;
        $this->tools = [];
    }

    public function exchange(AiPromptInterface $prompt, AiConversationInterface $conversation) : AiQuery
    {
        return $this->createExchangeQuery($prompt, $conversation, 'Instructions');
    }
}

class ConversationReplayTest extends TestCase
{
    private Workbench $workbench;

    protected function setUp() : void
    {
        $this->workbench = new Workbench();
    }

    private function message(string $id, int $sequence, string $role, array $data, bool $finished = true) : AiMessage
    {
        return new AiMessage($this->workbench, [
            'UID' => $id, 'AI_CONVERSATION' => 'conversation', 'SEQUENCE_NUMBER' => $sequence,
            'ROLE' => $role, 'MESSAGE' => 'MONITORING PREVIEW MUST NOT BE REPLAYED',
            'DATA' => AiPayload::encode($data), 'FINISHED_ON' => $finished ? '2026-11-06 20:00:00' : null,
        ]);
    }

    private function batch(string $id, int $sequence, array $provider = []) : AiMessage
    {
        $calls = [];
        $results = [];
        foreach (['a', 'b'] as $suffix) {
            $callId = $id . $suffix;
            $calls[$callId] = (new AiToolCall('read', $callId, ['key' => $callId], '{"key":"' . $callId . '"}'))->jsonSerialize();
            $result = new AiToolResultString($this->workbench, (object) ['value' => $callId, 'empty' => new \stdClass()], null, ['Shared appendix']);
            $results[$callId] = $result->exportUxonObject()->toArray();
        }
        return $this->message($id, $sequence, 'tool calling', [
            'content' => [['type' => 'text', 'text' => 'Calling ' . $id]],
            'calls' => (object) $calls, 'call_order' => array_keys($calls),
            'results' => (object) $results, 'provider' => $provider,
        ]);
    }

    private function connector() : AiConnectorInterface
    {
        $connector = $this->createMock(ClaudeConnector::class);
        $connector->method('getModelName')->willReturn('fixture-model');
        $connector->method('getTemperature')->willReturn(null);
        $connector->method('getMaxTokens')->willReturn(1024);
        return $connector;
    }

    public function testTwoToolRoundsRetainEveryCallOnceAndEarlierQueriesStayUnchanged() : void
    {
        $context = [
            $this->message('system', 1, 'system', ['content' => [['type' => 'text', 'text' => 'Instructions']]]),
            $this->message('user', 2, 'user', ['content' => [['type' => 'text', 'text' => 'Question']]]),
        ];
        $first = (new AiQuery($this->workbench))->setContextMessages($context);
        $firstBody = (new ResponsesApiRequestAdapter($this->connector()))->buildBody($first);
        $context[] = $this->batch('round1', 3);
        $second = (new AiQuery($this->workbench))->setContextMessages($context);
        $secondBody = (new ResponsesApiRequestAdapter($this->connector()))->buildBody($second);
        $context[] = $this->batch('round2', 4);
        $third = (new AiQuery($this->workbench))->setContextMessages($context);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
        $this->assertSame($firstBody, (new ResponsesApiRequestAdapter($this->connector()))->buildBody($first));
        $this->assertSame($secondBody, (new ResponsesApiRequestAdapter($this->connector()))->buildBody($second));
        $this->assertNull($third->getTokensInPrompt());
        $this->assertNull($third->getTokensInAnswer());
        $this->assertNull($third->getCosts());
        foreach ([
            new CompletionsApiRequestAdapter($this->connector()),
            new ResponsesApiRequestAdapter($this->connector()),
            new ClaudeMessagesApiRequestAdapter($this->connector()),
        ] as $adapter) {
            $body = $adapter->buildBody($third);
            $this->assertStringNotContainsString('MONITORING PREVIEW', $body);
            $this->assertSame($body, $adapter->buildBody($third));
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (isset($decoded['input'])) {
                $callIds = array_column(array_filter($decoded['input'], static fn($item) => ($item['type'] ?? '') === 'function_call'), 'call_id');
                $resultIds = array_column(array_filter($decoded['input'], static fn($item) => ($item['type'] ?? '') === 'function_call_output'), 'call_id');
            } else {
                $callIds = [];
                $resultIds = [];
                foreach ($decoded['messages'] as $message) {
                    foreach ($message['tool_calls'] ?? [] as $call) {
                        $callIds[] = $call['id'];
                    }
                    if (isset($message['tool_call_id'])) {
                        $resultIds[] = $message['tool_call_id'];
                    }
                    foreach (is_array($message['content']) ? $message['content'] : [] as $block) {
                        if (($block['type'] ?? '') === 'tool_use') {
                            $callIds[] = $block['id'];
                        } elseif (($block['type'] ?? '') === 'tool_result') {
                            $resultIds[] = $block['tool_use_id'];
                        }
                    }
                }
            }
            $this->assertSame(['round1a', 'round1b', 'round2a', 'round2b'], $callIds);
            $this->assertSame($callIds, $resultIds);
        }
    }

    public function testRestoredTypedResultsMatchDirectResultsAcrossAllApis() : void
    {
        $batch = $this->batch('round1', 1);
        $restored = (new AiQuery($this->workbench))->setContextMessages([$batch]);
        $results = $batch->getToolResults();
        $fresh = $restored->withToolResults($results);
        foreach ([
            new CompletionsApiRequestAdapter($this->connector()),
            new ResponsesApiRequestAdapter($this->connector()),
            new ClaudeMessagesApiRequestAdapter($this->connector()),
        ] as $adapter) {
            $this->assertSame($adapter->buildBody($fresh), $adapter->buildBody($restored));
            $this->assertStringContainsString('\\"empty\\":{}', $adapter->buildBody($restored));
        }
        $results['round1a']->setDurationMs(99);
        $this->assertNull($fresh->getToolResults()['round1a']->getDurationMs());
    }

    public function testExactResponsesReasoningAndClaudeThinkingSurviveSameApiContinuation() : void
    {
        $output = [
            ['type' => 'reasoning', 'id' => 'reasoning1', 'encrypted_content' => 'retained', 'summary' => []],
            ['type' => 'function_call', 'id' => 'item1', 'call_id' => 'round1a', 'name' => 'read', 'arguments' => '{"key":"round1a"}'],
            ['type' => 'function_call', 'id' => 'item2', 'call_id' => 'round1b', 'name' => 'read', 'arguments' => '{"key":"round1b"}'],
        ];
        $query = (new AiQuery($this->workbench))->setContextMessages([$this->batch('round1', 1, ['responses' => ['output' => $output, 'status' => 'completed']])]);
        $body = json_decode((new ResponsesApiRequestAdapter($this->connector()))->buildBody($query), true);
        $this->assertSame($output, array_slice($body['input'], 0, 3));
        $this->assertCount(2, array_filter($body['input'], static fn($item) => $item['type'] === 'function_call_output'));
        $claude = [
            ['type' => 'thinking', 'thinking' => 'retained thought', 'signature' => 'exact-signature'],
            ['type' => 'tool_use', 'id' => 'round1a', 'name' => 'read', 'input' => ['key' => 'round1a']],
            ['type' => 'tool_use', 'id' => 'round1b', 'name' => 'read', 'input' => ['key' => 'round1b']],
        ];
        $query->setContextMessages([$this->batch('round1', 1, ['claude' => ['content' => $claude, 'stop_reason' => 'tool_use']])]);
        $body = json_decode((new ClaudeMessagesApiRequestAdapter($this->connector()))->buildBody($query), true);
        $this->assertSame($claude, $body['messages'][0]['content']);
    }

    public function testIncompleteBatchIsExplicitlyRejectedWithoutFabricatedResults() : void
    {
        $message = $this->message('incomplete', 1, 'tool calling', [
            'calls' => (object) ['call' => (new AiToolCall('read', 'call', []))->jsonSerialize()],
            'call_order' => ['call'], 'results' => new \stdClass(),
        ], false);
        $query = (new AiQuery($this->workbench))->setContextMessages([$message]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('incomplete');
        AiContextRenderer::messages($query, 'responses');
    }

    public function testResponseMetadataIsNormalizedAndUnknownUsageStaysNull() : void
    {
        $fixtures = [
            [CompletionsApiResponseAdapter::class, ['model' => 'c', 'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Answer']]]], 'completions'],
            [ResponsesApiResponseAdapter::class, ['model' => 'r', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Answer']]]]], 'responses'],
            [ClaudeMessagesApiResponseAdapter::class, ['model' => 'a', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Answer']]], 'claude'],
        ];
        foreach ($fixtures as [$class, $payload, $namespace]) {
            $httpResponse = new Response(200, [], json_encode($payload));
            $adapter = new $class($httpResponse);
            $query = (new AiQuery($this->workbench))->withResponse($httpResponse, $adapter);
            $data = $query->getResponseData();
            $this->assertSame([['type' => 'text', 'text' => 'Answer']], $data['content']);
            $this->assertArrayHasKey($namespace, $data['provider']);
            $this->assertSame($payload['model'], $data['model']);
            $this->assertNull($data['tokens_prompt']);
            $this->assertNull($data['tokens_completion']);
            $this->assertNull($data['cost']);
        }
    }

    public function testFreshActualResultsCanContinueAfterCompletionWriteFailure() : void
    {
        $call = new AiToolCall('read', 'call', []);
        $message = $this->message('failed-write', 1, 'tool', [
            'content' => [], 'calls' => (object) ['call' => $call->jsonSerialize()],
            'call_order' => ['call'], 'results' => new \stdClass(),
        ], false);
        $result = new AiToolResultString($this->workbench, 'Actual unsaved result');
        $query = (new AiQuery($this->workbench))->setContextMessages([$message])->withToolResults(['call' => $result]);
        $messages = AiContextRenderer::messages($query, 'responses');
        $this->assertSame('Actual unsaved result', $messages[1]['content']);
        $this->assertTrue($message->isBroken());
    }

    public function testDiagnosticsAndFailureInstructionsSurviveResultRestoration() : void
    {
        $result = new AiToolResultString($this->workbench);
        $result->importUxonObject(new UxonObject([
            'value' => (object) ['number' => 1.0], 'failed' => true,
            'errors' => ['ERROR: first', 'ERROR: second'],
            'warnings' => ['WARNING: first', 'WARNING: second'],
        ]));
        $text = AiContextRenderer::resultText($result);
        $this->assertStringContainsString('{"number":1.0}', $text);
        $this->assertSame(1, substr_count($text, 'ERROR: first'));
        $this->assertSame(1, substr_count($text, 'WARNING: first'));
        $this->assertStringContainsString('Do not repeat the same call unchanged.', $text);
        $this->assertSame($text, AiContextRenderer::resultText($result));
    }

    public function testAttachmentsRemainOnTheirOriginalMessagesWithoutQueryWideDuplication() : void
    {
        $context = [];
        foreach (['first', 'second'] as $position => $name) {
            $context[] = $this->message($name, $position + 1, 'user', ['content' => [
                ['type' => 'text', 'text' => $name],
                ['type' => 'attachment', 'filename' => $name . '.pdf', 'mime_type' => 'application/pdf',
                    'message_id' => $name, 'position' => 1, 'digest' => hash('sha256', $name), 'contents_base64' => base64_encode($name)],
            ]]);
        }
        $query = (new AiQuery($this->workbench))->setContextMessages($context);
        $responses = json_decode((new ResponsesApiRequestAdapter($this->connector()))->buildBody($query), true);
        $this->assertSame('first.pdf', $responses['input'][0]['content'][1]['filename']);
        $this->assertSame('second.pdf', $responses['input'][1]['content'][1]['filename']);
        $claude = json_decode((new ClaudeMessagesApiRequestAdapter($this->connector()))->buildBody($query), true);
        $this->assertSame(base64_encode('first'), $claude['messages'][0]['content'][1]['source']['data']);
        $this->assertSame(base64_encode('second'), $claude['messages'][1]['content'][1]['source']['data']);
        $this->assertCount(2, $claude['messages']);
    }

    public function testUnavailableAttachmentProducesExplicitReplayError() : void
    {
        $message = $this->message('attachment', 1, 'user', ['content' => [
            ['type' => 'attachment', 'filename' => 'secret.pdf', 'unavailable' => true, 'reason' => 'Access denied'],
        ]]);
        $query = (new AiQuery($this->workbench))->setContextMessages([$message]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Access denied');
        AiContextRenderer::messages($query, 'responses');
    }

    public function testQuerySnapshotDetachesMutableCallAndResultJson() : void
    {
        $value = (object) ['nested' => (object) ['value' => 'original']];
        $result = new AiToolResultString($this->workbench, $value);
        $message = $this->message('mutable', 1, 'tool', [
            'content' => [],
            'calls' => (object) ['call' => (new AiToolCall('read', 'call', ['nested' => (object) ['value' => 'original']]))->jsonSerialize()],
            'call_order' => ['call'],
            'results' => (object) ['call' => $result->exportUxonObject()->toArray()],
        ]);
        $query = (new AiQuery($this->workbench))->setContextMessages([$message])->withToolResults(['call' => $result]);
        $adapter = new ResponsesApiRequestAdapter($this->connector());
        $body = $adapter->buildBody($query);
        $value->nested->value = 'changed original';
        $message->getCalls()[0]->getArguments()['nested']->value = 'changed source call';
        $query->getContextMessages()[0]->getCalls()[0]->getArguments()['nested']->value = 'changed returned call';
        $query->getToolResults()['call']->getValue()->nested->value = 'changed returned result';
        $this->assertSame($body, $adapter->buildBody($query));
    }

    public function testAgentCreatesFreshExchangeWithOnlyItsOwnResponseMetadata() : void
    {
        $initial = [$this->message('user', 1, 'user', ['content' => [['type' => 'text', 'text' => 'Question']]])];
        $firstRound = array_merge($initial, [$this->batch('round1', 2)]);
        $secondRound = array_merge($firstRound, [$this->batch('round2', 3)]);
        $conversation = $this->createMock(AiConversationInterface::class);
        $conversation->method('getConversationId')->willReturn('conversation');
        $conversation->method('getMessages')->willReturnOnConsecutiveCalls($initial, $firstRound, $secondRound);
        $prompt = $this->createStub(AiPromptInterface::class);
        $prompt->method('getUserPrompt')->willReturn('Question');
        $prompt->method('getFiles')->willReturn([]);
        $agent = new ExchangeFixtureAssistant($this->workbench);
        $first = $agent->exchange($prompt, $conversation);
        $http = new Response(200, [], json_encode([
            'model' => 'reported', 'status' => 'completed', 'output' => [],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ]));
        $performed = $first->withResponse($http, new ResponsesApiResponseAdapter($http), 0.5);
        $second = $agent->exchange($prompt, $conversation);
        $third = $agent->exchange($prompt, $conversation);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
        $this->assertCount(1, $first->getContextMessages());
        $this->assertCount(2, $second->getContextMessages());
        $this->assertCount(3, $third->getContextMessages());
        $this->assertSame($conversation, $third->getConversation());
        $this->assertSame(5, $performed->getTokensInPrompt());
        $this->assertSame(2, $performed->getTokensInAnswer());
        $this->assertSame(0.5, $performed->getCosts());
        $this->assertFalse($third->hasResponse());
        $this->assertNull($third->getRequest());
        $this->assertNull($third->getTokensInPrompt());
        $this->assertNull($third->getCosts());
    }

    public function testCompletedProviderHistoryMapsSemanticallyAcrossApis() : void
    {
        $providers = [
            ['responses' => ['status' => 'completed', 'output' => [
                ['type' => 'reasoning', 'id' => 'reasoning', 'encrypted_content' => 'opaque', 'summary' => []],
                ['type' => 'function_call', 'call_id' => 'round1a', 'name' => 'read', 'arguments' => '{"key":"round1a"}'],
                ['type' => 'function_call', 'call_id' => 'round1b', 'name' => 'read', 'arguments' => '{"key":"round1b"}'],
            ]]],
            ['claude' => ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'thinking', 'thinking' => 'thought', 'signature' => 'opaque'],
                ['type' => 'tool_use', 'id' => 'round1a', 'name' => 'read', 'input' => ['key' => 'round1a']],
                ['type' => 'tool_use', 'id' => 'round1b', 'name' => 'read', 'input' => ['key' => 'round1b']],
            ]]],
        ];
        foreach ($providers as $provider) {
            $query = (new AiQuery($this->workbench))->setContextMessages([$this->batch('round1', 1, $provider)]);
            $messages = AiContextRenderer::messages($query, isset($provider['claude']) ? 'responses' : 'claude');
            $this->assertSame('Calling round1', $messages[0]['content']);
            $this->assertSame(['round1a', 'round1b'], array_column($messages[0]['tool_calls'], 'id'));
            $this->assertStringContainsString('"value":"round1a"', $messages[1]['content']);
            $this->assertStringContainsString('"value":"round1b"', $messages[2]['content']);
        }
    }

    public function testResponseCallArgumentsRetainNestedObjectsAndExactRawJson() : void
    {
        $raw = '{"empty":{},"number":1.0,"identifier":"00123"}';
        $payloads = [
            [CompletionsApiResponseAdapter::class, '{"choices":[{"finish_reason":"tool_calls","message":{"role":"assistant","content":null,"tool_calls":[{"id":"call","function":{"name":"read","arguments":' . json_encode($raw) . '}}]}}]}'],
            [ResponsesApiResponseAdapter::class, '{"status":"completed","output":[{"type":"function_call","name":"read","call_id":"call","arguments":' . json_encode($raw) . '}]}'],
            [ClaudeMessagesApiResponseAdapter::class, '{"stop_reason":"tool_use","content":[{"type":"tool_use","name":"read","id":"call","input":' . $raw . '}]}'],
        ];
        foreach ($payloads as [$class, $payload]) {
            $adapter = new $class(new Response(200, [], $payload));
            $call = $adapter->getToolCalls()[0];
            $this->assertInstanceOf(\stdClass::class, $call->getArguments()['empty']);
            $this->assertSame(1.0, $call->getArguments()['number']);
            $this->assertSame('00123', $call->getArguments()['identifier']);
            $this->assertSame($raw, $call->getRawArguments());
        }
    }

    public function testReusedProviderCallIdsKeepSeparateBatchResults() : void
    {
        $call = new AiToolCall('read', 'reused', []);
        $data = ['content' => [], 'calls' => (object) ['reused' => $call->jsonSerialize()], 'call_order' => ['reused'], 'results' => new \stdClass()];
        $context = [
            $this->message('first', 1, 'tool', $data, false),
            $this->message('second', 2, 'tool', $data, false),
        ];
        $query = (new AiQuery($this->workbench))->setContextMessages($context);
        $query = $query->withToolResults(['reused' => new AiToolResultString($this->workbench, 'first value')], 'first');
        $query = $query->withToolResults(['reused' => new AiToolResultString($this->workbench, 'second value')], 'second');
        $messages = AiContextRenderer::messages($query, 'responses');
        $this->assertSame('first value', $messages[1]['content']);
        $this->assertSame('second value', $messages[3]['content']);
        $this->assertSame(['first', 'second'], array_keys($query->getToolResultBatches()));
    }

    public function testNumericLookingCallIdsSurviveTypedResultsAndRestoredReplay() : void
    {
        $callOrder = ['123', '0', '00123'];
        $calls = [];
        $results = [];
        $storedResults = [];
        foreach ($callOrder as $id) {
            $calls[$id] = (new AiToolCall('read', $id, ['identifier' => $id]))->jsonSerialize();
            $results[$id] = new AiToolResultString($this->workbench, 'Result for ' . $id);
            $storedResults[$id] = $results[$id]->exportUxonObject()->toArray();
        }
        $this->assertSame([123, 0, '00123'], array_keys($results));
        $message = $this->message('numeric-ids', 1, 'tool', [
            'content' => [], 'calls' => (object) $calls, 'call_order' => $callOrder,
            'results' => (object) $storedResults,
        ]);
        $restoredMessage = $this->message('numeric-ids', 1, 'tool', $message->getData()->toArray());
        $restored = (new AiQuery($this->workbench))->setContextMessages([$restoredMessage]);
        $fresh = (new AiQuery($this->workbench))->setContextMessages([$message])->withToolResults($results, 'numeric-ids');
        $this->assertSame($callOrder, array_map(static fn($call) => $call->getCallId(), $fresh->getContextMessages()[0]->getCalls()));
        $this->assertSame($callOrder, array_map(static fn($call) => $call->getCallId(), $restored->getContextMessages()[0]->getCalls()));
        foreach ($callOrder as $id) {
            $this->assertSame('Result for ' . $id, $fresh->getToolResults()[$id]->getValue());
            $this->assertSame('Result for ' . $id, $fresh->getToolResultBatches()['numeric-ids'][$id]->getValue());
        }
        foreach ([
            new CompletionsApiRequestAdapter($this->connector()),
            new ResponsesApiRequestAdapter($this->connector()),
            new ClaudeMessagesApiRequestAdapter($this->connector()),
        ] as $adapter) {
            $body = $adapter->buildBody($fresh);
            $this->assertSame($body, $adapter->buildBody($restored));
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (isset($decoded['input'])) {
                $wireCalls = array_column(array_filter($decoded['input'], static fn($item) => ($item['type'] ?? '') === 'function_call'), 'call_id');
                $wireResults = array_column(array_filter($decoded['input'], static fn($item) => ($item['type'] ?? '') === 'function_call_output'), 'call_id');
            } else {
                $wireCalls = [];
                $wireResults = [];
                foreach ($decoded['messages'] as $entry) {
                    foreach ($entry['tool_calls'] ?? [] as $call) {
                        $wireCalls[] = $call['id'];
                    }
                    if (isset($entry['tool_call_id'])) {
                        $wireResults[] = $entry['tool_call_id'];
                    }
                    foreach (is_array($entry['content']) ? $entry['content'] : [] as $block) {
                        if (($block['type'] ?? '') === 'tool_use') {
                            $wireCalls[] = $block['id'];
                        } elseif (($block['type'] ?? '') === 'tool_result') {
                            $wireResults[] = $block['tool_use_id'];
                        }
                    }
                }
            }
            $this->assertSame($callOrder, $wireCalls);
            $this->assertSame($callOrder, $wireResults);
        }
    }
}
