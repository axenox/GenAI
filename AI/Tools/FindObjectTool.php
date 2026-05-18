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
 * Finds metaobjects by a search term and returns Markdown descriptions for all matches.
 * 
 * Examples:
 * 
 * - `FindObject("user")` - will return all object, that have "user" in their name or alias, e.g. `exface.Core.USER`,
 * `exface.Core.USER_ROLE`, etc.
 * - `FindObject("0x98431a3sd5f985a")` - will search for the object with the given UID exactly
 * - `FindObject("exface.Core.USER") - will search for the object with the given alias exactly`
 */
class FindObjectTool extends AbstractAiTool
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

            $orGroup = $ds->getFilters()->addNestedOR();

            $isUidSearch = str_starts_with($searchTerm, '0x');
            $isLikelyAliasWithNs = substr_count($searchTerm, '.') >= 2;

            if ($isUidSearch) {
                $orGroup->addConditionFromString('UID', $searchTerm, ComparatorDataType::EQUALS);
            }

            if ($isLikelyAliasWithNs) {
                $orGroup->addConditionFromString('ALIAS_WITH_NS', $searchTerm, ComparatorDataType::EQUALS);
            }

            if (! $isUidSearch && ! $isLikelyAliasWithNs) {
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

        $parts = [];
        foreach ($rows as $row) {
            $selector = $row['UID'] ?? $row['ALIAS_WITH_NS'] ?? null;
            if (! is_string($selector) || $selector === '') {
                continue;
            }

            try {
                $parts[] = (new ObjectMarkdownPrinter($this->getWorkbench(), $selector, 0))->getMarkdown();
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e);
            }
        }

        if (empty($parts)) {
            return 'No object descriptions could be generated for term "' . $searchTerm . '".';
        }

        return implode("\n\n---\n\n", $parts);
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
                ->setDescription('Search term used to find objects by NAME and ALIAS, e.g. "user"')
                ->setRequired(true)
                ->setExample('user')
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