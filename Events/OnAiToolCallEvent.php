<?php
namespace axenox\GenAI\Events;

use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\Events\AbstractEvent;

/**
 * Event fired after an AI tool has been invoked and its duration was measured.
 */
class OnAiToolCallEvent extends AbstractEvent
{
    private AiToolResultInterface $result;
    private ?float $durationMs;

    public function __construct(AiToolResultInterface $result, ?float $durationMs = null)
    {
        $this->result = $result;
        $this->durationMs = $durationMs;
    }

    public function getResult(): AiToolResultInterface
    {
        return $this->result;
    }

    public function getDurationMs(): ?float
    {
        return $this->durationMs;
    }

    public function getWorkbench()
    {
        return $this->result->getWorkbench();
    }

    public static function getEventName(): string
    {
        return 'axenox.GenAI.OnAiToolCall';
    }
}