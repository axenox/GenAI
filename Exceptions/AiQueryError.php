<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiQueryInterface;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Widgets\DebugMessage;
use Psr\Http\Message\RequestInterface;

/**
 * Exception thrown when a query to an LLM model fails.
 *
 * Transport diagnostics are rendered by the query. An explicit HTTP request is attached
 * to a query copy so it can be rendered together with any response already received.
 */
class AiQueryError extends RuntimeException
{
    private AiQueryInterface $query;
    private ?RequestInterface $request = null;
    
    public function __construct(AiQueryInterface $query, string $message, ?string $alias = null, ?\Throwable $previous = null, ?RequestInterface $httpRequest = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->query = $httpRequest === null ? $query : $query->withRequest($httpRequest);
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
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Exceptions\RuntimeException::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        return $this->query->createDebugWidget($debug_widget);
    }
}