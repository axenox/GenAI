<?php
namespace axenox\GenAI\Common\DBML;

use exface\Core\DataConnectors\MariaDbSqlConnector;
use exface\Core\DataConnectors\MsSqlConnector;
use exface\Core\DataConnectors\MySqlConnector;
use exface\Core\DataConnectors\OdbcSqlConnector;
use exface\Core\DataConnectors\OracleSqlConnector;
use exface\Core\DataConnectors\PostgreSqlConnector;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Builds physical DBML schemas for an SQL data connection.
 */
class SqlDbmlBuilder extends DbmlBuilder
{
    private SqlDataConnectorInterface $connection;

    /**
     * @param WorkbenchInterface $workbench
     * @param SqlDataConnectorInterface $connection
     * @param MetaObjectInterface[] $objects
     */
    public function __construct(
        WorkbenchInterface $workbench,
        SqlDataConnectorInterface $connection,
        array $objects
    ) {
        $this->connection = $connection;
        parent::__construct($workbench, $this->filterObjects($objects));
    }

    /**
     * Adds the current SQL engine to the rendered DBML.
     *
     * @return string
     */
    public function buildDBML() : string
    {
        return '// Current DB engine: ' . $this->getSqlEngine() . PHP_EOL . parent::buildDBML();
    }

    /**
     * Returns a human-readable SQL engine name.
     *
     * @return string
     */
    public function getSqlEngine() : string
    {
        switch (true) {
            case $this->connection instanceof MsSqlConnector:
                return 'Microsoft SQL Server';
            case $this->connection instanceof OracleSqlConnector:
                return 'Oracle SQL';
            case $this->connection instanceof MariaDbSqlConnector:
                return 'MariaDB';
            case $this->connection instanceof MySqlConnector:
                return 'MySQL';
            case $this->connection instanceof PostgreSqlConnector:
                return 'PostgreSQL';
            case $this->connection instanceof OdbcSqlConnector:
                return 'ODBC SQL';
            default:
                return 'unspecified SQL DB';
        }
    }

    /**
     * Returns the physical SQL column address.
     *
     * @param MetaAttributeInterface $attribute
     * @return string|null
     */
    protected function buildDbmlColName(MetaAttributeInterface $attribute) : ?string
    {
        $address = trim((string) $attribute->getDataAddress());
        if ($address === '' || $this->isCustomSQL($address)) {
            return null;
        }
        return StringDataType::stripLineBreaks($address);
    }

    /**
     * Returns the SQL-oriented description of a column.
     *
     * @param MetaAttributeInterface $attribute
     * @return string|null
     */
    protected function buildDbmlColDescription(MetaAttributeInterface $attribute) : ?string
    {
        $description = trim((string) $attribute->getShortDescription());
        $name = trim((string) $attribute->getName());
        if ($name === '' && $description === '') {
            return null;
        }
        return trim(StringDataType::endSentence($name) . ' ' . $description);
    }

    /**
     * Returns the physical SQL table address.
     *
     * @param MetaObjectInterface $object
     * @return string|null
     */
    protected function buildDbmlTableName(MetaObjectInterface $object) : ?string
    {
        $address = trim((string) $object->getDataAddress());
        if ($address === '' || $this->isCustomSQL($address)) {
            return null;
        }
        return $address;
    }

    /**
     * Keeps only table-like objects backed by this builder's connection.
     *
     * @param MetaObjectInterface[] $objects
     * @return MetaObjectInterface[]
     */
    private function filterObjects(array $objects) : array
    {
        return array_values(array_filter($objects, function (MetaObjectInterface $object) : bool {
            return self::isTableObjectForConnection($object, $this->connection);
        }));
    }

    /**
     * Checks whether a metaobject represents a table on the given connection.
     *
     * @param MetaObjectInterface $object
     * @param SqlDataConnectorInterface $connection
     * @return bool
     */
    public static function isTableObjectForConnection(
        MetaObjectInterface $object,
        SqlDataConnectorInterface $connection
    ) : bool {
        $objectConnection = $object->getDataConnection();
        return $objectConnection instanceof SqlDataConnectorInterface
            && $objectConnection->getId() === $connection->getId()
            && ! self::containsCustomSQL((string) $object->getDataAddress());
    }

    /**
     * Checks whether a data address contains a custom SQL expression.
     *
     * @param string $address
     * @return bool
     */
    protected function isCustomSQL(string $address) : bool
    {
        return self::containsCustomSQL($address);
    }

    /**
     * Checks a data address without requiring a builder instance.
     *
     * @param string $address
     * @return bool
     */
    private static function containsCustomSQL(string $address) : bool
    {
        return mb_strpos($address, '(') !== false && mb_strpos($address, ')') !== false;
    }
}