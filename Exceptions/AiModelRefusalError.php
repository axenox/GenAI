<?php
namespace axenox\GenAI\Exceptions;

class AiModelRefusalError extends AiProviderDataQueryError
{
    public function getProviderErrorType() : string
    {
        return 'model_refusal';
    }
}
