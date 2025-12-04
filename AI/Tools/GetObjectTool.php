<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\ObjectMarkdownPrinter;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class GetObjectTool extends AbstractAiTool
{
    /**
     *
     * @var string
     */
    const ARG_OBJECT_SELECTOR = 'object_alias';    

    public function invoke(array $arguments): string
    {
        list($objectId) = $arguments;

        $printer = new ObjectMarkdownPrinter($this->workbench, $objectId);
        return $printer->getMarkdown();
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_OBJECT_SELECTOR)
                ->setDescription('Fully qualified alias (with namespace) or UID of the object to be read: e.g. `exface.Core.PAGE` or `0x11e86314af5caf7f971b0205857feb80`')
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