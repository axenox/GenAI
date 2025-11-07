<?php
namespace axenox\GenAI\Common\Selectors;

use axenox\GenAI\Interfaces\Selectors\AiMetricSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\ResolvableNameSelectorTrait;

/**
 * Generic implementation of the AiMetricSelectorInterface.
 * 
 * @see AiMetricSelectorInterface
 * 
 * @author Andrej Kabachnik
 *
 */
class AiMetricSelector extends AbstractSelector implements AiMetricSelectorInterface
{
    use ResolvableNameSelectorTrait;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Selectors\SelectorInterface::getComponentType()
     */
    public function getComponentType() : string
    {
        return 'AI test metric';
    }
}