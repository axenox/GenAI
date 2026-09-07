<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\DataSheetSchema;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Imports model components using Core registry DataSheet templates.
 */
class ModelComponentImportTool extends AbstractAiTool
{
    public const ARG_DATASHEET = 'data_sheet';

    /**
     * @var DataSheetSchema[]|null
     */
    private ?array $componentDataSchemas = null;

    /**
     * Enriches and imports registry-approved model component DataSheets.
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $warnings = [];
        try {
            $payload = $arguments[0] ?? null;
            if ($payload === null) {
                throw new AiToolRuntimeError($this, $prompt, 'Missing data argument in ImportTool');
            }

            $uxon = UxonObject::fromAnything($payload);
            $data = $uxon->toArray();
            if ($uxon->isArray()) {
                foreach ($data as &$dataSheetData) {
                    if (is_array($dataSheetData)) {
                        $this->enrichOptimisticLockValues($dataSheetData);
                    }
                }
                unset($dataSheetData);
            } else {
                $this->enrichOptimisticLockValues($data);
            }

            $arguments[0] = $data;
            $uxon = UxonObject::fromAnything($data);
            $messages = [];
            if ($uxon->isArray()) {
                foreach ($uxon as $index => $item) {
                    if (! $item instanceof UxonObject) {
                        $warning = (new AiToolRuntimeError($this, $prompt, 'Skipped invalid import row at index ' . $index . '.'))
                            ->setLogLevel(LoggerInterface::WARNING);
                        $this->getWorkbench()->getLogger()->logException($warning);
                        $warnings[] = $warning;
                        continue;
                    }

                    $sheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $item);
                    $sheet->dataSave();
                    $messages[] = 'Imported ' . count($sheet->getRows()) . ' row(s) into "' . $sheet->getMetaObject()->getAliasWithNamespace() . '".';
                }
            } else {
                $sheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $uxon);
                $sheet->dataSave();
                $messages[] = 'Imported ' . count($sheet->getRows()) . ' row(s) into "' . $sheet->getMetaObject()->getAliasWithNamespace() . '".';
            }

            $message = $messages === [] ? 'No Data Imported' : implode("\n", $messages);
            $result = new AiToolResultString($this, $arguments, $message, $this->getReturnDataType());
            foreach ($warnings as $warning) {
                $result->addException($warning);
            }

            return $result;
        } catch (\Throwable $e) {
            $message = 'Error during import: ' . $e->getMessage();
            $exception = $e instanceof ExceptionInterface
                ? $e
                : new AiToolRuntimeError($this, $prompt, 'Error during import. ' . $e->getMessage(), null, $e);
            $agent->getWorkbench()->getLogger()->logException($exception);

            return (new AiToolResultString($this, $arguments, $message, $this->getReturnDataType()))
                ->addException($exception);
        }
    }

    /**
     * Adds configured update timestamps to rows with an existing UID, including nested DataSheets.
     *
     * @param array<string, mixed> $dataSheetData
     */
    private function enrichOptimisticLockValues(array &$dataSheetData): void
    {
        $rows = $dataSheetData['rows'] ?? null;
        $objectAlias = $dataSheetData['object_alias'] ?? null;
        if (! is_array($rows) || ! is_string($objectAlias) || $objectAlias === '') {
            return;
        }

        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as &$value) {
                if (is_array($value) && isset($value['object_alias'], $value['rows'])) {
                    $this->enrichOptimisticLockValues($value);
                }
            }
            unset($value);
        }
        unset($row);

        $object = $this->getWorkbench()->model()->getObject($objectAlias);
        $uidAttribute = $object->getUidAttribute();
        $uidAlias = $uidAttribute->getAliasWithRelationPath();

        $updatedOnAttributes = [];
        foreach ($object->getBehaviors()->getByPrototypeClass(TimeStampingBehavior::class) as $behavior) {
            if ($behavior->getCheckForConflictsOnUpdate() && $behavior->hasUpdatedOnAttribute()) {
                $attribute = $behavior->getUpdatedOnAttribute();
                $updatedOnAttributes[$attribute->getAliasWithRelationPath()] = $attribute;
            }
        }

        foreach ($rows as &$row) {
            if (array_key_exists($uidAlias, $row) && $row[$uidAlias] === null) {
                unset($row[$uidAlias]);
            }
            foreach ($updatedOnAttributes as $alias => $attribute) {
                unset($row[$alias]);
            }
        }
        unset($row);

        $rowIndexesByUid = [];
        foreach ($rows as $rowIndex => $row) {
            $uid = trim((string) ($row[$uidAlias] ?? ''));
            if ($uid !== '') {
                $rowIndexesByUid[strtolower($uid)][] = $rowIndex;
            }
        }

        if ($rowIndexesByUid === []) {
            $dataSheetData['rows'] = $rows;
            return;
        }

        if ($updatedOnAttributes === []) {
            $dataSheetData['rows'] = $rows;
            return;
        }

        $lookupSheet = DataSheetFactory::createFromObject($object);
        $lookupUidColumn = $lookupSheet->getColumns()->addFromUidAttribute();
        $lookupTimestampColumns = [];
        foreach ($updatedOnAttributes as $alias => $attribute) {
            $lookupTimestampColumns[$alias] = $lookupSheet->getColumns()->addFromAttribute($attribute);
        }
        $lookupSheet->getFilters()->addConditionFromAttribute(
            $uidAttribute,
            implode($uidAttribute->getValueListDelimiter(), array_keys($rowIndexesByUid)),
            ComparatorDataType::IN
        );
        $lookupSheet->dataRead();

        $foundUids = [];
        foreach ($lookupUidColumn->getValues() as $lookupRowIndex => $uid) {
            $normalizedUid = strtolower((string) $uid);
            $foundUids[$normalizedUid] = true;
            foreach ($rowIndexesByUid[$normalizedUid] ?? [] as $rowIndex) {
                foreach ($lookupTimestampColumns as $alias => $column) {
                    $rows[$rowIndex][$alias] = $column->getValue($lookupRowIndex);
                }
            }
        }

        $missingUids = array_diff_key($rowIndexesByUid, $foundUids);
        if ($missingUids !== []) {
            throw new \RuntimeException(
                'Cannot update "' . $objectAlias . '": UID(s) not found: ' . implode(', ', array_keys($missingUids)) . '.'
            );
        }

        $dataSheetData['rows'] = $rows;
    }

    /**
     * Returns the DataSheet schemas approved for component imports by Core.
     *
     * @return DataSheetSchema[]
     */
    protected function getDataSchemas(): array
    {
        if ($this->componentDataSchemas === null) {
            $this->componentDataSchemas = [];
            $registry = $this->getWorkbench()->getComponentRegistry();

            foreach ($registry->getComponentKeys('save_component_data') as $component) {
                $template = $registry->getComponentSaveData($component);
                if ($template !== null) {
                    $this->componentDataSchemas[] = DataSheetSchema::createFromDataSheetUxon(
                        $this->getWorkbench(),
                        $template
                    );
                }
            }

            if ($this->componentDataSchemas === []) {
                throw new \RuntimeException('No component import templates are configured in the Core component registry.');
            }
        }

        return $this->componentDataSchemas;
    }

    /**
     * Returns the fixed registry-derived component import argument.
     */
    public function getArguments(): array
    {
        return [
            (new ServiceParameter($this))
                ->setName(self::ARG_DATASHEET)
                ->setDescription('Registry-approved model component DataSheet payload to import.')
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Array']))
                ->setCustomProperties(new UxonObject([
                    'json_schema' => json_encode(
                        $this->getDataSheetArgumentSchema(),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ])),
        ];
    }

    /**
     * Combines all registry-approved component schemas into one argument schema.
     *
     * @return array<string, mixed>
     */
    protected function getDataSheetArgumentSchema(): array
    {
        $schemas = [];
        foreach ($this->getDataSchemas() as $schema) {
            $schemas[] = $schema->generateJsonSchema();
        }

        if (count($schemas) === 1) {
            return $schemas[0];
        }

        return ['type' => 'array', 'items' => ['anyOf' => $schemas]];
    }

    /**
     * Returns the default argument template used before the registry schema is resolved.
     *
     * @return ServiceParameter[]
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_DATASHEET)
                ->setDescription('Registry-approved model component DataSheet payload to import.')
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Array'])),
        ];
    }

    /**
     * Returns markdown for successful imports and import errors.
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}