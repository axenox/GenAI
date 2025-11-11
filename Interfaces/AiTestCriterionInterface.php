<?php
namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Common\AiTestRating;
use exface\Core\Interfaces\iCanBeConvertedToUxon;


/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiTestCriterionInterface extends iCanBeConvertedToUxon
{
    public function getValue(AiResponseInterface $response) : string;

    /**
     * @param AiResponseInterface $response
     * @return AiTestRating[]
     */
    public function evaluateMetrics(AiResponseInterface $response) : array;
}