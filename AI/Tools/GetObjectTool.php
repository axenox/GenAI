<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\ObjectMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Retrieves detailed metaobject descriptions, searching objects UID, ALIAS or NAME.
 *
 * Examples:
 *
 * - `GetObject("user")` - will return all objects, that have "user" in their name or alias, e.g. `exface.Core.USER`,
 * `exface.Core.USER_ROLE`, etc.
 * - `GetObject("0x31343400000000000000000000000000")` - will search for the object with the given UID exactly
 * - `GetObject("exface.Core.USER") - will search for the object with the given alias exactly`
 */
class GetObjectTool extends AbstractAiTool
{
    public const ARG_SEARCH_TERM = 'search_term';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        $searchTerm = trim((string) ($arguments[0] ?? ''));
        if ($searchTerm === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: search_term');
        }

        try {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.OBJECT');
            $ds->getColumns()->addMultiple(['UID', 'NAME', 'ALIAS', 'ALIAS_WITH_NS']);

            switch (true) {
                // Search for exact UID
                case str_starts_with($searchTerm, '0x'):
                    $ds->getFilters()->addConditionFromString('UID', $searchTerm, ComparatorDataType::EQUALS);
                    break;
                // Search term is likely to be an alias
                case substr_count($searchTerm, '.') >= 2:
                    $ds->getFilters()->addConditionFromString('ALIAS_WITH_NS', $searchTerm, ComparatorDataType::EQUALS);
                    break;
                default:
                    $orGroup = $ds->getFilters()->addNestedOR();
                    $orGroup->addConditionFromString('NAME', $searchTerm, ComparatorDataType::IS);
                    $orGroup->addConditionFromString('ALIAS', $searchTerm, ComparatorDataType::IS);
            }

            $ds->dataRead();
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to search objects: ' . $e->getMessage(), null, $e);
        }

        $rows = $ds->getRows();
        if (empty($rows)) {
            return 'No objects found for term "' . $searchTerm . '".';
        }

        $objectMarkdowns = [];
        $objectAliases = [];
        foreach ($rows as $row) {
            $objectAliases[] = $row['ALIAS_WITH_NS'];
            $selector = $row['UID'] ?? $row['ALIAS_WITH_NS'] ?? null;
            if (! is_string($selector) || $selector === '') {
                throw new AiToolRuntimeError($this, 'Invalid object search result');
            }

            try {
                $objectMarkdowns[$row['ALIAS_WITH_NS']] = (new ObjectMarkdownPrinter($this->getWorkbench(), $selector, 0))->getMarkdown();
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e);
            }
        }

        if (empty($objectAliases)) {
            return 'No objects found for search query `' . $searchTerm . '`.';
        }
        
        if (in_array($searchTerm, $objectAliases)) {
            $exactMatch = $objectMarkdowns[$searchTerm];
            $objectMarkdowns = array_merge([$exactMatch], array_keys($objectMarkdowns));
        }

        $details = implode("\n\n---\n\n", $objectMarkdowns);
        $aliasList = "\n- `" . implode("`\n- `", $objectAliases) . '`';
        return <<<MD
# Object search results

Objects matching `{$searchTerm}`:
{$aliasList}

{$details}
MD;

    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_SEARCH_TERM)
                ->setDescription('UID or namespaced alias of an object or a search term to look for in object names and aliases"')
                ->setRequired(true)
                ->setExamples([
                    'exface.Core.USER',
                    '0x31343400000000000000000000000000',
                    'user'
                ])
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}