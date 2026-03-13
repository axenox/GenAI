<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * A tool is a function, that the LLM can call to interact with our system.
 * 
 * A tool has a name and an `invoke()` method, which receives arguments provided by the LLM and
 * some context information - namely the agent and the AI prompt object. Tools can basically do
 * anything, but they must derive all required information from the input of `invoke()`. Thus,
 * tools are stateless!
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiToolInterface extends iCanBeConvertedToUxon, WorkbenchDependantInterface
{
    /**
     *
     * @param AiAgentInterface $agent
     * @param AiPromptInterface $prompt
     * @param array $arguments
     * @return string
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments) : string;

    /**
     * Summary of getArguments
     * @return \exface\Core\Interfaces\Actions\ServiceParameterInterface[]
     */
    public function getArguments() : array;

    /**
     * 
     * @return string
     */
    public function getName() : string;

    /**
     * 
     * @return string
     */
    public function getDescription() : string;

    /**
     * @return DataTypeInterface
     */
    public function getReturnDataType() : DataTypeInterface;
}