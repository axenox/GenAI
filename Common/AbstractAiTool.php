<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Common\Selectors\AiToolSelector;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Uxon\AiToolUxonSchema;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Interfaces\WorkbenchInterface;
use Wingu\OctopusCore\Reflection\ReflectionClass;

/**
 * Configure a named tool and refine the arguments advertised to an agent or MCP client.
 * 
 * Select a concrete tool prototype by alias, then describe its purpose and override
 * argument templates only where the current use case needs different metadata.
 * For example:
 * 
 * ```json
 * {
 *     "alias": "axenox.GenAI.GetTimeTool",
 *     "name": "CurrentTime",
 *     "description": "Read the current local time."
 * }
 * 
 * ```
 * 
 * @author GitHub Copilot
 */
abstract class AbstractAiTool implements AiToolInterface
{
    use ImportUxonObjectTrait;

    private $workbench = null;
    private ?string $alias = null;

    private $uxon = null;

    private $arguments = null;

    private $name = null;

    private $description = null;

    private $securitychecks = [];

    private $securityFailureMessage = 'This is not allowed';

    /**
     * Imports tool configuration in the supplied Workbench.
     * 
     * @param WorkbenchInterface $workbench
     * @param UxonObject|null $uxon
     * @return void
     */
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
     * @uxon-template [{"name": "", "description": ""}]
     * 
     * @param \exface\Core\CommonLogic\UxonObject $arrayOfServiceParams
     * @return AiToolInterface
     */
    protected function setArguments(UxonObject $arrayOfServiceParams) : AiToolInterface
    {
        $argTemplates = static::getArgumentsTemplates($this->getWorkbench());
        foreach ($arrayOfServiceParams as $i => $uxon) {
            $argParam = $argTemplates[$i] ?? null;
            if ($argParam !== null) {
                $argParam->importUxonObject($uxon);
            } else {
                $argParam = new ServiceParameter($this, $uxon);
            }
            $argTemplates[$i] = $argParam;
        }
        $this->arguments = array_values($argTemplates);
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getArguments()
     */
    public function getArguments(): array
    {
        if ($this->arguments === null) {
            $defaultArgs = static::getArgumentsTemplates($this->getWorkbench());
            if (! empty($defaultArgs)) {
                $this->arguments = $defaultArgs;
            }
        }
        return $this->arguments ?? [];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::normalizeArguments()
     */
    public function normalizeArguments(array $arguments) : array
    {
        $positional = array_is_list($arguments);
        $normalized = [];
        foreach ($this->getArguments() as $index => $parameter) {
            $name = $parameter->getName();
            $key = $positional ? $index : $name;
            $value = array_key_exists($key, $arguments) ? $arguments[$key] : $parameter->getDefaultValue();
            unset($arguments[$key]);
            if ($value === null && $parameter->isRequired()) {
                throw new UnexpectedValueException('Missing required argument "' . $name . '".');
            }
            if ($value instanceof UxonObject) {
                $value = $value->toArray();
            }
            if (is_array($value) || $value instanceof \stdClass) {
                // ServiceParameter::parseValue() only supports scalar values.
                $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            } elseif ($value !== null) {
                $value = $parameter->parseValue($value);
            }
            $normalized[] = $value;
        }
        if ($arguments !== []) {
            throw new UnexpectedValueException('Unknown arguments: ' . implode(', ', array_keys($arguments)));
        }
        return $normalized;
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getAppendix()
     */
    public function getAppendix() : array
    {
        return [];
    }

    /**
     * Returns the default set of arguments
     * 
     * This allows the designer of an assistant to only specify certain argument properties
     * while the rest will be taken from the templates.
     * 
     * @param WorkbenchInterface $workbench
     * @return ServiceParameter[]
     */
    abstract protected static function getArgumentsTemplates(WorkbenchInterface $workbench) : array;

    /**
     * Returns the function name advertised to the model.
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
     * Returns the configured description or the concrete prototype's default description.
     * 
     * @return string|null
     */
    public function getDescription() : ?string
    {
        return $this->description ?? $this->getDescriptionDefault();
    }

    /**
     * Returns the first line of the tool class docblock as its default description.
     *
     * @return string|null
     * @throws \ReflectionException
     */
    protected function getDescriptionDefault() : ?string
    {
        return (new ReflectionClass($this))->getReflectionDocComment()->getShortDescription();
    }

    /**
     * The description of the tool or function
     * 
     * @uxon-property description
     * @uxon-type string
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
     * Adds prototype-defined text comparisons without exposing a generic UXON property.
     * 
     * @param array<array-key,array{startsWith?:string,endsWith?:string,contains?:string,equals?:string}> $checks
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

    /**
     * Accepts a value matching any registered comparison, or any value if none exist.
     * 
     * @param string $value
     * @return bool
     */
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
     * Customize the rejection text for tools using the built-in text comparisons.
     * 
     * This changes the message, not the tool's access permissions.
     * 
     * @uxon-property security_failure_message
     * @uxon-type string
     * @uxon-default This is not allowed
     * 
     * @param string $message
     * @return AiToolInterface
     */
    protected function setSecurityFailureMessage(string $message) : AiToolInterface
    {
        $this->securityFailureMessage = $message;
        return $this;
    }

    /**
     * Returns the configured rejection text for failed text comparisons.
     * 
     * @return string
     */
    public function getSecurityFailurMessage() : string
    {
        return $this->securityFailureMessage;
    }

    /**
     * Returns the Workbench owning this configured tool.
     * 
     * @return WorkbenchInterface
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    /**
     * Returns the original tool configuration, if one was supplied.
     * 
     * @return UxonObject|null
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return $this->uxon;
    }

    /**
     * Builds a configuration template from the concrete tool's default arguments.
     * 
     * @param WorkbenchInterface $workbench
     * @return UxonObject
     */
    public static function exportUxonTemplate(WorkbenchInterface $workbench) : UxonObject
    {
        $uxon = new UxonObject();
        $uxon->setProperty('description', '');
        foreach (static::getArgumentsTemplates($workbench) as $param) {
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
        return AiToolUxonSchema::class;
    }

    /**
     * Alias of the tool prototype with namespace
     *
     * @uxon-property alias
     * @uxon-type metamodel:axenox.GenAI.AI_TOOL_PROTOTYPE:ALIAS_WITH_NS
     * @uxon-required true
     *
     * @param string $aliasWithNamespace
     * @return AiToolInterface
     */
    protected function setAlias(string $aliasWithNamespace) : AiToolInterface
    {
        $this->alias = $aliasWithNamespace;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getAliasWithNamespace()
     */
    public function getAliasWithNamespace() : string
    {
        return $this->alias ?? AiFactory::findToolAlias(new AiToolSelector($this->getWorkbench(), get_class($this)));
    }
    
    /**
     * Builds the designer-facing configuration model for this concrete tool prototype.
     * 
     * @param WorkbenchInterface $workbench
     * @return array<string,mixed>
     */
    public static function getTemplate(WorkbenchInterface $workbench) : array
    {
        $argsModels = [];
        foreach (static::getArgumentsTemplates($workbench) as $param) {
            $argModel = [
                'name' => $param->getName(),
                'description' => $param->getDescription()
            ];
            if ($param->hasExamples()) {
                $argModel['examples'] = $param->getExamples();
            }
            $argsModels[] = $argModel;
        }
        return [
            'alias' => AiFactory::findToolAlias(new AiToolSelector($workbench, static::class)),
            'description' => '',
            'arguments' => $argsModels,
        ];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getRules()
     */
    public function getRules() : ?string
    {
        return null;
    }
}