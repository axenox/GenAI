<?php
namespace axenox\GenAI\Interfaces;

use Countable;
use IteratorAggregate;

/**
 * AI ToolBox Interface
 * ====================
 *
 * The ToolBox is a specialized collection and registry for AI tools (`AiToolInterface`)
 * contributed by skills, concepts, and agents, preparing them for invocation by the LLM.
 *
 * Core Responsibilities & Mechanics:
 * ----------------------------------
 * 1. **Deduplication & Intelligent Merging:**
 *    When tools with the **same prototype alias** and **identical functional configuration**
 *    are added, they are merged into a single tool entry. Their descriptions (`description`)
 *    are combined so that all usage guidelines and instructions from all sources are retained.
 *
 * 2. **Coexistence of Differing Configurations:**
 *    Tools sharing the same prototype alias but having **different functional configurations**
 *    (e.g. different arguments, tables, security checks, or options) remain as **distinct,
 *    separate tools** side-by-side in the ToolBox.
 *
 * 3. **Collision Detection & Warning Management:**
 *    When a tool collides with an existing tool under the same function name but has a differing
 *    configuration or alias, the incoming tool replaces the slot and an `AiToolConfigurationWarning`
 *    is recorded. Warnings can be retrieved via `getWarnings()` and persisted to the conversation.
 *
 * 4. **Order & Precedence (`append` vs `prepend`):**
 *    - `append()`: Adds tools to the **end of the list**. Existing tools retain naming priority;
 *      later additions supplement or override existing slots on collision.
 *    - `prepend()`: Places tools at the **beginning of the list** with **higher precedence**.
 *      Prepended tools take priority in tool ordering and function naming when merged, and override
 *      existing entries on collision.
 *
 * @author Brooklyn Fränzschky
 */
interface AiToolBoxInterface extends Countable, IteratorAggregate
{
    /**
     * Appends a tool to the end of the ToolBox.
     *
     * How append works:
     * - Adds the tool at the end of the collection list.
     * - **Same Alias & Same Config**: Merged into a single tool entry (descriptions combined).
     *   The existing tool's function name is preserved.
     * - **Same Alias & Different Config**: Both tools are kept as separate entries.
     * - **Same Name & Different Config**: Replaces the existing tool at that name and records
     *   an `AiToolConfigurationWarning` documenting the override.
     *
    * @param AiToolInterface $tool Tool instance
     * @param string|null $toolName Optional explicit function name for the tool
     * @param string|null $source Optional origin description (e.g. skill or concept name) for warning logs
     * @return self
     */
    public function append(AiToolInterface $tool, ?string $toolName = null, ?string $source = null) : self;

    /**
     * Prepends a tool to the beginning of the ToolBox with high precedence.
     *
     * How prepend works:
     * - Places the tool at the very front (beginning) of the collection list.
     * - **Same Alias & Same Config**: Merged into a single tool entry (descriptions combined).
     *   The prepended tool's name and position take priority and move to the front.
     * - **Same Alias & Different Config**: Both tools remain in the ToolBox, with the prepended
     *   tool placed at the front.
     * - **Same Name & Different Config**: Overrides the existing tool entry, moves to the front,
     *   and records an `AiToolConfigurationWarning`.
     *
    * @param AiToolInterface $tool Tool instance
     * @param string|null $toolName Optional explicit function name for the tool
     * @param string|null $source Optional origin description for warning logs
     * @return self
     */
    public function prepend(AiToolInterface $tool, ?string $toolName = null, ?string $source = null) : self;

    /**
     * Appends multiple tools to the end of the ToolBox.
     *
     * @param iterable $tools Array or iterable of tools (optionally keyed by function name)
     * @param string|null $source Optional origin description
     * @return self
     */
    public function appendTools(iterable $tools, ?string $source = null) : self;

    /**
     * Prepends multiple tools to the beginning of the ToolBox, preserving their relative order.
     *
     * @param iterable $tools Array or iterable of tools
     * @param string|null $source Optional origin description
     * @return self
     */
    public function prependTools(iterable $tools, ?string $source = null) : self;

    /**
     * Merges another ToolBox by appending all its tools, sources, and warnings.
     *
     * @param AiToolBoxInterface $toolBox
     * @return self
     */
    public function appendToolBox(AiToolBoxInterface $toolBox) : self;

    /**
     * Merges another ToolBox by prepending all its tools, sources, and warnings to the front.
     *
     * @param AiToolBoxInterface $toolBox
     * @return self
     */
    public function prependToolBox(AiToolBoxInterface $toolBox) : self;

    /**
     * Returns all registered tools indexed by their function name.
     *
     * @return AiToolInterface[]
     */
    public function getTools() : array;

    /**
     * Returns a tool by its function name, or NULL if not found.
     *
     * @param string $name
     * @return AiToolInterface|null
     */
    public function getTool(string $name) : ?AiToolInterface;

    /**
     * Checks if a tool with the given function name is registered.
     *
     * @param string $name
     * @return bool
     */
    public function hasTool(string $name) : bool;

    /**
     * Removes a tool by its function name from the ToolBox.
     *
     * @param string $name
     * @return self
     */
    public function remove(string $name) : self;

    /**
     * Returns all recorded configuration warnings (e.g. collisions, overrides).
     *
     * @return \Throwable[]
     */
    public function getWarnings() : array;

    /**
     * Checks whether any warnings have been recorded.
     *
     * @return bool
     */
    public function hasWarnings() : bool;

    /**
     * Returns the origin source description of a tool if specified when added.
     *
     * @param string $toolName
     * @return string|null
     */
    public function getToolSource(string $toolName) : ?string;
}
