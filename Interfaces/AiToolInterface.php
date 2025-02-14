<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiToolInterface extends iCanBeConvertedToUxon, WorkbenchDependantInterface
{
    /**
     * 
     * @param array $arguments
     * @return void
     */
    public function invoke(array $arguments) : string;

    /**
     * Summary of getArguments
     * @return \exface\Core\Interfaces\Actions\ServiceParameterInterface[]
     */
    public function getArguments() : array;
}