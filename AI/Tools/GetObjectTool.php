<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\Markdown\ObjectMarkdownPrinter;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class GetObjectTool extends AbstractAiTool
{

    /**
     *
     * @var string
     */
    const ARG_OBJECT_ID = 'LogId';

    

    public function invoke(array $arguments): string
    {
        list($objectId) = $arguments;

        $printer = new ObjectMarkdownPrinter($this->workbench);
        $printer->setObjectId($objectId);
        return $printer->getMarkdown();
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_OBJECT_ID)
                ->setDescription('Object ID pointing to the Object itself to get details for')
        ];
    }
}