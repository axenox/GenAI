<?php
namespace axenox\GenAI\Common\Selectors;

use exface\Core\CommonLogic\Selectors\AbstractSelector;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\CommonLogic\Selectors\Traits\VersionedAliasSelectorTrait;

/**
 * Generic implementation of the AiAgentSelectorInterface.
 * 
 * @see AiAgentSelectorInterface
 * 
 * @author Andrej Kabachnik
 *
 */
class AiAgentSelector extends AbstractSelector implements AiAgentSelectorInterface
{
    use VersionedAliasSelectorTrait;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Selectors\SelectorInterface::getComponentType()
     */
    public function getComponentType() : string
    {
        return 'AI agent';
    }
}