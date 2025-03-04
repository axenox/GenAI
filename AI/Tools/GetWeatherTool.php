<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\UrlDataType;
use exface\Core\Facades\DocsFacade;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to request any file from our app docs.
 * 
 * The idea is to include a list of available docs files in the instructions for the LLM using
 * the `AppDocsConcept` and give it the possibility to request any of those files if needed.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 *  {
 *      "instructions": "You are an helpful assistant answering questions about a no-code platform for business web apps. Use here is an overview of the available documentation. [#app_docs_overview#]",
 *      "concepts: {
 *          "app_docs_overview": {
 *              "class": "axenox\\GenAI\\AI\\Concepts\\AppDocsConcept",
 *              "app_alias": "exface.Core",
 *              "depth": 0
 *          }
 *      },
 *      "tools": {
 *          "GetDocs": {
 *              "description": "Load markdown from our documentation by URL",
 *              "arguments": [
 *                  {
 *                      "name": "uri",
 *                      "description": "Markdown file URL - absolute (with https://...) or relative to api/docs on this server",
 *                      "data_type": {
 *                          "alias": "exface.Core.String"
 *                      }
 *                  }
 *              ]
 *          }
 *      }
 *  }
 * 
 * ```
 */
class GetWeatherTool extends AbstractAiTool
{
    /**
     * E.g. 
     * - exface/Core/Docs/Tutorials/BookClub_walkthrough/index.md
     * - exface/Core/Docs/index.md
     * @var string
     */
    const ARG_URI = 'uri';

    public function invoke(array $arguments): string
    {        
        return "29 Celcius";
    }

    /**
     * {@inheritDoc}
     * @see AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench) : array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_URI)
                ->setDescription('Markdown file URL - absolute (with https://...) or relative to api/docs on this server')
        ];
    }
}
