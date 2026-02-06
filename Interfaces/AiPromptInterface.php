<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\iCanGenerateDebugWidgets;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiPromptInterface extends TaskInterface, iCanGenerateDebugWidgets
{
    /**
     * 
     * @return string
     */
    public function getUserPrompt() : string;


    /**
     * 
     * @return string|null
     */
    public function getConversationUid() : ?string;

    /**
     * 
     * @param string $uid
     * @return void
     */
    public function setConversationUid(string $uid) : AiPromptInterface;

    /**
     * @param string $text
     * @return AiPromptInterface
     */
    public function setPrompt(string $text) : AiPromptInterface;
}