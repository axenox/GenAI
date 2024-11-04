<?php
namespace axenox\GenAI\AI\Concepts;

use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

class SqlDbmlConcept extends MetamodelDbmlConcept
{

    protected function buildDbmlColName(MetaAttributeInterface $attr) : ?string
    {
        $address = $attr->getDataAddress();
        if ($this->isCustomSQL($address)) {
            return null;
        }
        return StringDataType::stripLineBreaks($address);
    }

    protected function buildDbmlColDescription(MetaAttributeInterface $attr) : string
    {
        return StringDataType::endSentence($attr->getName()) . ' ' . $attr->getShortDescription();
    }

    protected function buildDbmlTableName(MetaObjectInterface $obj) : ?string
    {
        $address = $obj->getDataAddress();
        if ($this->isCustomSQL($address)) {
            return null;
        }
        return $address;
    } 

    protected function isCustomSQL(string $address) : bool
    {
        if ($address === null || $address === '') {
            return false;
        }
        return mb_strpos($address, '(') !== false && mb_strpos($address, ')') !== false;
    }
}