<?php
namespace axenox\GenAI\Exceptions;

class AiInvalidRequestError extends AiProviderDataQueryError
{
    public function getProviderErrorType() : string
    {
        return 'invalid_request_error';
    }
}
