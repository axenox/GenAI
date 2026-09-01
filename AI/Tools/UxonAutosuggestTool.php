<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\Actions\UxonAutosuggest;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Tasks\ResultJSON;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Returns context-aware suggestions from the UXON editor autosuggest engine.
 *
 * Provide the complete UXON and the path of the node being edited. Depending on
 * the input type, the tool can suggest property names, values, presets, details,
 * or model-browser entries.
 *
 * ## Example
 *
 * ```json
 * {
 *   "alias": "axenox.GenAI.UxonAutosuggestTool",
 *   "description": "Find valid UXON properties and values before generating UXON."
 * }
 *  
 * ```
 */
class UxonAutosuggestTool extends AbstractAiTool
{
    public const ARG_UXON = 'uxon';
    public const ARG_PATH = 'path';
    public const ARG_INPUT = 'input';
    public const ARG_TEXT = 'text';
    public const ARG_OBJECT = 'object';
    public const ARG_PROTOTYPE = 'prototype';
    public const ARG_SCHEMA = 'schema';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        [$uxon, $path, $input, $text, $object, $prototype, $schema] = array_pad($arguments, 7, null);

        try {
            $task = new GenericTask($this->getWorkbench());
            $task->setParameter('uxon', $this->encodeTaskJsonParameter($uxon));
            $task->setParameter('path', $this->encodeTaskJsonParameter($path ?? []));
            $task->setParameter(UxonAutosuggest::PARAM_TYPE, $input);
            $task->setParameter(UxonAutosuggest::PARAM_TEXT, $text ?? '');
            $task->setParameter('object', $object);
            $task->setParameter('prototype', $prototype);
            $task->setParameter('schema', $schema);

            $action = ActionFactory::createFromString($this->getWorkbench(), UxonAutosuggest::class);
            $result = $action->handle($task);
            if (! $result instanceof ResultJSON) {
                throw new \UnexpectedValueException('UxonAutosuggest returned an unexpected result type.');
            }

            return new AiToolResultString(
                $this,
                $arguments,
                $result->getContent(),
                $this->getReturnDataType()
            );
        } catch (\Throwable $e) {
            $exception = new AiToolRuntimeError(
                $this,
                $prompt,
                'UXON autosuggest failed: ' . $e->getMessage(),
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
     * Normalize and encode a structured tool argument as a JSON task parameter.
     *
     * @param mixed $value
     * @return string
     */
    private function encodeTaskJsonParameter(mixed $value): string
    {
        if ($value instanceof UxonObject) {
            $value = $value->toArray();
        } elseif (is_string($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
                ->setDescription('Complete UXON object being edited. Include surrounding properties because suggestions depend on their context.'),
            (new ServiceParameter($self))
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Array']))
                ->setName(self::ARG_PATH)
                ->setDescription('Path from the UXON root to the node being edited, as an array of property names and array indexes.'),
            (new ServiceParameter($self))
                ->setName(self::ARG_INPUT)
                ->setDescription('Suggestion type: field, value, preset, details, or modelbrowser.')
                ->setDataType(new UxonObject([
                    'alias' => 'exface.Core.GenericStringEnum',
                    'values' => [
                        UxonAutosuggest::TYPE_FIELD => 'Field',
                        UxonAutosuggest::TYPE_VALUE => 'Value',
                        UxonAutosuggest::TYPE_PRESET => 'Preset',
                        UxonAutosuggest::TYPE_DETAILS => 'Details',
                        UxonAutosuggest::TYPE_MODEL_BROWSER => 'Model Browser'
                    ]
                ])),
            (new ServiceParameter($self))
                ->setName(self::ARG_TEXT)
                ->setDescription('Text typed so far, used to filter value and model-browser suggestions (optional).')
                ->setRequired(false),
            (new ServiceParameter($self))
                ->setName(self::ARG_OBJECT)
                ->setDescription('Optional (but recommended) alias or UID of the root metaobject that provides object context.')
                ->setRequired(false)
                ->setExamples(['exface.Core.PAGE']),
            (new ServiceParameter($self))
                ->setName(self::ARG_PROTOTYPE)
                ->setDescription('Optional (but recommended) fully qualified root prototype class or PHP file path relative to the vendor folder.')
                ->setRequired(false)
                ->setExamples(['\\exface\\Core\\Widgets\\DataTable', 'exface/Core/Widgets/DataTable.php']),
            (new ServiceParameter($self))
                ->setName(self::ARG_SCHEMA)
                ->setDescription('Optional UXON schema class or schema name used to interpret the UXON.')
                ->setRequired(false),
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