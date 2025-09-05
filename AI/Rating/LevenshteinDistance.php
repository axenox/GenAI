<?php
namespace axenox\GenAI\AI\Rating;

use axenox\GenAI\Interfaces\AiRaterInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Calculates the levenshtein distance between the reference value and the LLM response
 * 
 * @author Andrej Kabachnik
 */
class LevenshteinDistance implements AiRaterInterface
{
    use ImportUxonObjectTrait;
    
    private WorkbenchInterface $workbench;
    private UxonObject $originalUxon;
    
    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon)
    {
        $this->workbench = $workbench;
        $this->originalUxon = $uxon;
    }

    /**
     * @inheritDoc
     */
    public function exportUxonObject()
    {
        return $this->originalUxon;
    }
}