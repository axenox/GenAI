<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\DataSheetSchema;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Interfaces\Widgets\iHaveColumns;
use exface\Core\Interfaces\Widgets\iShowData;

/**
 * Asks the AI to transform some data (even files) into data sheets.
 * 
 * This agent will automatically append some additional instructions to make the LLM focus on content
 * generation.
 * 
 * ## Examples
 * 
 * Read a PDF report and save it to a complex metaobject
 * 
 * ```
 * {
 *     "instructions": "",
 *     "silent": true,
 *     "data_schemas": [{ // helper object \axenox\GenAI\Common\Prompt\DataSheetSchema
 *         "object_alias": "my.App.REPORT",
 *         "require_attributes": ["NO", "DATE", "TITLE"], //Not Possible
 *         "subsheets": [
 *              {
 *                  "object_alias": "my.App.TOPIC",
 *                  "subsheets": []
 *              }
 *         ]
 *     }]
 * }
 * 
 * ```
 * 
 * It will use the metamodel of the object to generate a JSON schema and will automatically provide to the
 * LLM and force it to return only data and only adhering to the required schema.
 * 
 * In addition to the schema, the agent will automatically provide a Markdown description of the target object(s)
 * without the need to include corresponding concepts.
 * 
 * ## Silent mode
 * 
 * The agent cannot ask question in silent mode. If it cannot to its work for any reason, it should
 * throw and error. The agent will tell the LLM to skip unclear data. If required fields cannot be filled, it
 * should return a meaningful error message, that will be transformed into an exception.
 * 
 */
class ImportAgent extends GenericAssistant
{
    private array $additionalMessages = [];
    
    private bool $silenced = false;
    
    private bool $autosaveData = false;
    
    private bool $readyToSave = false;

    private ?UxonObject $saveAsUxon = null;

    private ?UxonObject $dataSchemasUxon = null;

    private ?DataSheetSchema $dataSchema = null;

    /**
     * @var DataSheetSchema[]|null
     */
    private ?array $dataSchemas = null;

    private string $messageSchemaDescription = 'Message returned by the AI. This may contain confirmation, missing information, follow-up questions, or other user-facing text.';

    private string $readyToSaveSchemaDescription = 'Indicates whether the AI considers the data complete enough to be saved.';

    private bool $showMessageOnly = true;
    
    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        $jsonSchema = $this->enrichJsonSchemaWithStandartVariabels(
            $this->getDataPayloadSchema($prompt)
        );
        
        $this->setResponseJsonSchema(new UxonObject($jsonSchema));

        $response = parent::handle($prompt);
        // Since $json complies with our JSON schema, we know that at least the data payload is valid.
        $json = $response->getJson();
        $payload = $json['data'] ?? $json;

        $this->readyToSave = (bool) ($json['ready_to_save'] ?? false);
        
        $importedSheet = $this->dataSave(UxonObject::fromAnything($payload));
        $response->setData($importedSheet);

        if($this->willSaveData()){
            $response->addOKStatusMessage('Data saved successfully.');
        }else{
            $response->addErrorStatusMessage('Data not saved.');
        }
        
        return $response;
    }
    
    protected function dataSave(UxonObject $uxon, ?DataSheetSchema $dataSheetSchema = null) : DataSheetInterface
    {
        $importedSheet = null;

        if ($this->isMultipleSchemaConfiguration() && $uxon->isArray()) {
            foreach ($uxon as $item) {
                if (! $item instanceof UxonObject) {
                    continue;
                }
                $dataSheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $item);
                if ($this->willSaveData()) {
                    //TODO
                    //IDEE wenn ein Fehler passiert die KI erneut aufrufen mit zurückgeben des Fehlers. So könnte die KI selbstständig versuchen Fehler zu korrigieren
                    $dataSheet->dataSave();
                }
                $importedSheet ??= $dataSheet;
            }

            if ($importedSheet === null) {
                throw new RuntimeException('ImportAgent expected an array of UXON objects for "data_schemas" payload.');
            }

            return $importedSheet;
        }

        $dataSheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $uxon);
        
        if($this->willSaveData()){
            //TODO
            //IDEE wenn ein Fehler passiert die KI erneut aufrufen mit zurückgeben des Fehlers. So könnte die KI selbstständig versuchen Fehler zu korrigieren
            $dataSheet->dataSave();
        } 
        return $dataSheet;
    }

    
    protected function getReadyUxonForImport(UxonObject $uxon) : UxonObject
    {
        return new UxonObject(["rows" => [$uxon->toArray()]]);
    }

    /**
     * Target object schema used for import output.
     *
     * @uxon-property save_as
     * @uxon-type \axenox\GenAI\Common\DataSheetSchema
     * @uxon-template {"object_alias":"my.App.REPORT","subsheets":[{"object_alias":"my.App.TOPIC","subsheets":[]}]}
     */
    protected function setSaveAs(UxonObject $uxon) : ImportAgent
    {
        $this->saveAsUxon = $uxon;
        $this->dataSchemasUxon = null;
        $this->dataSchema = null;
        $this->dataSchemas = null;
        return $this;
    }

    /**
     * Target object schemas used for import output.
     *
     * @uxon-property data_schemas
     * @uxon-type \axenox\GenAI\Common\DataSheetSchema[]
     * @uxon-template [{"object_alias":"my.App.REPORT","subsheets":[{"object_alias":"my.App.TOPIC","subsheets":[]}]}]
     */
    protected function setDataSchemas(UxonObject $uxon) : ImportAgent
    {
        if (! $uxon->isArray()) {
            throw new RuntimeException('UXON property "data_schemas" must be an array of schema objects.');
        }

        if ($uxon->isEmpty()) {
            throw new RuntimeException('UXON property "data_schemas" cannot be empty.');
        }

        $this->dataSchemasUxon = $uxon;
        $this->saveAsUxon = null;
        $this->dataSchema = null;
        $this->dataSchemas = null;
        return $this;
    }

    /**
     * TODO allow multiple schemas - e.g. for master data pages with a split and multiple tables
     * 
     * ```
     * {
     *     "data_schemas":  [
     *          {
     *              "object_alias": "Obj1"
     *          },
     *          {
     *              "object_alias": "obj2
     *          }
     *      ]
     * }
     * ```
     * 
     * @param AiPromptInterface $prompt
     * @return DataSheetSchema
     */
    protected function getDataSchemas(AiPromptInterface $prompt) : array
    {
        if ($this->dataSchemas === null) {
            $this->dataSchemas = [];

            if ($this->dataSchemasUxon !== null) {
                foreach ($this->dataSchemasUxon as $idx => $schemaUxon) {
                    if (! $schemaUxon instanceof UxonObject) {
                        throw new RuntimeException('Invalid schema entry at $.data_schemas[' . $idx . ']. Expected UXON object.');
                    }

                    $schema = new DataSheetSchema($this->getWorkbench(), $schemaUxon);
                    $this->validateSchemaNode($schema, '$.data_schemas[' . $idx . ']');
                    $this->dataSchemas[] = $schema;
                }
            } elseif ($this->saveAsUxon !== null) {
                $schema = new DataSheetSchema($this->getWorkbench(), $this->saveAsUxon);
                $this->validateSchemaNode($schema, '$.save_as');
                $this->dataSchemas[] = $schema;
            } else {
                switch (true) {
                    case $prompt->isTriggeredOnPage():
                        $page = $prompt->getPageTriggeredOn();
                        $rootWidget = $page->getWidgetRoot();
                        if ($rootWidget) {
                            $this->dataSchemas = $this->findSchemasInWidget($rootWidget);
                        }
                        break;
                    default:
                        throw new RuntimeException('ImportAgent requires UXON property "save_as" (object) or "data_schemas" (array), each with at least "object_alias".');
                }
            }

            if (empty($this->dataSchemas)) {
                throw new RuntimeException('ImportAgent requires at least one data schema.');
            }

            $this->dataSchema = $this->dataSchemas[0];
        }

        return $this->dataSchemas;
    }

    protected function getDataSchema(AiPromptInterface $prompt) : DataSheetSchema
    {
        if ($this->dataSchema === null) {
            $this->dataSchema = $this->getDataSchemas($prompt)[0];
        }

        return $this->dataSchema;
    }

    protected function getDataPayloadSchema(AiPromptInterface $prompt): array
    {
        $schemas = [];
        foreach ($this->getDataSchemas($prompt) as $schema) {
            $schemas[] = $schema->generateJsonSchema();
        }

        if (count($schemas) === 1) {
            return $schemas[0];
        }

        return ["type" => "array", "items" => ["anyOf" => $schemas]];
    }

    protected function isMultipleSchemaConfiguration(): bool
    {
        return $this->dataSchemasUxon !== null;
    }

    /**
     * TODO add all required attributes even if they are not in the data widget. See similar problem in ShowDataImportDialog
     *  
     * @param WidgetInterface $widget
     * @return array
     */
    protected function findSchemasInWidget(WidgetInterface $widget) : array
    {
        $schemas = [];
        switch (true) {
            case $widget instanceof iHaveColumns:
                $schema = new DataSheetSchema($this->getWorkbench(), new UxonObject([
                    'object_alias' => $widget->getMetaObject()->getAliasWithNamespace(),
                ]));
                $schemaAttrAliases = [];
                foreach ($widget->getColumns() as $col) {
                    if ($col->isBoundToAttribute()) {
                        $attr = $col->getAttribute();
                        if ($attr->isRelated()) {
                            $firstRel = $attr->getRelationPath()->getRelationFirst();
                            if($firstRel->isReverseRelation()){
                                $subSheet = new DataSheetSchema($this->getWorkbench(), new UxonObject([
                                    'object_alias' => $firstRel->getRightObject()->getAliasWithNamespace(),
                                ]), $schema);
                                $schema->addSubsheet($subSheet);
                            }
                            
                            if (! $firstRel->isForwardRelation()) {
                                continue;
                            }
                            if (! in_array($firstRel->getAlias(), $schemaAttrAliases)) {
                                $attr = $firstRel->getLeftKeyAttribute();
                            }
                        }
                        if (! $attr->isWritable()) {
                            continue;
                        }
                        $schemaAttrAliases[] = $attr->getAlias();
                    }
                }
                $schema->setRequireAttributes($schemaAttrAliases);
                $schemas[] = $schema;
                break;
            case $widget instanceof iContainOtherWidgets:
                foreach ($widget->getWidgets() as $child) {
                    $schemas = array_merge($schemas, $this->findSchemasInWidget($child));
                }
                break;
        }
        return $schemas;
    }

    protected function validateSchemaNode(DataSheetSchema $schema, string $path) : void
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

    protected function getMetaObjectByAlias(string $objectAlias, string $path) : MetaObjectInterface
    {
        try {
            return $this->getWorkbench()->model()->getObject($objectAlias);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid "object_alias" "' . $objectAlias . '" at ' . $path . '.', 0, $e);
        }
    }
    
    protected function willSaveData() : bool
    {
        return $this->autosaveData  &&  $this->readyToSave || $this->autosaveData && $this->silenced;
    }
    
    /**
     * Returns the JSON schema for a DataSheet based on the given object
     * 
     * The response JSON should look like this:
     * 
     * ```
     *  {
     *      "object_alias": "my.App.REPORT",
     *      "rows": [
     *          {
     *              "NO": "",
     *              "DATE": "",
     *              "TITLE": "",
     *              "TOPIC": { // There is a relation from my.App.TOPIC to REPORT. So one report can contain multipl topics as subsheets
     *                  {
     *                      "object_alias": "my.App.TOPIC":
     *                      "rows": [
     *                          {"TITLE": "", "DESCRIPTION": ""} // Attributes of the topic object
     *                      ]
     *                  }
     *              } 
     *          }
     *      ]
     *  }
     * 
     * ```
     * 
     * The JSON schema would be:
     * 
     * ```
     * {
     *  "$schema": "https://json-schema.org/draft/2020-12/schema",
     *  "type": "object",
     *  "required": ["object_alias", "rows"],
     *  "additionalProperties": false,
     *  "properties": {
     *      "object_alias": { // Object alias is fixed by the agent config
     *          "type": "string"
     *      },
     *      "rows": {
     *          "type": "array",
     *          "items": {
     *              "type": "object",
     *              "required": ["NO", "DATE", "TITLE"], // All required attributes: $obj->getAttributes()->getRequired()
     *              "additionalProperties": false,
     *              "properties": { // All writable attributes of the object
     *                  "NO": {
     *                      "type": "string" // see JsonDataType::convertDataTypeToJsonSchema()
     *                  },
     *                  "DATE": {
     *                      "type": "string"
     *                  },
     *                  "TITLE": {
     *                      "type": "string"
     *                  },
     *                  "TOPIC": { // Subsheet for topics
     *                      "type": "object",
     *                          "required": ["object_alias", "rows"],
     *                          "additionalProperties": false,
     *                          "properties": {},
     *                          "rows": {}
     *                      }
     *                  }
     *              }
     *          }
     *      }
     *  }
     * }
     * 
     * ```  
     * 
     * @param MetaObjectInterface $object
     * @return \stdClass
     */
    protected function getJsonSchema(MetaObjectInterface $object) : \stdClass
    {
        return json_decode(json_encode($this->enrichJsonSchemaWithStandartVariabels($this->getDataSchema()->generateJsonSchema())));
    }

    /**
     * If set to true, this enables silent mode, which does not return any messages but only the requested JSON, and the AI does not ask whether to save the data.
     *
     * @uxon-property silenced
     * @uxon-type bool
     * @uxon-default false
     *
     * @param bool $trueOrFalse
     * @return ImportAgent
     */
    protected function setSilenced(bool $trueOrFalse) : ImportAgent
    {
        $this->silenced = $trueOrFalse;
        return $this;
    }

    /**
     * If set to true, imported data is saved automatically (still requires a approval from AI).
     *
     * @uxon-property auto_save
     * @uxon-type bool
     * @uxon-default false
     *
     * @param bool $trueOrFalse
     * @return ImportAgent
     */
    protected function setAutoSave(bool $trueOrFalse) : ImportAgent
    {
        $this->autosaveData = $trueOrFalse;
        return $this;
    }
    

    /**
     * Description for response property `message` in the generated JSON schema.
     *
     * @uxon-property message_description
     * @uxon-type string
     *
     * @param string $description
     * @return ImportAgent
     */
    protected function setMessageDescription(string $description) : ImportAgent
    {
        $this->messageSchemaDescription = $description;
        return $this;
    }

    /**
     * Description for response property `ready_to_save` in the generated JSON schema.
     *
     * @uxon-property ready_to_save_description
     * @uxon-type string
     *
     * @param string $description
     * @return ImportAgent
     */
    protected function setReadyToSaveDescription(string $description) : ImportAgent
    {
        $this->readyToSaveSchemaDescription = $description;
        return $this;
    }

    /**
     * If `true`, only `$.message` is shown as answer output. If `false`, the full JSON is shown.
     *
     * @uxon-property show_message_only
     * @uxon-type bool
     * @uxon-default true
     *
     * @param bool $trueOrFalse
     * @return ImportAgent
     */
    protected function setShowMessageOnly(bool $trueOrFalse) : ImportAgent
    {
        $this->showMessageOnly = $trueOrFalse;
        return $this;
    }


    /**
     * Enriches a given JSON Schema with standardized wrapper fields used by the system.
     *
     * This method takes an existing schema (intended to describe the structure of the
     * `data` payload) and wraps it into a higher-level response schema. Depending on
     * the configuration (`$this->silenced`, `$this->showMessageOnly`), additional
     * metadata fields are injected.
     *
     * Behavior:
     * - Always wraps the provided schema under the root property `data`
     * - Optionally adds:
     *   - `message` (string): Human-readable response message
     *   - `ready_to_save` (boolean): Indicates whether the payload is considered complete
     * - Dynamically adjusts the `required` fields accordingly
     * - Disables any unknown properties via `additionalProperties = false`
     * - If `showMessageOnly` is enabled, internal response paths are redirected to `$.message`
     *
     * ---
     * BEFORE (input schema):
     *
     * {
     *   "type": "object",
     *   "properties": {
     *     "name": { "type": "string" },
     *     "age":  { "type": "number" }
     *   },
     *   "required": ["name"]
     * }
     *
     * ---
     * AFTER (enriched schema, silenced = false):
     *
     * {
     *   "type": "object",
     *   "required": ["data", "message", "ready_to_save"],
     *   "additionalProperties": false,
     *   "properties": {
     *     "data": {
     *       "type": "object",
     *       "properties": {
     *         "name": { "type": "string" },
     *         "age":  { "type": "number" }
     *       },
     *       "required": ["name"]
     *     },
     *     "message": {
     *       "type": "string",
     *       "description": "..."
     *     },
     *     "ready_to_save": {
     *       "type": "boolean",
     *       "description": "..."
     *     }
     *   }
     * }
     *
     * ---
     * AFTER (enriched schema, silenced = true):
     *
     * {
     *   "type": "object",
     *   "required": ["data"],
     *   "additionalProperties": false,
     *   "properties": {
     *     "data": {
     *       "type": "object",
     *       "properties": {
     *         "name": { "type": "string" },
     *         "age":  { "type": "number" }
     *       },
     *       "required": ["name"]
     *     }
     *   }
     * }
     *
     * Summary:
     * This method standardizes API/AI responses by enforcing a consistent envelope
     * structure while preserving the original data schema.
     */
    protected function enrichJsonSchemaWithStandartVariabels(array $dataSchema): array
    {
        $dataKey        = 'data';
        $messageKey     = 'message';
        $readyToSaveKey = 'ready_to_save';

        if ($this->showMessageOnly === true && ! $this->silenced) {
            $this->importUxonObject(new UxonObject([
                'response_answer_path' => '$.' . $messageKey,
                'response_title_path'  => '$.' . $messageKey,
            ]));
        }

        $properties = [
            $dataKey => $dataSchema,
        ];

        $required = [$dataKey];

        if (! $this->silenced) {
            $properties[$messageKey] = [
                'type' => 'string',
                'description' => $this->messageSchemaDescription,
            ];

            $properties[$readyToSaveKey] = [
                'type' => 'boolean',
                'description' => $this->readyToSaveSchemaDescription,
            ];

            $required[] = $messageKey;
            $required[] = $readyToSaveKey;
        }

        return [
            'type' => 'object',
            'required' => $required,
            'additionalProperties' => false,
            'properties' => $properties,
        ];
    }
}