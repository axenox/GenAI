<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolExecutor;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Events\OnAiToolCallEvent;
use axenox\GenAI\Events\OnBeforeAiToolCallEvent;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\AiConversationInterface;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Exceptions\DataTypes\DataTypeValidationError;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use PHPUnit\Framework\TestCase;

/** Exercises invocation and real event dispatch with a real, unstarted Workbench. */
final class AiToolExecutorTest extends TestCase
{
    /** Failed preparation must not execute a side-effecting tool. */
    public function testBeforeListenerFailureSkipsInvocation() : void
    {
        $workbench = new Workbench();
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getWorkbench')->willReturn($workbench);
        $tool->method('getArguments')->willReturn([]);
        $tool->expects(self::never())->method('invoke');
        $workbench->eventManager()->addListener(OnBeforeAiToolCallEvent::getEventName(), static function () : void {
            throw new \RuntimeException('Preparation failed');
        });
        $result = (new AiToolExecutor())->execute($this->handler($workbench, $tool), new GenericTask($workbench), new AiToolCall('write', 'one', []));
        self::assertTrue($result->isFailed());
        self::assertStringContainsString('skipped', $result->getValue());
    }

    /** A failed observer cannot discard a completed result or change its tool outcome. */
    public function testAfterListenerFailurePreservesResult() : void
    {
        $workbench = new Workbench();
        $expected = new AiToolResultString($workbench, 'completed side effect');
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getWorkbench')->willReturn($workbench);
        $tool->method('getArguments')->willReturn([]);
        $tool->expects(self::once())->method('invoke')->willReturn($expected);
        $workbench->eventManager()->addListener(OnAiToolCallEvent::getEventName(), static function () : void {
            throw new \RuntimeException('Observer failed');
        });
        $result = (new AiToolExecutor())->execute($this->handler($workbench, $tool), new GenericTask($workbench), new AiToolCall('write', 'one', []));
        self::assertSame($expected, $result);
        self::assertFalse($result->isFailed());
        self::assertSame([], $result->getErrors());
        self::assertGreaterThanOrEqual(0, $result->getDurationMs());
    }

    /** Invocation defects become deliverable failure results, never success-shaped empty strings. */
    public function testUnexpectedInvocationErrorBecomesFailedResult() : void
    {
        $workbench = new Workbench();
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getWorkbench')->willReturn($workbench);
        $tool->method('getArguments')->willReturn([]);
        $tool->method('getReturnDataType')->willReturn(DataTypeFactory::createFromPrototype($workbench, StringDataType::class));
        $tool->expects(self::once())->method('invoke')->willThrowException(new \RuntimeException('Write defect'));
        $result = (new AiToolExecutor())->execute($this->handler($workbench, $tool), new GenericTask($workbench), new AiToolCall('write', 'one', []));
        self::assertTrue($result->isFailed());
        self::assertNotEmpty($result->getErrors());
    }

    /** Invalid values are corrective failures rather than operational errors. */
    public function testDatatypeValidationFailureIsWarningOnly() : void
    {
        $workbench = new Workbench();
        $datatype = DataTypeFactory::createFromPrototype($workbench, StringDataType::class);
        $parameter = $this->createMock(ServiceParameterInterface::class);
        $parameter->method('getName')->willReturn('value');
        $parameter->method('parseValue')->willThrowException(new DataTypeValidationError($datatype, 'Invalid tool value'));
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getWorkbench')->willReturn($workbench);
        $tool->method('getArguments')->willReturn([$parameter]);
        $tool->method('getReturnDataType')->willReturn($datatype);
        $tool->expects(self::never())->method('invoke');
        $result = (new AiToolExecutor())->execute($this->handler($workbench, $tool), new GenericTask($workbench), new AiToolCall('write', 'one', ['value' => 'invalid']));
        self::assertTrue($result->isFailed());
        self::assertSame([], $result->getErrors());
        self::assertNotEmpty($result->getWarnings());
    }

    /** Expected authorization denials remain generic corrective warnings without protected details. */
    public function testAccessDenialIsFailedWarningWithoutDisclosure() : void
    {
        $workbench = new Workbench();
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getArguments')->willReturn([]);
        $tool->expects(self::once())->method('invoke')->willThrowException(new \exface\Core\Exceptions\Security\AccessDeniedError('Protected permission detail'));
        $result = (new AiToolExecutor())->execute($this->handler($workbench, $tool), new GenericTask($workbench), new AiToolCall('write', 'one', []));
        self::assertTrue($result->isFailed());
        self::assertSame([], $result->getErrors());
        self::assertNotEmpty($result->getWarnings());
        self::assertStringNotContainsString('Protected permission detail', $result->getValueWithMetadata());
    }

    /** Detail-store failure cannot prevent execution or cause a replacement completion write. */
    public function testDetailStartFailureStillExecutesExactlyOnce() : void
    {
        $workbench = new Workbench();
        $expected = new AiToolResultString($workbench, 'completed');
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getArguments')->willReturn([]);
        $tool->expects(self::once())->method('invoke')->willReturn($expected);
        $conversation = $this->createMock(AiConversationInterface::class);
        $conversation->method('getTaskHandler')->willReturn($this->handler($workbench, $tool));
        $conversation->expects(self::once())->method('saveToolCallStart')->willThrowException(new \RuntimeException('Detail store unavailable'));
        $conversation->expects(self::never())->method('saveToolCallEnd');
        $conversation->expects(self::once())->method('saveError')->willReturn(new \exface\Core\Exceptions\RuntimeException('Detail store unavailable'));
        $conversation->expects(self::once())->method('saveToolResults')->with('batch', ['one' => $expected], self::callback(static function (array $data) : bool {
            return $data['timing']->one['duration_ms'] >= 0 && isset($data['finished_on']);
        }));
        $results = (new AiToolExecutor())->executeBatch($conversation, new GenericTask($workbench), 'batch', [new AiToolCall('write', 'one', [])]);
        self::assertSame(['one' => $expected], $results);
        self::assertFalse($expected->isFailed());
    }

    /** Completed in-memory results survive a failed batch completion, without replay. */
    public function testCompletionFailureReturnsActualResultsWithoutRetry() : void
    {
        $workbench = new Workbench();
        $expected = new AiToolResultString($workbench, 'completed');
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getArguments')->willReturn([]);
        $tool->expects(self::once())->method('invoke')->willReturn($expected);
        $conversation = $this->createMock(AiConversationInterface::class);
        $conversation->method('getTaskHandler')->willReturn($this->handler($workbench, $tool));
        $conversation->expects(self::once())->method('saveToolCallStart')->willReturn('detail');
        $conversation->expects(self::once())->method('saveToolCallEnd')->with('detail', $expected, self::anything());
        $conversation->expects(self::once())->method('saveToolResults')->willThrowException(new \RuntimeException('Completion store unavailable'));
        $conversation->expects(self::once())->method('saveError')->willReturn(new \exface\Core\Exceptions\RuntimeException('Completion store unavailable'));
        $results = (new AiToolExecutor())->executeBatch($conversation, new GenericTask($workbench), 'batch', [new AiToolCall('write', 'one', [])]);
        self::assertSame(['one' => $expected], $results);
        self::assertFalse($expected->isFailed());
    }

    /** Supplies the narrow configured-tool boundary without mocking Workbench services. */
    private function handler(Workbench $workbench, AiToolInterface $tool) : AiTaskHandlerInterface
    {
        $handler = $this->createMock(AiTaskHandlerInterface::class);
        $handler->method('getWorkbench')->willReturn($workbench);
        $handler->method('getTool')->willReturn($tool);
        return $handler;
    }
}
