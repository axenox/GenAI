<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface;

/**
 * A reusable set of optional instructions, concepts, and tools for an AI agent.
 */
interface AiSkillInterface extends iCanBeConvertedToUxon, PlaceholderResolverInterface
{
    /**
     * Returns the placeholder used to insert this skill's instructions.
     */
    public function getPlaceholder() : string;

    /**
     * Returns the rendered instruction text for this skill.
     */
    public function getInstructions() : string;

    /**
     * Returns the tools contributed by this skill.
     *
     * @return AiToolInterface[]
     */
    public function getTools() : array;

    /**
     * Returns TRUE if this skill may be appended to the system prompt automatically when its
     * placeholder is not explicitly used inside the agent's (or parent skill's) instructions.
     */
    public function isAutoAppendEnabled() : bool;

    /**
     * Returns warnings produced while preparing this skill.
     *
     * @return \Throwable[]
     */
    public function getWarnings() : array;
}