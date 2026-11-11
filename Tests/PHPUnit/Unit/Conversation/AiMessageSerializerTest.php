<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\Messages\AiMessageSerializer;
use axenox\GenAI\Common\Messages\DiagnosticMessage;
use axenox\GenAI\Common\Messages\InputMessage;
use axenox\GenAI\Common\Messages\InstructionsMessage;
use axenox\GenAI\Common\Messages\OutputMessage;
use axenox\GenAI\Common\Messages\ToolCallMessage;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the persisted DATA format of messages and the restoration of all legacy formats.
 * 
 * The serializer is used by AiConversation only. These tests need no database.
 * 
 * @author Andrej Kabachnik
 */
class AiMessageSerializerTest extends TestCase
{
    /**
     * Numeric-looking strings, empty objects/lists and results survive storage.
     * 
     * @return void
     */
    public function testLosslessToolCallRoundTrip() : void
    {
        $workbench = new Workbench();
        $serializer = new AiMessageSerializer($workbench);
        $result = new AiToolResultString($workbench, '00123');
        $result->setDurationMs(12.5);
        $message = (new ToolCallMessage('Accompanying text', [
            new AiToolCall('removed_tool', '00012', (object) [
                'identifier' => '00123', 'empty_object' => new \stdClass(), 'empty_list' => [],
                'nothing' => null, 'flag' => false, 'unicode' => "\u{00FC}"
            ], null, null, 'my.App.RemovedTool')
        ], [], ['some.adapter' => ['items' => [(object) ['empty' => new \stdClass(), 'list' => []]]]]))
            ->withToolResults(['00012' => $result]);

        $json = $serializer->encode($serializer->toData($message));
        $restored = $serializer->fromRow($this->row(AiMessageTypeDataType::TOOL), $serializer->decode($json));

        self::assertInstanceOf(ToolCallMessage::class, $restored);
        self::assertSame('Accompanying text', $restored->getText());
        self::assertSame('message', $restored->getUid());
        self::assertSame(3, $restored->getSequenceNumber());
        $arguments = $restored->getCalls()[0]->getOriginalArguments();
        self::assertInstanceOf(\stdClass::class, $arguments);
        self::assertSame('00123', $arguments->identifier);
        self::assertInstanceOf(\stdClass::class, $arguments->empty_object);
        self::assertSame([], $arguments->empty_list);
        self::assertNull($arguments->nothing);
        self::assertFalse($arguments->flag);
        self::assertSame("\u{00FC}", $arguments->unicode);
        self::assertSame('my.App.RemovedTool', $restored->getCalls()[0]->getToolAlias());
        self::assertSame('00123', $restored->getToolResults()['00012']->getValue());
        self::assertSame(12.5, $restored->getToolResults()['00012']->getDurationMs());
        self::assertTrue($restored->isComplete());
        // Adapter data keeps the distinction between {} and []
        self::assertSame('{"items":[{"empty":{},"list":[]}]}', json_encode($restored->getAdapterData('some.adapter')));
        // Saving the restored message produces the same DATA
        self::assertSame($json, $serializer->encode($serializer->toData($restored)));
    }

    /**
     * Diagnostics of results survive storage even though exceptions are transient.
     * 
     * @return void
     */
    public function testResultDiagnosticsSurviveStorage() : void
    {
        $workbench = new Workbench();
        $serializer = new AiMessageSerializer($workbench);
        $result = new AiToolResultString($workbench, 'Partial result');
        $warning = new RuntimeException('First warning');
        $warning->setLogLevel(LoggerInterface::WARNING);
        $result->addException($warning);
        $result->addException(new RuntimeException('First error'));
        $freshText = $result->getValueWithMetadata();
        $message = (new ToolCallMessage('', [new AiToolCall('deleted', 'call', new \stdClass())]))->withToolResults(['call' => $result]);
        $restored = $serializer->fromRow($this->row(AiMessageTypeDataType::TOOL), $serializer->decode($serializer->encode($serializer->toData($message))));
        $restoredResult = $restored->getToolResults()['call'];
        self::assertSame(['First warning'], $restoredResult->getWarnings());
        self::assertSame(['First error'], $restoredResult->getErrors());
        self::assertTrue($restoredResult->isFailed());
        self::assertSame($freshText, $restoredResult->getValueWithMetadata());
    }

    /**
     * Each type is restored as the message class with the matching context role; legacy types are normalized.
     * 
     * @return void
     */
    public function testTypesAndLegacyTypesRestoreContextRoles() : void
    {
        $serializer = new AiMessageSerializer(new Workbench());
        self::assertInstanceOf(InstructionsMessage::class, $serializer->fromRow($this->row('system'), ['text' => 'x']));
        self::assertInstanceOf(InputMessage::class, $serializer->fromRow($this->row('user'), ['text' => 'x']));
        self::assertInstanceOf(OutputMessage::class, $serializer->fromRow($this->row('assistant'), ['text' => 'x']));
        self::assertInstanceOf(DiagnosticMessage::class, $serializer->fromRow($this->row('warning'), []));
        $developer = $serializer->fromRow($this->row('developer'), []);
        self::assertTrue($developer->isInstructions());
        self::assertSame(AiMessageTypeDataType::SYSTEM, $developer->getType());
        self::assertInstanceOf(ToolCallMessage::class, $serializer->fromRow($this->row('tool calling'), []));
        // Unknown types stay visible to designers only
        $unknown = $serializer->fromRow($this->row('something new'), []);
        self::assertFalse($unknown->isInstructions() || $unknown->isInput() || $unknown->isOutput());
    }

    /**
     * Legacy text sources: WIP `content` blocks, `fullJsonResponse`, the MESSAGE column and unreadable DATA.
     * 
     * @return void
     */
    public function testLegacyTextFormats() : void
    {
        $serializer = new AiMessageSerializer(new Workbench());
        $content = $serializer->fromRow($this->row('assistant'), $serializer->decode('{"content":[{"type":"text","text":"Hello"},{"type":"json","value":{"id":"0012","object":{},"list":[]}},{"type":"unsupported","source_type":"audio"}]}'));
        self::assertSame("Hello\n{\"id\":\"0012\",\"object\":{},\"list\":[]}", $content->getText());

        $full = $serializer->fromRow($this->row('assistant'), $serializer->decode('{"fullJsonResponse":{"id":"0012","object":{},"list":[]}}'));
        self::assertSame('{"id":"0012","object":{},"list":[]}', $full->getText());

        $plain = $serializer->fromRow($this->row('user'), $serializer->decode('{}'));
        self::assertSame('Monitoring text', $plain->getText());

        $data = $serializer->decode('{broken');
        self::assertSame('{broken', $data['legacy']->unreadable_data);
        self::assertSame('Monitoring text', $serializer->fromRow($this->row('user'), $data)->getText());
    }

    /**
     * Legacy `provider` entries are ignored - they never become adapter data.
     * 
     * @return void
     */
    public function testLegacyProviderDataIsIgnored() : void
    {
        $serializer = new AiMessageSerializer(new Workbench());
        $message = $serializer->fromRow($this->row('assistant'), $serializer->decode('{"content":[{"type":"text","text":"x"}],"provider":{"openai.responses":{"output":[]}},"portable":false}'));
        self::assertSame([], $message->getAdapterDataAll());
        self::assertNull($message->getAdapterData('openai.responses'));
    }

    /**
     * Legacy Chat Completions `tool_calls` restore calls without results - an incomplete batch.
     * 
     * @return void
     */
    public function testLegacyToolCallsRestoreIncompleteBatch() : void
    {
        $serializer = new AiMessageSerializer(new Workbench());
        $message = $serializer->fromRow($this->row('tool calling'), $serializer->decode('{"tool_calls":[{"id":"call_1","type":"function","function":{"name":"GetTime","arguments":"{\"zone\":\"UTC\"}"}}]}'));
        self::assertInstanceOf(ToolCallMessage::class, $message);
        self::assertSame('GetTime', $message->getCalls()[0]->getToolName());
        self::assertSame('UTC', $message->getCalls()[0]->getOriginalArguments()->zone);
        self::assertFalse($message->isComplete());
    }

    /**
     * Malformed collections and untrusted result classes never abort loading - affected parts are left out.
     * 
     * @return void
     */
    public function testMalformedHistoryRemainsLoadable() : void
    {
        $serializer = new AiMessageSerializer(new Workbench());
        foreach ([
            '{"calls":1,"results":"bad","content":{}}',
            '{"calls":[{"call_id":"call","tool_name":"old","arguments":"not-an-object"}]}',
            '{"calls":[{"call_id":"call","tool_name":"old","arguments":{}}],"results":[{"call_id":"call","class":"\\\\Unexpected\\\\Class","value":"x"}]}',
            '{"calls":[{"call_id":"call","tool_name":"old","arguments":{}},{"call_id":"call","tool_name":"duplicate","arguments":{}}]}'
        ] as $json) {
            $message = $serializer->fromRow($this->row(AiMessageTypeDataType::TOOL), $serializer->decode($json));
            self::assertInstanceOf(ToolCallMessage::class, $message);
            self::assertFalse($message->isComplete(), $json);
            self::assertLessThanOrEqual(1, count($message->getCalls()));
        }
    }

    /**
     * Attachment references are read from the current format and from legacy content blocks.
     * 
     * @return void
     */
    public function testAttachmentReferences() : void
    {
        $serializer = new AiMessageSerializer(new Workbench());
        $current = $serializer->getAttachmentReferences($serializer->decode('{"attachments":[{"uid":"a","path":"p/a.png","filename":"photo.png","mime_type":"image/png","sha256":"abc"}]}'));
        self::assertSame([['uid' => 'a', 'path' => 'p/a.png', 'filename' => 'photo.png', 'mime_type' => 'image/png', 'sha256' => 'abc']], $current);
        $legacy = $serializer->getAttachmentReferences($serializer->decode('{"content":[{"type":"text","text":"x"},{"type":"attachment","path":"p/b.pdf","filename":"doc.pdf","mime_type":"application/pdf","message_id":"m"}]}'));
        self::assertSame('p/b.pdf', $legacy[0]['path']);
        self::assertTrue($serializer->isMissingAttachmentInfo($serializer->decode('{}')));
        self::assertFalse($serializer->isMissingAttachmentInfo($serializer->decode('{"attachments":[]}')));
    }

    /**
     * Cyclic or live structures fail explicitly instead of becoming empty JSON.
     * 
     * @return void
     */
    public function testEncodeRejectsNonJsonValues() : void
    {
        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $this->expectException(\InvalidArgumentException::class);
        (new AiMessageSerializer(new Workbench()))->encode(['text' => 'x', 'meta' => $cycle]);
    }

    /**
     * Supplies a row without loading a model or connecting to a database.
     * 
     * @param string $type
     * @return array<string,mixed>
     */
    private function row(string $type) : array
    {
        return [
            'UID' => 'message', 'AI_CONVERSATION' => 'conversation', 'SEQUENCE_NUMBER' => 3,
            'ROLE' => $type, 'MESSAGE' => 'Monitoring text'
        ];
    }
}
