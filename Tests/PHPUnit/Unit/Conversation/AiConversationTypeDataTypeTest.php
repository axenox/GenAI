<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\DataTypes\DataTypeCastingError;
use exface\Core\Factories\DataTypeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the conversation type enum without persisted model data.
 */
class AiConversationTypeDataTypeTest extends TestCase
{
    /**
     * Keeps the stored values and designer-facing labels stable.
     *
     * @return void
     */
    public function testValuesAndLabels() : void
    {
        self::assertSame('agent', AiConversationTypeDataType::AGENT);
        self::assertSame('mcp', AiConversationTypeDataType::MCP);
        self::assertSame(['agent', 'mcp'], AiConversationTypeDataType::getValuesStatic());
        $type = DataTypeFactory::createFromPrototype(new Workbench(), AiConversationTypeDataType::class);
        self::assertSame(['agent' => 'Agent', 'mcp' => 'MCP'], $type->getLabels());
    }

    /**
     * Uses the platform enum casting rules for supported values.
     *
     * @return void
     */
    public function testCastingNormalizesSupportedValues() : void
    {
        self::assertSame(AiConversationTypeDataType::AGENT, AiConversationTypeDataType::cast(' AGENT '));
        self::assertSame(AiConversationTypeDataType::MCP, AiConversationTypeDataType::cast('MCP'));
    }

    /**
     * Rejects unsupported conversation types.
     *
     * @return void
     */
    public function testCastingRejectsUnsupportedValues() : void
    {
        $this->expectException(DataTypeCastingError::class);
        AiConversationTypeDataType::cast('other');
    }
}
