<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface;

/**
 * A reusable set of optional instructions, concepts, and tools for an AI agent.
 * 
 * ## Usage 
 * 
 * Skills assigned directly to an agent have their instructions appended to the system prompt and
 * are not registered as placeholders in the agent's template renderer. 
 * 
 * Skills nested inside another skill are registered as placeholder resolvers so the parent can place 
 * their instructions at a specific position. Nested tools remain available whether the placeholder
 * is used or not. 
 * 
 * In contrast to direct assignment to an agent, nested skills inside a skill behave much like concepts. 
 * In the UI of the skill users see placeholders for concepts and nested skills and can place them in
 * specific passages of the skill instructions. In agents skill are just "dropped" into the corresponding
 * list of agent skills and the agent designer does not have to bother about including them in the instructions.
 * This makes it much easier to build up new agents on the one side, but forces designers to polish skills
 * themselves on the other side.
 * 
 * ## Placeholders
 * 
 * Extending PlaceholderResolverInterface makes this nested composition capability part of every
 * skill implementation's contract, analogous to concepts without changing top-level rendering.
 */
interface AiSkillInterface extends iCanBeConvertedToUxon, PlaceholderResolverInterface
{
    /**
        * Returns the local assignment name used as a placeholder when this skill is nested.
     */
    public function getPlaceholder() : string;

    /**
     * Returns the rendered instruction text for this skill.
     */
    public function getInstructions() : string;

    /**
     * Replaces this skill's local placeholder with its rendered instructions.
     *
     * @param string[] $placeholders
     * @return array
     */
    public function resolve(array $placeholders) : array;

    /**
     * Returns the direct, nested, and concept-contributed tools, keyed by function name.
     *
     * @return AiToolInterface[]
     */
    public function getTools() : array;

    /**
     * Returns tool configuration warnings from this skill and its nested skills.
     *
     * @return \Throwable[]
     */
    public function getWarnings() : array;

    /**
     * Returns the effective skill configuration as UXON.
     */
    public function exportUxonObject();

    /**
     * Returns the UXON schema used by skill editors.
     */
    public static function getUxonSchemaClass() : ?string;
}