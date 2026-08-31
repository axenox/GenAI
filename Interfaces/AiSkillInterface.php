<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface;

/**
 * A reusable set of optional instructions, concepts, and tools for an AI agent.
 */
interface AiSkillInterface extends PlaceholderResolverInterface, iCanBeConvertedToUxon
{
    /**
     * Returns the local placeholder configured by the consuming agent.
     */
    public function getPlaceholder() : string;

    /**
     * Returns the tools contributed by this skill.
     *
     * @return AiToolInterface[]
     */
    public function getTools() : array;
}