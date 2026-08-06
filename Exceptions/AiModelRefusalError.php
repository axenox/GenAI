<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;

class AiModelRefusalError extends AiProviderDataQueryError
{
    private ?string $providerName;

    private ?string $refusalCategory;

    public function __construct(
        OpenAiApiDataQuery $query,
        string $message,
        ?\Throwable $previous = null,
        ?bool $retryDecision = null,
        ?string $modelName = null,
        ?string $providerName = null,
        ?string $refusalCategory = null
    )
    {
        $this->providerName = $providerName;
        $this->refusalCategory = $refusalCategory;
        parent::__construct($query, $message, $previous, $retryDecision, $modelName);
    }

    protected function generateMessage(OpenAiApiDataQuery $query, string $message) : string
    {
        $result = 'Das Modell hat die Anfrage aus Sicherheitsbedenken abgelehnt.';

        if ($this->refusalCategory !== null && $this->refusalCategory !== '') {
            $result .= ' Kategorie: ' . $this->refusalCategory . '.';
        }

        $result .= $this->generateProviderMessage($this->providerName);
        $result .= $this->generateModelMessage();
        $result .= $this->generateDetailsMessage($message);
        $result .= $this->generateRetryMessage();
        return $result;
    }

    public function getProviderErrorType() : string
    {
        return 'model_refusal';
    }
}
