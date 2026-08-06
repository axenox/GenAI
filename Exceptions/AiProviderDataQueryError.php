<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Interfaces\AiProviderErrorInterface;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;

class AiProviderDataQueryError extends DataQueryFailedError implements AiProviderErrorInterface
{
    protected ?bool $retryDecision;

    protected ?string $modelName;

    public function __construct(
        OpenAiApiDataQuery $query,
        string $message,
        ?\Throwable $previous = null,
        ?bool $retryDecision = null,
        ?string $modelName = null
    )
    {
        $this->retryDecision = $retryDecision;
        $this->modelName = $modelName;

        parent::__construct($query, $this->generateMessage($query, $message), null, $previous);
    }

    protected function generateMessage(OpenAiApiDataQuery $query, string $message) : string
    {
        $result = 'Die KI-Anfrage konnte nicht verarbeitet werden.';
        $result .= $this->generateModelMessage();
        $result .= $this->generateDetailsMessage($message);
        $result .= $this->generateRetryMessage();
        return $result;
    }

    protected function generateRetryMessage() : string
    {
        if ($this->retryDecision === true) {
            return ' Bitte versuchen Sie es später noch einmal.';
        }

        if ($this->retryDecision === false) {
            return ' Bitte versuchen Sie die Anfrage nicht unverändert erneut.';
        }

        return '';
    }

    protected function generateModelMessage() : string
    {
        if ($this->modelName === null || $this->modelName === '') {
            return '';
        }

        return ' Betroffenes Modell: ' . $this->modelName . '.';
    }

    protected function generateProviderMessage(?string $providerName) : string
    {
        if ($providerName === null || $providerName === '') {
            return '';
        }

        return ' Anbieter: ' . $providerName . '.';
    }

    protected function generateDetailsMessage(string $message) : string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        return ' Details: ' . $message . '.';
    }

    public function shouldRetry() : bool
    {
        return $this->retryDecision ?? false;
    }

    public function getProviderErrorType() : string
    {
        return 'provider_error';
    }
}
