<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Interfaces\AiProviderErrorInterface;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;

class AiProviderDataQueryError extends DataQueryFailedError implements AiProviderErrorInterface
{
    public function __construct(OpenAiApiDataQuery $query, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($query, $message, null, $previous);
    }

    public function shouldRetry() : bool
    {
        return false;
    }

    public function getProviderErrorType() : string
    {
        return 'provider_error';
    }
}
