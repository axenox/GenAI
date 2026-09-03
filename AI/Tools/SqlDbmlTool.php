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
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Returns a physical DBML schema for an SQL data connection.
 *
 * The optional object alias list can limit the result to the tables relevant to the current task.
 */
class SqlDbmlTool extends AbstractAiTool
{
    public const ARG_CONNECTION = 'connection';
    public const ARG_OBJECT_ALIASES = 'object_aliases';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $connectionSelector = trim((string) ($arguments[0] ?? ''));
        $objectAliases = $arguments[1] ?? [];
        if ($connectionSelector === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: `connection`.');
        }
        if (is_string($objectAliases)) {
            $objectAliases = array_filter(array_map('trim', explode(',', $objectAliases)));
        }
        if (! is_array($objectAliases)) {
            throw new AiToolRuntimeError($this, $prompt, 'Argument `object_aliases` must be an array of namespaced metaobject aliases.');
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
            $objects = SqlDbmlBuilder::findObjects($this->getWorkbench(), $connection, $objectAliases);
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
                ->setName(self::ARG_OBJECT_ALIASES)
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Array']))
                ->setDescription('Optional namespaced metaobject aliases used to limit the returned tables')
                ->setRequired(false)
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        /* @var $type \exface\Core\DataTypes\CodeDataType */
        $type = DataTypeFactory::createFromPrototype($this->getWorkbench(), CodeDataType::class);
        $type->setLanguage('dbml');
        return $type;
    }
}