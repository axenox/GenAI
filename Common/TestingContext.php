<?php

namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchInterface;

class TestingContext implements iCanBeConvertedToUxon
{
    use ImportUxonObjectTrait;

    private WorkbenchInterface $workbench;

    /**
     * Alias of the object that will be used for the sample call
     */
    private ?string $objectAlias = null;

    /**
     * Alias of the action that will be used for the sample call
     */
    private ?string $actionAlias = null;

    /**
     * Input data for the sample call
     */
    private ?UxonObject $inputData = null;

    /**
     * Example system prompt for testing
     */
    private ?string $sampleSystemPrompt = null;

    /**
     * Example concepts for the sample system prompt
     */
    private array $sampleConcepts = [];

    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    public function getSamplePromptUxon(): UxonObject
    {
        $data = [];

        if (!empty($this->objectAlias)) {
            $data['object_alias'] = $this->objectAlias;
        }

        if (!empty($this->actionAlias)) {
            $data['action_alias'] = $this->actionAlias;
        }

        if (!empty($this->inputData)) {
            $data['input_data'] = $this->inputData;
        }
        
        $data['page_alias'] = 'axenox.genai.testing';

        return new UxonObject($data);
    }
    
    public function enrichWithSampleSystemPrompt(AiAgentInterface $agent) : TestingContext
    {
        if($this->sampleSystemPrompt){
            $uxon = new UxonObject(['sample_system_prompt'=>$this->sampleSystemPrompt]);
            $agent->importUxonObject($uxon);
        }
        return $this;
    }
    
    public function enrichWithSampleConcept(AiAgentInterface $agent) : TestingContext
    {
        
        
        
        
        if($this->sampleConcepts){
            $data = $agent->getRawConcepts();
            foreach ($data as $key => $concept) {
                /** @var UxonObject $concept */
                if($this->sampleConcepts[$key]){
                    $concept->setProperty('output', $this->sampleConcepts[$key]);
                }
            }
            $agent->importUxonObject(UxonObject::fromArray(['concepts' => $data]));
        }
        return $this;
    }
    

    /**
     * Object alias for the sample request
     *
     * This is the technical name of the business object that will be called
     * when the sample request is executed.
     *
     * @uxon-property object_alias
     * @uxon-type string
     * @uxon-template "exface.Core.CONNECTION"
     *
     * @param string $objectAlias
     * @return \axenox\GenAI\Common\TestingContext
     */
    protected function setObjectAlias(string $objectAlias) : TestingContext
    {
        $this->objectAlias = $objectAlias;
        return $this;
    }

    /**
     * Action alias for the sample request
     *
     * This is the technical name of the action that will be executed on the
     * configured business object.
     *
     * @uxon-property action_alias
     * @uxon-type string
     * @uxon-template "exface.Core.CONNECTION"
     *
     * @param string $actionAlias
     * @return \axenox\GenAI\Common\TestingContext
     */
    protected function setActionAlias(string $actionAlias) : TestingContext
    {
        $this->actionAlias = $actionAlias;
        return $this;
    }

    /**
     * Input data for the sample request
     *
     *
     * @uxon-property input_data
     * @uxon-template {
     *   "object_alias": "exface.Core.CONNECTION",
     *   "rows": [
     *     {
     *       "UID": "0x11ea72c00f0fadeca3480205857feb80"
     *     }
     *   ]
     * }
     *
     * @param \exface\Core\CommonLogic\UxonObject $inputData
     * @return \axenox\GenAI\Common\TestingContext
     */
    protected function setInputData(UxonObject $inputData) : TestingContext
    {
        $this->inputData = $inputData;
        return $this;
    }

    /**
     * Example system prompt for testing
     *
     * This prompt is only used inside the testing context and does not change
     * the real agent configuration.
     *
     * @uxon-property sample_system_prompt
     * @uxon-type string
     * 
     *
     * @param string $systemPrompt
     * @return \axenox\GenAI\Common\TestingContext
     */
    protected function setSampleSystemPrompt(string $systemPrompt) : TestingContext
    {
        $this->sampleSystemPrompt = $systemPrompt;
        return $this;
    }

    /**
     * Example concepts that can be used in the sample system prompt
     *
     * @uxon-property sample_concepts
     * @uxon-template {
     *   "introduction": ""
     * }
     *
     * @param \exface\Core\CommonLogic\UxonObject $concepts
     * @return \axenox\GenAI\Common\TestingContext
     */
    protected function setSampleConcepts(UxonObject $concepts) : TestingContext
    {
        $this->sampleConcepts = $concepts->getPropertiesAll();
        return $this;
    }

    

    /**
     * Export current testing configuration to Uxon
     *
     * @return \exface\Core\CommonLogic\UxonObject
     */
    public function exportUxonObject()
    {
        $uxon = new UxonObject();

        if ($this->objectAlias !== null) {
            $uxon->setProperty('object_alias', $this->objectAlias);
        }

        if ($this->actionAlias !== null) {
            $uxon->setProperty('action_alias', $this->actionAlias);
        }

        if ($this->inputData !== null) {
            $uxon->setProperty('input_data', $this->inputData);
        }

        if ($this->sampleSystemPrompt !== null) {
            $uxon->setProperty('sample_system_prompt', $this->sampleSystemPrompt);
        }

        if (!empty($this->sampleConcepts)) {
            $uxon->setProperty('sample_concepts', UxonObject::fromArray($this->sampleConcepts));
        }

        return $uxon;
    }
}