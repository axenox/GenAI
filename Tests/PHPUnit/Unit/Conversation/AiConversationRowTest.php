<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\Common\AiConversation;
use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use exface\Core\CommonLogic\DataSheets\DataSheet;
use exface\Core\CommonLogic\Model\Attribute;
use exface\Core\CommonLogic\Model\MetaObject;
use exface\Core\CommonLogic\Model\Model;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataTypeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks the conversation row boundary without a persisted metamodel or mocked Workbench.
 */
class AiConversationRowTest extends TestCase
{
    /**
     * Supplies incomplete, unsupported and obsolete creation rows.
     *
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function invalidRows() : iterable
    {
        yield 'empty' => [[]];
        yield 'missing type' => [['DEVMODE' => false]];
        yield 'unsupported source' => [['TYPE' => 'other', 'DEVMODE' => false]];
        yield 'obsolete context keys' => [['type' => AiConversationTypeDataType::AGENT, 'devmode' => false]];
    }

    /**
     * Rejects invalid creation metadata before accessing the database.
     *
     * @param array<string,mixed> $row
     * @return void
     */
    #[DataProvider('invalidRows')]
    public function testInvalidCreationRowsFailBeforePersistence(array $row) : void
    {
        $handler = $this->createStub(AiTaskHandlerInterface::class);
        $handler->method('getWorkbench')->willReturn(new Workbench());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TYPE to be agent or mcp');
        AiFactory::createConversation($handler, null, $row);
    }

    /**
     * A restored row must still match the caller's expected conversation source.
     *
     * @return void
     */
    public function testRestorationRejectsDifferentSource() : void
    {
        $handler = $this->createStub(AiTaskHandlerInterface::class);
        $workbench = new Workbench();
        $handler->method('getWorkbench')->willReturn($workbench);
        $conversation = $this->getMockBuilder(AiConversation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConversationData'])
            ->getMock();
        $conversation->method('getConversationData')->willReturn($this->sheet($workbench));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conversation source does not match the caller.');
        $conversation->__construct($handler, 'conversation', ['TYPE' => AiConversationTypeDataType::MCP]);
    }

    /**
     * Supplied creation metadata cannot overwrite fields when restoring an existing identity.
     *
     * @return void
     */
    public function testRestorationKeepsStoredMetadata() : void
    {
        $handler = $this->createStub(AiTaskHandlerInterface::class);
        $workbench = new Workbench();
        $handler->method('getWorkbench')->willReturn($workbench);
        $conversation = $this->getMockBuilder(AiConversation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConversationData', 'saveNewConversation'])
            ->getMock();
        $conversation->method('getConversationData')->willReturn($this->sheet($workbench));
        $conversation->expects($this->never())->method('saveNewConversation');
        $conversation->__construct($handler, 'conversation', [
            'TYPE' => AiConversationTypeDataType::AGENT, 'TITLE' => 'replacement', 'DEVMODE' => false
        ]);
        self::assertSame('conversation', $conversation->getConversationId());
        self::assertSame('stored title', $conversation->getTitle());
        self::assertSame($handler, $conversation->getTaskHandler());
    }

    /**
     * Builds real local row/column behavior; authorization and persistence remain integration tests.
     *
     * @param Workbench $workbench
     * @return DataSheet
     */
    private function sheet(Workbench $workbench) : DataSheet
    {
        $object = new MetaObject(new Model($workbench));
        $object->setAlias('CONVERSATION_ROW_TEST');
        foreach (['TYPE', 'TITLE'] as $alias) {
            $attribute = (new \ReflectionClass(Attribute::class))->newInstanceWithoutConstructor();
            $attribute->setObject($object);
            $attribute->setName($alias);
            $attribute->setAlias($alias);
            $attribute->setDataType(DataTypeFactory::createFromPrototype($workbench, StringDataType::class));
            $object->addAttribute($attribute);
        }
        $sheet = new DataSheet($object);
        $sheet->addRow(['TYPE' => AiConversationTypeDataType::AGENT, 'TITLE' => 'stored title']);
        return $sheet;
    }
}
