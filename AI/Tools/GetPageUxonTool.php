<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\UiPageMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\SelectorFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class GetPageUxonTool extends AbstractAiTool
{
    public const ARG_PAGE_ALIAS = 'page_alias';

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $pageAlias = trim((string) ($arguments[0] ?? ''));

        if ($pageAlias === '') {
            return new AiToolResultString($this, $arguments, 'Invalid arguments: missing page_alias.', $this->getReturnDataType());
        }

        // Try multiple alias attributes because installations may expose different naming.
        foreach (['ALIAS_WITH_NS', 'ALIAS_WITH_NAMESPACE', 'ALIAS'] as $attributeAlias) {
            $content = $this->findPageContent($pageAlias, $attributeAlias);
            if ($content !== null) {
                return new AiToolResultString($this, $arguments, $this->renderPageMarkdown($content, $pageAlias), $this->getReturnDataType());
            }
        }

        return new AiToolResultString($this, $arguments, 'Page not found for page_alias: ' . $pageAlias, $this->getReturnDataType());
    }

    protected function renderPageMarkdown(string $content, string $pageAlias): string
    {
        try {
            $page = $this->createPageFromContent($pageAlias, $content);
            $printer = new UiPageMarkdownPrinter($page);
            $markdown = (string) $printer->getMarkdown();
            if ($markdown !== '') {
                return $markdown;
            }
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
        }

        return 'ERROR: UiPageMarkdownPrinter returned no markdown for page_alias: ' . $pageAlias;
    }

    protected function createPageFromContent(string $pageAlias, string $content): UiPageInterface
    {
        $selector = SelectorFactory::createPageSelector($this->getWorkbench(), $pageAlias);
        return UiPageFactory::createFromString($selector, $content);
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
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}
