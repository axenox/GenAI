<?php
namespace axenox\GenAI\AI\TestCriteria;

use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use exface\Core\CommonLogic\UxonObject;

class JsonResponseTestCriterion implements AiTestCriterionInterface
{
    public function getValue(AiResponseInterface $response): string
    {
        // TODO: Implement getValue() method.
    }

    /**
     * @inheritDoc
     */
    public function exportUxonObject()
    {
        // TODO: Implement exportUxonObject() method.
    }

    /**
     * @inheritDoc
     */
    public function importUxonObject(UxonObject $uxon)
    {
        // TODO: Implement importUxonObject() method.
    }

    /**
     * @inheritDoc
     */
    public static function getUxonSchemaClass(): ?string
    {
        // TODO: Implement getUxonSchemaClass() method.
    }
}