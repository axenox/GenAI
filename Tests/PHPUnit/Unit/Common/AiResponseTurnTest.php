<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Common;

use axenox\GenAI\AI\TestCriteria\ToolResponseTestCriterion;
use axenox\GenAI\Common\AiMessage;
use axenox\GenAI\Common\AiPayload;
use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Interfaces\AiConversationInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\Tasks\TaskInterface;
use PHPUnit\Framework\TestCase;

class AiResponseTurnTest extends TestCase
{
    public function testResponseAndCriterionUseOnlyCapturedTurnNotConversationHistory() : void
    {
        $workbench = new Workbench(['DEBUG.LIVE_CLASS_AUTOLOADER' => false]);
        $task = $this->createStub(TaskInterface::class);
        $task->method('getWorkbench')->willReturn($workbench);
        $old = $this->message($workbench, 'old', 'previous_tool');
        $current = $this->message($workbench, 'current', 'current_tool');
        $conversation = $this->createMock(AiConversationInterface::class);
        $conversation->method('getConversationId')->willReturn('conversation');
        $conversation->expects($this->never())->method('getMessages');
        $response = (new AiResponse($task, 'Answer'))->setConversation($conversation)->setMessages([$current]);
        $this->assertSame($conversation, $response->getConversation());
        $this->assertSame('conversation', $response->getConversationId());
        $this->assertSame(['current'], array_map(static fn($message) => $message->getUid(), $response->getMessages()));
        $this->assertNotSame($current, $response->getMessages()[0]);
        $this->assertSame('actual result', $response->getMessages()[0]->getToolResults()['current']->getValue());
        $criterion = new ToolResponseTestCriterion($workbench, new UxonObject());
        $output = $criterion->getValue($response);
        $this->assertStringContainsString('current_tool', $output);
        $this->assertStringNotContainsString('previous_tool', $output);
        $this->assertSame('previous_tool', $old->getCalls()[0]->getToolName());
    }

    private function message(Workbench $workbench, string $id, string $tool) : AiMessage
    {
        $call = new AiToolCall($tool, $id, ['nested' => (object) ['key' => 'value']]);
        $result = new AiToolResultString($workbench, 'actual result');
        return new AiMessage($workbench, [
            'UID' => $id, 'AI_CONVERSATION' => 'conversation', 'SEQUENCE_NUMBER' => $id === 'old' ? 1 : 2,
            'ROLE' => 'tool', 'MESSAGE' => 'preview', 'FINISHED_ON' => '2026-11-06 20:00:00',
            'DATA' => AiPayload::encode([
                'calls' => (object) [$id => $call->jsonSerialize()], 'call_order' => [$id],
                'results' => (object) [$id => $result->exportUxonObject()->toArray()],
            ]),
        ]);
    }

    public function testResponseRetainsActualResultsWithoutInventingACompletionAfterWriteFailure() : void
    {
        $workbench = new Workbench(['DEBUG.LIVE_CLASS_AUTOLOADER' => false]);
        $task = $this->createStub(TaskInterface::class);
        $task->method('getWorkbench')->willReturn($workbench);
        $call = new AiToolCall('read', 'call', []);
        $result = new AiToolResultString($workbench, 'Actual unsaved result');
        $message = new AiMessage($workbench, [
            'UID' => 'message', 'AI_CONVERSATION' => 'conversation', 'SEQUENCE_NUMBER' => 1,
            'ROLE' => 'tool', 'MESSAGE' => 'Request preview', 'FINISHED_ON' => null,
            'DATA' => AiPayload::encode([
                'calls' => (object) ['call' => $call->jsonSerialize()], 'call_order' => ['call'], 'results' => new \stdClass(),
            ]),
        ]);
        $response = (new AiResponse($task, 'Answer', 'conversation'))->setMessages([$message], ['call' => $result]);
        $snapshot = $response->getMessages()[0];
        $this->assertTrue($snapshot->isBroken());
        $this->assertSame('Actual unsaved result', $snapshot->getToolResults()['call']->getValue());
        $this->assertFalse($snapshot->getData()->hasProperty('finished_on'));
        $this->assertSame([], $message->getToolResults());
    }
}
