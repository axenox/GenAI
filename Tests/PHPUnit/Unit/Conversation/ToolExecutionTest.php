<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\Common\AbstractAiTaskHandler;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Events\OnAiToolCallEvent;
use axenox\GenAI\Events\OnBeforeAiToolCallEvent;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the shared execution boundary with a real, unstarted Workbench and narrow tool doubles.
 * 
 * @author GitHub Copilot
 */
class ToolExecutionTest extends TestCase
{
    /**
     * A before listener may enforce preparation or authorization; its failure must block invocation.
     * 
     * @throws RuntimeException
     * @return void
     */
    public function testBeforeFailurePreventsSideEffectsAndReturnsFailure() : void
    {
        $workbench = new Workbench();
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('tool');
        $tool->expects(self::never())->method('invoke');
        $workbench->eventManager()->addListener(OnBeforeAiToolCallEvent::getEventName(), static function () : void {
            throw new RuntimeException('Preparation failed');
        });
        $handler = $this->handler($workbench, $tool);
        $result = $handler->callTool(new GenericTask($workbench), new AiToolCall('tool', 'call', [], null, []));
        self::assertTrue($result->isFailed());
        self::assertSame(['Preparation failed'], $result->getErrors());
        self::assertNotNull($result->getDurationMs());
    }

    /**
     * Reporting after execution is operational and cannot turn a completed effect into a failed tool.
     * 
     * @throws RuntimeException
     * @return void
     */
    public function testAfterFailurePreservesResultAndDoesNotRetry() : void
    {
        $workbench = new Workbench();
        $expected = new AiToolResultString($workbench, 'Side effect completed');
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('tool');
        $tool->expects(self::once())->method('invoke')->willReturn($expected);
        $workbench->eventManager()->addListener(OnAiToolCallEvent::getEventName(), static function () : void {
            throw new RuntimeException('Observer failed');
        });
        $handler = $this->handler($workbench, $tool);
        $result = $handler->callTool(new GenericTask($workbench), new AiToolCall('tool', 'call', [], null, []));
        self::assertSame($expected, $result);
        self::assertFalse($result->isFailed());
        self::assertSame([], $result->getErrors());
        self::assertSame('Observer failed', $handler->getToolOperationalErrors()[0]->getMessage());
    }

    /**
     * Unexpected defects become durable errors instead of escaping and aborting the rest of a batch.
     * 
     * @return void
     */
    public function testInvocationFailureAndUnknownToolRemainDeliverable() : void
    {
        $workbench = new Workbench();
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('tool');
        $tool->expects(self::once())->method('invoke')->willThrowException(new \LogicException('Defect'));
        $handler = $this->handler($workbench, $tool);
        $task = new GenericTask($workbench);
        $result = $handler->callTool($task, new AiToolCall('tool', 'first', [], null, []));
        self::assertTrue($result->isFailed());
        self::assertStringContainsString('Defect', $result->getErrors()[0]);
        $rejected = $handler->callTool($task, new AiToolCall('unknown', 'second', []));
        self::assertTrue($rejected->isFailed());
        self::assertSame([], $rejected->getErrors());
        self::assertCount(1, $rejected->getWarnings());
    }

    /**
     * Supplies configured tools without a persisted endpoint; the Workbench and execution stay real.
     * 
     * @param WorkbenchInterface $workbench
     * @param AiToolInterface $tool
     * @return AbstractAiTaskHandler
     */
    private function handler(WorkbenchInterface $workbench, AiToolInterface $tool) : AbstractAiTaskHandler
    {
        return new class($workbench, $tool) extends AbstractAiTaskHandler {
            /**
             * Avoids model loading because this fixture tests execution, not persisted handler identity.
             * 
             * @param WorkbenchInterface $workbench
             * @param AiToolInterface $tool
             * @return void
             */
            public function __construct(WorkbenchInterface $workbench, AiToolInterface $tool)
            {
                $this->workbench = $workbench;
                $this->tools = [$tool];
            }
        };
    }
}
