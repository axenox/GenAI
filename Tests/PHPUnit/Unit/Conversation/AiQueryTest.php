<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiResponseAdapter;
use axenox\GenAI\Common\DataQueries\AiQuery;
use axenox\GenAI\DataTypes\AiFinishReasonDataType;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\Workbench;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that queries transport messages and derive everything about the answer from the response message.
 * 
 * @author Andrej Kabachnik
 */
class AiQueryTest extends TestCase
{
    /**
     * Messages keep their identity and order - queries never inspect them.
     * 
     * @return void
     */
    public function testSuppliedMessagesKeepTheirIdentityAndOrder() : void
    {
        $messages = [];
        for ($index = 0; $index < 3; $index++) {
            $message = $this->createMock(AiMessageInterface::class);
            $message->expects(self::never())->method('getType');
            $messages[] = $message;
        }

        $query = new AiQuery(new Workbench(), null, [$messages[1]]);
        self::assertSame([$messages[1]], $query->getMessages());
        self::assertSame($query, $query->appendMessage($messages[2]));
        self::assertSame($query, $query->prependMessage($messages[0]));
        self::assertSame($messages, $query->getMessages());
        self::assertFalse($query->hasResponse());
        self::assertNull($query->getCosts());
    }

    /**
     * Answer, tool calls and finish reason come from the response message - a single source of truth.
     * 
     * @return void
     */
    public function testAnswerIsDerivedFromResponseMessage() : void
    {
        $adapter = $this->createStub(HttpResponseAdapterInterface::class);
        $call = new AiToolCall('GetTime', 'call_1', new \stdClass());
        $adapter->method('getResponseMessage')->willReturn(AiFactory::createToolCallMessage('checking', [$call]));
        $adapter->method('getFinishReason')->willReturn(AiFinishReasonDataType::TOOL_CALLS);
        $adapter->method('getWarnings')->willReturn(['from adapter']);
        $query = (new AiQuery(new Workbench()))
            ->addWarning('from request')
            ->withResponse(new Response(), $adapter)
            ->withWarnings(['from connector']);
        self::assertTrue($query->hasToolCalls());
        self::assertSame([$call], $query->getToolCalls());
        self::assertSame('checking', $query->getAnswerRaw());
        self::assertFalse($query->isFinished());
        self::assertSame(['from request', 'from connector', 'from adapter'], $query->getWarnings());
        // The same message instance is returned every time, so the agent can enrich its calls before saving
        self::assertSame($query->getResponseMessage(), $query->getResponseMessage());
    }

    /**
     * Structured answers are the JSON text of the response message.
     * 
     * @return void
     */
    public function testStructuredAnswer() : void
    {
        $response = new Response(200, [], json_encode([
            'model' => 'fixture', 'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"text":"00123","list":[]}']]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4]
        ]));
        $query = (new AiQuery(new Workbench()))
            ->setResponseJsonSchema(['type' => 'object'])
            ->setResponseAnswerPath('$.text');
        $query = $query->withResponse($response, new CompletionsApiResponseAdapter($response));
        self::assertSame(['text' => '00123', 'list' => []], $query->getAnswerJson());
        self::assertSame('00123', $query->getAnswerMarkdown());
        self::assertSame(AiFinishReasonDataType::COMPLETED, $query->getFinishReason());
        self::assertTrue($query->isFinished());
        self::assertSame('fixture', $query->getModelName());
        self::assertSame(3, $query->getTokensInPrompt());
        self::assertSame(AiMessageTypeDataType::ASSISTANT, $query->getResponseMessage()->getType());
    }
}
