<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\Common\AiMessage;
use axenox\GenAI\Common\AiPayload;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use exface\Core\CommonLogic\Workbench;
use PHPUnit\Framework\TestCase;

/** Verifies the real detached history model without mocking Workbench or persistence. */
final class AiMessageTest extends TestCase
{
    /** Call payloads retain typed nested values and original string identifiers. */
    public function testCallPayloadPreservesTypesAndCorrelation() : void
    {
        $call = new AiToolCall('read', '00123', [
            'id' => '00123', 'options' => new \stdClass(), 'items' => [], 'flag' => false, 'nothing' => null
        ], '{"id":"00123","options":{},"items":[],"flag":false,"nothing":null}');
        $data = [
            'call_order' => ['00123'], 'calls' => (object) ['00123' => $call->jsonSerialize()],
            'results' => new \stdClass()
        ];
        $message = new AiMessage(new Workbench(), $this->row(AiPayload::encode($data)));
        $restored = $message->getCalls()[0];
        self::assertSame('00123', $restored->getCallId());
        self::assertSame('00123', $restored->getArguments()['id']);
        self::assertInstanceOf(\stdClass::class, $restored->getArguments()['options']);
        self::assertSame([], $restored->getArguments()['items']);
        self::assertFalse($restored->getArguments()['flag']);
        self::assertNull($restored->getArguments()['nothing']);
        self::assertSame($call->getRawArguments(), $restored->getRawArguments());
        self::assertTrue($message->isBroken());
    }

    /** A caller modifying retrieved payload data cannot mutate captured query context. */
    public function testMessagePayloadIsDetachedFromReturnedUxon() : void
    {
        $message = new AiMessage(new Workbench(), $this->row(AiPayload::encode([
            'content' => [['type' => 'text', 'text' => 'original']]
        ]), 'assistant'));
        $copy = $message->getData();
        $blocks = $copy->toArray()['content'];
        $blocks[0]->text = 'changed';
        self::assertSame('original', $message->getData()->toArray()['content'][0]->text);
        self::assertFalse($message->isBroken());
    }

    /** Legacy provider-shaped requests remain identifiable without a live tool. */
    public function testIncompleteLegacyRequestRetainsCalls() : void
    {
        $message = new AiMessage(new Workbench(), $this->row(AiPayload::encode([
            'tool_calls' => [[
                'id' => 'old-call', 'type' => 'function',
                'function' => ['name' => 'removed_tool', 'arguments' => '{"id":"00123"}']
            ]]
        ]), 'tool calling'));
        self::assertSame('removed_tool', $message->getCalls()[0]->getToolName());
        self::assertSame('00123', $message->getCalls()[0]->getArguments()['id']);
        self::assertTrue($message->isBroken());
    }

    /** PHP numeric map-key conversion must not break string call IDs or typed results. */
    public function testCanonicalNumericStringIdReconstructsCompleteResult() : void
    {
        $workbench = new Workbench();
        $call = new AiToolCall('read', '123', ['id' => '123']);
        $row = $this->row(AiPayload::encode([
            'call_order' => ['123'], 'calls' => (object) ['123' => $call->jsonSerialize()],
            'results' => (object) ['123' => (new AiToolResultString($workbench, true))->exportUxonObject()->toArray()]
        ]));
        $row['FINISHED_ON'] = '2026-01-01 12:00:01';
        $message = new AiMessage($workbench, $row);
        self::assertSame('123', $message->getCalls()[0]->getCallId());
        self::assertSame('123', $message->getCalls()[0]->getArguments()['id']);
        self::assertTrue($message->getToolResults()['123']->getValue());
        self::assertFalse($message->isBroken());
    }

    /** Tool-like structured assistant answers do not become historical tool executions. */
    public function testOrdinaryMessagesDoNotExposeToolCollections() : void
    {
        $message = new AiMessage(new Workbench(), $this->row(AiPayload::encode([
            'calls' => (object) ['answer' => ['anything' => 'structured output']],
            'results' => (object) ['answer' => ['anything' => 'structured output']]
        ]), 'assistant'));
        self::assertSame([], $message->getCalls());
        self::assertSame([], $message->getToolResults());
    }

    /** Supplies a persisted-message fixture independent of exported metamodel configuration. */
    private function row(string $data, string $role = 'tool') : array
    {
        return [
            'UID' => 'message-1', 'AI_CONVERSATION' => 'conversation-1', 'SEQUENCE_NUMBER' => 4,
            'ROLE' => $role, 'MESSAGE' => 'display only', 'DATA' => $data, 'FINISHED_ON' => null
        ];
    }
}
