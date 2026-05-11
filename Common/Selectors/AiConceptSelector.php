<?php
namespace axenox\GenAI\Common\Selectors;

use exface\Core\CommonLogic\Selectors\AbstractSelector;
use axenox\GenAI\Interfaces\Selectors\AiConceptSelectorInterface;
use exface\Core\CommonLogic\Selectors\Traits\ResolvableNameSelectorTrait;

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
    use ResolvableNameSelectorTrait;
    
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