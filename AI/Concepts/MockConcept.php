<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;

/**
 * 
 * 
 */
class MockConcept extends AbstractConcept
{
    private ?string $value = null;

    
    protected function getOutput(): string
    {
        return $this->value;
    }

    /**
     * The value to replace the placeholder when running tests
     * 
     * @uxon-property value
     * @uxon-type string
     * 
     * @param string $value
     * @return $this
     */
    protected function setValue(string $value): MockConcept
    {
        $this->value = $value;
        return $this;
    }
}