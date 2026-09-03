<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\DBML\SqlDbmlBuilder;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\CodeDataType;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\ArrayPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;

/**
 * Returns a physical DBML schema for an SQL data connection optionally filtered by object properties like data address.
 *
 * The optional search can limit the result to the tables relevant to the current task. By default it will be used as
 * a contains filter on the data address. However, you can redefine the search by providing a `object_search_data_sheet` 
 * with a `[#~query#]` placeholder anywhere.
 * 
 * The DBML schema is built on-the fly by searching through the metaobjects of the given SQL data connection and including
 * those of them, that point to a table or view. Similarly, only attributes, that point to an SQL column are included.
 * Relations are derived from metamodel relations and not from foreign key constraints. This gives a good overview of the
 * DB structure, but is a DB agnostic abstraction at the same time.
 * 
 * @author Andrej Kabachnik
 */
class SqlDbmlTool extends AbstractAiTool
{
    public const ARG_CONNECTION = 'connection';
    public const ARG_DATA_ADDRESS_SEARCH = 'data_address_search';
    
    private ?UxonObject $objectSheetUxon = null;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $connectionSelector = trim((string) ($arguments[0] ?? ''));
        $dataAddressSearch = trim((string) ($arguments[1] ?? ''));
        if ($connectionSelector === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: `connection`.');
        }

        try {
            $connection = DataConnectionFactory::createFromModel($this->getWorkbench(), $connectionSelector);
            if (! $connection instanceof SqlDataConnectorInterface) {
                throw new AiToolRuntimeError(
                    $this,
                    $prompt,
                    'Data connection `' . $connectionSelector . '` is not an SQL connection.'
                );
            }
            $objects = $this->findObjects($connection, $dataAddressSearch);
            if (empty($objects)) {
                throw new AiToolRuntimeError(
                    $this,
                    $prompt,
                    'No table-like SQL metaobjects found for connection `' . $connectionSelector . '`.'
                );
            }
            $dbml = (new SqlDbmlBuilder($this->getWorkbench(), $connection, $objects))->buildDBML();
        } catch (AiToolRuntimeError $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new AiToolRuntimeError(
                $this,
                $prompt,
                'Failed to build DBML for connection `' . $connectionSelector . '`: ' . $exception->getMessage(),
                null,
                $exception
            );
        }

        return new AiToolResultString($this, $arguments, $dbml, $this->getReturnDataType());
    }
    
    protected function findObjects(SqlDataConnectorInterface $connection, string $query) : array
    {

        $uxon = $this->objectSheetUxon;
        if ($uxon === null) {
            $uxon = new UxonObject([
                'object_alias' => 'exface.Core.OBJECT',
                'filters' => [
                    'operator' => EXF_LOGICAL_AND,
                    'conditions' => [
                        [
                            'attribute_alias' => 'DATA_ADDRESS',
                            'comparator' => ComparatorDataType::IS,
                            'value' => $query
                        ]
                    ]
                ]
            ]);
        } else {
            $json = $uxon->toJson();

            $renderer = new BracketHashStringTemplateRenderer($this->getWorkbench());
            $renderer->addPlaceholder(new FormulaPlaceholders($this->getWorkbench()));
            $renderer->addPlaceholder(new ArrayPlaceholders(['~query' => $query]));
            $renderedJson = $renderer->render($json);
            $uxon = UxonObject::fromJson($renderedJson);
        }

        $dataSheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $uxon);

        $aliasColumn = $dataSheet->getColumns()->addFromExpression('ALIAS_WITH_NS');
        $dataAddressColumn = $dataSheet->getColumns()->addFromExpression('DATA_ADDRESS');
        $dataSheet->getFilters()->addConditionFromString('DATA_SOURCE__CONNECTION', $connection->getId(), ComparatorDataType::EQUALS);
        $dataSheet->dataRead();


        $objects = [];
        foreach ($aliasColumn->getValues() as $rowNumber => $alias) {
            $dataAddress = (string) $dataAddressColumn->getValue($rowNumber);
            $object = MetaObjectFactory::createFromString($this->getWorkbench(), $alias);
            if (! SqlDbmlBuilder::isTableObjectForConnection($object, $connection)) {
                continue;
            }
            $objects[] = $object;
        }
        return $objects;
    }

    /**
     * Data sheet to use to read objects from the metamodel - place the `[#query#]` placeholder in any filter to control the search.
     * 
     * @uxon-property object_search_data_sheet
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheet
     * @uxon-template {"object_alias":"exface.Core.OBJECT","filters":[{"attribute_alias":"DATA_ADDRESS","comparator":"=","value":"[#query#]"}]}
     * 
     * @param UxonObject $uxon
     * @return $this
     */
    protected function setObjectSearchDataSheet(UxonObject $uxon) : SqlDbmlTool
    {
        $this->objectSheetUxon = $uxon;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench) : array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_CONNECTION)
                ->setDescription('UID or namespaced alias of the SQL data connection')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName(self::ARG_DATA_ADDRESS_SEARCH)
                ->setDescription('Optional case-insensitive text to search for in metaobject data addresses, such as `dbo.` or `order_`')
                ->setRequired(false)
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        /** @var CodeDataType $type */
        $type = DataTypeFactory::createFromPrototype($this->getWorkbench(), CodeDataType::class);
        $type->setLanguage('dbml');
        return $type;
    }

    public function getRules(): ?string
    {
        return <<<MD

The DBML schema is built on-the fly by searching through the metaobjects of the given SQL data connection and including
those of them, that point to a table or view. Similarly, only attributes, that point to an SQL column are included. 
Relations are derived from metamodel relations and not from foreign key constraints. This gives a good overview of the
DB structure, but is a DB agnostic abstraction at the same time.

Useful hints:
- A comment above the DBML will tell you, which DB type your are dealing with
- Search for table/view name patterns to narrow down the result: e.g. `dbo.` for a certain schema or `order` for all 
tables/views containing `order` in their names.
- Use SQL DDL tools (if available) to get precise information about the keys, constraints, column types and other details 

MD;

    }
}