<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\AI\Tools\GetDocsTool;
use axenox\GenAI\Common\AbstractConcept;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\TemplateRenderer\PlaceholderValueInvalidError;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\DocMarkdownPrinter;

/**
 * Includes an app documentation page as markdown in agent instructions.
 * 
 * Use this concept to inject rendered docs of a selected app into a placeholder.
 * The content can start at a specific page and optionally normalize heading levels
 * to fit into surrounding instruction sections.
 * 
 * The rendered markdown can not only contain a single docs page, but also pages
 * linked to from within this page. Set `depth` > 0 to include linked pages.
 */
class AppDocsConcept extends AbstractConcept
{
    private $appAlias = null;

    private $depth = 0;

    private $startingPage = null;

    private $hideTitle = false;

    private ?int $headingLevel = null;


    protected function getOutput(): string
    {
        return $this->buildMarkdownDocs();
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
        $docPrinter = new DocMarkdownPrinter(
            $this->getWorkbench(),
            null,
            $this->getDepth(),
            $this->getAppAlias(),
            $this->getStartingPage()
        );
            
        if(!$docPrinter->docsExists()){
            throw new PlaceholderValueInvalidError($this->getPlaceholder(), 'Docs not found for app "' . $this->getAppAlias() . '"');
        }
        
        $markdown = $docPrinter->getMarkdown();

        $markdown = $this->hideTitleIfNeeded($markdown);

        if (null !== $this->getHeadingLevel()) {
            $markdown = MarkdownDataType::convertHeaderLevels($markdown, $this->getHeadingLevel());
        }

        $result = str_replace('\\', '\/', $markdown);
        return $result;
    }

    /**
     * Applies `hide_title` options to the rendered markdown.
     * 
     * The first line starting with `#` is considered the top-level title. If `hide_title`
     * is enabled, that line is removed entirely. 
     * 
     * @param string $markdown
     * @return string
     */
    protected function hideTitleIfNeeded(string $markdown) : string
    {
        if ($this->hideTitle === false) {
            return $markdown;
        }

        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        foreach ($lines as $i => $line) {
            if (preg_match('/^(#+)\s+/', $line, $matches) !== 1) {
                continue;
            }
            if ($this->hideTitle === true) {
                unset($lines[$i]);
            } 
            break;
        }

        return implode("\n", $lines);
    }

    /**
     * {@inheritDoc}
     * @see AbstractConcept::getToolModels()
     */
    public function getToolModels() : array
    {
        $tools = [];
        $tools['get_docs'] = new UxonObject(
            [
                'class' => '\\' . GetDocsTool::class,
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

        return $tools;
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

    /**
     * Remove the top-level title (the first line starting with `#`) completely.
     * 
     * @uxon-property hide_title
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $hide
     * @return AppDocsConcept
     */
    protected function setHideTitle(bool $hide) : AppDocsConcept
    {
        $this->hideTitle = $hide;
        return $this;
    }

    /**
     * Heading level for the highest heading in the resulting markdown.
     * 
     * Use this property to embed app docs under a specific heading depth in larger
     * instructions.
     * 
     * @uxon-property heading_level
     * @uxon-type integer
     * 
     * @param int $level
     * @return AppDocsConcept
     */
    protected function setHeadingLevel(int $level) : AppDocsConcept
    {
        if ($level < 1) {
            throw new PlaceholderValueInvalidError($this->getPlaceholder(), 'Invalid heading_level value "' . $level . '": heading levels must be greater than 0.');
        }

        $this->headingLevel = $level;
        return $this;
    }

    /**
     * @return int|null
     */
    protected function getHeadingLevel() : ?int
    {
        return $this->headingLevel;
    }
}