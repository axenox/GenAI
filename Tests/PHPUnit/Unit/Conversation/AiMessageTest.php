<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\AI\TestCriteria\ToolResponseTestCriterion;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\Messages\ToolCallMessage;
use axenox\GenAI\Common\Messages\UnavailableAttachment;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use exface\Core\CommonLogic\Filesystem\InMemoryFile;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the provider-independent message model without database or LLM.
 * 
 * @author Andrej Kabachnik
 */
class AiMessageTest extends TestCase
{
    /**
     * Every current type maps to exactly one context role (or designers only).
     * 
     * @return iterable
     */
    public static function typeRoles() : iterable
    {
        yield 'system' => [AiMessageTypeDataType::SYSTEM, 'instructions'];
        yield 'user' => [AiMessageTypeDataType::USER, 'input'];
        yield 'assistant' => [AiMessageTypeDataType::ASSISTANT, 'output'];
        yield 'warning' => [AiMessageTypeDataType::WARNING, null];
        yield 'error' => [AiMessageTypeDataType::ERROR, null];
    }

    /**
     * Message classes decide their context role - at most one isser returns TRUE.
     * 
     * @param string $type
     * @param string|null $role
     * @return void
     */
    #[DataProvider('typeRoles')]
    public function testFactoryCreatesMessagesWithContextRoles(string $type, ?string $role) : void
    {
        $message = AiFactory::createMessage($type, 'text');
        self::assertSame($type, $message->getType());
        self::assertSame('text', $message->getText());
        self::assertSame($role === 'instructions', $message->isInstructions());
        self::assertSame($role === 'input', $message->isInput());
        self::assertSame($role === 'output', $message->isOutput());
        self::assertSame('', $message->getUid());
    }

    /**
     * Tool call messages are output messages with their own interface.
     * 
     * @return void
     */
    public function testToolCallMessageIsOutputAndTracksCompleteness() : void
    {
        $workbench = new Workbench();
        $message = AiFactory::createToolCallMessage('Let me check', [
            new AiToolCall('first', 'a', new \stdClass()),
            new AiToolCall('second', 'b', (object) ['x' => 1])
        ]);
        self::assertInstanceOf(AiToolCallMessageInterface::class, $message);
        self::assertSame(AiMessageTypeDataType::TOOL, $message->getType());
        self::assertTrue($message->isOutput());
        self::assertFalse($message->isComplete());
        /** @var ToolCallMessage $message */
        $partial = $message->withToolResults(['a' => new AiToolResultString($workbench, 'A')]);
        self::assertFalse($partial->isComplete());
        $complete = $partial->withToolResults(['b' => new AiToolResultString($workbench, 'B')]);
        self::assertTrue($complete->isComplete());
        self::assertSame(['a', 'b'], array_keys($complete->getToolResults()));
        // The original message is not changed
        self::assertSame([], $message->getToolResults());
    }

    /**
     * Results must reference calls of the same message.
     * 
     * @return void
     */
    public function testToolResultsForUnknownCallsAreRejected() : void
    {
        $message = new ToolCallMessage('', [new AiToolCall('first', 'a', new \stdClass())]);
        $this->expectException(\InvalidArgumentException::class);
        $message->withToolResults(['unknown' => new AiToolResultString(new Workbench(), 'x')]);
    }

    /**
     * The designer markdown is bounded and fences cannot be closed by tool results - the text remains full.
     * 
     * @return void
     */
    public function testToolMarkdownIsBoundedAndEscapesFences() : void
    {
        $workbench = new Workbench();
        $value = "before\n```\n<script>retained only</script>\n" . str_repeat("\u{00FC}", 17000);
        $message = (new ToolCallMessage('', [
            new AiToolCall('first', 'first', new \stdClass()),
            new AiToolCall('second', 'second', new \stdClass())
        ]))->withToolResults([
            'first' => new AiToolResultString($workbench, $value),
            'second' => new AiToolResultString($workbench, $value)
        ]);
        $markdown = $message->toMarkdown();
        self::assertLessThanOrEqual(ToolCallMessage::MAX_MARKDOWN_LENGTH, mb_strlen($markdown));
        self::assertStringContainsString('Preview truncated', $markdown);
        self::assertStringStartsWith("````\n", $markdown);
        self::assertSame($value, $message->getToolResults()['first']->getValue());
        // Markdown is not model context
        self::assertSame('', $message->getText());
    }

    /**
     * JSON answers are pretty-printed for designers but kept as raw JSON text for the model.
     * 
     * @return void
     */
    public function testStructuredAnswerTextAndMarkdownAreSeparate() : void
    {
        $message = AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, '{"answer":"00123"}');
        self::assertSame('{"answer":"00123"}', $message->getText());
        self::assertStringStartsWith("```json\n", $message->toMarkdown());
    }

    /**
     * Attachments are files; unavailable attachments remain visible but cannot be read.
     * 
     * @return void
     */
    public function testAttachmentsAreFiles() : void
    {
        $message = AiFactory::createMessage(AiMessageTypeDataType::USER, 'see files', [
            new InMemoryFile('png-bytes', 'photo.png', 'image/png'),
            new UnavailableAttachment('lost.pdf', 'application/pdf')
        ]);
        self::assertCount(2, $message->getAttachments());
        self::assertTrue($message->getAttachments()[0]->exists());
        self::assertFalse($message->getAttachments()[1]->exists());
        self::assertStringContainsString('lost.pdf (unavailable)', $message->toMarkdown());
        $this->expectException(\Throwable::class);
        $message->getAttachments()[1]->openFile()->read();
    }

    /**
     * Adapter data is opaque, per key and must be JSON - live objects are rejected without running them.
     * 
     * @return void
     */
    public function testAdapterDataIsOpaqueAndJsonOnly() : void
    {
        $message = AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, 'x', [], ['owner' => ['items' => [(object) []]]]);
        self::assertNull($message->getAdapterData('other'));
        self::assertEquals(['items' => [new \stdClass()]], $message->getAdapterData('owner'));
        self::assertStringNotContainsString('items', $message->toMarkdown());

        $live = new class implements \JsonSerializable {
            public bool $called = false;
            /**
             * Records an unwanted attempt to serialize a live object.
             * 
             * @return mixed
             */
            public function jsonSerialize() : mixed
            {
                $this->called = true;
                return [];
            }
        };
        try {
            AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, 'x', [], ['owner' => ['live' => $live]]);
            self::fail('Live objects must not become adapter data.');
        } catch (\InvalidArgumentException $expected) {
            self::assertFalse($live->called);
        }
    }

    /**
     * Unknown types and tool messages without calls are programming errors.
     * 
     * @return void
     */
    public function testFactoryRejectsUnknownTypes() : void
    {
        $this->expectException(InvalidArgumentException::class);
        AiFactory::createMessage('summary-from-the-future', 'x');
    }

    /**
     * Test criteria read only tool call messages of the supplied turn and render nested arguments.
     * 
     * @return void
     */
    public function testCriterionRendersToolCallArgumentsOnly() : void
    {
        $workbench = new Workbench();
        $messages = [
            AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, 'no calls here'),
            AiFactory::createToolCallMessage('', [new AiToolCall('old', 'call', (object) [
                'id' => '0012', 'object' => new \stdClass(), 'list' => [], 'flag' => false, 'null' => null
            ])])
        ];
        $response = $this->createStub(AiResponseInterface::class);
        $response->method('getMessages')->willReturn($messages);
        $criterion = new ToolResponseTestCriterion($workbench, new UxonObject());
        self::assertSame("Toolname:\nold\nArguments:\nid = \"0012\"\nobject = {}\nlist = []\nflag = false\nnull = null\n", $criterion->getValue($response));
    }
}
