<?php
namespace axenox\GenAI\Common;

use ArrayIterator;
use axenox\GenAI\Exceptions\AiToolConfigurationWarning;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiToolBoxInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use ReflectionClass;
use Throwable;
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
 * How Configuration Comparison Works (`extractConfigurationSignature`):
 * ----------------------------------------------------------------------
 * When adding tools (`append` / `prepend`), the ToolBox computes a canonical signature representing
 * the tool's functional identity.
 *
 * **Included in the signature (functional configuration):**
 * - Prototype alias (`$tool->getAliasWithNamespace()`, e.g. `axenox.GenAI.GetTimeTool`).
 * - Arguments schema (`$tool->getArguments()`):
 *   - Parameter names (`name`)
 *   - Data types (`data_type`)
 *   - Requirement status (`required`)
 *   - Default values (`default`) and empty/nullability flags (`empty`).
 * - Security & UXON rules: `securitychecks` (e.g. `startsWith`, `contains`, `equals`),
 *   configuration options in UXON (e.g. target tables, filters).
 *
 * **Explicitly EXCLUDED from the signature (LLM-facing metadata):**
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
     * @var array<string, array{tool: AiToolInterface, source: string, signature: string}>
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
        $newSignature = $this->extractConfigurationSignature($tool);

        // 1. Check if a tool with identical alias & configuration already exists
        $matchingExistingName = $this->findToolNameBySignature($newSignature);
        if ($matchingExistingName !== null) {
            $existingTool = $this->entries[$matchingExistingName]['tool'];
            if ($matchingExistingName !== $targetName || $existingTool->getDescription() !== $tool->getDescription()) {
                $this->warnings[] = new AiToolConfigurationWarning(
                    'AI tool "' . $targetName . '" was merged with the identical configuration of "'
                    . $matchingExistingName . '" because only the name or description differed.'
                );
            }
            $mergedDesc = $this->mergeDescriptions($existingTool->getDescription(), $tool->getDescription());

            $mergedTool = $this->createMergedToolInstance(
                $existingTool,
                $matchingExistingName,
                $mergedDesc
            );

            $this->entries[$matchingExistingName]['tool'] = $mergedTool;
            $this->entries[$matchingExistingName]['signature'] = $newSignature;
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
            'signature' => $newSignature,
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
        $newSignature = $this->extractConfigurationSignature($tool);

        // 1. Check if a tool with identical alias & configuration already exists
        $matchingExistingName = $this->findToolNameBySignature($newSignature);
        if ($matchingExistingName !== null) {
            $existingTool = $this->entries[$matchingExistingName]['tool'];
            if ($matchingExistingName !== $targetName || $existingTool->getDescription() !== $tool->getDescription()) {
                $this->warnings[] = new AiToolConfigurationWarning(
                    'AI tool "' . $targetName . '" was merged with the identical configuration of "'
                    . $matchingExistingName . '" because only the name or description differed.'
                );
            }
            $mergedDesc = $this->mergeDescriptions($tool->getDescription(), $existingTool->getDescription());

            // Prepended tool takes priority on name and placement
            $finalName = $targetName;
            $mergedTool = $this->createMergedToolInstance(
                $tool,
                $finalName,
                $mergedDesc
            );

            if ($finalName !== $matchingExistingName) {
                unset($this->entries[$matchingExistingName]);
            }

            // Put at the front of the list
            $entries = $this->entries;
            $this->entries = [
                $finalName => [
                    'tool' => $mergedTool,
                    'source' => $sourceName,
                    'signature' => $newSignature,
                ],
            ] + $entries;
            return $this;
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
                'signature' => $newSignature,
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
     * Computes a canonical signature hash representing the tool's prototype alias
     * and functional configuration (excluding LLM name and description).
     *
     * @param AiToolInterface $tool
     * @return string
     */
    public function extractConfigurationSignature(AiToolInterface $tool) : string
    {
        $alias = $tool->getAliasWithNamespace();
        $configData = [];

        // 1. If UXON object is present, inspect configuration excluding name/description/rules
        $uxon = $tool->exportUxonObject();
        if ($uxon !== null) {
            $uxonArray = $uxon->toArray();
            unset($uxonArray['name'], $uxonArray['description'], $uxonArray['rules']);
            $configData['uxon'] = $this->normalizeArrayForComparison($uxonArray);
        }

        // 2. Extract arguments schema
        $argsData = [];
        foreach ($tool->getArguments() as $param) {
            $paramName = $param->getName();
            $dataType = null;
            if (method_exists($param, 'getDataType') && $param->getDataType() !== null) {
                $dataType = $param->getDataType()->getAliasWithNamespace();
            } elseif (method_exists($param, 'getDataTypeUxon') && $param->getDataTypeUxon() !== null) {
                $dataType = (string)$param->getDataTypeUxon();
            }

            $argsData[$paramName] = [
                'name' => $paramName,
                'data_type' => $dataType,
                'required' => $param->isRequired(),
                'default' => method_exists($param, 'getDefaultValue') ? $param->getDefaultValue() : null,
                'empty' => method_exists($param, 'isEmptyAllowed') ? $param->isEmptyAllowed() : null,
            ];
        }
        ksort($argsData);
        $configData['arguments'] = $argsData;

        // 3. Security checks if defined
        try {
            $ref = new ReflectionClass($tool);
            while ($ref) {
                if ($ref->hasProperty('securitychecks')) {
                    $prop = $ref->getProperty('securitychecks');
                    $prop->setAccessible(true);
                    $checks = $prop->getValue($tool);
                    if (!empty($checks)) {
                        $configData['securitychecks'] = $checks;
                    }
                    break;
                }
                $ref = $ref->getParentClass();
            }
        } catch (Throwable $e) {
            // ignore reflection exceptions
        }

        $configData = $this->normalizeArrayForComparison($configData);
        return $alias . '::' . md5(json_encode($configData));
    }

    /**
     * Checks if two tools have identical prototype alias and functional configuration.
     *
     * @param AiToolInterface $toolA
     * @param AiToolInterface $toolB
     * @return bool
     */
    public function areConfigurationsEqual(AiToolInterface $toolA, AiToolInterface $toolB) : bool
    {
        return $this->extractConfigurationSignature($toolA) === $this->extractConfigurationSignature($toolB);
    }

    /**
     * Finds an already registered tool function name with the identical signature.
     *
     * @param string $signature
     * @return string|null
     */
    private function findToolNameBySignature(string $signature) : ?string
    {
        foreach ($this->entries as $name => $entry) {
            if ($entry['signature'] === $signature) {
                return $name;
            }
        }
        return null;
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
     * Instantiates or clones a tool instance with updated name and description.
     *
     * @param AiToolInterface $baseTool
     * @param string $targetName
     * @param string|null $mergedDescription
     * @return AiToolInterface
     */
    protected function createMergedToolInstance(AiToolInterface $baseTool, string $targetName, ?string $mergedDescription) : AiToolInterface
    {
        $workbench = $this->workbench;
        if ($workbench === null && method_exists($baseTool, 'getWorkbench')) {
            try {
                $workbench = $baseTool->getWorkbench();
            } catch (Throwable $e) {
                $workbench = null;
            }
        }

        if ($workbench !== null) {
            $baseUxon = $baseTool->exportUxonObject();
            if ($baseUxon !== null) {
                $mergedUxon = new UxonObject($baseUxon->toArray());
            } else {
                $mergedUxon = new UxonObject([
                    'alias' => $baseTool->getAliasWithNamespace()
                ]);
            }

            $mergedUxon->setProperty('name', $targetName);
            if ($mergedDescription !== null) {
                $mergedUxon->setProperty('description', $mergedDescription);
            }

            try {
                return AiFactory::createToolFromUxon($workbench, $mergedUxon, $targetName);
            } catch (Throwable $e) {
                // Fallback via cloning
            }
        }

        // Fallback: Clone tool and update properties via reflection
        $mergedTool = clone $baseTool;
        try {
            $ref = new ReflectionClass($mergedTool);
            while ($ref) {
                if ($ref->hasProperty('description') && $mergedDescription !== null) {
                    $prop = $ref->getProperty('description');
                    $prop->setAccessible(true);
                    $prop->setValue($mergedTool, $mergedDescription);
                }
                if ($ref->hasProperty('name')) {
                    $prop = $ref->getProperty('name');
                    $prop->setAccessible(true);
                    $prop->setValue($mergedTool, $targetName);
                }
                $ref = $ref->getParentClass();
            }
        } catch (Throwable $e) {
            // Ignore reflection errors on clone
        }

        return $mergedTool;
    }

    /**
     * Recursively sorts arrays by key for canonical comparison.
     *
     * @param array $array
     * @return array
     */
    private function normalizeArrayForComparison(array $array) : array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->normalizeArrayForComparison($value);
            }
        }
        ksort($array);
        return $array;
    }
}
