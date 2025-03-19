<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiToolCallInterface
{
    public function getToolName() : string;

    public function getCallId() : string;

    public function getArguments() : array;
}