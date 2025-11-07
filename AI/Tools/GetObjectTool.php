<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
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

        $metaObject = MetaObjectFactory::createFromString($this->getWorkbench(), $objectId);
        $attributes = $metaObject->getAttributes();

        $markdown = "| Name | Alias | Data Address | Data Type | Required | Relation |\n";
        $markdown .= "|------|--------|--------------|------------|-----------|-----------|\n";

        foreach ($attributes->getAll() as  $attribute ) {
            $name = $attribute->getName();
            $alias = $attribute->getAlias();
            $dataAddress = $attribute->getDataAddress();
            $dataType = $attribute->getDataType();
            $required = $attribute->isRequired();
            $relation = $attribute->getRelationPath();

            $markdown .= "| {$name} | {$alias} | {$dataAddress} | {$dataType} | {$required} | {$relation} |\n";
        }
        


        return $markdown;
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