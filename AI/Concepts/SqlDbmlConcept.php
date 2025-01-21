<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Exceptions\AiConceptIncompleteError;
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
        return "// Current DB engine: {$this->getSqlEngine()}" . PHP_EOL
            . $dbml;
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
        $address = trim($attr->getDataAddress());
        if ($address === null || $address === '' || $this->isCustomSQL($address)) {
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
        $address = trim($obj->getDataAddress());
        if ($address === null || $address === '' || $this->isCustomSQL($address)) {
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
        $objects = parent::getObjects();
        if (empty($objects)) {
            throw new AiConceptIncompleteError('No SQL-based meta objects found!');
        }
        return $objects;
    }

    protected function includesObject(MetaObjectInterface $obj) : bool
    {
        $connection = $obj->getDataConnection();
        $isSql = $connection instanceof SqlDataConnectorInterface;
        $isTable = stripos($obj->getDataAddress(), '(') === false; // Otherwise it is a SQL statement like (SELECT ...)
        // TODO also only those, that are in the same database as the object we are filtering
        if ($isSql && $isTable) {
            if ($this->connection === null) {
                $this->connection = $connection;
            }
            if ($this->connection === $connection) {
                return true;
            }
        }
        return false;
    }
}