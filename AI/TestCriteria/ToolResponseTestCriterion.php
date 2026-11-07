<?php
namespace axenox\GenAI\AI\TestCriteria;

use axenox\GenAI\Common\AbstractTestCriterion;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\RegularExpressionDataType;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * 
 */
class ToolResponseTestCriterion extends AbstractTestCriterion
{
    use ImportUxonObjectTrait;
    
    

    
    
    
    public function getValue(AiResponseInterface $response): string
    {
        $toolCallResponses = [];
        foreach ($response->getMessages() as $message) {
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
                    $output .= $key . ' = "' . (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"' . "\n";
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