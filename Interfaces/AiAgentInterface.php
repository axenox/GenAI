<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\AliasInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiAgentInterface extends iCanBeConvertedToUxon, AliasInterface
{
    public function handle(AiPromptInterface $prompt) : AiResponseInterface;

    public function setDevmode(bool $trueOrFalse): AiAgentInterface;

    public function getPromptSuggestions(): array;

    /**
     * Returns the UxonObject containing the concepts
     *
     * @return array UxonObject
     */
    public function getRawConcepts() : ?UxonObject;
}