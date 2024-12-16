<?php
namespace axenox\GenAI\AI\Concepts;

use exface\Core\DataConnectors\MariaDbSqlConnector;
use exface\Core\DataConnectors\MsSqlConnector;
use exface\Core\DataConnectors\MySqlConnector;
use exface\Core\DataConnectors\OracleSqlConnector;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

class SqlDbmlConcept extends MetamodelDbmlConcept
{
    private $connection = null;

    public function buildDBML() : string
    {
        $dbml = parent::buildDBML();
        return "// Current DB engine: {$this->getSqlEngine()}
        " . $dbml;
    }

    protected function getSqlEngine() : string
    {
        switch (true) {
            case $this->connection instanceof MsSqlConnector: return 'Microsoft SQL Server';
            case $this->connection instanceof OracleSqlConnector: return 'Oracle SQL';
            case $this->connection instanceof MariaDbSqlConnector: return 'Maria DB';
            case $this->connection instanceof MySqlConnector: return 'MySQL';
        }
        return 'unspecified SQL DB';
    }

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

    protected function getObjects() : array
    {
        $objects = [];
        foreach (parent::getObjects() as $obj) {
            $connection = $obj->getDataConnection;
            $isSql = $connection instanceof SqlDataConnectorInterface;
            $isTable = stripos($obj->getDataAddress(), '(') === false; // Otherwise it is a SQL statement like (SELECT ...)
            // TODO also only those, that are in the same database as the object we are filtering
            if ($isSql && $isTable) {
                if ($this->connection === null) {
                    $this->connection = $connection;
                }
                if ($this->connection === $connection) {
                    $objects[] = $obj;
                }
            }
        }
        return $objects;
    }
}