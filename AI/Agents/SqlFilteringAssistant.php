<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\AI\Concepts\MetamodelDbmlConcept;
use exface\Core\Exceptions\RuntimeException;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Templates\Placeholders\ArrayPlaceholders;
use exface\Core\Templates\BracketHashStringTemplateRenderer;

class SqlFilteringAssistant extends GenericAssistant
{
    protected function getConcepts(AiPromptInterface $prompt, BracketHashStringTemplateRenderer $configRenderer) : array
    {
        $concepts = parent::getConcepts($prompt, $configRenderer);
        foreach ($concepts as $concept) {
            if ($concept instanceof MetamodelDbmlConcept) {
                if ($prompt->hasMetaObject()) {
                    $obj = $prompt->getMetaObject();
                    $targetConnectionAlias = $obj->getDataConnection()->getAliasWithNamespace();
                } else {
                    throw new RuntimeException('Cannot generate AI filter: no base object specified in prompt');
                }
                $objFilter = function(MetaObjectInterface $obj) use ($targetConnectionAlias) {
                    $isInTargetConnection = $obj->getDataConnection()->isExactly($targetConnectionAlias);
                    // TODO also only those, that are in the same database as the object we are filtering
                    return $isInTargetConnection;
                };
                $concept->setObjectFilterCallback($objFilter);
            }
        }
        $concepts[] = new ArrayPlaceholders([
            'main_table_address' => $prompt->getMetaObject()->getDataAddress()
        ]);
        return $concepts;
    }
}