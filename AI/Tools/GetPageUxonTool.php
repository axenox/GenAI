<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class GetPageUxonTool extends AbstractAiTool
{
    public const ARG_PAGE_ALIAS = 'page_alias';

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        $pageAlias = trim((string) ($arguments[0] ?? ''));

        if ($pageAlias === '') {
            return 'Invalid arguments: missing page_alias.';
        }

        // Try multiple alias attributes because installations may expose different naming.
        foreach (['ALIAS_WITH_NS', 'ALIAS_WITH_NAMESPACE', 'ALIAS'] as $attributeAlias) {
            $content = $this->findPageContent($pageAlias, $attributeAlias);
            if ($content !== null) {
                return $content;
            }
        }

        return 'Page not found for page_alias: ' . $pageAlias;
    }

    protected function findPageContent(string $pageAlias, string $filterAlias): ?string
    {
        try {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.PAGE');
            $sheet->getColumns()->addMultiple(['CONTENT']);
            $sheet->getFilters()->addConditionFromString($filterAlias, $pageAlias, ComparatorDataType::EQUALS);
            $sheet->dataRead();

            $rows = $sheet->getRows();
            if (empty($rows)) {
                return null;
            }

            return (string) ($rows[0]['CONTENT'] ?? '');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_PAGE_ALIAS)
                ->setDescription('Alias der Seite, z.B. axenox.genai.home. Das Tool liest die UXON-Spalte CONTENT.'),
        ];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
    }
}
