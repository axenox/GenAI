<?php
namespace axenox\GenAI\Interfaces;

interface AiProviderErrorInterface
{
    /**
     * Returns TRUE if the request can be retried safely.
     *
     * @return bool
     */
    public function shouldRetry() : bool;

    /**
     * Returns a normalized provider error type.
     *
     * @return string
     */
    public function getProviderErrorType() : string;
}
