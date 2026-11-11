<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Common\ApiAdapters;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiResponseAdapter;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiResponseAdapter;
use axenox\GenAI\Common\ApiAdapters\ConversationApiAdapterTrait;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiResponseAdapter;
use axenox\GenAI\Common\DataQueries\AiQuery;
use axenox\GenAI\Common\Messages\AbstractAiMessage;
use axenox\GenAI\Common\Messages\AiMessageSerializer;
use axenox\GenAI\Common\Messages\InputMessage;
use axenox\GenAI\Common\Messages\InstructionsMessage;
use axenox\GenAI\Common\Messages\OutputMessage;
use axenox\GenAI\Common\Messages\ToolCallMessage;
use axenox\GenAI\Common\Messages\UnavailableAttachment;
use axenox\GenAI\DataConnectors\ClaudeConnector;
use axenox\GenAI\DataConnectors\OpenAiConnector;
use axenox\GenAI\DataTypes\AiFinishReasonDataType;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiProviderDataQueryError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\Filesystem\InMemoryFile;
use exface\Core\CommonLogic\Selectors\DataConnectorSelector;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Verifies, that any conversation can be continued with any API adapter - using real Workbenches, no database and no LLM.
 * 
 * Messages pass through the same serializer the conversation uses, so every test continues a
 * stored conversation.
 * 
 * @author Andrej Kabachnik
 */
class ConversationApiAdaptersTest extends TestCase
{
    const APIS = ['completions', 'responses', 'claude'];
    const PNG = "\x89PNG\r\n\x1a\nfixture";
    const RESPONSES_MARKER = 'encrypted-continuation';
    const CLAUDE_MARKER = 'signature-00123';

    /**
     * Every combination of source and target adapter.
     * 
     * @return iterable
     */
    public static function apiPairs() : iterable
    {
        foreach (self::APIS as $source) {
            foreach (self::APIS as $target) {
                yield "$source to $target" => [$source, $target];
            }
        }
    }

    /**
     * Every supported request API.
     * 
     * @return iterable
     */
    public static function apis() : iterable
    {
        foreach (self::APIS as $api) {
            yield $api => [$api];
        }
    }

    /**
     * Continues a stored conversation with tool rounds, attachments and structured answers with any adapter.
     * 
     * Own adapter data is replayed only by the adapter, that produced it.
     * 
     * @param string $source
     * @param string $target
     * @return void
     */
    #[DataProvider('apiPairs')]
    public function testContinueStoredConversationWithAnyAdapter(string $source, string $target) : void
    {
        $workbench = new Workbench();
        $messages = $this->createConversation($workbench, $source);
        $query = new AiQuery($workbench, null, $messages);
        $json = $this->createRequestAdapter($target, $workbench)->buildBody($query);
        $request = $this->readProtocol($target, json_decode($json, false, 512, JSON_THROW_ON_ERROR));

        self::assertSame([], $query->getWarnings());
        self::assertStringContainsString('Be helpful', $request['instructions']);
        self::assertContains('What time is it? 00123', $request['texts']);
        self::assertContains('round one text', $request['texts']);
        self::assertContains('{"answer":"00123","retained":{"object":{},"list":[]}}', $request['texts']);
        self::assertContains('Thanks', $request['texts']);
        self::assertCount(1, $request['calls']);
        self::assertSame('GetTime', $request['calls'][0]['name']);
        self::assertSame('00123', $request['calls'][0]['arguments']->code);
        self::assertCount(1, $request['results']);
        self::assertSame($request['calls'][0]['id'], $request['results'][0]['id']);
        self::assertSame('{"looks":"json but remains a string"}', $request['results'][0]['content']);
        self::assertStringContainsString(base64_encode(self::PNG), $json);

        // Adapter data is only visible to its own adapter
        foreach (['responses' => self::RESPONSES_MARKER, 'claude' => self::CLAUDE_MARKER] as $owner => $marker) {
            if ($source === $owner && $target === $owner) {
                self::assertStringContainsString($marker, $json);
            } else {
                self::assertStringNotContainsString($marker, $json);
            }
        }
    }

    /**
     * Response adapters return generic messages, generic finish reasons and usage.
     * 
     * @param string $api
     * @return void
     */
    #[DataProvider('apis')]
    public function testResponseAdaptersReturnGenericMessages(string $api) : void
    {
        $adapter = $this->createToolResponseAdapter($api, 'round one text', [['id' => 'call_1', 'name' => 'GetTime', 'arguments' => '{"zone":"UTC"}']]);
        $message = $adapter->getResponseMessage();
        self::assertInstanceOf(AiToolCallMessageInterface::class, $message);
        self::assertTrue($message->isOutput());
        self::assertSame('round one text', $message->getText());
        self::assertInstanceOf(\stdClass::class, $message->getCalls()[0]->getOriginalArguments());
        self::assertSame(AiFinishReasonDataType::TOOL_CALLS, $adapter->getFinishReason());
        self::assertStringStartsWith('fixture', $adapter->getModelName());
        self::assertSame(7, $adapter->getTokensInPrompt());

        $final = $this->createTextResponseAdapter($api, 'done');
        self::assertNotInstanceOf(AiToolCallMessageInterface::class, $final->getResponseMessage());
        self::assertSame(AiFinishReasonDataType::COMPLETED, $final->getFinishReason());
        self::assertSame([], $final->getWarnings());
    }

    /**
     * Unfinished answers keep their text, report TRUNCATED and can be continued by any adapter.
     * 
     * @param string $source
     * @param string $target
     * @return void
     */
    #[DataProvider('apiPairs')]
    public function testUnfinishedAnswersCanBeContinued(string $source, string $target) : void
    {
        $payload = match ($source) {
            'completions' => ['model' => 'fixture', 'choices' => [['finish_reason' => 'length', 'message' => ['role' => 'assistant', 'content' => 'partial']]]],
            'responses' => ['model' => 'fixture', 'status' => 'incomplete', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'partial', 'annotations' => []]]]]],
            default => ['model' => 'fixture', 'stop_reason' => 'pause_turn', 'content' => [['type' => 'text', 'text' => 'partial']]],
        };
        $adapter = $this->createResponseAdapter($source, $payload);
        self::assertFalse($adapter->isError());
        self::assertSame(AiFinishReasonDataType::TRUNCATED, $adapter->getFinishReason());
        $workbench = new Workbench();
        $messages = [
            $this->store($workbench, new InputMessage('Tell me a story'), 0),
            $this->store($workbench, $adapter->getResponseMessage(), 1),
            $this->store($workbench, new InputMessage('continue'), 2)
        ];
        $query = new AiQuery($workbench, null, $messages);
        $request = $this->readProtocol($target, json_decode($this->createRequestAdapter($target, $workbench)->buildBody($query), false));
        self::assertSame(['Tell me a story', 'partial', 'continue'], $request['texts']);
    }

    /**
     * Legacy DATA formats are restored by the conversation serializer and replayed by every adapter.
     * 
     * @param string $api
     * @return void
     */
    #[DataProvider('apis')]
    public function testLegacyHistoryIsReplayedByEveryAdapter(string $api) : void
    {
        $workbench = new Workbench();
        $serializer = new AiMessageSerializer($workbench);
        $rows = [
            ['developer', 'Old developer instructions', '{}'],
            ['user', 'Monitoring only', '{"content":[{"type":"text","text":"Legacy question"}]}'],
            ['tool calling', 'Old tool preview', '{"tool_calls":[{"id":"old_call","type":"function","function":{"name":"OldTool","arguments":"{}"}}]}'],
            ['assistant', 'Extracted answer for designers', '{"fullJsonResponse":{"text":"00123"},"provider":{"openai.chat_completions":{"message":{"role":"assistant"}}},"portable":false}'],
            ['tool', 'WIP preview', '{"content":[],"calls":[{"call_id":"wip.call/1","tool_name":"GetTime","arguments":{}}],"results":[{"call_id":"wip.call/1","class":"\\\\axenox\\\\GenAI\\\\Common\\\\AiToolResultString","value":"12:00"}],"provider":{"anthropic.messages":{"content":[]}}}'],
            ['warning', 'Designers only', '{}'],
            ['user', 'Broken data fallback', '{broken']
        ];
        $messages = [];
        foreach ($rows as $index => [$type, $text, $data]) {
            $messages[] = $serializer->fromRow([
                'UID' => 'legacy' . $index, 'AI_CONVERSATION' => 'c', 'SEQUENCE_NUMBER' => $index, 'ROLE' => $type, 'MESSAGE' => $text
            ], $serializer->decode($data));
        }
        $query = new AiQuery($workbench, null, $messages);
        $request = $this->readProtocol($api, json_decode($this->createRequestAdapter($api, $workbench)->buildBody($query), false));
        self::assertStringContainsString('Old developer instructions', $request['instructions']);
        self::assertSame(['Legacy question', '{"text":"00123"}', 'Broken data fallback'], $request['texts']);
        // The legacy batch without results is omitted, the completed WIP batch is sent with a safe ID
        self::assertCount(1, $request['calls']);
        self::assertSame('GetTime', $request['calls'][0]['name']);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $request['calls'][0]['id']);
        self::assertSame($request['calls'][0]['id'], $request['results'][0]['id']);
        self::assertSame('12:00', $request['results'][0]['content']);
        self::assertCount(1, $query->getWarnings());
        self::assertStringContainsString('without results', (string) $query->getWarnings()[0]);
    }

    /**
     * Unsupported and missing attachments become notes and warnings - the request is still built.
     * 
     * @param string $api
     * @return void
     */
    #[DataProvider('apis')]
    public function testAttachmentsDegradeToNotes(string $api) : void
    {
        $workbench = new Workbench();
        $messages = [
            $this->store($workbench, new InputMessage('See files', [
                new InMemoryFile('heic-bytes', 'photo.heic', 'image/heic'),
                new UnavailableAttachment('lost.png', 'image/png')
            ]), 0),
            $this->store($workbench, new OutputMessage('Here is your chart', [new InMemoryFile(self::PNG, 'chart.png', 'image/png')]), 1)
        ];
        $query = new AiQuery($workbench, null, $messages);
        $json = $this->createRequestAdapter($api, $workbench)->buildBody($query);
        $request = $this->readProtocol($api, json_decode($json, false));
        self::assertSame(
            "See files\n\n[photo.heic was attached but cannot be sent to this model]\n\n[lost.png was attached but cannot be sent to this model]",
            $request['texts'][0]
        );
        self::assertSame("Here is your chart\n\n[chart.png was attached but cannot be sent to this model]", $request['texts'][1]);
        self::assertStringNotContainsString('heic-bytes', $json);
        self::assertCount(3, $query->getWarnings());
    }

    /**
     * Incomplete tool batches are omitted entirely - calls and results together - with a warning.
     * 
     * @param string $api
     * @return void
     */
    #[DataProvider('apis')]
    public function testIncompleteToolBatchesAreOmitted(string $api) : void
    {
        $workbench = new Workbench();
        $complete = (new ToolCallMessage('', [new AiToolCall('Done', 'call_1', new \stdClass())]))
            ->withToolResults(['call_1' => new AiToolResultString($workbench, 'ok')]);
        $incomplete = (new ToolCallMessage('I will check', [
            new AiToolCall('Done', 'call_2', new \stdClass()),
            new AiToolCall('Open', 'call_3', new \stdClass())
        ]))->withToolResults(['call_2' => new AiToolResultString($workbench, 'ok')]);
        $query = new AiQuery($workbench, null, [
            $this->store($workbench, new InputMessage('Go'), 0),
            $this->store($workbench, $complete, 1),
            $this->store($workbench, $incomplete, 2),
            $this->store($workbench, new InputMessage('Again'), 3)
        ]);
        $request = $this->readProtocol($api, json_decode($this->createRequestAdapter($api, $workbench)->buildBody($query), false));
        self::assertSame(['Go', 'I will check', 'Again'], $request['texts']);
        self::assertSame(['call_1'], array_column($request['calls'], 'id'));
        self::assertSame(['call_1'], array_column($request['results'], 'id'));
        self::assertCount(1, $query->getWarnings());
    }

    /**
     * Duplicate or unsafe call IDs are mapped to safe unique IDs, consistently for calls and results.
     * 
     * @param string $api
     * @return void
     */
    #[DataProvider('apis')]
    public function testCallIdsAreMappedToSafeUniqueIds(string $api) : void
    {
        $workbench = new Workbench();
        $batch = fn(string $id) => (new ToolCallMessage('', [new AiToolCall('Tool', $id, ['positional'])]))
            ->withToolResults([$id => new AiToolResultString($workbench, 'ok ' . $id)]);
        $query = new AiQuery($workbench, null, [
            $this->store($workbench, new InputMessage('Go'), 0),
            $this->store($workbench, $batch('call.1/x'), 1),
            $this->store($workbench, $batch('call.1/x'), 2),
            $this->store($workbench, $batch('safe_id'), 3),
            $this->store($workbench, $batch(str_repeat('a', 41)), 4)
        ]);
        $request = $this->readProtocol($api, json_decode($this->createRequestAdapter($api, $workbench)->buildBody($query), false));
        $ids = array_column($request['calls'], 'id');
        self::assertCount(4, array_unique($ids));
        self::assertSame('safe_id', $ids[2]);
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,40}$/', $id);
        }
        self::assertSame($ids, array_column($request['results'], 'id'));
        // Argument lists are sent as objects
        self::assertInstanceOf(\stdClass::class, $request['calls'][0]['arguments']);
        self::assertSame([], $query->getWarnings());
    }

    /**
     * Adapter data is ignored if it no longer matches the generic message or the model - the generic request is built then.
     * 
     * @return void
     */
    public function testAdapterDataIsIgnoredWhenItDoesNotMatch() : void
    {
        $workbench = new Workbench();
        foreach (['responses' => self::RESPONSES_MARKER, 'claude' => self::CLAUDE_MARKER] as $api => $marker) {
            /** @var AbstractAiMessage $original */
            $original = $this->createTextResponseAdapter($api, 'original answer')->getResponseMessage();
            $adapterKey = $api === 'responses' ? ResponsesApiResponseAdapter::ADAPTER_DATA_KEY : ClaudeMessagesApiResponseAdapter::ADAPTER_DATA_KEY;
            self::assertNotNull($original->getAdapterData($adapterKey));

            // Same model and matching text - replayed
            $json = $this->buildRequest($api, $workbench, [$this->store($workbench, $original, 0)]);
            self::assertStringContainsString($marker, $json);

            // Text changed (e.g. edited or split) - ignored
            $changed = new OutputMessage('changed answer', [], $original->getAdapterDataAll());
            $json = $this->buildRequest($api, $workbench, [$this->store($workbench, $changed, 0)]);
            self::assertStringNotContainsString($marker, $json);
            self::assertStringContainsString('changed answer', $json);

            // Another model - ignored, and exactly the same request as without any adapter data
            $otherModel = $this->buildRequest($api, $workbench, [$this->store($workbench, $original, 0)], 'other-model');
            $dropped = $this->buildRequest($api, $workbench, [$this->store($workbench, $original->withoutAdapterData(), 0)], 'other-model');
            self::assertStringNotContainsString($marker, $otherModel);
            self::assertSame($dropped, $otherModel);
        }
    }

    /**
     * A new adapter can be implemented with the message interfaces and the shared trait only - and is interoperable.
     * 
     * @return void
     */
    public function testNewAdapterNeedsNoChangesElsewhere() : void
    {
        $workbench = new Workbench();
        $response = new Response(200, [], json_encode([
            'state' => 'all-done', 'say' => 'Here is the chart', 
            'file' => ['name' => 'chart.png', 'mime' => 'image/png', 'base64' => base64_encode(self::PNG)]
        ]));
        $responseAdapter = $this->createFixtureResponseAdapter($response);
        $message = $responseAdapter->getResponseMessage();
        self::assertSame(AiFinishReasonDataType::COMPLETED, $responseAdapter->getFinishReason());
        self::assertSame('chart.png', $message->getAttachments()[0]->getFilename());

        // Messages of the new adapter can be continued by every existing adapter
        $stored = [
            $this->store($workbench, new InstructionsMessage('Be helpful'), 0),
            $this->store($workbench, new InputMessage('Draw a chart'), 1),
            $this->store($workbench, $message, 2)
        ];
        foreach (self::APIS as $api) {
            $query = new AiQuery($workbench, null, $stored);
            $request = $this->readProtocol($api, json_decode($this->createRequestAdapter($api, $workbench)->buildBody($query), false));
            self::assertSame(['Draw a chart', "Here is the chart\n\n[chart.png was attached but cannot be sent to this model]"], $request['texts']);
        }

        // The new adapter can continue conversations of every existing adapter
        foreach (self::APIS as $api) {
            $query = new AiQuery($workbench, null, $this->createConversation($workbench, $api));
            $native = json_decode($this->createFixtureRequestAdapter()->buildBody($query), true);
            self::assertSame('Be helpful', $native['rules']);
            self::assertContains('ask: What time is it? 00123', $native['turns']);
            self::assertContains('call GetTime -> {"looks":"json but remains a string"}', $native['turns']);
        }
    }

    /**
     * A new message class with an existing context role is rendered by every adapter without adapter changes.
     * 
     * Adapters must never ask for the message type - the fixture throws if they do.
     * 
     * @param string $api
     * @return void
     */
    #[DataProvider('apis')]
    public function testNewMessageClassNeedsNoAdapterChanges(string $api) : void
    {
        $workbench = new Workbench();
        $summary = new class implements AiMessageInterface {
            public function getUid() : string { return 'summary'; }
            public function getConversationId() : string { return 'c'; }
            public function getSequenceNumber() : int { return 1; }
            public function getType() : string { throw new \LogicException('Adapters must not use message types'); }
            public function isInstructions() : bool { return true; }
            public function isInput() : bool { return false; }
            public function isOutput() : bool { return false; }
            public function getText() : string { return 'Summary of earlier messages'; }
            public function getAttachments() : array { return []; }
            public function toMarkdown() : string { return 'Summary'; }
            public function getAdapterData(string $key) : ?array { return null; }
        };
        $question = new class extends OutputMessage {
            public function __construct() { parent::__construct('Which format do you prefer?', [], [], 'question'); }
        };
        $query = new AiQuery($workbench, null, [
            new InstructionsMessage('Be helpful'),
            $summary,
            $question,
            new InputMessage('PDF')
        ]);
        $request = $this->readProtocol($api, json_decode($this->createRequestAdapter($api, $workbench)->buildBody($query), false));
        self::assertStringContainsString('Be helpful', $request['instructions']);
        self::assertStringContainsString('Summary of earlier messages', $request['instructions']);
        self::assertSame(['Which format do you prefer?', 'PDF'], $request['texts']);
    }

    /**
     * A response truncated while reasoning keeps no native items - a lone reasoning item cannot be replayed.
     * 
     * @return void
     */
    public function testTruncatedReasoningIsNotKeptAsAdapterData() : void
    {
        $workbench = new Workbench();
        $adapter = $this->createResponseAdapter('responses', [
            'model' => 'fixture', 'status' => 'incomplete', 'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => self::RESPONSES_MARKER]
            ]
        ]);
        /** @var AbstractAiMessage $message */
        $message = $adapter->getResponseMessage();
        self::assertSame(AiFinishReasonDataType::TRUNCATED, $adapter->getFinishReason());
        self::assertNull($message->getAdapterData(ResponsesApiResponseAdapter::ADAPTER_DATA_KEY));

        // Even if such data was stored earlier, it is not replayed
        $stored = new OutputMessage('', [], [ResponsesApiResponseAdapter::ADAPTER_DATA_KEY => [
            'model' => 'fixture', 'output' => [(object) ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => self::RESPONSES_MARKER]]
        ]]);
        $json = $this->buildRequest('responses', $workbench, [new InputMessage('Go'), $this->store($workbench, $stored, 1)]);
        self::assertStringNotContainsString(self::RESPONSES_MARKER, $json);
    }

    /**
     * The Responses API receives all documented file types as files - unknown types become notes.
     * 
     * @return void
     */
    public function testResponsesSendsDocumentedFileTypes() : void
    {
        $workbench = new Workbench();
        $files = [
            new InMemoryFile('%PDF-1.4', 'doc.pdf', 'application/pdf'),
            new InMemoryFile('# Title', 'notes.md', 'text/markdown; charset=utf-8'),
            new InMemoryFile('a;b', 'table.csv', 'text/csv'),
            new InMemoryFile('docx-bytes', 'letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            new InMemoryFile('xlsx-bytes', 'sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            new InMemoryFile('pptx-bytes', 'slides.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'),
            new InMemoryFile('{}', 'data.json', 'application/json'),
            new InMemoryFile(self::PNG, 'photo.png', 'image/png'),
            new InMemoryFile('zip-bytes', 'archive.zip', 'application/zip')
        ];
        $query = new AiQuery($workbench, null, [new InputMessage('See files', $files)]);
        $native = json_decode($this->createRequestAdapter('responses', $workbench)->buildBody($query), true);
        $content = $native['input'][0]['content'];
        self::assertSame("See files\n\n[archive.zip was attached but cannot be sent to this model]", $content[0]['text']);
        self::assertSame(
            ['doc.pdf', 'notes.md', 'table.csv', 'letter.docx', 'sheet.xlsx', 'slides.pptx', 'data.json'],
            array_column(array_filter($content, fn($part) => $part['type'] === 'input_file'), 'filename')
        );
        self::assertCount(1, array_filter($content, fn($part) => $part['type'] === 'input_image'));
        self::assertCount(1, $query->getWarnings());
    }

    /**
     * Claude rejects empty text blocks - tool call messages without text produce none.
     * 
     * @return void
     */
    public function testClaudeOmitsEmptyTextBlocks() : void
    {
        $workbench = new Workbench();
        $batch = (new ToolCallMessage('', [new AiToolCall('Tool', 'call_1', new \stdClass())]))
            ->withToolResults(['call_1' => new AiToolResultString($workbench, 'ok')]);
        $native = json_decode($this->buildRequest('claude', $workbench, [new InputMessage('Go'), $batch]), true);
        self::assertSame([['type' => 'tool_use', 'id' => 'call_1', 'name' => 'Tool', 'input' => []]], $native['messages'][1]['content']);
    }

    /**
     * Citations become a list of sources, unsupported content is dropped with a warning.
     * 
     * @return void
     */
    public function testResponseAdaptersNormalizeCitationsAndUnsupportedContent() : void
    {
        $responses = $this->createResponseAdapter('responses', [
            'model' => 'fixture', 'status' => 'completed', 'output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Answer', 'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://example.com', 'title' => 'Example']
                ]]]],
                ['type' => 'mystery_item']
            ]
        ]);
        self::assertSame("Answer\n\nSources:\n- [Example](https://example.com)", $responses->getResponseMessage()->getText());
        self::assertCount(1, $responses->getWarnings());

        $claude = $this->createResponseAdapter('claude', [
            'model' => 'fixture', 'stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Part one, '],
                ['type' => 'text', 'text' => 'part two', 'citations' => [['type' => 'web_search_result_location', 'url' => 'https://example.com', 'title' => 'Example']]]
            ]
        ]);
        self::assertSame("Part one, part two\n\nSources:\n- [Example](https://example.com)", $claude->getResponseMessage()->getText());
        self::assertSame([], $claude->getWarnings());

        $completions = $this->createResponseAdapter('completions', [
            'model' => 'fixture', 'choices' => [['finish_reason' => 'content_filter', 'message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that']]]
        ]);
        self::assertSame('I cannot help with that', $completions->getResponseMessage()->getText());
        self::assertSame(AiFinishReasonDataType::REFUSED, $completions->getFinishReason());
    }

    /**
     * Generated images of the Responses API become attachments of the response message.
     * 
     * @return void
     */
    public function testGeneratedFilesBecomeAttachments() : void
    {
        $adapter = $this->createResponseAdapter('responses', [
            'model' => 'fixture', 'status' => 'completed', 'output' => [
                ['type' => 'image_generation_call', 'id' => 'ig_1', 'status' => 'completed', 'output_format' => 'png', 'result' => base64_encode(self::PNG)],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Here you go', 'annotations' => []]]]
            ]
        ]);
        $message = $adapter->getResponseMessage();
        self::assertSame('Here you go', $message->getText());
        self::assertCount(1, $message->getAttachments());
        self::assertSame(self::PNG, $message->getAttachments()[0]->openFile()->read());
        self::assertSame('image/png', $message->getAttachments()[0]->getMimetype());
    }

    /**
     * Builds a stored conversation with instructions, an image, a tool round and a structured answer produced by the source adapter.
     * 
     * @param Workbench $workbench
     * @param string $source
     * @return AiMessageInterface[]
     */
    private function createConversation(Workbench $workbench, string $source) : array
    {
        $toolMessage = $this->createToolResponseAdapter($source, 'round one text', [
            ['id' => 'call_1', 'name' => 'GetTime', 'arguments' => '{"zone":"UTC","code":"00123"}']
        ])->getResponseMessage();
        $toolMessage = $toolMessage->withToolResults(['call_1' => new AiToolResultString($workbench, '{"looks":"json but remains a string"}')]);
        $answer = $this->createTextResponseAdapter($source, '{"answer":"00123","retained":{"object":{},"list":[]}}')->getResponseMessage();
        return [
            $this->store($workbench, new InstructionsMessage('Be helpful'), 0),
            $this->store($workbench, new InputMessage('What time is it? 00123', [new InMemoryFile(self::PNG, 'photo.png', 'image/png')]), 1),
            $this->store($workbench, $toolMessage, 2),
            $this->store($workbench, $answer, 3),
            $this->store($workbench, AiFactory::createMessage(AiMessageTypeDataType::WARNING, 'Designers only'), 4),
            $this->store($workbench, new InputMessage('Thanks'), 5)
        ];
    }

    /**
     * Saves and loads a message with the serializer of the conversation - attachments are passed through as the conversation reloads them.
     * 
     * @param Workbench $workbench
     * @param AiMessageInterface $message
     * @param int $sequence
     * @return AbstractAiMessage
     */
    private function store(Workbench $workbench, AiMessageInterface $message, int $sequence) : AbstractAiMessage
    {
        /** @var AbstractAiMessage $message */
        $serializer = new AiMessageSerializer($workbench);
        $data = $serializer->decode($serializer->encode($serializer->toData($message)));
        return $serializer->fromRow([
            'UID' => 'm' . $sequence, 'AI_CONVERSATION' => 'c', 'SEQUENCE_NUMBER' => $sequence,
            'ROLE' => $message->getType(), 'MESSAGE' => 'MONITORING PREVIEW'
        ], $data, $message->getAttachments());
    }

    /**
     * Builds the request body of a target API for the given messages
     * 
     * @param string $api
     * @param Workbench $workbench
     * @param AiMessageInterface[] $messages
     * @param string $model
     * @return string
     */
    private function buildRequest(string $api, Workbench $workbench, array $messages, string $model = 'fixture') : string
    {
        return $this->createRequestAdapter($api, $workbench, $model)->buildBody(new AiQuery($workbench, null, $messages));
    }

    /**
     * Uses real connector configuration without connecting to any service.
     * 
     * @param string $api
     * @param Workbench $workbench
     * @param string $model
     * @return HttpRequestAdapterInterface
     */
    private function createRequestAdapter(string $api, Workbench $workbench, string $model = 'fixture') : HttpRequestAdapterInterface
    {
        if ($api === 'claude') {
            return new ClaudeMessagesApiRequestAdapter(new ClaudeConnector(new DataConnectorSelector($workbench, ClaudeConnector::class), new UxonObject(['model' => $model])));
        }
        $connector = new OpenAiConnector(new DataConnectorSelector($workbench, OpenAiConnector::class), new UxonObject(['model' => $model]));
        return $api === 'responses' ? new ResponsesApiRequestAdapter($connector) : new CompletionsApiRequestAdapter($connector);
    }

    /**
     * Creates a response adapter from a native payload.
     * 
     * @param string $api
     * @param array<string,mixed> $payload
     * @return HttpResponseAdapterInterface
     */
    private function createResponseAdapter(string $api, array $payload) : HttpResponseAdapterInterface
    {
        $response = new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
        return match ($api) {
            'completions' => new CompletionsApiResponseAdapter($response),
            'responses' => new ResponsesApiResponseAdapter($response),
            default => new ClaudeMessagesApiResponseAdapter($response),
        };
    }

    /**
     * Creates a final answer response including native continuation data where the API has it.
     * 
     * @param string $api
     * @param string $text
     * @return HttpResponseAdapterInterface
     */
    private function createTextResponseAdapter(string $api, string $text) : HttpResponseAdapterInterface
    {
        return $this->createToolResponseAdapter($api, $text, []);
    }

    /**
     * Builds provider-valid response envelopes with native continuation data and no remote state.
     * 
     * @param string $api
     * @param string $text
     * @param array<int,array<string,string>> $calls
     * @return HttpResponseAdapterInterface
     */
    private function createToolResponseAdapter(string $api, string $text, array $calls) : HttpResponseAdapterInterface
    {
        switch ($api) {
            case 'completions':
                $message = ['role' => 'assistant', 'content' => $text];
                foreach ($calls as $call) {
                    $message['tool_calls'][] = ['type' => 'function', 'id' => $call['id'], 'function' => ['name' => $call['name'], 'arguments' => $call['arguments']]];
                }
                return $this->createResponseAdapter($api, ['model' => 'fixture', 'choices' => [['finish_reason' => $calls === [] ? 'stop' : 'tool_calls', 'message' => $message]], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3]]);
            case 'responses':
                $output = [['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => self::RESPONSES_MARKER]];
                $output[] = ['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
                foreach ($calls as $index => $call) {
                    $output[] = ['type' => 'function_call', 'id' => 'fc_' . $index, 'status' => 'completed', 'call_id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments']];
                }
                return $this->createResponseAdapter($api, ['id' => 'resp_1', 'model' => 'fixture-2026-01-01', 'status' => 'completed', 'output' => $output, 'usage' => ['input_tokens' => 7, 'output_tokens' => 3]]);
            default:
                $content = [['type' => 'thinking', 'thinking' => 'provider-only continuation', 'signature' => self::CLAUDE_MARKER], ['type' => 'text', 'text' => $text]];
                foreach ($calls as $call) {
                    $content[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => json_decode($call['arguments'], false, 512, JSON_THROW_ON_ERROR)];
                }
                return $this->createResponseAdapter($api, ['id' => 'msg_1', 'model' => 'fixture', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => $calls === [] ? 'end_turn' : 'tool_use', 'content' => $content, 'usage' => ['input_tokens' => 7, 'output_tokens' => 3]]);
        }
    }

    /**
     * A response adapter for a fictional API - using only public interfaces and the factory.
     * 
     * @param ResponseInterface $response
     * @return HttpResponseAdapterInterface
     */
    private function createFixtureResponseAdapter(ResponseInterface $response) : HttpResponseAdapterInterface
    {
        return new class ($response) implements HttpResponseAdapterInterface {
            private array $json;
            public function __construct(ResponseInterface $response) { $this->json = json_decode((string) $response->getBody(), true); }
            public function getResponseMessage() : AiMessageInterface
            {
                $file = new InMemoryFile(base64_decode($this->json['file']['base64']), $this->json['file']['name'], $this->json['file']['mime']);
                return AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, $this->json['say'], [$file]);
            }
            public function getFinishReason() : string { return $this->json['state'] === 'all-done' ? AiFinishReasonDataType::COMPLETED : AiFinishReasonDataType::ERROR; }
            public function getModelName() : ?string { return null; }
            public function getTokensInPrompt() : int { return 0; }
            public function getTokensInAnswer() : int { return 0; }
            public function getUsage() : array { return []; }
            public function getWarnings() : array { return []; }
            public function isError() : bool { return false; }
            public function enrichError(AiQueryInterface $query, \Exception $e) : \Exception { return new AiProviderDataQueryError($query, 'Fixture error', $e); }
        };
    }

    /**
     * A request adapter for a fictional API - using only the message interfaces and the shared trait.
     * 
     * @return HttpRequestAdapterInterface
     */
    private function createFixtureRequestAdapter() : HttpRequestAdapterInterface
    {
        return new class implements HttpRequestAdapterInterface {
            use ConversationApiAdapterTrait;
            public function buildBody(AiQueryInterface $query) : string
            {
                [$instructions, $dialog] = $this->groupContextMessages($query);
                $turns = [];
                $appendixSeen = [];
                foreach ($dialog as $message) {
                    $text = $message->getText();
                    foreach ($message->getAttachments() as $file) {
                        if (null === $this->readAttachment($query, $file)) {
                            $text = $this->appendNote($text, $this->buildAttachmentNote($file));
                        }
                    }
                    $turns[] = ($message->isInput() ? 'ask: ' : 'say: ') . $text;
                    if ($message instanceof AiToolCallMessageInterface) {
                        foreach ($message->getCalls() as $call) {
                            $turns[] = 'call ' . $call->getToolName() . ' -> ' . $this->buildToolResultText($message->getToolResults()[$call->getCallId()], $appendixSeen);
                        }
                    }
                }
                return json_encode(['rules' => implode("\n", array_map(fn($m) => $m->getText(), $instructions)), 'turns' => $turns]);
            }
            public function getDryrunResponse(array $requestJson, string $response) : ResponseInterface { return new Response(200, [], '{}'); }
        };
    }

    /**
     * Reads each target's actual protocol fields for independent semantic assertions.
     * 
     * @param string $api
     * @param object $wire
     * @return array{instructions: string, texts: string[], calls: array<int,array<string,mixed>>, results: array<int,array<string,mixed>>}
     */
    private function readProtocol(string $api, object $wire) : array
    {
        $instructions = [];
        $texts = [];
        $calls = [];
        $results = [];
        if ($api === 'responses' && isset($wire->instructions)) {
            $instructions[] = $wire->instructions;
        }
        if ($api === 'claude' && isset($wire->system)) {
            $instructions[] = $wire->system;
        }
        foreach ($api === 'responses' ? $wire->input : $wire->messages as $item) {
            $item = (array) $item;
            if ($api === 'completions' && $item['role'] === 'system') {
                $instructions[] = $item['content'];
            } elseif ($api === 'responses' && ($item['type'] ?? null) === 'function_call') {
                $calls[] = ['id' => $item['call_id'], 'name' => $item['name'], 'arguments' => json_decode($item['arguments'])];
            } elseif ($api === 'responses' && ($item['type'] ?? null) === 'function_call_output') {
                $results[] = ['id' => $item['call_id'], 'content' => $item['output']];
            } elseif ($api === 'completions' && $item['role'] === 'tool') {
                $results[] = ['id' => $item['tool_call_id'], 'content' => $item['content']];
            } else {
                foreach ($item['tool_calls'] ?? [] as $call) {
                    $calls[] = ['id' => $call->id, 'name' => $call->function->name, 'arguments' => json_decode($call->function->arguments)];
                }
                $content = $item['content'] ?? [];
                if (is_string($content)) {
                    $texts[] = $content;
                    continue;
                }
                foreach ($content ?? [] as $block) {
                    $block = (array) $block;
                    if (isset($block['text'])) {
                        $texts[] = $block['text'];
                    } elseif (($block['type'] ?? null) === 'tool_use') {
                        $calls[] = ['id' => $block['id'], 'name' => $block['name'], 'arguments' => $block['input']];
                    } elseif (($block['type'] ?? null) === 'tool_result') {
                        $results[] = ['id' => $block['tool_use_id'], 'content' => $block['content'], 'is_error' => $block['is_error']];
                    }
                }
            }
        }
        return ['instructions' => implode("\n\n", $instructions), 'texts' => $texts, 'calls' => $calls, 'results' => $results];
    }
}
