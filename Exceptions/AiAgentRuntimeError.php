<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Widgets\DebugMessage;

class AiAgentRuntimeError extends RuntimeException
{
    private AiTaskHandlerInterface $agent;
    
    public function __construct(AiTaskHandlerInterface $agent, string $message, string $alias, \Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->agent = $agent;
    }
    
    public function getAgent(): AiTaskHandlerInterface
    {
        return $this->agent;
    }

    /**
     * @param DebugMessage $debugWidget
     * @return DebugMessage
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        $debugWidget = parent::createDebugWidget($debugWidget);
        $debugWidget = $this->getAgent()->createDebugWidget($debugWidget);
        return $debugWidget;
    }
}