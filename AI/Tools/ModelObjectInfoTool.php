<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolCriticalError;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Exceptions\AiToolRuntimeWarning;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\ObjectMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
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
class ModelObjectInfoTool extends AbstractAiTool
{
    public const ARG_SEARCH_TERM = 'search_term';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $toolWarnings = [];
        $searchTerm = trim((string) ($arguments[0] ?? ''));
        if ($searchTerm === '') {
            $error = new AiToolRuntimeError($this, $prompt, 'Missing required argument: `search_term`');
            return new AiToolResultString($this, $arguments, $error->getMessage(), $this->getReturnDataType(), [], [$error]);
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
            $error = new AiToolRuntimeError($this, $prompt, 'Failed to search objects: ' . $e->getMessage(), null, $e);
            return new AiToolResultString($this, $arguments, $e->getMessage(), $this->getReturnDataType(), [], [$error]);
        }

        $rows = $ds->getRows();
        if (empty($rows)) {
            $notFoundMsg = 'No objects found for term "' . $searchTerm . '".';
            $warning = new AiToolRuntimeWarning($this, $prompt, $notFoundMsg);
            return new AiToolResultString($this, $arguments, $notFoundMsg, $this->getReturnDataType(), [], [$warning]);
        }

        $objectMarkdowns = [];
        $objectAliases = [];
        foreach ($rows as $row) {
            $objectAliases[] = $row['ALIAS_WITH_NS'];
            $selector = $row['UID'] ?? $row['ALIAS_WITH_NS'] ?? null;
            if (! is_string($selector) || $selector === '') {
                throw new AiToolCriticalError($this, $prompt, 'Invalid object search result: now selector found in object data in row ' . json_encode($row));
            }

            try {
                $objectMarkdowns[$row['ALIAS_WITH_NS']] = (new ObjectMarkdownPrinter($this->getWorkbench(), $selector, 0))->getMarkdown();
            } catch (\Throwable $e) {
                $warning = new AiToolRuntimeWarning($this, $prompt, 'Failed to render object markdown. ' . $e->getMessage(), null, $e);
                $toolWarnings[] = $warning;
            }
        }

        if (empty($objectAliases)) {
            $notFoundMsg = 'No objects found for search query `' . $searchTerm . '`.';
            return new AiToolResultString($this, $arguments, $notFoundMsg, $this->getReturnDataType());
        }
        
        if (in_array($searchTerm, $objectAliases)) {
            $exactMatch = $objectMarkdowns[$searchTerm];
            $objectMarkdowns = array_merge([$exactMatch], array_keys($objectMarkdowns));
        }

        $details = implode("\n\n---\n\n", $objectMarkdowns);
        $aliasList = "\n- `" . implode("`\n- `", $objectAliases) . '`';
        $result = <<<MD
# Object search results

Objects matching `{$searchTerm}`:
{$aliasList}

{$details}
MD;

        $toolResult = new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
        foreach ($toolWarnings as $warning) {
            $toolResult->addException($warning);
        }

        return $toolResult;
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