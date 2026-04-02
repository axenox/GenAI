<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\AI\Concepts\MetamodelDbmlConcept;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Templates\Placeholders\ArrayPlaceholders;
use exface\Core\Templates\BracketHashStringTemplateRenderer;

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
 *     "save_as": { // helper object \axenox\GenAI\Common\Prompt\DataSheetSchema
 *         "object_alias": "my.App.REPORT",
 *         "require_attributes": ["NO", "DATE", "TITLE"],
 *         "subsheets": [
 *              {
 *                  "object_alias": "my.App.TOPIC",
 *                  "subsheets": []
 *              }
 *         ]
 *     }
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
    private bool $autosaveData = false;
    
    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        $response = parent::handle($prompt);
        // Since $json complies with our JSONschema, we know, that it is a valid data sheet.
        $json = $response->getMessage();
        $dataSheet = DataSheetFactory::createFromObject($this->getDataSchema()->getMetaObject());
        $dataSheet->importUxonObject(UxonObject::fromJson($json));
        if ($this->willSaveData()) {
            $dataSheet->dataSave();
        }
        $response->setData($dataSheet);
        return $response;
    }
    
    protected function getDataSchema() : DataSheetSchema
    {
        // TODO
    }
    
    protected function willSaveData() : bool
    {
        return $this->autosaveData;
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
        
    }
}