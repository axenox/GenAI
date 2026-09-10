<?php
namespace axenox\GenAI\Events;

use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\Events\AbstractEvent;

/**
 * Event fired immediately before an AI tool is invoked.
 */
class OnBeforeAiToolCallEvent extends AbstractEvent
{
    private AiToolInterface $tool;

    public function __construct(AiToolInterface $tool)
    {
        $this->tool = $tool;
    }

    public function getTool(): AiToolInterface
    {
        return $this->tool;
    }

    public function getWorkbench()
    {
        return $this->tool->getWorkbench();
    }

    public static function getEventName(): string
    {
        return 'axenox.GenAI.OnBeforeAiToolCall';
    }
}