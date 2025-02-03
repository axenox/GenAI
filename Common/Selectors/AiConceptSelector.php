<?php
namespace axenox\GenAI\Common\Selectors;

use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\PrototypeSelectorTrait;
use axenox\GenAI\Interfaces\Selectors\AiConceptSelectorInterface;

/**
 * Generic implementation of the AiConceptSelectorInterface.
 * 
 * @see AiConceptSelectorInterface
 * 
 * @author Andrej Kabachnik
 *
 */
class AiConceptSelector extends AbstractSelector implements AiConceptSelectorInterface
{
    use PrototypeSelectorTrait;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Selectors\SelectorInterface::getComponentType()
     */
    public function getComponentType() : string
    {
        return 'AI concept';
    }
}