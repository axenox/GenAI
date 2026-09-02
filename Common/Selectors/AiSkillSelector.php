<?php
namespace axenox\GenAI\Common\Selectors;

use axenox\GenAI\Interfaces\Selectors\AiSkillSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\ResolvableNameSelectorTrait;

/**
 * Selects a persisted AI skill by its namespaced alias.
 */
class AiSkillSelector extends AbstractSelector implements AiSkillSelectorInterface
{
    use ResolvableNameSelectorTrait;

    /**
     * Returns the human-readable component type.
     */
    public function getComponentType() : string
    {
        return 'AI skill';
    }
}