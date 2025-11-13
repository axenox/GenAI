<?php

namespace axenox\GenAI\Common\Markdown;

use axenox\GenAI\Common\AbstractPrinter;
use axenox\GenAI\Interfaces\MarkdownPrinterInterface;
use exface\Core\Factories\MetaObjectFactory;

class ObjectMarkdownPrinter extends AbstractPrinter implements MarkdownPrinterInterface
{

    private ?string $objectId = null;
    
    public function getMarkdown(): string
    {
        if(!$this->objectId) return '';

        $markdown = "| Name | Alias | Data Address | Data Type | Required | Relation |\n";
        $markdown .= "|------|--------|--------------|------------|-----------|-----------|\n";
        
        $markdown.= implode("\n",$this->getAttributes($this->objectId));
        
        return $markdown;
        
    }
    /**
     * Returns a list of attribute strings in Markdown format.
     *| Name | Alias | Data Address | Data Type | Required | Relation |
     * 
     * @param string $objectId
     * @return string[]  
     */
    protected function getAttributes(string $objectId): array
    {
        $metaObject = MetaObjectFactory::createFromString($this->workbench, $objectId);
        $attributes = $metaObject->getAttributes();

        

        $list = [];
        
        foreach ($attributes->getAll() as  $attribute ) {
            $name = $attribute->getName();
            $alias = $attribute->getAlias();
            $dataAddress = $attribute->getDataAddress();
            $dataType = $attribute->getDataType();
            $required = $attribute->isRequired();
            $relation = $attribute->getRelationPath();

            $list[] = "| {$name} | {$alias} | {$dataAddress} | {$dataType} | {$required} | {$relation} |\n";
        }
        return $list;
    }

    public function getObjectId(): ?string
    {
        return $this->objectId;
    }

    public function setObjectId(?string $objectId): ObjectMarkdownPrinter
    {
        $this->objectId = $objectId;
        return $this;
    }
    
    
}