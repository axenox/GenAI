<?php
namespace axenox\GenAI\Exceptions;

class AiMaxTokensExceededError extends AiInvalidRequestError
{
    public function getProviderErrorType() : string
    {
        return 'invalid_request_error.max_tokens_exceeded';
    }
}
