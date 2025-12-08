<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\CodeMarkdownPrinter;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\ObjectMarkdownPrinter;
use exface\Core\Interfaces\WorkbenchInterface;

class GetCodeTool extends AbstractAiTool
{

    /**
     *
     * @var string
     */
    const ARG_CODE_PATCH = 'CodePath';

    

    public function invoke(array $arguments): string
    {
        list($codePath) = $arguments;

        $printer = new CodeMarkdownPrinter($this->getWorkbench(), $codePath);
        return $printer->getMarkdown();
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_CODE_Path)
                ->setDescription('Path pointing to the Code File itself to get details for')
        ];
    }
}