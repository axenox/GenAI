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
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        $debug_widget = parent::createDebugWidget($debug_widget);
        // TODO add useful tabs for the AI agent
        return $debug_widget;
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