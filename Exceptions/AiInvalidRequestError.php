<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiQueryInterface;

class AiInvalidRequestError extends AiProviderDataQueryError
{
    protected ?string $providerName;

    public function __construct(
        AiQueryInterface    $query,
        string              $message,
        ?\Throwable         $previous = null,
        ?bool               $retryDecision = null,
        ?string             $modelName = null,
        ?string             $providerName = null
    )
    {
        $this->providerName = $providerName;
        parent::__construct($query, $message, $previous, $retryDecision, $modelName);
    }

    protected function generateMessage(AiQueryInterface $query, string $message) : string
    {
        $result = 'Die Anfrage wurde vom KI-Anbieter als ungültig abgelehnt.';
        $result .= $this->generateProviderMessage($this->providerName);
        $result .= $this->generateModelMessage();
        $result .= $this->generateDetailsMessage($message);
        $result .= $this->generateRetryMessage();
        return $result;
    }

    public function getProviderErrorType() : string
    {
        return 'invalid_request_error';
    }
}