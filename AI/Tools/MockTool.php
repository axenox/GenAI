<?php

namespace axenox\GenAI\AI\Tools;


use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\RequestResponsePairs;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;


/**
 * Return a fixed sample response while developing or testing an agent.
 * 
 * Configure `sample_response` and the arguments of the real tool being simulated.
 * Request-response pair conversion is not yet implemented; use the sample response
 * for deterministic tests rather than relying on request-specific matching.
 * 
 * ```json
 * {
 *     "alias": "axenox.GenAI.MockTool",
 *     "sample_response": "No matching records",
 *     "arguments": [{"name": "request", "data_type": {"alias": "exface.Core.String"}}]
 * }
 * 
 * ```
 * 
 * @author GitHub Copilot
 */
class MockTool extends AbstractAiTool
{

    
    private ?array $requestResponsePairs = null;
    
    private string $sampleResponse = "No data available for this request";
    

    /**
     * Executes the configured tool and returns its typed outcome.
     * 
     * @param AiTaskHandlerInterface $agent
     * @param TaskInterface $prompt
     * @param array<array-key,mixed> $arguments
     * @return AiToolResultInterface
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($var) = $arguments;
        
        if($this->requestResponsePairs !== null){
            foreach($this->requestResponsePairs as $pair){
                if($pair instanceof RequestResponsePairs){
                    if($pair->isMatch($var)){
                        $response = $pair->getResponse();
                        return new AiToolResultString($this->getWorkbench(), $response, $this->getReturnDataType());
                    }
                }
            }
        }
        
        return new AiToolResultString($this->getWorkbench(), $this->sampleResponse, $this->getReturnDataType());
    }

    /**
     * If you expect a specific request from the AI, you can specify here what should be returned for those requests.
     * 
     * @uxon-property request_response_pairs
     * @uxon-type \axenox\GenAI\Common\RequestResponsePairs[]
     * @uxon-template [{"request": "", "response": ""}]
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
     * 
     * @uxon-property sample_response
     * @uxon-type string
     * @uxon-default No data available for this request
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

    /**
     * Builds the default argument definitions for this tool.
     * 
     * @param WorkbenchInterface $workbench
     * @return array<array-key,mixed>
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        return [];
    }
}