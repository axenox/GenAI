<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;

class AiMaxTokensExceededError extends AiInvalidRequestError
{
    private ?int $requestedMaxTokens;

    private ?int $allowedMaxTokens;

    public function __construct(
        OpenAiApiDataQuery $query,
        string $message,
        ?\Throwable $previous = null,
        ?bool $retryDecision = null,
        ?string $modelName = null,
        ?string $providerName = null,
        ?int $requestedMaxTokens = null,
        ?int $allowedMaxTokens = null
    )
    {
        $this->requestedMaxTokens = $requestedMaxTokens;
        $this->allowedMaxTokens = $allowedMaxTokens;
        parent::__construct($query, $message, $previous, $retryDecision, $modelName, $providerName);
    }

    protected function generateMessage(OpenAiApiDataQuery $query, string $message) : string
    {
        $result = 'Die angegebene Tokenanzahl im Request überschreitet das Maximum.';

        if ($this->requestedMaxTokens !== null && $this->allowedMaxTokens !== null) {
            $result .= ' Bitte reduzieren Sie die derzeitige Tokenzahl von '
                . $this->requestedMaxTokens
                . ' auf höchstens '
                . $this->allowedMaxTokens
                . '.';
        } elseif ($this->allowedMaxTokens !== null) {
            $result .= ' Bitte reduzieren Sie die Tokenzahl auf höchstens ' . $this->allowedMaxTokens . '.';
        } elseif ($this->requestedMaxTokens !== null) {
            $result .= ' Aktuell angeforderte Tokenzahl: ' . $this->requestedMaxTokens . '.';
        }

        $result .= $this->generateProviderMessage($this->providerName);
        $result .= $this->generateModelMessage();
        $result .= $this->generateDetailsMessage($message);
        $result .= $this->generateRetryMessage();
        return $result;
    }

    public function getProviderErrorType() : string
    {
        return 'invalid_request_error.max_tokens_exceeded';
    }
}
