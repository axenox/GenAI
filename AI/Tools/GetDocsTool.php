<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Exceptions\AiToolRuntimeWarning;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\CodeMarkdownPrinter;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Facades\DocsFacade;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
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
 *          "get_docs": {
 *              "alias": "axenox.GenAI.GetDocsTool",
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
class GetDocsTool extends AbstractAiTool
{
    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon = null)
    {
        parent::__construct($workbench, $uxon);

        // TODO also allow absolute URLs starting with https:// here
        $this->addSecurityCheck([
            ['startsWith' => 'api/docs']
        ])
        ->setSecurityFailureMessage('Bad URL! Local URLs MUST start with `api/docs`');
    }

    /**
     * E.g. 
     * - exface/Core/Docs/Tutorials/BookClub_walkthrough/index.md
     * - exface/Core/Docs/index.md
     * @var string
     */
    const ARG_URI = 'uri';

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($url) = $arguments;
        $url = str_replace('\\/', '/', $url);
        $url = ltrim($url, '/');
        
        if(! $this->checkSecurity($url)){
            $errorMsg = $this->getSecurityFailurMessage();
            $warning = new AiToolRuntimeWarning($this, $prompt, $errorMsg);
            return new AiToolResultString($this, $arguments, $errorMsg, $this->getReturnDataType(), [], [$warning]);
        }
        
        if(StringDataType::endsWith($url,"php")){
            $phpPrinter = new CodeMarkdownPrinter($this->getWorkbench(), $url);
            $markdown = $phpPrinter->getMarkdown();
            return new AiToolResultString($this, $arguments, $markdown, $this->getReturnDataType());
        }
        
        $docsFacade = FacadeFactory::createFromString(DocsFacade::class, $this->getWorkbench());
        $url = rtrim($url, '.');
        try{
            $md = $docsFacade->getDocsMarkdown($url);
            $result = new AiToolResultString($this, $arguments, $md, $this->getReturnDataType());

            // Some docs responses may return an error/warning page as markdown instead of throwing.
            if (preg_match('/\b(error|warning)\b/i', $md) === 1) {
                $warning = AiToolRuntimeWarning($this, $prompt, 'Docs markdown contains error/warning content for URL "' . $url . '"');
                $result->addException($warning);
            }

            return $result;
        }
        catch(\Throwable $e){
            if ($e instanceof ExceptionInterface) {
                $exception = $e;
            } else {
                $exception = new AiToolRuntimeError($this, $prompt, 'Failed to load docs markdown. ' . $e->getMessage(), null, $e);
            }

            $this->getWorkbench()->getLogger()->logException($exception);
            $errorMsg = 'ERROR: file not found!';
            return (new AiToolResultString($this, $arguments, $errorMsg, $this->getReturnDataType()))
                ->addException($exception);
        }
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

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}