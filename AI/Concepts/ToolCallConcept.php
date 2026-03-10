<?php

namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;

class ToolCallConcept extends AbstractConcept
{
    
    private ?AiToolInterface $tool = null;
    private ?string $toolName = null;
    private ?UxonObject $toolDef = null;
    private ?string $toolClass = null;
    
    private array $arguments = [];

    
    public function getOutput(): string
    {
        try{
            return $this->getTool()->invoke($this->arguments);
        }catch (\Exception $e){
            $this->getWorkbench()->getLogger()->logException($e);
            return "";
        }
        
    }
    
    public function getToolClass(): string
    {
        if($this->toolClass === null){
            $this->toolClass = AiFactory::findToolClass($this->getWorkbench(), $this->toolName, $this->toolDef);
        }
        return $this->toolClass;
    }

    protected function getTool() : AiToolInterface
    {
        if($this->tool === null){
            if ($this->toolDef !== null) {
                $this->tool = AiFactory::createToolFromUxon($this->getWorkbench(), $this->getToolName(), $this->toolDef);
            } else {
                throw new AiConceptConfigurationError($this, 'Missing tool definition for ToolCallConcept "' . $this->getPlaceholder() . '"');
            }
        }
        
        
        return $this->tool;
    }
    
    public function getToolUxon() : UxonObject
    {
        return $this->toolDef;
    }


    /**
     * @param string|UxonObject $stringOrUxon
     * @return $this
     */
    public function setTool(string|UxonObject | AiToolInterface $stringOrUxonOrTool) : ToolCallConcept
    {
        if($stringOrUxonOrTool instanceof AiToolInterface){
            $this->tool = $stringOrUxonOrTool;
            return $this;
        }
        if ($stringOrUxonOrTool instanceof UxonObject) {
            $this->parseLegacySyntax($stringOrUxonOrTool);
        } else {
            $this->toolName = $stringOrUxonOrTool;
        }
        return $this;
    }
    
    public function getToolName() : string
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