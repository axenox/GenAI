<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\AI\Tools\DataSheetReadTool;
use axenox\GenAI\AI\Tools\FileSearchTool;
use axenox\GenAI\Common\AbstractAiTaskHandler;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Guards shared agent/MCP normalization using the real parameter definitions of existing tools.
 * 
 * @author GitHub Copilot
 */
class ToolArgumentNormalizationTest extends TestCase
{
    /**
     * Object-valued UXON reaches execution as arrays while the original JSON objects remain intact.
     * 
     * @return void
     */
    public function testStructuredDataSheetArgumentsReachBothExecutionPaths() : void
    {
        $workbench = new Workbench();
        $value = (object) ['object_alias' => 'exface.Core.PAGE', 'rows_limit' => 5, 'code' => '00123', 'empty' => new \stdClass()];
        $expected = [['object_alias' => 'exface.Core.PAGE', 'rows_limit' => 5, 'code' => '00123', 'empty' => []]];
        $tool = $this->getMockBuilder(DataSheetReadTool::class)
            ->setConstructorArgs([$workbench, new UxonObject(['name' => 'read'])])
            ->onlyMethods(['invoke'])
            ->getMock();
        $tool->expects(self::once())->method('invoke')->with(self::anything(), self::anything(), $expected)
            ->willReturn(new AiToolResultString($workbench, 'executed'));
        $call = new AiToolCall('read', 'call', (object) ['data_sheet' => $value]);
        $result = $this->handler($workbench, $tool)->callTool(new GenericTask($workbench), $call);
        self::assertFalse($result->isFailed());
        self::assertSame($expected, $call->getExecutionArguments());
        self::assertInstanceOf(\stdClass::class, $call->getOriginalArguments()->data_sheet->empty);
        self::assertSame($expected, $tool->normalizeArguments(['data_sheet' => $value]));
    }

    /**
     * Omitted optional search values remain null instead of violating parseValue's return type.
     * 
     * @return void
     */
    public function testMissingOptionalFileSearchArgumentsDoNotRejectExecution() : void
    {
        $workbench = new Workbench();
        $tool = $this->getMockBuilder(FileSearchTool::class)
            ->setConstructorArgs([$workbench, new UxonObject(['name' => 'search'])])
            ->onlyMethods(['invoke'])
            ->getMock();
        $expected = ['axenox/genai', null, null];
        $tool->expects(self::once())->method('invoke')->with(self::anything(), self::anything(), $expected)
            ->willReturn(new AiToolResultString($workbench, 'executed'));
        $call = new AiToolCall('search', 'call', ['path' => 'axenox/genai']);
        $result = $this->handler($workbench, $tool)->callTool(new GenericTask($workbench), $call);
        self::assertFalse($result->isFailed());
        self::assertSame($expected, $call->getExecutionArguments());
        self::assertSame($expected, $tool->normalizeArguments(['path' => 'axenox/genai']));
    }

    /**
     * Historical execution arguments never bypass validation of the current original request.
     *
     * @return void
     */
    public function testInvalidArgumentsAreRejectedBeforeEventsAndInvocation() : void
    {
        $workbench = new Workbench();
        $tool = $this->getMockBuilder(FileSearchTool::class)
            ->setConstructorArgs([$workbench, new UxonObject(['name' => 'search'])])
            ->onlyMethods(['invoke'])
            ->getMock();
        $tool->expects(self::never())->method('invoke');
        $events = [];
        $workbench->eventManager()->addListener(
            \axenox\GenAI\Events\OnBeforeAiToolCallEvent::getEventName(),
            static function () use (&$events) : void {
                $events[] = 'before';
            }
        );
        $call = new AiToolCall('search', 'call', ['path' => 'axenox/genai', 'unexpected' => 'value'], null, []);
        $result = $this->handler($workbench, $tool)->callTool(new GenericTask($workbench), $call);
        self::assertTrue($result->isFailed());
        self::assertSame([], $result->getErrors());
        self::assertStringContainsString('Unknown arguments: unexpected', $result->getWarnings()[0]);
        self::assertSame([], $events);
    }

    /**
     * Keeps the Workbench real while replacing only the external tool side effect.
     * 
     * @param WorkbenchInterface $workbench
     * @param AiToolInterface $tool
     * @return AbstractAiTaskHandler
     */
    private function handler(WorkbenchInterface $workbench, AiToolInterface $tool) : AbstractAiTaskHandler
    {
        return new class($workbench, $tool) extends AbstractAiTaskHandler {
            /**
             * Supplies an execution-only tool fixture without a persisted handler model.
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
