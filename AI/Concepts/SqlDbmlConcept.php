<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\DBML\DbmlBuilder;
use axenox\GenAI\Common\DBML\SqlDbmlBuilder;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

/**
 * Adds a physical SQL database schema to AI instructions as DBML.
 * 
 * Use `object_filters` to select table-like metaobjects. Only objects from the first matching SQL
 * connection are included, and their physical table and column addresses are used in the schema.
 * 
 * ## Example
 * 
 * ```json
 * {
 *   "alias": "axenox.GenAI.SqlDbmlConcept",
 *   "object_filters": {
 *     "operator": "AND",
 *     "conditions": [
 *       {"expression": "DATA_SOURCE__CONNECTION", "comparator": "==", "value": "[#~input:UID#]"}
 *     ]
 *   }
 * }
 * 
 * ```
 */
class SqlDbmlConcept extends MetamodelDbmlConcept
{
    private $connection = null;

    /**
     * Ensures the concept produces a useful SQL schema.
     *
     * {@inheritDoc}
     * @see \axenox\GenAI\AI\Concepts\MetamodelDbmlConcept::getObjects()
     */
    protected function getObjects() : array
    {
        $objects = parent::getObjects();
        if (empty($objects)) {
            throw new AiConceptConfigurationError($this, 'No SQL-based meta objects found!');
        }
        return $objects;
    }

    /**
     * Includes table-like objects from one SQL connection.
     *
     * {@inheritDoc}
     * @see \axenox\GenAI\AI\Concepts\MetamodelDbmlConcept::includesObject()
     */
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

    /**
     * Creates the physical SQL renderer for the selected metaobjects.
     *
     * {@inheritDoc}
     * @see \axenox\GenAI\AI\Concepts\MetamodelDbmlConcept::createDbmlBuilder()
     */
    protected function createDbmlBuilder() : DbmlBuilder
    {
        $objects = $this->getObjects();
        return new SqlDbmlBuilder($this->getWorkbench(), $this->connection, $objects);
    }
}