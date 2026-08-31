<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\UxonDataType;
use exface\Core\Exceptions\DataTypes\UxonValidationError;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Validates UXON and returns advisory diagnostics that an AI agent can use to correct it.
 *
 * The tool can validate UXON against a schema and optionally provide the root object and
 * prototype context. Its validation creates mock model components and can therefore report
 * false positives. Agents should use the result as guidance rather than proof that UXON is
 * valid or invalid.
 *
 * ## Example
 *
 * ```json
 * {
 *   "alias": "axenox.GenAI.UxonValidateTool",
 *   "description": "Validate generated widget UXON before returning it."
 * }
 *  
 * ```
 */
class UxonValidateTool extends AbstractAiTool
{
    public const ARG_UXON = 'uxon';
    public const ARG_SCHEMA = 'schema';
    public const ARG_OBJECT = 'object';
    public const ARG_PROTOTYPE = 'prototype';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        [$input, $objectSelector, $prototypeSelector, $schemaName] = array_pad($arguments, 4, null);

        try {
            $uxon = UxonObject::fromAnything($input);
            if ($objectSelector !== null && $objectSelector !== '') {
                try {
                    $object = $this->getWorkbench()->model()->getObject($objectSelector);
                    if (! $uxon->hasProperty('object_alias')) {
                        $uxon->setProperty('object_alias', $object->getAliasWithNamespace());
                    }
                } catch (\Throwable $e) {
                    // Match UxonValidate: unknown optional object context does not abort validation.
                }
            }

            $prototypeClass = $this->normalizePrototypeClass($prototypeSelector);
            /** @var UxonDataType $dataType */
            $dataType = DataTypeFactory::createFromString($this->getWorkbench(), UxonDataType::class);
            $dataType->setSchema($schemaName);
            $diagnostics = array_map(
                static fn(UxonValidationError $error): array => [
                    'path' => $error->getPath(),
                    'message' => $error->getMessage(),
                ],
                $dataType->validate($uxon, $prototypeClass)
            );

            return new AiToolResultString(
                $this,
                $arguments,
                json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                $this->getReturnDataType()
            );
        } catch (\Throwable $e) {
            $exception = new AiToolRuntimeError(
                $this,
                $prompt,
                'UXON validation failed: ' . $e->getMessage(),
                null,
                $e
            );
            $agent->getWorkbench()->getLogger()->logException($exception);

            return new AiToolResultString(
                $this,
                $arguments,
                json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                $this->getReturnDataType(),
                [],
                [$exception]
            );
        }
    }

    /**
     * Normalize a PHP class or prototype file path to a fully qualified class name.
     *
     * @param mixed $prototypeSelector
     * @return string|null
     */
    private function normalizePrototypeClass(mixed $prototypeSelector): ?string
    {
        if (! is_string($prototypeSelector) || trim($prototypeSelector) === '') {
            return null;
        }

        $prototypeSelector = trim($prototypeSelector);
        if (StringDataType::endsWith($prototypeSelector, '.php', false)) {
            return '\\' . ltrim(str_replace('/', '\\', substr($prototypeSelector, 0, -4)), '\\');
        }

        return $prototypeSelector;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Json']))
                ->setName(self::ARG_UXON)
                ->setDescription('UXON object to validate. Diagnostics are advisory and can contain false positives.'),
            (new ServiceParameter($self))
                ->setName(self::ARG_OBJECT)
                ->setDescription('Optional (but recommended!) alias or UID of the root metaobject that provides object context.')
                ->setRequired(false)
                ->setExamples(['exface.Core.PAGE']),
            (new ServiceParameter($self))
                ->setName(self::ARG_PROTOTYPE)
                ->setDescription('Optional (but recommended!) fully qualified root prototype class or PHP file path relative to the vendor folder.')
                ->setRequired(false)
                ->setExamples(['\\exface\\Core\\Widgets\\DataTable', 'exface/Core/Widgets/DataTable.php']),
            (new ServiceParameter($self))
                ->setName(self::ARG_SCHEMA)
                ->setDescription('Optional UXON schema class or schema name used to interpret the UXON.')
                ->setRequired(false)
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), JsonDataType::class);
    }
}