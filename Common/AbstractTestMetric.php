<?php
namespace axenox\genAI\common;

use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * 
 */
abstract class AbstractTestMetric implements AiTestMetricInterface
{
    use ICanBeConvertedToUxonTrait;
    
    protected WorkbenchInterface $workbench;
    
    private ?string $type = null;
    private ?string $name = null;

    protected ?string $aiTestResultOid = null;

    protected ?string $result = null;

    protected ?int $rating = null;

    protected ?string $explanation = null;

    protected ?array $pros = null;

    protected ?array $cons = null;



    public function __construct(WorkbenchInterface $workbench, ?UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

   

    /**
     * @uxon-property type
     * @uxon-type metamodel:axenox.GenAI.AI_TEST_METRIC_PROTOTYPE:ALIAS_WITH_NS
     * 
     */
    protected function setType(string $type) : AiTestMetricInterface
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }
    
    

    /**
     * @uxon-property name
     * @uxon-type string
     *
     */
    protected function setName(string $name) : AiTestMetricInterface
    {
        $this->name = $name;
        return $this;

    }

    public function getName(): string
    {
        if (!$this->name) {
            $this->name = substr(strrchr($this->getType(), AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER), 1);
        }
        return $this->name;
    }


    public function getWeight(): int
    {
        return 1;
        // TODO: Implement getWeight() method.
    }


    /**
     * currently not available
     * 
     * @uxon-property weight
     * @uxon-type int
     *
     */
    public function setWeight(int $weight) : AiTestMetricInterface
    {
        // TODO: Implement setWeight() method.
        return $this;
    }
    
    
    
}