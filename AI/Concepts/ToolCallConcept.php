<?php

namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;

class ToolCallConcept extends AbstractConcept
{
    private ?string $toolName = null;
    private ?UxonObject $toolDef = null;
    
    private array $arguments = [];

    
    public function getOutput(): string
    {
        try{
            return $this->tool->invoke($this->arguments);
        }catch (\Exception $e){
            $this->getWorkbench()->getLogger()->logException($e);
            return "";
        }
        
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