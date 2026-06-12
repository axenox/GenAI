<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;

/**
 * Renders a markdown description of a given widget including the click path to take in the UI to reach it.
 *
 * Use this concept to include a chapter describing a specific UI in the instructions of an agent.
 * 
 * 
 * 
 * @author Andrej Kabachnik
 */
class UiWidgetInfoConcept extends AbstractConcept
{
    

    /**
     * Renders the configured markdown file contents for this placeholder.
     * 
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractConcept::getOutput()
     */
    protected function getOutput(): string
    {
        
    }
}