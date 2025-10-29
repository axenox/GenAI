<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Common\FileReader;
use axenox\GenAI\Common\LinkRebaser;
use axenox\GenAI\Common\Selectors\AiToolSelector;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\TemplateRenderer\PlaceholderValueInvalidError;

class AppDocsConcept extends AbstractConcept
{
    private $appAlias = null;

    private $depth = 0;

    private $startingPage = null;

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface::resolve()
     */ 
    public function resolve(array $placeholders) : array
    {
        $phVals = [];
        $phVals[$this->getPlaceholder()] = $this->buildMarkdownDocs();
        return $phVals;
    }

    /**
     * Alias of the app to get the docs from
     * 
     * @uxon-property app_alias
     * @uxon-type metamodel:app
     * 
     * @param string $alias
     * @return \axenox\GenAI\AI\Concepts\AppDocsConcept
     */
    protected function setAppAlias(string $alias): AppDocsConcept
    {
        $this->appAlias = $alias;
        return $this;
    }

    protected function getAppAlias() : string
    {
        return $this->appAlias;
    }

    /**
     * Determines the depth of the file reading
     * 
     * @uxon-property depth
     * @uxon-type number
     * 
     * @param int $depth
     * @return AppDocsConcept
     */
    protected function setDepth(int $depth): AppDocsConcept
    {
        $this->depth = $depth;
        return $this;
    }

    protected function getDepth() : int
    {
        return $this->depth;
    }

    protected function buildMarkdownDocs() : string
    {
        $app = $this->getWorkbench()->getApp($this->getAppAlias());
        $pathToApp = $app->getDirectoryAbsolutePath();
        $pathToDocs = $pathToApp . DIRECTORY_SEPARATOR . 'Docs';

        if (! file_exists($pathToDocs)) {
            throw new PlaceholderValueInvalidError($this->getPlaceholder(), 'Docs not found for app "' . $this->getAppAlias() . '"');
        }
        if ($this->depth < 0) {
            return "";
        }
        $pathToIndex = $pathToDocs . DIRECTORY_SEPARATOR . $this->getStartingPage();

        $fileReader = new FileReader();
        $indexContent = $fileReader->readFile($pathToIndex);

        $baseUrl = $app->getDirectory();
        $linkRebaser = new LinkRebaser();
        // Tutorials/... -> exface/Core/Docs/Tutorials...
        $tableOfContents = $linkRebaser->rebaseRelativeLinks($indexContent , $pathToIndex, $baseUrl, $this->depth);

        $result = str_replace('\\', '\/', $tableOfContents);;
        return $result;
    }

    public function getTools() : array
    {
        $tool = [];
        $getDocsToolUxon = new UxonObject(
                            [
                                'name'=> 'GetDocs',
                                'description'=> 'Load markdown from our documentation by URL',
                                'arguments'=> [                
                                    [
                                        'name'=> 'url',
                                        'description'=> 'Markdown file URL - absolute (with https->//...) or relative to api/docs on this server',
                                        'data_type'=> [
                                            'alias'=> 'exface.Core.String'
                                        ]            
                                    ]    
                                ]
                            ]
                        );
        $tool[] = AiFactory::createToolFromSelector(new AiToolSelector($this->getWorkbench(), \axenox\GenAI\AI\Tools\GetDocsTool::class), $getDocsToolUxon);

        return $tool;
    }

    /**
     * 
     * @return string
     */
    protected function getStartingPage() : string
    {
        return $this->startingPage ?? 'index.md';
    }

    /**
     * Define the page to start the docs with
     * 
     * @uxon-property starting_page
     * @uxon-type string
     * 
     * @param string $page
     * @return AppDocsConcept
     */
    protected function setStartingPage(string $page) : AppDocsConcept
    {
        $this->startingPage = $page;
        return $this;
    }
}