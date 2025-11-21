<?php

namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\AI\Agents\GenericAssistant;
use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Common\Selectors\AiToolSelector;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;

class ToolCallConcept extends AbstractConcept
{
    
    private ?AiToolInterface $tool = null;
    
    private array $arguments = [];

    public function resolve(array $placeholders) : array
    {
        $phVals = [];
        $phVals[$this->getPlaceholder()] =  $this->tool->invoke($this->arguments);;
        return $phVals;
    }


    /**
     * Tools (function calls)
     *
     * ```
     *   {
     *      "tool": {
     *          "GetDocs": {
     *              "arguments": [
     *                      {
     *                          "uri": "api\/docs\/axenox\/genai\/Docs\/AI_Testing\/index.md"
     *                      }
     *                  }
     *              ]
     *          }
     *      }
     *  }
     *
     * ```
     * @uxon-property tool
     * @uxon-type \axenox\GenAI\Common\AbstractAiTool
     * @uxon-template {"": {"arguments": [{"name": "", "data_type": {"alias": ""}}]}}
     *
     * @param \exface\Core\CommonLogic\UxonObject $objectWithToolDefs
     * @return ToolCallConcept
     */
    protected function setTool(UxonObject $objectWithToolDefs) : ToolCallConcept
    {
        $props     = $objectWithToolDefs->getPropertiesAll();
        $toolName  = array_key_first($props);
        $toolDef   = $props[$toolName]->getPropertiesAll();
        $arguments = $toolDef['arguments'] ?? [];


        $this->arguments = [];

       
        $argumentDefs = [];

        foreach ($arguments as $argument) {
            $argProps = $argument->getPropertiesAll(); 

            foreach ($argProps as $key => $value) {
               
                $this->arguments[] = $value;

                
            }
        }

        $this->tool = $this->buildools($toolName, $this->arguments);

        return $this;
    }




    public function buildools(string $name, array $argumentsList) : AiToolInterface
    {
        $arguements = [];
        foreach ($argumentsList as $key) {
            $arguements[] = [
                'name' => $key
            ];
            
        }
        $toolUxon = new UxonObject(
            [
                'name'=> $name,
                'arguments'=> $arguements
                
            ]
        );
        $tool = AiFactory::createToolFromUxon($this->getWorkbench(), $name, $toolUxon);

        return $tool;
    }

    
}