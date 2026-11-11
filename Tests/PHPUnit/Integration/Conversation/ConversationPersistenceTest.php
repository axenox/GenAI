<?php
namespace axenox\GenAI\Tests\PHPUnit\Integration\Conversation;

use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiResponseAdapter;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiResponseAdapter;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiResponseAdapter;
use axenox\GenAI\Common\DataQueries\AiQuery;
use axenox\GenAI\Common\Mcp\McpWorkbenchScope;
use axenox\GenAI\DataConnectors\ClaudeConnector;
use axenox\GenAI\DataConnectors\OpenAiConnector;
use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use axenox\GenAI\DataTypes\AiFinishReasonDataType;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\Selectors\DataConnectorSelector;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\FileFactory;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\WorkbenchInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies real authorized persistence and restart without invoking tools or contacting providers.
 *
 * Set GENAI_TEST_HANDLER to an installed handler accessible to the current CLI user.
 * 
 * @author GitHub Copilot
 */
class ConversationPersistenceTest extends TestCase
{
    /**
     * Keeps metamodel row values, ratings and write snapshots across creation and restoration.
     *
     * @return void
     */
    public function testCachedMetadataUpdatesPreserveStoredRow() : void
    {
        $selector = getenv('GENAI_TEST_HANDLER');
        if ($selector === false || $selector === '') {
            self::markTestSkipped('Set GENAI_TEST_HANDLER to provision the installed-handler boundary.');
        }
        $originalErrorHandler = set_error_handler(static fn() => false);
        restore_error_handler();
        $originalExceptionHandler = set_exception_handler(static function (\Throwable $error) : void {});
        restore_exception_handler();
        $conversationId = null;
        $workbench = McpWorkbenchScope::start();
        try {
            $handler = AiFactory::createTaskHandlerFromString($workbench, $selector);
            $data = '{"retained":"00123","object":{},"list":[]}';
            $conversation = AiFactory::createConversation($handler, null, [
                'TYPE' => AiConversationTypeDataType::MCP, 'TITLE' => '',
                'TOPIC_ID' => 'isolated-row-test', 'DATA' => $data,
                'RATING' => 4, 'RATING_FEEDBACK' => 'Existing feedback',
                'AI_AGENT' => 'not-the-owner', 'AI_AGENT_VERSION_NO' => 'not-the-version',
                'USER' => 'not-the-user'
            ]);
            $conversationId = $conversation->getConversationId();
            $conversation->setTitle('');
            $conversation->saveUserPrompt('Isolated row prompt');
            self::assertSame('Isolated row prompt', $conversation->getTitle());
            $conversation->setTitle('First title update');
            $conversation->setTitle('First title update');
            $conversation->saveError(new RuntimeException('isolated row test first error'));
            $conversation->saveError(new RuntimeException('isolated row test second error'));
            $conversation->setTitle('Final title update');
            $restored = AiFactory::createConversation($handler, $conversationId, [
                'TYPE' => AiConversationTypeDataType::MCP, 'DEVMODE' => true, 'TITLE' => 'Must not overwrite'
            ]);
            self::assertSame('Final title update', $restored->getTitle());
            $restored->setTitle('Restored title update');
            $restored->setTitle('Final title update');
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_CONVERSATION');
            $sheet->getColumns()->addMultiple([
                'AI_AGENT', 'AI_AGENT_VERSION_NO', 'USER', 'TYPE', 'DEVMODE', 'TITLE',
                'TOPIC_ID', 'DATA', 'RATING', 'RATING_FEEDBACK'
            ]);
            $sheet->getFilters()->addConditionFromString('UID', $conversationId, ComparatorDataType::EQUALS);
            $sheet->dataRead();
            self::assertSame(strtolower($handler->getUid()), strtolower($sheet->getCellValue('AI_AGENT', 0)));
            self::assertSame($handler->getVersion(), $sheet->getCellValue('AI_AGENT_VERSION_NO', 0));
            self::assertSame(
                strtolower($workbench->getSecurity()->getAuthenticatedUser()->getUid()),
                strtolower($sheet->getCellValue('USER', 0))
            );
            self::assertSame(AiConversationTypeDataType::MCP, $sheet->getCellValue('TYPE', 0));
            self::assertSame(0, (int) $sheet->getCellValue('DEVMODE', 0));
            self::assertSame('Final title update', $sheet->getCellValue('TITLE', 0));
            self::assertSame('isolated-row-test', $sheet->getCellValue('TOPIC_ID', 0));
            self::assertSame(json_decode($data, true), json_decode($sheet->getCellValue('DATA', 0), true));
            self::assertSame(4, (int) $sheet->getCellValue('RATING', 0));
            self::assertStringStartsWith('Existing feedback', $sheet->getCellValue('RATING_FEEDBACK', 0));
            self::assertStringContainsString('isolated row test first error', $sheet->getCellValue('RATING_FEEDBACK', 0));
            self::assertStringContainsString('isolated row test second error', $sheet->getCellValue('RATING_FEEDBACK', 0));
            self::assertSame([0, 1, 2], array_map(static fn($message) => $message->getSequenceNumber(), $restored->getMessages()));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Conversation source does not match the caller.');
            AiFactory::createConversation($handler, $conversationId, ['TYPE' => AiConversationTypeDataType::AGENT]);
        } finally {
            try {
                if ($conversationId !== null) {
                    foreach (['AI_MESSAGE', 'AI_CONVERSATION'] as $alias) {
                        $sheet = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.' . $alias);
                        $sheet->getFilters()->addConditionFromString(
                            $alias === 'AI_CONVERSATION' ? 'UID' : 'AI_CONVERSATION', $conversationId, ComparatorDataType::EQUALS
                        );
                        $sheet->dataDelete();
                    }
                }
            } finally {
                McpWorkbenchScope::stop($workbench);
                $this->restoreProcessHandlers($originalErrorHandler, $originalExceptionHandler);
            }
        }
    }

    /**
     * Restores the maximum once, permits duplicate writers and keeps UID-stable history.
     *
     * @return void
     */
    public function testLocalSequencesContinueFromMaximumAndPermitDuplicateWriters() : void
    {
        $selector = getenv('GENAI_TEST_HANDLER');
        if ($selector === false || $selector === '') {
            self::markTestSkipped('Set GENAI_TEST_HANDLER to provision the installed-handler boundary.');
        }
        $originalErrorHandler = set_error_handler(static fn() => false);
        restore_error_handler();
        $originalExceptionHandler = set_exception_handler(static function (\Throwable $error) : void {});
        restore_exception_handler();
        $conversationId = null;
        $workbench = McpWorkbenchScope::start();
        try {
            $handler = AiFactory::createTaskHandlerFromString($workbench, $selector);
            $conversation = AiFactory::createConversation($handler, null, [
                'TYPE' => AiConversationTypeDataType::MCP, 'TITLE' => 'Local sequence fixture'
            ]);
            $conversationId = $conversation->getConversationId();
            $conversation->saveUserPrompt('First message');
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_MESSAGE');
            $sheet->getColumns()->addFromSystemAttributes();
            $sheet->getFilters()->addConditionFromString('AI_CONVERSATION', $conversationId, ComparatorDataType::EQUALS);
            $sheet->dataRead();
            $sheet->setCellValue('SEQUENCE_NUMBER', 0, 7);
            $sheet->dataUpdate(false);

            $first = AiFactory::createConversation($handler, $conversationId);
            $second = AiFactory::createConversation($handler, $conversationId);
            $counter = new CrudCounter($workbench);
            $counter->start([$handler->getWorkbench()->model()->getObject('axenox.GenAI.AI_CONVERSATION')]);
            try {
                $first->saveUserPrompt('First writer A');
                $second->saveUserPrompt('Second writer A');
                $first->saveUserPrompt('First writer B');
                $second->saveUserPrompt('Second writer B');
            } finally {
                $counter->stop();
            }
            self::assertSame(0, (int) $counter->getReads());
            self::assertSame(0, (int) $counter->getWrites());
            self::assertSame([8, 9], array_map(static fn($message) => $message->getSequenceNumber(), $first->getGeneratedMessages()));
            self::assertSame([8, 9], array_map(static fn($message) => $message->getSequenceNumber(), $second->getGeneratedMessages()));

            $restored = AiFactory::createConversation($handler, $conversationId);
            $restored->saveUserPrompt('Continued after parallel writes');
            $messages = $restored->getMessages();
            self::assertSame([7, 8, 8, 9, 9, 10], array_map(static fn($message) => $message->getSequenceNumber(), $messages));
            self::assertSame($messages, $restored->getMessages('user'));
            self::assertSame([], $restored->getMessages('tool'));
            $ids = array_map(static fn($message) => $message->getUid(), $messages);
            self::assertLessThan(0, strcasecmp($ids[1], $ids[2]));
            self::assertLessThan(0, strcasecmp($ids[3], $ids[4]));
            $reloaded = AiFactory::createConversation($handler, $conversationId);
            self::assertSame($ids, array_map(static fn($message) => $message->getUid(), $reloaded->getMessages()));
        } finally {
            try {
                if ($conversationId !== null) {
                    foreach (['AI_MESSAGE', 'AI_CONVERSATION'] as $alias) {
                        $sheet = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.' . $alias);
                        $sheet->getFilters()->addConditionFromString(
                            $alias === 'AI_CONVERSATION' ? 'UID' : 'AI_CONVERSATION', $conversationId, ComparatorDataType::EQUALS
                        );
                        $sheet->dataDelete();
                    }
                }
            } finally {
                McpWorkbenchScope::stop($workbench);
                $this->restoreProcessHandlers($originalErrorHandler, $originalExceptionHandler);
            }
        }
    }

    /**
     * Covers every source protocol; each stored conversation is rendered through all three targets.
     * 
     * @return iterable
     */
    public static function sourceApis() : iterable
    {
        foreach (['completions', 'responses', 'claude'] as $api) {
            yield $api => [$api];
        }
    }

    /**
     * Reconstructs instructions, attachments, two tool rounds and final JSON from a new Workbench.
     * 
     * @param string $source
     * @return void
     */
    #[DataProvider('sourceApis')]
    public function testStoreOnlyRestartAndCrossApiContinuation(string $source) : void
    {
        $selector = getenv('GENAI_TEST_HANDLER');
        if ($selector === false || $selector === '') {
            self::markTestSkipped('Set GENAI_TEST_HANDLER to provision the installed-handler boundary.');
        }
        $originalErrorHandler = set_error_handler(static fn() => false);
        restore_error_handler();
        $originalExceptionHandler = set_exception_handler(static function (\Throwable $error) : void {});
        restore_exception_handler();
        $conversationId = null;
        $attachmentPath = null;
        $file = tempnam(sys_get_temp_dir(), 'genai-replay-');
        self::assertNotFalse($file);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO5sAAAAASUVORK5CYII=', true);
        file_put_contents($file, $bytes);
        $workbench = McpWorkbenchScope::start();
        try {
            $handler = AiFactory::createTaskHandlerFromString($workbench, $selector);
            $exactSelector = $handler->getSelector()->toString();
            $conversation = AiFactory::createConversation($handler, null, [
                'TYPE' => AiConversationTypeDataType::AGENT, 'DEVMODE' => true, 'TITLE' => 'Isolated store-only verification'
            ]);
            $conversationId = $conversation->getConversationId();
            self::assertSame('Isolated store-only verification', $conversation->getTitle());
            $conversation->setTitle('Updated store-only verification');
            $conversation->setTitle('Isolated store-only verification');
            self::assertSame('Isolated store-only verification', $conversation->getTitle());
            $conversation->saveSystemPrompt('original instructions');
            $conversation->saveUserPrompt('original prompt', [FileFactory::createFileInfoFromPath($workbench, $file)->openFile()]);
            $user = $conversation->getMessages('user')[0];
            $attachmentPath = $this->readData($workbench, $user->getUid())->attachments[0]->path;
            unset($user);
            unlink($file);
            foreach ([1, 2] as $round) {
                $calls = $round === 1 ? [
                    ['id' => 'reused', 'name' => 'retired_tool', 'arguments' => '{"code":"00123","object":{},"list":[],"nil":null,"flag":false}'],
                    ['id' => 'second', 'name' => 'removed_tool', 'arguments' => '{}']
                ] : [['id' => 'reused', 'name' => 'retired_tool', 'arguments' => '{"round":2}']];
                $adapter = $this->response($source, 'round ' . $round . ' text', $calls);
                $query = (new AiQuery($workbench))->withResponse(new Response(), $adapter);
                $messageId = $conversation->saveToolCalls($query->getResponseMessage(), $query)->getUid();
                $results = [];
                foreach ($query->getToolCalls() as $call) {
                    $recordId = $conversation->saveToolCallStart($messageId, $call);
                    $result = new AiToolResultString($workbench, $call->getCallId() === 'second' ? 'partial 00123' : 'retained result ' . $round);
                    if ($call->getCallId() === 'second') {
                        $result->importUxonObject(new UxonObject([
                            'failed' => true, 'errors' => ['first error', 'second error'],
                            'warnings' => ['first warning'], 'appendix' => ['retained appendix']
                        ]));
                    }
                    $result->setDurationMs(2.5);
                    $conversation->saveToolCallEnd($recordId, $result);
                    $results[$call->getCallId()] = $result;
                }
                $conversation->saveToolResults($messageId, $results);
            }
            $adapter = $this->response($source, '{"answer":"00123","retained":{"object":{},"list":[]}}');
            $query = (new AiQuery($workbench))->withResponse(new Response(), $adapter);
            $query->setResponseJsonSchema(['type' => 'object']);
            $query->setResponseAnswerPath('$.answer');
            $answerId = $conversation->saveResponse($query)->getUid();
            unset($handler, $conversation, $adapter, $query, $calls, $call, $result, $results);
            McpWorkbenchScope::stop($workbench);
            unset($workbench);

            foreach (['completions', 'responses', 'claude'] as $index => $target) {
                $workbench = McpWorkbenchScope::start();
                $handler = AiFactory::createTaskHandlerFromString($workbench, $exactSelector);
                $conversation = AiFactory::createConversation($handler, $conversationId, ['TYPE' => AiConversationTypeDataType::AGENT]);
                self::assertSame('Isolated store-only verification', $conversation->getTitle());
                $conversation->setTitle('Updated store-only verification');
                $conversation->setTitle('Isolated store-only verification');
                if ($index === 0) {
                    $conversation->saveUserPrompt('new prompt');
                }
                $messages = $conversation->getMessagesActive();
                self::assertCount(2, $conversation->getMessages('tool'));
                self::assertCount(2, $conversation->getMessages('error'));
                $warnings = $conversation->getMessages('warning');
                self::assertCount(1, $warnings);
                self::assertSame('first warning', $warnings[0]->getText());
                $counts = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_CONVERSATION');
                $counts->getColumns()->addMultiple(['WARNING_COUNT', 'ERROR_COUNT', 'RATING_FEEDBACK']);
                $counts->getFilters()->addConditionFromString('UID', $conversationId, ComparatorDataType::EQUALS);
                $counts->dataRead();
                self::assertSame(1, (int) $counts->getCellValue('WARNING_COUNT', 0));
                self::assertSame(2, (int) $counts->getCellValue('ERROR_COUNT', 0));
                self::assertStringNotContainsString('first warning', (string) $counts->getCellValue('RATING_FEEDBACK', 0));
                $storedCalls = $conversation->getMessages('tool')[0];
                self::assertInstanceOf(AiToolCallMessageInterface::class, $storedCalls);
                self::assertTrue($storedCalls->isComplete());
                $warningDiagnostics = array_filter(
                    $this->readData($workbench, $storedCalls->getUid())->diagnostics,
                    static fn($diagnostic) => $diagnostic->severity === 'warning'
                );
                self::assertCount(1, $warningDiagnostics);
                $warningDiagnostic = array_values($warningDiagnostics)[0];
                self::assertSame($warnings[0]->getUid(), $warningDiagnostic->warning_message_uid);
                self::assertSame($warningDiagnostic->id, $this->readData($workbench, $warnings[0]->getUid())->diagnostic_id);
                self::assertSame('00123', $storedCalls->getCalls()[0]->getOriginalArguments()->code);
                self::assertInstanceOf(\stdClass::class, $storedCalls->getCalls()[0]->getOriginalArguments()->object);
                self::assertSame([], $storedCalls->getCalls()[0]->getOriginalArguments()->list);
                self::assertNull($storedCalls->getCalls()[0]->getOriginalArguments()->nil);
                self::assertFalse($storedCalls->getCalls()[0]->getOriginalArguments()->flag);
                self::assertSame([], $storedCalls->getToolResults()['second']->getExceptions());
                self::assertSame(['first error', 'second error'], $storedCalls->getToolResults()['second']->getErrors());
                self::assertTrue($storedCalls->getToolResults()['second']->isFailed());
                $attachment = $conversation->getMessages('user')[0]->getAttachments()[0];
                self::assertTrue($attachment->exists());
                self::assertSame($bytes, $attachment->openFile()->read());
                $attachmentData = $this->readData($workbench, $conversation->getMessages('user')[0]->getUid())->attachments[0];
                self::assertSame(hash('sha256', $bytes), $attachmentData->sha256);
                self::assertSame($attachmentPath, $attachmentData->path);
                // Designers see the extracted answer, the model context keeps the full JSON
                $answer = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_MESSAGE');
                $answer->getColumns()->addMultiple(['MESSAGE', 'FINISH_REASON']);
                $answer->getFilters()->addConditionFromString('UID', $answerId, ComparatorDataType::EQUALS);
                $answer->dataRead();
                self::assertSame('00123', $answer->getCellValue('MESSAGE', 0));
                self::assertSame(AiFinishReasonDataType::COMPLETED, $answer->getCellValue('FINISH_REASON', 0));
                $query = new AiQuery($workbench, $conversation, $messages);
                $connector = $target === 'claude'
                    ? new ClaudeConnector(new DataConnectorSelector($workbench, ClaudeConnector::class), new UxonObject(['model' => 'fixture']))
                    : new OpenAiConnector(new DataConnectorSelector($workbench, OpenAiConnector::class), new UxonObject(['model' => 'fixture']));
                $requestAdapter = match ($target) {
                    'claude' => new ClaudeMessagesApiRequestAdapter($connector),
                    'responses' => new ResponsesApiRequestAdapter($connector),
                    default => new CompletionsApiRequestAdapter($connector)
                };
                $body = $requestAdapter->buildBody($query);
                $wire = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
                $this->assertProtocol($target, $wire);
                self::assertSame([], $query->getWarnings());
                self::assertStringContainsString(base64_encode($bytes), $body);
                $rows = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_MESSAGE');
                $rows->getColumns()->addMultiple(['ROLE', 'DATA', 'ERROR_LOG_ID', 'TOKENS_PROMPT', 'TOKENS_COMPLETION']);
                $rows->getFilters()->addConditionFromString('AI_CONVERSATION', $conversationId, ComparatorDataType::EQUALS);
                $rows->dataRead();
                foreach ($rows->getRows() as $row) {
                    self::assertStringNotContainsString(base64_encode($bytes), (string) $row['DATA']);
                    if ($row['ROLE'] !== 'error') {
                        self::assertEmpty($row['ERROR_LOG_ID']);
                    }
                    if ($row['ROLE'] === 'tool') {
                        self::assertSame(0, (int) $row['TOKENS_PROMPT']);
                        self::assertSame(0, (int) $row['TOKENS_COMPLETION']);
                    }
                }
                $feedback = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_CONVERSATION');
                $feedback->getColumns()->addMultiple(['RATING', 'RATING_FEEDBACK', 'ERROR_COUNT', 'WARNING_COUNT']);
                $feedback->getFilters()->addConditionFromString('UID', $conversationId, ComparatorDataType::EQUALS);
                $feedback->dataRead();
                self::assertSame(1, (int) $feedback->getCellValue('RATING', 0));
                self::assertStringContainsString('first error', $feedback->getCellValue('RATING_FEEDBACK', 0));
                self::assertStringContainsString('second error', $feedback->getCellValue('RATING_FEEDBACK', 0));
                self::assertSame(2, (int) $feedback->getCellValue('ERROR_COUNT', 0));
                self::assertSame(1, (int) $feedback->getCellValue('WARNING_COUNT', 0));
                unset($handler, $conversation, $messages, $storedCalls, $query, $connector, $requestAdapter, $rows);
                McpWorkbenchScope::stop($workbench);
                unset($workbench);
            }
        } finally {
            if (isset($workbench)) {
                McpWorkbenchScope::stop($workbench);
            }
            if (is_file($file)) {
                unlink($file);
            }
            if ($conversationId !== null) {
                $cleanup = McpWorkbenchScope::start();
                try {
                    if ($attachmentPath !== null) {
                        $sheet = DataSheetFactory::createFromObjectIdOrAlias($cleanup, 'axenox.GenAI.AI_MESSAGE_FILE');
                        $sheet->getFilters()->addConditionFromString('PATHNAME_RELATIVE', $attachmentPath, ComparatorDataType::EQUALS);
                        $sheet->dataDelete();
                    }
                    foreach (['AI_TOOL_CALL', 'AI_MESSAGE', 'AI_CONVERSATION'] as $alias) {
                        $sheet = DataSheetFactory::createFromObjectIdOrAlias($cleanup, 'axenox.GenAI.' . $alias);
                        $sheet->getFilters()->addConditionFromString($alias === 'AI_CONVERSATION' ? 'UID' : 'AI_CONVERSATION', $conversationId, ComparatorDataType::EQUALS);
                        $sheet->dataDelete();
                    }
                } finally {
                    McpWorkbenchScope::stop($cleanup);
                }
            }
            $this->restoreProcessHandlers($originalErrorHandler, $originalExceptionHandler);
        }
    }

    /**
     * Reads the persisted DATA of a message directly from the model database
     * 
     * @param WorkbenchInterface $workbench
     * @param string $messageId
     * @return \stdClass
     */
    private function readData(WorkbenchInterface $workbench, string $messageId) : \stdClass
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_MESSAGE');
        $sheet->getColumns()->addFromExpression('DATA');
        $sheet->getFilters()->addConditionFromString('UID', $messageId, ComparatorDataType::EQUALS);
        $sheet->dataRead();
        return json_decode((string) $sheet->getCellValue('DATA', 0), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Removes handlers installed by Workbench startup without disturbing PHPUnit's handlers.
     * 
     * @param callable|null $errorHandler
     * @param callable|null $exceptionHandler
     * @return void
     */
    private function restoreProcessHandlers(?callable $errorHandler, ?callable $exceptionHandler) : void
    {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $current = set_error_handler(static fn() => false);
            restore_error_handler();
            if ($current === $errorHandler) {
                break;
            }
            restore_error_handler();
        }
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $current = set_exception_handler(static function (\Throwable $error) : void {});
            restore_exception_handler();
            if ($current === $exceptionHandler) {
                break;
            }
            restore_exception_handler();
        }
    }

    /**
     * Produces actual protocol envelopes without contacting a remote service.
     * 
     * @param string $api
     * @param string $text
     * @param array<array-key,mixed> $calls
     * @return HttpResponseAdapterInterface
     */
    private function response(string $api, string $text, array $calls = []) : HttpResponseAdapterInterface
    {
        if ($api === 'completions') {
            $message = ['role' => 'assistant', 'content' => $text];
            foreach ($calls as $call) {
                $message['tool_calls'][] = ['type' => 'function', 'id' => $call['id'], 'function' => ['name' => $call['name'], 'arguments' => $call['arguments']]];
            }
            return new CompletionsApiResponseAdapter(new Response(200, [], json_encode([
                'choices' => [['finish_reason' => $calls === [] ? 'stop' : 'tool_calls', 'message' => $message]],
                'usage' => ['prompt_tokens' => 0]
            ], JSON_THROW_ON_ERROR)));
        }
        if ($api === 'responses') {
            $output = [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]];
            foreach ($calls as $call) {
                $output[] = ['type' => 'function_call', 'call_id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments']];
            }
            return new ResponsesApiResponseAdapter(new Response(200, [], json_encode([
                'status' => 'completed', 'output' => $output, 'usage' => ['input_tokens' => 0]
            ], JSON_THROW_ON_ERROR)));
        }
        $content = [['type' => 'text', 'text' => $text]];
        foreach ($calls as $call) {
            $content[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => json_decode($call['arguments'], false, 512, JSON_THROW_ON_ERROR)];
        }
        return new ClaudeMessagesApiResponseAdapter(new Response(200, [], json_encode([
            'stop_reason' => $calls === [] ? 'end_turn' : 'tool_use', 'content' => $content, 'usage' => ['input_tokens' => 0]
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * Checks each native protocol independently rather than comparing unlike JSON envelopes.
     * 
     * @param string $api
     * @param object $wire
     * @return void
     */
    private function assertProtocol(string $api, object $wire) : void
    {
        $texts = [];
        $calls = [];
        $results = [];
        if ($api !== 'completions') {
            self::assertSame('original instructions', $api === 'responses' ? $wire->instructions : $wire->system);
        }
        foreach ($api === 'responses' ? $wire->input : $wire->messages as $item) {
            if (($item->type ?? '') === 'function_call') {
                $calls[] = $item->call_id;
            } elseif (($item->type ?? '') === 'function_call_output') {
                $results[] = $item->call_id;
            } elseif (($item->role ?? '') === 'tool') {
                $results[] = $item->tool_call_id;
            } else {
                foreach ($item->tool_calls ?? [] as $call) {
                    $calls[] = $call->id;
                }
                if (is_string($item->content ?? null)) {
                    $texts[] = $item->content;
                }
                foreach (is_array($item->content ?? null) ? $item->content : [] as $block) {
                    if (isset($block->text)) {
                        $texts[] = $block->text;
                    } elseif (($block->type ?? '') === 'tool_use') {
                        $calls[] = $block->id;
                    } elseif (($block->type ?? '') === 'tool_result') {
                        $results[] = $block->tool_use_id;
                    }
                }
            }
        }
        self::assertCount(3, $calls);
        self::assertSame($calls, $results);
        self::assertCount(3, array_unique($calls));
        self::assertContains('original prompt', $texts);
        self::assertSame(1, count(array_keys($texts, 'new prompt', true)));
        self::assertContains('round 1 text', $texts);
        self::assertContains('round 2 text', $texts);
        self::assertContains('{"answer":"00123","retained":{"object":{},"list":[]}}', $texts);
    }
}
