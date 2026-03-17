<?php

namespace axenox\GenAI\Common;

use axenox\GenAI\AI\Concepts\MockConcept;
use axenox\GenAI\AI\Tools\MockTool;
use axenox\GenAI\Interfaces\AiAgentInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchInterface;

class TestingContext implements iCanBeConvertedToUxon
{
    use ICanBeConvertedToUxonTrait;

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
    
    private array $sampleTools = [];
    
    

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
    
    public function apply(AiAgentInterface $agent) : AiAgentInterface
    {
        $uxon = $agent->exportUxonObject();
        
        $uxon = $this->enrichWithSampleConcept($uxon);
        $uxon = $this->enrichWithSampleSystemPrompt($uxon);
        $uxon = $this->enrichWithSampleTool($uxon);
        
        $agent->importUxonObject($uxon);
        return $agent;
    }
    
    /**
     * Enriches the agent UXON with a sample system prompt if one is defined in this context.
     * 
     * If a `sample_system_prompt` is configured, it overwrites the agent's `instructions`
     * property for the duration of the test. If no sample prompt is set, the agent UXON
     * is returned unchanged.
     * 
     * @param UxonObject $agentUxon
     * @return UxonObject
     */
    protected function enrichWithSampleSystemPrompt(UxonObject $agentUxon) : UxonObject
    {
        if($this->sampleSystemPrompt){
            $agentUxon->setProperty('instructions', $this->sampleSystemPrompt);
        }
        return $agentUxon;
    }
    
    /**
     * Enriches the agent UXON by replacing configured concepts with mock equivalents if
     * sample concept values are defined in this context.
     * 
     * For each concept already declared in the agent UXON, if a matching sample value
     * exists under the same key in `sample_concepts`, the concept's class is replaced
     * with `MockConcept` and its value is set to the provided sample text. Concepts
     * without a corresponding sample entry are left unchanged. If no sample concepts
     * are configured, the agent UXON is returned unchanged.
     * 
     * @param UxonObject $agentUxon
     * @return UxonObject
     */
    protected function enrichWithSampleConcept(UxonObject $agentUxon) : UxonObject
    {
        if($this->sampleConcepts && $agentUxon->hasProperty('concepts')) {
            $conceptsUxon = $agentUxon->getProperty('concepts');
            foreach ($conceptsUxon as $key => $concept) {
                /** @var UxonObject $concept */
                if($this->sampleConcepts[$key]){
                    $conceptsUxon->setProperty($key, [
                        'class' => '\\' . MockConcept::class,
                        'value' => $this->sampleConcepts[$key]
                    ]);
                }
            }
            $agentUxon->setProperty('concepts', $conceptsUxon);
        }
        return $agentUxon;
    }
    
    /**
     * Enriches the agent UXON by replacing configured tools with mock equivalents if
     * sample tool data is defined in this context.
     * 
     * For each tool already declared in the agent UXON, if a matching sample entry
     * exists under the same key in `sample_tools`, the tool is replaced with a `MockTool`
     * whose `description` and `arguments` are carried over from the original tool
     * definition so that the agent contract stays intact. Tools without a corresponding
     * sample entry are kept as-is. If no sample tools are configured, the agent UXON
     * is returned unchanged.
     * 
     * @param UxonObject $agentUxon
     * @return UxonObject
     */
    protected function enrichWithSampleTool(UxonObject $agentUxon) : UxonObject
    {
        if($this->sampleTools && $agentUxon->hasProperty('tools')) {
            $newToolsUxon = new UxonObject();
            $toolsUxon = $agentUxon->getProperty('tools');
            foreach ($toolsUxon as $key => $tool) {
                /** @var UxonObject $tool */
                if($this->sampleTools[$key]) {
                    //$this->sampleTools[$key]['name'] =$key;
                    if($tool->hasProperty('description')){
                        $this->sampleTools[$key]->setProperty('description', $tool->getProperty('description'));
                    }
                    if ($tool->hasProperty('arguments')) {
                        $this->sampleTools[$key]->setProperty('arguments', $tool->getProperty('arguments'));
                    }
                    $newToolsUxon->setProperty("Mock", $this->sampleTools[$key]);
                    
                    
                } else{
                    $newToolsUxon->setProperty($key, $tool);
                }
            }
            $agentUxon->setProperty('tools', $newToolsUxon);
        }
        return $agentUxon;
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
     * @uxon-template {"object_alias": "exface.Core.CONNECTION","rows": [{"UID": "0x11ea72c00f0fadeca3480205857feb80"}]}
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
     * @uxon-template {"introduction": ""}
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
     * Example tools that can be used in the agent
     *
     * @uxon-property sample_tools
     * @uxon-type \axenox\GenAI\Tools\MockTool[]
     * @uxon-template {"GetLogEntryTool":{"request_response_pairs":[{"request": "Log-ID1234", "response": "Answer Log-ID1223"}]}}
     *
      * @param \exface\Core\CommonLogic\UxonObject $tools
      * @return \axenox\GenAI\Common\TestingContext
     * 
     */
    protected function setSampleTools(UxonObject $tools) : TestingContext
    {
        $this->sampleTools = $tools->getPropertiesAll();
        return $this;
    }
    
}