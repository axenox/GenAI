<?php
namespace axenox\GenAI\Exceptions;

class AiOverloadError extends AiProviderDataQueryError
{
    public function shouldRetry() : bool
    {
        return true;
    }

    public function getProviderErrorType() : string
    {
        return 'overloaded_error';
    }
}
