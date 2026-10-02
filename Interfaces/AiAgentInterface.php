<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\CommonLogic\UxonObject;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiAgentInterface extends AiTaskHandlerInterface
{
    /**
     * @param AiPromptInterface $prompt
     * @return AiResponseInterface
     */
    public function handle(AiPromptInterface $prompt) : AiResponseInterface;

    /**
     * @param bool $trueOrFalse
     * @return AiAgentInterface
     */
    public function setDevmode(bool $trueOrFalse): AiAgentInterface;

    public function getPromptSuggestions(): array;

    /**
     * Returns the model connection used by this agent.
     */
    public function getConnection() : AiConnectorInterface;

    /**
     * Returns whether the agent runs in development mode.
     */
    public function getDevmode() : bool;

    /**
     * Returns the UxonObject containing the concepts
     *
     * @return array UxonObject
     */
    public function getRawConcepts() : ?UxonObject;

    /**
     * Returns the fully rendered instructions (system prompt) for the given prompt,
     * with all concepts resolved.
     *
     * @param AiPromptInterface $prompt
     * @return string
     */
    public function renderSystemPrompt(AiPromptInterface $prompt) : string;
}