<?php

namespace axenox\GenAI\AI\Tools;


use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\RequestResponsePairs;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;


class MockTool extends AbstractAiTool
{

    
    private ?array $requestResponsePairs = null;
    
    private string $sampleResponse = "No data available for this request";
    

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        list($var) = $arguments;
        
        if($this->requestResponsePairs !== null){
            foreach($this->requestResponsePairs as $pair){
                if($pair instanceof RequestResponsePairs){
                    if($pair->isMatch($var)){
                        return $pair->getResponse();
                    }
                }
            }
        }
        
        return $this->sampleResponse;
    }

    /**
     * If you expect a specific request from the AI, you can specify here what should be returned for those requests.
     * 
     * @uxon-proeprty request_response_pairs
     * @uxon-type \axenox\GenAI\Common\RequestResponsePairs[]
     * 
     * @param UxonObject $requestResponsePairs
     * @return AiToolInterface
     */
    protected function setRequestResponsePairs(UxonObject $requestResponsePairs): AiToolInterface
    {
        $this->requestResponsePairs = [];
        foreach ($requestResponsePairs as $pair => $uxon) {
            $this->requestResponsePairs[] = $uxon;
        }
        return $this;
    }

    /**
     * What should the AI tool return when it is called?
     *.
     *
     * @uxon-property sample_Response
     * @uxon-type string
     *
     *
     * @param string $sampleResponse
     * @return AiToolInterface
     */
    protected function setSampleResponse(string $sampleResponse): AiToolInterface
    {
        $this->sampleResponse = $sampleResponse;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        return [];
    }
}