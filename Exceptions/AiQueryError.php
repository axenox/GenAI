<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use exface\Core\CommonLogic\Debugger\HttpMessageDebugWidgetRenderer;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Widgets\DebugMessage;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Exception thrown when a query to an LLM model fails.
 */
class AiQueryError extends RuntimeException
{
    private AiQueryInterface $query;
    private ?RequestInterface $request = null;
    
    public function __construct(AiQueryInterface $query, string $message, ?string $alias = null, ?\Throwable $previous = null, ?RequestInterface $httpRequest = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->query = $query;
        $this->request = $httpRequest;
    }
    
    public function getQuery(): AiQueryInterface
    {
        return $this->query;
    }
    
    public function getHttpRequest(): ?RequestInterface
    {
        return $this->request;
    }
    
    public function getHttpResponse() : ?ResponseInterface
    {
        return $this->query->hasResponse() ? $this->query->getResponseMessage() : null;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        if (null !== $request = $this->getHttpRequest()) {
            $renderer = new HttpMessageDebugWidgetRenderer($request, $this->getHttpResponse(), 'LLM request', 'LLM response');
            $debug_widget = $renderer->createDebugWidget($debug_widget);
        }

        return $debug_widget;
    }
}