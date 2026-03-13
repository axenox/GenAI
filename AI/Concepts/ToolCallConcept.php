<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;

/**
 * Allows to use placeholders for predefined tool calls in system prompts.
 * 
 * The concept can be used in two ways:
 * 
 * 1. Simply calling one of the tools of this agent: Just set the tool name as string value of the concept. The tool 
 * must be defined in the agent's tool collection with the same name as the concept's placeholder.
 * 2. Define an arbitrary tool inside the concept (the tool than cannot be called by the AI, just by this concept).
 * 
 */
class ToolCallConcept extends AbstractConcept
{
    private ?string $toolName = null;
    private ?UxonObject $toolDef = null;
    
    private array $arguments = [];

    
    protected function getOutput(): string
    {
        try{
            return $this->getTool()->invoke($this->getAgent(), $this->getPrompt(), $this->arguments);
        }catch (\Exception $e){
            $this->getWorkbench()->getLogger()->logException($e);
            return "";
        }
        
    }

    protected function getTool() : AiToolInterface
    {
        if ($this->toolDef !== null) {
            $tool = AiFactory::createToolFromUxon($this->getWorkbench(), $this->getToolName(), $this->toolDef);
        } else {
            throw new AiConceptConfigurationError($this, 'Missing tool definition for ToolCallConcept "' . $this->getPlaceholder() . '"');
        }
        
        return $tool;
    }


    /**
     * @param string|UxonObject $stringOrUxon
     * @return $this
     */
    protected function setTool(string|UxonObject $stringOrUxon) : ToolCallConcept
    {
        if ($stringOrUxon instanceof UxonObject) {
            $this->parseLegacySyntax($stringOrUxon);
        } else {
            $this->toolName = $stringOrUxon;
        }
        return $this;
    }
    
    protected function getToolName() : string
    {
        return $this->toolName ?? $this->getPlaceholder();
    }

    /**
     * @return string[]
     */
    protected function getArguments() : array
    {
        return $this->arguments;
    }

    /**
     * Arguments to be used in the tool call
     * 
     * @uxon-property arguments
     * @uxon-type array
     * @uxon-template [""]
     * 
     * @param UxonObject $arguments
     * @return $this
     */
    protected function setArguments(UxonObject $arguments) : ToolCallConcept
    {
        $this->arguments = $arguments->toArray();
        return $this;
    }

    /**
     * Define a tool right here instead of referencing an existing tool in this agent
     *
     * ```
     *   {
     *      "tool": {
     *          "class": "\axenox\GenAI\AI\Tools\GetDocsTool"
     *      }
     *  }
     *
     * ```
     * @uxon-property tool_definition
     * @uxon-type \axenox\GenAI\Common\AbstractAiTool
     * @uxon-template {"class": ""}
     *
     * @param \exface\Core\CommonLogic\UxonObject $objectWithToolDefs
     * @return ToolCallConcept
     */
    protected function setToolDefinition(UxonObject $objectWithToolDefs) : ToolCallConcept
    {
        $this->toolDef = $objectWithToolDefs;
        return $this;
    }
    
    protected function parseLegacySyntax(UxonObject $objectWithToolDefs) : ToolCallConcept
    {
        $props     = $objectWithToolDefs->getPropertiesAll();
        $toolName  = array_key_first($props);
        $toolDef   = $props[$toolName]->copy();
        $arguments = $toolDef->getProperty('arguments') ?? [];
        $toolDef->unsetProperty('arguments');
        $toolDef->setProperty('name', $toolName);
        $argVals = [];

        foreach ($arguments as $argument) {
            $argProps = $argument->getPropertiesAll();
            foreach ($argProps as $value) {
                $argVals[] = $value;
            }
        }
        $this->setArguments(new UxonObject($argVals));
        
        $this->setToolDefinition($toolDef);
        $this->setTool($toolName);
        return $this;
    }
}