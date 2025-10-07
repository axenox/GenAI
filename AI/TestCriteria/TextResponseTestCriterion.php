<?php
namespace axenox\GenAI\AI\TestCriteria;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\RegularExpressionDataType;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Allows to extract testing criteria from a pure-text LLM response
 * 
 * If not configured, it will use the entire response message as criterion. In most cases, you will want
 * to extract certain parts - e.g. `extract_code_block` or `extract_by_regex`.
 * 
 * 
 * To do implement Interface 
 */
class TextResponseTestCriterion
{
    use ImportUxonObjectTrait;
    
    private WorkbenchInterface $workbench;
    private ?UxonObject $uxon = null;
    private ?string $regex = null;
    private ?bool $withBlock = false;

    
    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon)
    {
        $this->workbench = $workbench;
        $this->uxon = $uxon;
        $this->importUxonObject($uxon);
    }
    
    public function getValue(AiResponseInterface $response): string
    {
        $regex = $this->getExtractionRegex();
        if ($regex !== null) {
            if (! RegularExpressionDataType::cast($regex)) {
                // TODO throw bad regex
            }
            try {
                $matches = [];
                $message =  $response->getMessage();
                preg_match($regex, $response->getMessage(), $matches);
                $value = $matches[$this->withBlock ? 0 : 1];
            } catch (\Exception $e) {
                // TODO expection wrapper
                throw $e;
            }
        } else {
            $value = $response->getMessage();
        }
        return $value;
    }

    /**
     * @return string
     */
    protected function getExtractionRegex(): string | null
    {
        return $this->regex;
    }

    /**
     * Extract criteria from LLM response using this regular expression
     * 
     * @uxon-property extract_by_regex
     * @uxon-type string
     * 
     * @param string $regex
     * @return $this
     */
    protected function setExtractByRegex(string $regex) : TextResponseTestCriterion
    {
        $this->regex = $regex;
        return $this;
    }

    /**
     * Use the n-th code block from the LLM response as test criteria
     *
     * @uxon-property extract_code_block
     * @uxon-type int
     * 
     * @param int $number
     * @return $this
     */
    protected function setExtractCodeBlock(int $number) : TextResponseTestCriterion
    {
        $this->setExtractByRegex('/```([\s\S]*?)```/');
        return $this;
    }

    /**
     * Extract der Block with Regexfunction
     *
     * @uxon-property with_block
     * @uxon-type bool
     * 
     * @param bool $bool
     * @return $this
     */
    protected function setWithBlock(bool $value) : TextResponseTestCriterion
    {
       
    }

    /**
     * @inheritDoc
     */
    public function exportUxonObject()
    {
        return $this->uxon;
    }
}