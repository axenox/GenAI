<?php
namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\AliasInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\iCanGenerateDebugWidgets;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * Common contract for configured handlers that execute tasks through AI tools.
 */
interface AiTaskHandlerInterface extends iCanBeConvertedToUxon, AliasInterface, iCanGenerateDebugWidgets, WorkbenchDependantInterface
{
    /**
     * Returns the persisted handler UID.
     */
    public function getUid() : string;

    /**
     * Returns the persisted handler name.
     */
    public function getName() : string;

    /**
     * Returns the exact configured handler version.
     */
    public function getVersion() : string;

    /**
     * Returns the exact selector resolved for this handler instance.
     */
    public function getSelector() : AiAgentSelectorInterface;

    /**
     * Returns a configured tool by its exposed name.
     */
    public function getTool(string $name) : AiToolInterface;

    /**
     * Returns all tools exposed by this handler.
     *
     * @return AiToolInterface[]
     */
    public function getTools() : array;
}
