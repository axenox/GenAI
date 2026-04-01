<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\Exceptions\NotFoundError;
use exface\Core\Facades\DocsFacade;
use exface\Core\Widgets\DebugMessage;


class AiConnectionNotFoundError extends NotFoundError
{

    private AiAgentInterface $agent;
    private AiPromptInterface $prompt;

    public function __construct(AiAgentInterface $agent, string $message, ?string $alias = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->agent = $agent;
    }
    
    public function getAgent(): AiAgentInterface
    {
        return $this->agent;
    }

    /**
     * {@inheritDoc}
     * @see ExceptionTrait::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        $debugWidget = parent::createDebugWidget($debugWidget);
        $debugWidget = $this->getAgent()->createDebugWidget($debugWidget);
        //TODO ?
        //$debugWidget = $this->getPrompt()->createDebugWidget($debugWidget); 
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