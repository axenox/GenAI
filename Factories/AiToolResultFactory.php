<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Restores supported results without resolving or executing their original tool.
 */
final class AiToolResultFactory
{
    public static function createFromUxon(WorkbenchInterface $workbench, UxonObject $uxon): AiToolResultInterface
    {
        $class = $uxon->getProperty('class');
        if (! is_string($class) || ltrim($class, '\\') !== AiToolResultString::class) {
            throw new \UnexpectedValueException('Unsupported or missing AI tool result class.');
        }
        $result = new AiToolResultString($workbench);
        $result->importUxonObject($uxon);
        return $result;
    }
}
