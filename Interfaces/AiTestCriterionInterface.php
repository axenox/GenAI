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
    
    public function executeMetrics(string $aiTestResultOid, AiResponseInterface $result) : AiTestCriterionInterface;

}