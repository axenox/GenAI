<?php
namespace axenox\GenAI\Tests\Unit\Mcp;

use axenox\GenAI\AI\Agents\GenericAssistant;
use axenox\GenAI\AI\Agents\McpServer;
use axenox\GenAI\AI\Skills\GenericSkill;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use PHPUnit\Framework\TestCase;

class TaskHandlerContractTest extends TestCase
{
    public function testMcpServerImplementsTaskHandlerWithoutBecomingChatAgent() : void
    {
        $this->assertTrue(is_subclass_of(McpServer::class, AiTaskHandlerInterface::class));
        $this->assertFalse(is_subclass_of(McpServer::class, AiAgentInterface::class));
    }

    public function testGenericAssistantRemainsChatAgent() : void
    {
        $this->assertTrue(is_subclass_of(GenericAssistant::class, AiAgentInterface::class));
    }

    public function testGenericSkillAcceptsAnyTaskHandlerAndOptionalPrompt() : void
    {
        $parameters = (new \ReflectionMethod(GenericSkill::class, '__construct'))->getParameters();

        $this->assertSame(AiTaskHandlerInterface::class, (string) $parameters[0]->getType());
        $this->assertTrue($parameters[1]->allowsNull());
    }
}