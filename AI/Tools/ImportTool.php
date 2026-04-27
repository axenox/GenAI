<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\DataSheetSchema;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class ImportTool extends AbstractAiTool
{
    public const ARG_DATASHEET = 'data_sheet';

    private ?UxonObject $saveAsUxon = null;

    private ?DataSheetSchema $dataSchema = null;

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        $payload = $arguments[0] ?? null;
        if ($payload === null) {
            return "Invalid Arguments";
        }

        $schema = $this->getDataSchema();
        $uxon = UxonObject::fromAnything($payload);

        if (! $uxon->hasProperty('object_alias')) {
            $uxon->setProperty('object_alias', $schema->getMetaObject()->getAliasWithNamespace());
        }

        $this->validatePayloadObjectAlias($uxon, $schema);

        $sheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $uxon);
        $sheet->dataSave();

        return 'Imported ' . count($sheet->getRows()) . ' row(s) into "' . $schema->getMetaObject()->getAliasWithNamespace() . '".';
    }

    /**
     * Target object schema used to validate and generate the `data_sheet` argument schema.
     *
     * @uxon-property save_as
     * @uxon-type \axenox\GenAI\Common\DataSheetSchema
     * @uxon-template {"object_alias":"my.App.REPORT","subsheets":[{"object_alias":"my.App.TOPIC","subsheets":[]}]}
     */
    protected function setSaveAs(UxonObject $uxon): ImportTool
    {
        $this->saveAsUxon = $uxon;
        $this->dataSchema = null;

        $this->ensureSchemaArgumentConfigured();
        
        return $this;
    }

    public function getArguments(): array
    {
        $this->ensureSchemaArgumentConfigured();
        return parent::getArguments();
    }

    public function getArgumentSchema(string $argumentName): ?array
    {
        if ($argumentName !== self::ARG_DATASHEET) {
            return null;
        }

        return $this->getDataSchema()->generateJsonSchema();
    }

    protected function ensureSchemaArgumentConfigured(): void
    {
        if ($this->saveAsUxon === null || ! empty(parent::getArguments())) {
            return;
        }

        $schemaJson = json_encode(
            $this->getDataSchema()->generateJsonSchema(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $argUxon = new UxonObject([
            'name' => self::ARG_DATASHEET,
            'description' => 'DataSheet payload to import. The JSON schema is generated from `save_as`.',
            'data_type' => [
                'alias' => 'exface.Core.Array',
            ],
            'custom_properties' => [
                'json_schema' => $schemaJson,
            ],
        ]);

        $argsUxon = new UxonObject();
        $argsUxon->append($argUxon);
        $this->setArguments($argsUxon);
    }

    protected function getDataSchema(): DataSheetSchema
    {
        if ($this->dataSchema === null) {
            if ($this->saveAsUxon === null) {
                throw new RuntimeException('ImportTool requires UXON property "save_as" with at least "object_alias".');
            }

            $this->dataSchema = new DataSheetSchema($this->getWorkbench(), $this->saveAsUxon);
            $this->validateSchemaNode($this->dataSchema, '$.save_as');
        }

        return $this->dataSchema;
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

    protected function validatePayloadObjectAlias(UxonObject $uxon, DataSheetSchema $schema): void
    {
        $objectAlias = (string) $uxon->getProperty('object_alias');
        $expectedAlias = $schema->getMetaObject()->getAliasWithNamespace();

        if ($objectAlias !== $expectedAlias) {
            throw new RuntimeException(
                'Invalid payload object_alias "' . $objectAlias . '". Expected "' . $expectedAlias . '" from save_as.'
            );
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