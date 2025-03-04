<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use exface\Core\Exceptions\TemplateRenderer\PlaceholderValueInvalidError;

class AppDocsConcept extends AbstractConcept
{
    private $appAlias = null;

    private $depth = 0;
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
     * @param string $depth
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
        $pathToIndex = $pathToDocs . DIRECTORY_SEPARATOR . 'index.md';
        $content = file_get_contents($pathToIndex);

        return $content;
    }
}