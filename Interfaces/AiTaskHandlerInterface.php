<?php
namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\AliasInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\iCanGenerateDebugWidgets;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Common contract for configured handlers that execute tasks through AI tools.
 * 
 * @author GitHub Copilot
 */
interface AiTaskHandlerInterface extends iCanBeConvertedToUxon, AliasInterface, iCanGenerateDebugWidgets, WorkbenchDependantInterface
{
    /**
     * Returns the persisted handler UID.
     * 
     * @return string
     */
    public function getUid() : string;

    /**
     * Returns the persisted handler name.
     * 
     * @return string
     */
    public function getName() : string;

    /**
     * Returns the exact configured handler version.
     * 
     * @return string
     */
    public function getVersion() : string;

    /**
     * Returns the exact selector resolved for this handler instance.
     * 
     * @return AiAgentSelectorInterface
     */
    public function getSelector() : AiAgentSelectorInterface;

    /**
     * Returns a configured tool by its exposed name.
     * 
     * @param string $name
     * @return AiToolInterface
     */
    public function getTool(string $name) : AiToolInterface;

    /**
     * Returns all tools exposed by this handler.
     * 
     * @return AiToolInterface[]
     */
    public function getTools() : array;

    /**
     * Resolves and invokes a configured tool, returning deliverable failures without persistence.
     * 
     * @param TaskInterface $task
     * @param AiToolCallInterface $call
     * @return AiToolResultInterface
     */
    public function callTool(TaskInterface $task, AiToolCallInterface $call) : AiToolResultInterface;

    /**
     * @return \Throwable[] Operational after-listener errors from the most recent invocation.
     */
    public function getToolOperationalErrors() : array;
}
