<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;


/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiTestCriterionInterface extends iCanBeConvertedToUxon
{
    public function getValue(AiResponseInterface $result) : string;

    /**
     * @param AiResponseInterface $response
     * @return array
     */
    public function evaluateMetrics(AiResponseInterface $response) : array;
}