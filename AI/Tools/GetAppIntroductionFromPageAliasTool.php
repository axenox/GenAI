<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to fetch the introduction documentation for an app by page alias.
 *
 * The tool:
 * 1. Takes a page_alias as input
 * 2. Loads the PAGE object to determine which APP it belongs to
 * 3. Loads the APP object to get the DOCS_INTRO path
 * 4. Uses GetDocsTool logic to return the markdown content
 */
class GetAppIntroductionFromPageAliasTool extends GetDocsTool
{
    public const ARG_PAGE_ALIAS = 'page_alias';

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        $pageAlias = trim((string) ($arguments[0] ?? ''));

        if ($pageAlias === '') {
            return 'Invalid arguments: missing page_alias.';
        }

        try {
            $appAlias = $this->getAppAliasFromPageAlias($pageAlias);
            if ($appAlias === null) {
                return 'Page not found for page_alias: ' . $pageAlias;
            }

            $docsIntroPath = $this->getDocsIntroPathFromAppAlias($appAlias);
            if ($docsIntroPath === null || $docsIntroPath === '') {
                return 'No documentation intro found for app: ' . $appAlias;
            }

            return parent::invoke($agent, $prompt, [$docsIntroPath]);
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            return 'ERROR: Failed to fetch app introduction for page_alias: ' . $pageAlias;
        }
    }

    protected function getAppAliasFromPageAlias(string $pageAlias): ?string
    {
        foreach (['ALIAS_WITH_NS', 'ALIAS_WITH_NAMESPACE', 'ALIAS'] as $attributeAlias) {
            try {
                $pageSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.PAGE');
                $pageSheet->getColumns()->addMultiple(['APP']);
                $pageSheet->getFilters()->addConditionFromString($attributeAlias, $pageAlias, ComparatorDataType::EQUALS);
                $pageSheet->dataRead();

                $rows = $pageSheet->getRows();
                if (!empty($rows)) {
                    $appValue = $rows[0]['APP'];
                    if (!empty($appValue)) {
                        return $this->resolveAppValue($appValue);
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    protected function resolveAppValue(string $appValue): ?string
    {
        try {
            if (strpos($appValue, '.') !== false) {
                return $appValue;
            }

            $appSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.APP');
            $appSheet->getColumns()->addMultiple(['ALIAS_WITH_NS', 'ALIAS']);
            $appSheet->getFilters()->addConditionFromString('UID', $appValue, ComparatorDataType::EQUALS);
            $appSheet->dataRead();

            $rows = $appSheet->getRows();
            if (!empty($rows)) {
                return $rows[0]['ALIAS_WITH_NS'] ?? $rows[0]['ALIAS'] ?? null;
            }
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
        }

        return null;
    }

    protected function getDocsIntroPathFromAppAlias(string $appAlias): ?string
    {
        try {
            foreach (['ALIAS_WITH_NS', 'ALIAS_WITH_NAMESPACE', 'ALIAS'] as $attributeAlias) {
                try {
                    $appSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.APP');
                    $appSheet->getColumns()->addMultiple(['DOCS_INTRO']);
                    $appSheet->getFilters()->addConditionFromString($attributeAlias, $appAlias, ComparatorDataType::EQUALS);
                    $appSheet->dataRead();

                    $rows = $appSheet->getRows();
                    if (!empty($rows)) {
                        $docsIntro = $rows[0]['DOCS_INTRO'];
                        if (!empty($docsIntro)) {
                            return (string) $docsIntro;
                        }
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
        }

        return null;
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_PAGE_ALIAS)
                ->setDescription('Alias der Seite um die App-Dokumentation zu laden, z.B. axenox.genai.home. Das Tool ermittelt die App und laedt deren DOCS_INTRO.')
        ];
    }
}
