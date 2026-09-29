<?php
namespace axenox\GenAI\Common;

use ArrayIterator;
use axenox\GenAI\Exceptions\AiToolConfigurationWarning;
use axenox\GenAI\Interfaces\AiToolBoxInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Traversable;

/**
 * AI ToolBox
 * ==========
 *
 * The `ToolBox` is the central collection and registry for AI tools (`AiToolInterface`).
 * It aggregates tools from multiple sources (skills, concepts, direct agent configuration)
 * and prepares them in an optimized, collision-free format for invocation by the Large Language Model (LLM).
 *
 * Why the ToolBox exists:
 * -----------------------
 * In complex AI agents, tools are often introduced via multiple nested skills or concepts:
 * 1. Identical tools may be included redundantly (e.g. `GetTimeTool` across multiple skills).
 * 2. The same tool prototype may be configured with different parameters/permissions (e.g. `DbQueryTool`
 *    once for the `ORDERS` table and once for `CUSTOMERS`).
 * 3. Naming collisions may arise (e.g. two distinct tools claiming the function name `query_data`).
 *
 * How Tool Comparison Works (`areToolsEqual`):
 * ----------------------------------------------------------------------
 * When adding tools (`append` / `prepend`), the ToolBox compares the original tool configurations.
 *
 * **Explicitly EXCLUDED from comparison (LLM-facing metadata):**
 * - Function name (`name`)
 * - Tool description (`description`)
 * - Specific prompt rules (`rules`)
 *
 * This allows the ToolBox to reliably detect whether two tools are *functionally identical*,
 * even when different skills assign differing function names or slightly different description texts.
 *
 * Merging & Collision Logic:
 * --------------------------
 * 1. **Case 1: Same Alias + Same Functional Configuration (Merging):**
 *    - The tool is identified as the same functional capability.
 *    - Exactly **one** tool entry is maintained in the ToolBox (avoiding duplicate LLM schemas).
 *    - The descriptions (`description`) are **combined** (`mergeDescriptions`), preserving
 *      all contextual guidelines and instructions for the model.
 *    - With `append()`, the existing tool's name is kept; with `prepend()`, the incoming
 *      prepended tool's name and position take priority.
 *
 * 2. **Case 2: Same Alias + Different Functional Configuration (Coexistence):**
 *    - Both tools share the same prototype class, but perform distinct operations
 *      (e.g. `order_search` vs `customer_search`).
 *    - **Both tools are retained as distinct entries in the ToolBox.**
 *
 * 3. **Case 3: Name Collision with Differing Configuration/Alias (Override with Warning):**
 *    - Two different tools claim the same function name (e.g. `search_database`).
 *    - The incoming tool replaces the slot (at the end for `append()`, or at the beginning for `prepend()`).
 *    - An `AiToolConfigurationWarning` is recorded in `$warnings`, detailing the overridden and incoming sources.
 *    - Warnings can be inspected via `getWarnings()` and persisted to the conversation history
 *      (`AiConversationInterface::saveWarnings()`).
 *
 * @author Brooklyn Fränzschky
 */
class ToolBox implements AiToolBoxInterface, WorkbenchDependantInterface
{
    private ?WorkbenchInterface $workbench = null;

    /**
     * Registered tools and their merge metadata indexed by function name.
     *
    * @var array<string, array{tool: AiToolInterface, source: string}>
     */
    private array $entries = [];

    /**
     * Recorded configuration warnings.
     *
     * @var \Throwable[]
     */
    private array $warnings = [];

    /**
     * @param WorkbenchInterface|null $workbench
     * @param iterable|null $initialTools Optional initial tools
     * @param string|null $defaultSource Optional default source description
     */
    public function __construct(?WorkbenchInterface $workbench = null, ?iterable $initialTools = null, ?string $defaultSource = null)
    {
        $this->workbench = $workbench;
        if ($initialTools !== null) {
            $this->appendTools($initialTools, $defaultSource);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Appends a tool to the end of the ToolBox.
     *
     * - Adds the tool to the end of the collection list.
     * - If a tool with the same prototype alias and identical configuration already exists,
     *   their descriptions are merged into the existing tool entry.
     * - If the function name collides with a different tool/configuration, the new tool
     *   overrides the existing entry and an AiToolConfigurationWarning is recorded.
     */
    public function append(AiToolInterface $tool, ?string $toolName = null, ?string $source = null) : self
    {
        $targetName = $toolName ?? $tool->getName();
        $sourceName = $source ?? 'tool configuration';

        // 1. Keep the first identical original and merge all matching descriptions into it.
        $equalTools = $this->findEqualTools($tool);
        if ($equalTools !== []) {
            $retainedName = array_key_first($equalTools);
            $retainedTool = $equalTools[$retainedName];
            $mergedDescription = null;

            foreach ($equalTools as $existingName => $existingTool) {
                if ($existingName !== $targetName || $existingTool->getDescription() !== $tool->getDescription()) {
                    $this->warnings[] = new AiToolConfigurationWarning(
                        'AI tool "' . $targetName . '" was merged with the identical configuration of "'
                        . $existingName . '" because only the name or description differed.'
                    );
                }
                $mergedDescription = $this->mergeDescriptions($mergedDescription, $existingTool->getDescription());
                if ($existingName !== $retainedName) {
                    unset($this->entries[$existingName]);
                }
            }

            $mergedDescription = $this->mergeDescriptions($mergedDescription, $tool->getDescription());
            $this->applyDescription($retainedTool, $mergedDescription);
            return $this;
        }

        // 2. Name collision with DIFFERENT configuration
        if (isset($this->entries[$targetName])) {
            $prevSource = $this->entries[$targetName]['source'] ?? 'previous configuration';
            $this->warnings[] = new AiToolConfigurationWarning(
                'AI tool "' . $targetName . '" from ' . $sourceName
                . ' overrides the tool from ' . $prevSource . '.'
            );
        }

        // 3. Register as new tool entry at the end of the list
        $this->entries[$targetName] = [
            'tool' => $tool,
            'source' => $sourceName,
        ];

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Prepends a tool to the beginning of the ToolBox with high precedence.
     *
     * - Places the tool at the front of the collection list.
     * - If a tool with the same prototype alias and identical configuration already exists,
     *   their descriptions are merged, and the prepended tool's name and position take priority.
     * - If the function name collides with an existing tool with different configuration,
     *   the prepended tool overrides the previous entry, moves to the front, and records
     *   an AiToolConfigurationWarning.
     */
    public function prepend(AiToolInterface $tool, ?string $toolName = null, ?string $source = null) : self
    {
        $targetName = $toolName ?? $tool->getName();
        $sourceName = $source ?? 'tool configuration';

        // 1. Remove all identical tools and preserve their descriptions on the prepended original.
        $mergedDescription = $tool->getDescription();
        $equalTools = $this->findEqualTools($tool);
        foreach ($equalTools as $existingName => $existingTool) {
            if ($existingName !== $targetName || $existingTool->getDescription() !== $tool->getDescription()) {
                $this->warnings[] = new AiToolConfigurationWarning(
                    'AI tool "' . $targetName . '" was merged with the identical configuration of "'
                    . $existingName . '" because only the name or description differed.'
                );
            }
            $mergedDescription = $this->mergeDescriptions($mergedDescription, $existingTool->getDescription());
            unset($this->entries[$existingName]);
        }
        if ($equalTools !== []) {
            $this->applyDescription($tool, $mergedDescription);
        }

        // 2. Name collision with DIFFERENT configuration
        if (isset($this->entries[$targetName])) {
            $prevSource = $this->entries[$targetName]['source'] ?? 'previous configuration';
            $this->warnings[] = new AiToolConfigurationWarning(
                'AI tool "' . $targetName . '" from prepended ' . $sourceName
                . ' overrides the tool from ' . $prevSource . '.'
            );
            unset($this->entries[$targetName]);
        }

        // 3. Put at the front of the list
        $entries = $this->entries;
        $this->entries = [
            $targetName => [
                'tool' => $tool,
                'source' => $sourceName,
            ],
        ] + $entries;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function appendTools(iterable $tools, ?string $source = null) : self
    {
        foreach ($tools as $toolName => $tool) {
            $explicitName = is_string($toolName) && !is_numeric($toolName) ? $toolName : null;
            $this->append($tool, $explicitName, $source);
        }
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function prependTools(iterable $tools, ?string $source = null) : self
    {
        $items = [];
        foreach ($tools as $toolName => $tool) {
            $explicitName = is_string($toolName) && !is_numeric($toolName) ? $toolName : null;
            $items[] = [$tool, $explicitName];
        }

        for ($i = count($items) - 1; $i >= 0; $i--) {
            $this->prepend($items[$i][0], $items[$i][1], $source);
        }
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function appendToolBox(AiToolBoxInterface $toolBox) : self
    {
        foreach ($toolBox->getTools() as $toolName => $tool) {
            $this->append($tool, $toolName, $toolBox->getToolSource($toolName));
        }
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function prependToolBox(AiToolBoxInterface $toolBox) : self
    {
        $tools = $toolBox->getTools();
        $toolNames = array_keys($tools);

        for ($i = count($toolNames) - 1; $i >= 0; $i--) {
            $toolName = $toolNames[$i];
            $tool = $tools[$toolName];
            $this->prepend($tool, $toolName, $toolBox->getToolSource($toolName));
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getTools() : array
    {
        $tools = [];
        foreach ($this->entries as $name => $entry) {
            $tools[$name] = $entry['tool'];
        }
        return $tools;
    }

    /**
     * {@inheritDoc}
     */
    public function getTool(string $name) : ?AiToolInterface
    {
        return $this->entries[$name]['tool'] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function hasTool(string $name) : bool
    {
        return isset($this->entries[$name]);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $name) : self
    {
        unset($this->entries[$name]);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getToolSource(string $toolName) : ?string
    {
        return $this->entries[$toolName]['source'] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getWarnings() : array
    {
        return $this->warnings;
    }

    /**
     * {@inheritDoc}
     */
    public function hasWarnings() : bool
    {
        return !empty($this->warnings);
    }

    /**
     * {@inheritDoc}
     */
    public function count() : int
    {
        return count($this->entries);
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator() : Traversable
    {
        return new ArrayIterator($this->getTools());
    }

    /**
     * Returns the workbench if configured.
     *
     * @return WorkbenchInterface
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    /**
     * Sets the workbench.
     *
     * @param WorkbenchInterface $workbench
     * @return self
     */
    public function setWorkbench(WorkbenchInterface $workbench) : self
    {
        $this->workbench = $workbench;
        return $this;
    }

    /**
     * Checks whether two original tools have the same prototype and UXON configuration.
     *
     * @param AiToolInterface $toolA
     * @param AiToolInterface $toolB
     * @return bool
     */
    public function areToolsEqual(AiToolInterface $toolA, AiToolInterface $toolB) : bool
    {
        if ($toolA->getAliasWithNamespace() !== $toolB->getAliasWithNamespace()) {
            return false;
        }

        $uxonA = $toolA->exportUxonObject()?->copy() ?? new UxonObject();
        $uxonB = $toolB->exportUxonObject()?->copy() ?? new UxonObject();

        foreach (['name', 'description', 'rules'] as $property) {
            $uxonA->unsetProperty($property);
            $uxonB->unsetProperty($property);
        }

        return $uxonA->toArray() == $uxonB->toArray();
    }

    /**
     * Finds all registered tools with the same alias and configuration.
     *
     * @param AiToolInterface $tool
     * @return array<string, AiToolInterface>
     */
    private function findEqualTools(AiToolInterface $tool) : array
    {
        $equalTools = [];
        $alias = $tool->getAliasWithNamespace();

        foreach ($this->entries as $name => $entry) {
            $existingTool = $entry['tool'];
            if ($existingTool->getAliasWithNamespace() !== $alias) {
                continue;
            }
            if ($this->areToolsEqual($existingTool, $tool)) {
                $equalTools[$name] = $existingTool;
            }
        }

        return $equalTools;
    }

    /**
     * Merges two tool descriptions, combining unique information.
     *
     * @param string|null $descA
     * @param string|null $descB
     * @return string|null
     */
    protected function mergeDescriptions(?string $descA, ?string $descB) : ?string
    {
        $descA = trim($descA ?? '');
        $descB = trim($descB ?? '');

        if ($descA === '' && $descB === '') {
            return null;
        }
        if ($descA === '') {
            return $descB;
        }
        if ($descB === '') {
            return $descA;
        }
        if ($descA === $descB) {
            return $descA;
        }
        if (str_contains($descA, $descB)) {
            return $descA;
        }
        if (str_contains($descB, $descA)) {
            return $descB;
        }
        return $descA . "\n\n" . $descB;
    }

    /**
     * Applies a merged description without replacing the original tool instance.
     *
     * @param AiToolInterface $tool
     * @param string|null $mergedDescription
     * @return void
     */
    protected function applyDescription(AiToolInterface $tool, ?string $mergedDescription) : void
    {
        if ($mergedDescription !== null) {
            $tool->importUxonObject(new UxonObject([
                'description' => $mergedDescription,
            ]));
        }
    }
}
