<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\Exceptions\ExceptionTrait;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\DocsFacade;
use exface\Core\Widgets\DebugMessage;

class AiPromptError extends RuntimeException
{
    private AiAgentInterface $agent;
    private AiPromptInterface $prompt;
    
    public function __construct(AiAgentInterface $agent, AiPromptInterface $prompt, string $message, ?string $alias = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->agent = $agent;
        $this->prompt = $prompt;
    }
    
    public function getAgent(): AiAgentInterface
    {
        return $this->agent;
    }
    
    public function getPrompt(): AiPromptInterface
    {
        return $this->prompt;
    }

    /**
     * {@inheritDoc}
     * @see ExceptionTrait::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        $debugWidget = parent::createDebugWidget($debugWidget);
        $debugWidget = $this->getAgent()->createDebugWidget($debugWidget);
        $debugWidget = $this->getPrompt()->createDebugWidget($debugWidget);
        return $debugWidget;
    }

    /**
     * {@inheritDoc}
     * @see ExceptionTrait::createDebugWidget()
     */
    public function getLinks(): array
    {
        $links = parent::getLinks();
        $links['AI agent prototype "' . $this->agent->getAlias() . '"'] = DocsFacade::buildUrlToDocsForUxonPrototype($this->agent);
        return $links;
    }
}