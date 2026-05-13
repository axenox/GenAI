<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\AliasInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\iCanGenerateDebugWidgets;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiAgentInterface extends iCanBeConvertedToUxon, AliasInterface, iCanGenerateDebugWidgets, WorkbenchDependantInterface
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
     * @param string $name
     * @return AiToolInterface
     */
    public function getTool(string $name) : AiToolInterface;

    /**
     * Returns the UxonObject containing the concepts
     *
     * @return array UxonObject
     */
    public function getRawConcepts() : ?UxonObject;
}