<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\CommonLogic\Debugger\HttpMessageDebugWidgetRenderer;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Widgets\DebugMessage;

class AiConceptRuntimeError extends RuntimeException
{
    private AiConceptInterface $concept;
    private AiPromptInterface $prompt;
    
    public function __construct(AiConceptInterface $concept, AiPromptInterface $prompt, string $message, string $alias, \Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->concept = $concept;
        $this->prompt = $prompt;
    }
    
    public function getConcept(): AiConceptInterface
    {
        return $this->concept;
    }
    
    public function getPrompt(): AiPromptInterface
    {
        return $this->prompt;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        $debugWidget = parent::createDebugWidget($debugWidget);
        $debugWidget = $this->getPrompt()->createDebugWidget($debugWidget);
        return $debugWidget;
    }
}