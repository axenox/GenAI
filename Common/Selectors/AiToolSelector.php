<?php
namespace axenox\GenAI\Common\Selectors;

use axenox\GenAI\Interfaces\Selectors\AiToolSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\ResolvableNameSelectorTrait;

/**
 * Generic implementation of the AiToolSelectorInterface.
 * 
 * @see AiConceptSelectorInterface
 * 
 * @author Andrej Kabachnik
 *
 */
class AiToolSelector extends AbstractSelector implements AiToolSelectorInterface
{
    use ResolvableNameSelectorTrait;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Selectors\SelectorInterface::getComponentType()
     */
    public function getComponentType() : string
    {
        return 'AI tool';
    }
}