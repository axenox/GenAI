<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Uxon\AiConceptUxonSchema;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Base class for AI tools
 */
abstract class AbstractAiTool implements AiToolInterface
{
    use ImportUxonObjectTrait;

    protected $workbench = null;

    private $uxon = null;

    private $arguments = [];

    private $name = null;

    private $description = null;

    private $securitychecks = [];

    private $securityFailureMessage = 'This is not allowed';

    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        
        $this->uxon = $uxon;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    /**
     * Define the arguments of a tool call
     * 
     * @uxon-property arguments
     * @uxon-type \exface\Core\CommonLogic\Actions\ServiceParameter[]
     * @uxon-tempalte []
     * 
     * @param \exface\Core\CommonLogic\UxonObject $arrayOfServiceParams
     * @return AiToolInterface
     */
    protected function setArguments(UxonObject $arrayOfServiceParams) : AiToolInterface
    {
        foreach ($arrayOfServiceParams as $i => $uxon) {
            array_push($this->arguments, new ServiceParameter($this, $uxon));
        }
        return $this;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Returns the default set of arguments
     * 
     * This allows the designer of an assistant to only specify certain argument properties
     * while the rest will be taken from the templates.
     * 
     * @return ServiceParameter[]
     */
    abstract protected static function getArgumentsTemplates(WorkbenchInterface $workbench) : array;

    /**
     * 
     * @return string
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * The name of the tool or function
     * 
     * @uxon-property name
     * @uxon-type string
     * @uxon-required true
     * 
     * @param string $name
     * @return AiToolInterface
     */
    protected function setName(string $name) : AiToolInterface
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 
     * @return string
     */
    public function getDescription() : string
    {
        return $this->description;
    }

    /**
     * The description of the tool or function
     * 
     * @uxon-property description
     * @uxon-type string
     * @uxon-required true
     * 
     * @param string $description
     * @return AiToolInterface
     */
    protected function setDescription(string $description) : AiToolInterface
    {
        $this->description = $description;
        return $this;
    }


    /**
     * If the KI is only allowed to query certain things, you can set up comparisons here. 
     * 
     * @uxon-property checks
     * @uxon-type array
     * @uxon-required true
     * 
     * @param array $checks
     * @return AiToolInterface
     */
    protected function addSecurityCheck(array $checks): AiToolInterface
    {
        foreach ($checks as $check) {

            $allowedKeys = ['startsWith', 'endsWith', 'contains', 'equals'];
            $filtered = [];

            foreach ($allowedKeys as $key) {
                if (isset($check[$key])) {
                    $filtered[$key] = $check[$key];
                }
            }

            if (!empty($filtered)) {
                $this->securitychecks[] = $filtered;
            }
        }

        return $this;
    }

    protected function checkSecurity(string $value): bool
    {
        if (count($this->securitychecks) <= 0) {
            return true;
        }
        foreach ($this->securitychecks as $check) {
            foreach ($check as $type => $expected) {
                switch ($type) {
                    case 'startsWith':
                        if (str_starts_with($value, $expected)) {
                            return true;
                        }
                        break;
                    case 'endsWith':
                        if (str_ends_with($value, $expected)) {
                            return true;
                        }
                        break;
                    case 'contains':
                        if (str_contains($value, $expected)) {
                            return true;
                        }
                        break;
                    case 'equals':
                        if ($value === $expected) {
                            return true;
                        }
                        break;
                }
            }
        }
        return false;
    }

    /**
     * The response when the securityCheck goes wrong
     * 
     * @uxon-property message
     * @uxon-type string
     * @uxon-required true
     * 
     * @param string $message
     * @return AiToolInterface
     */
    protected function setSecurityFailureMessage(string $message) : AiToolInterface
    {
        $this->securityFailureMessage = $message;
        return $this;
    }

    public function getSecurityFailurMessage() : string
    {
        return $this->securityFailureMessage;
    }

    /**
     * 
     * @return WorkbenchInterface
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    /**
     * 
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return $this->uxon;
    }

    /**
     * Returns the default UXON template fo this too (e.g. for autosuggest templates)
     * 
     * @return UxonObject
     */
    public static function exportUxonTemplate(WorkbenchInterface $workbench) : UxonObject
    {
        $uxon = new UxonObject();
        $uxon->setProperty('description', '');
        foreach (self::getArgumentsTemplates($workbench) as $param) {
            $uxon->appendToProperty('arguments', $param->exportUxonObject());
        }
        return $uxon;
    }

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::getUxonSchemaClass()
     */
    public static function getUxonSchemaClass() : ?string
    {
        return AiConceptUxonSchema::class;
    }

    /**
     * PHP class of the tool
     * 
     * @uxon-property class
     * @uxon-type string
     * @uxon-template \axenox\GenAI\AI\Tools\GetDocsTool
     * 
     * @param string $class
     * @return AiToolInterface
     */
    protected function setClass(string $class) : AiToolInterface
    {
        // Do nothing - this is just to make importUxon() work
        return $this;
    }
}