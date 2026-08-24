<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\DataSheetSchema;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Save data to the data source by passing a UXON model for a DataSheet
 *
 * Usage notes:
 * - If `save_as` is configured, `getArguments()` exposes a
 *   generated `json_schema` for `data_sheet` so the LLM sees the target shape.
 * - Without schema configuration, the tool keeps the generic `data_sheet`
 *   argument and the LLM must provide the full DataSheet payload itself.
 * - In `OpenAiToolTester`, write the payload as a JSON string inside the
 *   function call, e.g. `import_data('{"object_alias":"exface.Core.USER","rows":[...]}')`.
 *
 * For end-user examples and tool tester call formats, keep this class in sync
 * with `Docs/AI/Tools/index.md` and `Docs/AI/Tools/index_german.md`.
 * 
 * @author Brookly Fränzschky, Andrej Kabachnik
 */
class DataSheetImportTool extends AbstractAiTool
{
    public const ARG_DATASHEET = 'data_sheet';

    private ?UxonObject $saveAsUxon = null;

    private ?DataSheetSchema $dataSchema = null;

    /**
     * @var DataSheetSchema[]|null
     */
    private ?array $dataSchemas = null;

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $result = null;
        $warnings = [];
        try{
            $payload = $arguments[0] ?? null;
            if ($payload === null) {
                throw new AiToolRuntimeError($this, $prompt, 'Missing data argument in ImportTool');
            }

            $uxon = UxonObject::fromAnything($payload);
            $messages = [];
            if($uxon->isArray()){
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

            if(count($messages) === 0){
                $message = 'No Data Imported';
            }else {
                $message = implode("\n", $messages);
            }

            $result = new AiToolResultString($this, $arguments, $message, $this->getReturnDataType());
            foreach ($warnings as $warning) {
                $result->addException($warning);
            }
        }catch (\Throwable $e) {
            $message = 'Error during import: ' . $e->getMessage();
            if ($e instanceof ExceptionInterface) {
                $exception = $e;
            } else {
                $exception = new AiToolRuntimeError($this, $prompt, 'Error during import. ' . $e->getMessage(), null, $e);
            }

            $agent->getWorkbench()->getLogger()->logException($exception);
            $result = (new AiToolResultString($this, $arguments, $message, $this->getReturnDataType()))
                ->addException($exception);
        }

        return $result;
    }

    /**
     * One target object schema or an array of target schemas used to validate
     * and generate the `data_sheet` argument schema.
     *
     * @uxon-property save_as
     * @uxon-type \axenox\GenAI\Common\DataSheetSchema
     * @uxon-template {"object_alias":"my.App.REPORT","subsheets":[{"object_alias":"my.App.TOPIC","subsheets":[]}]}
     */
    protected function setSaveAs(UxonObject $uxon): DataSheetImportTool
    {
        $this->saveAsUxon = $uxon;
        $this->dataSchema = null;
        $this->dataSchemas = null;

        return $this;
    }

    public function getArguments(): array
    {
        if (! $this->hasSchemaConfiguration()) {
            return parent::getArguments();
        }

        return [
            (new ServiceParameter($this))
                ->setName(self::ARG_DATASHEET)
                ->setDescription('DataSheet payload to import. The JSON schema is generated from `save_as`.')
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Array']))
                ->setCustomProperties(new UxonObject([
                    'json_schema' => json_encode($this->getDataSheetArgumentSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ])),
        ];
    }

    /**
     * @return DataSheetSchema[]
     */
    protected function getDataSchemas(): array
    {
        if ($this->dataSchemas === null) {
            $this->dataSchemas = [];

            if ($this->saveAsUxon !== null) {
                if ($this->saveAsUxon->isArray()) {
                    if ($this->saveAsUxon->isEmpty()) {
                        throw new RuntimeException('UXON property "save_as" cannot be empty.');
                    }

                    foreach ($this->saveAsUxon as $idx => $targetUxon) {
                        if (! $targetUxon instanceof UxonObject) {
                            throw new RuntimeException('Invalid schema entry at $.save_as[' . $idx . ']. Expected UXON object.');
                        }

                        $schema = new DataSheetSchema($this->getWorkbench(), $targetUxon);
                        $this->validateSchemaNode($schema, '$.save_as[' . $idx . ']');
                        $this->dataSchemas[] = $schema;
                    }
                } else {
                    $schema = new DataSheetSchema($this->getWorkbench(), $this->saveAsUxon);
                    $this->validateSchemaNode($schema, '$.save_as');
                    $this->dataSchemas[] = $schema;
                }
            } else {
                throw new RuntimeException('ImportTool requires UXON property "save_as" as one schema object or an array of schema objects, each with at least "object_alias".');
            }

            if (empty($this->dataSchemas)) {
                throw new RuntimeException('ImportTool requires at least one save target schema.');
            }

            $this->dataSchema = $this->dataSchemas[0];
        }

        return $this->dataSchemas;
    }

    protected function getDataSchema(): DataSheetSchema
    {
        if ($this->dataSchema === null) {
            $this->dataSchema = $this->getDataSchemas()[0];
        }

        return $this->dataSchema;
    }

    protected function getDataSheetArgumentSchema(): array
    {
        $schemas = [];
        foreach ($this->getDataSchemas() as $schema) {
            $schemas[] = $schema->generateJsonSchema();
        }

        if (count($schemas) === 1) {
            return $schemas[0];
        }

        return ["type" => "array", "items" => [ "anyOf" => $schemas]];
    }

    protected function hasSchemaConfiguration(): bool
    {
        return $this->saveAsUxon !== null;
    }

    protected function validateSchemaNode(DataSheetSchema $schema, string $path): void
    {
        $objectAlias = $schema->getObjectAlias();
        if ($objectAlias === null || $objectAlias === '') {
            throw new RuntimeException('Missing required "object_alias" in ' . $path . '.');
        }

        $object = $this->getMetaObjectByAlias($objectAlias, $path);

        foreach ($schema->getRequiredAttributes() as $attributeAlias) {
            if ($attributeAlias === '') {
                throw new RuntimeException('Empty value found in "require_attributes" at ' . $path . '.');
            }
            if (! $object->hasAttribute($attributeAlias)) {
                throw new RuntimeException('Unknown required attribute "' . $attributeAlias . '" for object "' . $objectAlias . '" at ' . $path . '.');
            }
        }

        foreach ($schema->getSubsheets() as $index => $subsheetSchema) {
            $this->validateSchemaNode($subsheetSchema, $path . '.subsheets[' . $index . ']');
        }
    }

    protected function getMetaObjectByAlias(string $objectAlias, string $path): MetaObjectInterface
    {
        try {
            return $this->getWorkbench()->model()->getObject($objectAlias);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid "object_alias" "' . $objectAlias . '" at ' . $path . '.', 0, $e);
        }
    }
    

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_DATASHEET)
                ->setDescription('DataSheet payload to import. The JSON schema is generated from `save_as`.')
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Array'])),
        ];
    }

    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}