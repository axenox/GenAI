<?php

namespace axenox\GenAI\AI\Metrics\UxonConfigData;

use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

class ToolCheckData
{
    use ImportUxonObjectTrait;

    private string $name;
    private bool $required = true;
    private ?int $min_calls = null;
    private ?int $max_calls = null;
    /** @var array<string, mixed>|null */
    private ?array $arguments = null;
    private bool $allow_additional_arguments = false;


    private WorkbenchInterface $workbench;
    private ?UxonObject $uxon = null;

    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon)
    {
        $this->workbench = $workbench;
        $this->uxon = $uxon;
        $this->importUxonObject($uxon);
    }

    /**
     * Whether additional arguments are allowed
     *
     * @uxon-property allow_additional_arguments
     * @uxon-type bool
     */
    public function setAllowAdditionalArguments(bool $allow_additional_arguments): ToolCheckData
    {
        $this->allow_additional_arguments = $allow_additional_arguments;
        return $this;
    }

    /**
     * The tool name
     *
     * @uxon-property name
     * @uxon-type string
     */
    public function setName(string $name): ToolCheckData
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Whether the tool is required
     *
     * @uxon-property required
     * @uxon-type bool
     */
    public function setRequired(bool $required): ToolCheckData
    {
        $this->required = $required;
        return $this;
    }

    /**
     * Maximum number of allowed calls
     *
     * @uxon-property max_calls
     * @uxon-type int
     */
    public function setMaxCalls(int $max_calls): ToolCheckData
    {
        $this->max_calls = $max_calls;
        return $this;
    }

    /**
     * Minimum number of required calls
     *
     * @uxon-property min_calls
     * @uxon-type int
     */
    public function setMinCalls(int $min_calls): ToolCheckData
    {
        $this->min_calls = $min_calls;
        return $this;
    }

    /**
     * List of argument definitions
     *
     * @uxon-property arguments
     * @uxon-type \axenox\GenAI\AI\Metrics\UxonConfigData\ArgumentData[]
     */
    public function setArguments(UxonObject $arguments): ToolCheckData
    {
        $this->arguments = $arguments->getPropertiesAll();
        return $this;
    }

    
    
    public function isAllowAdditionalArguments(): bool
    {
        return $this->allow_additional_arguments;
    }

    /**
     * Returns the tool name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns whether the tool is required
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Returns the maximum number of calls
     */
    public function getMaxCalls(): int
    {
        if(!($this->max_calls)) return 99;
        return $this->max_calls;
    }

    /**
     * Returns the minimum number of calls
     */
    public function getMinCalls(): int
    {
        if(!$this->min_calls) return 1;
        return $this->min_calls;
    }

    /**
     * Returns the argument definitions
     */
    public function getArguments(): array
    {
        if(!$this->arguments) return [];
        return $this->arguments;
    }




    /**
     * @inheritDoc
     */
    public function exportUxonObject()
    {
        return $this->uxon;
    }
    
}