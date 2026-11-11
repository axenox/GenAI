<?php
namespace axenox\GenAI\AI\TestCriteria;

use axenox\genAI\common\AbstractTestCriterion;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\RegularExpressionDataType;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Evaluate the tool names and original arguments generated in the current response turn.
 * 
 * Use this criterion with metrics that inspect tool usage; earlier conversation
 * turns and their tools are not included.
 * 
 * ```json
 * {"type": "ToolResponse", "metrics": []}
 * 
 * ```
 * 
 * @author GitHub Copilot
 */
class ToolResponseTestCriterion extends AbstractTestCriterion
{
    use ImportUxonObjectTrait;
    
    

    
    
    
    /**
     * Returns the current response-turn tool evidence used by test metrics.
     * 
     * @param AiResponseInterface $response
     * @return string
     */
    public function getValue(AiResponseInterface $response) : string
    {
        $toolCallResponses = [];
        foreach ($response->getMessages() as $message) {
            if (! $message instanceof AiToolCallMessageInterface) {
                continue;
            }
            foreach ($message->getCalls() as $call) {
                $toolCallResponses[] = $call;
            }
        }

        $output = "";
        $lastIndex = count($toolCallResponses) - 1;
        $currentIndex = 0;

        foreach ($toolCallResponses as $toolCallResponse) {
            $output .= "Toolname:\n";
            $output .= $toolCallResponse->getToolName() . "\n";

            $output .= "Arguments:\n";
            $arguments = $toolCallResponse->getArguments();

            if (is_array($arguments)) {
                foreach ($arguments as $key => $value) {
                    $value = is_string($value)
                        ? '"' . $value . '"'
                        : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $output .= $key . ' = ' . $value . "\n";
                }
            } else {
                $output .= $arguments . "\n";
            }

            if ($currentIndex < $lastIndex) {
                $output .= "\n";
            }

            $currentIndex++;
        }

        return $output;
    }




    

    
}