<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\Exceptions\RuntimeException;

/**
 * Exception thrown if an AI tool encounters a severe error, which makes it impossible to use this tool for the LLM
 * 
 * In particular, this type of error means, the tool did not return any meaningful result and should actually not be
 * used again. 
 * 
 * If this error occurs, the agent will tell the LLM to stop using the tool, but the other tools will remain usable
 * and the conversation can continue.
 * 
 * @author Andrej Kabachnik
 */
class AiToolCriticalError extends RuntimeException
{
    private AiToolInterface $tool;
    private AiPromptInterface $prompt;

    /**
     * @param AiToolInterface $tool
     * @param AiPromptInterface $prompt
     * @param string $message
     * @param string|null $alias
     * @param \Throwable|null $previous
     */
    public function __construct(AiToolInterface $tool, AiPromptInterface $prompt, string $message, ?string $alias = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->tool = $tool;
        $this->prompt = $prompt;
    }

    /**
     * @return AiToolInterface
     */
    public function getTool(): AiToolInterface
    {
        return $this->tool;
    }

    /**
     * @return AiPromptInterface
     */
    public function getPrompt(): AiPromptInterface
    {
        return $this->prompt;
    }
}