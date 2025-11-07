<?php
namespace axenox\genAI\common;

use axenox\GenAI\Factories\AiTestingFactory;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\RegularExpressionDataType;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * 
 */
abstract class AbstractTestCriterion implements AiTestCriterionInterface
{
    use ImportUxonObjectTrait;
    
    private WorkbenchInterface $workbench;
    protected ?UxonObject $uxon = null;

    /** @var AiTestMetricInterface[] */
    private array $metrics;



    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon)
    {
        $this->workbench = $workbench;
        $this->uxon = $uxon;
        $this->importUxonObject($uxon);
    }

    /**
     * AI agents will be evaluated later using these metrics.
     * For questions: AITestRunHelper
     * 
     * @uxon-property metrics
     * @uxon-type \axenox\GenAI\Common\AbstractTestMetric[]
     * @uxon-template [{"type": ""}]
     */
    protected function setMetrics(UxonObject $uxon) : AiTestCriterionInterface
    {
        $list = $uxon->getPropertiesAll();
        foreach ($list as $name => $value) {
            $this->metrics[] = AiTestingFactory::createMetricFromUxon($this->workbench, $value);
        }
        return $this;
    }
    
    public function getMetrics() : array
    {
        return $this->metrics;
    }
    
    public function executeMetrics(string $aiTestResultOid, AiResponseInterface $result) : AiTestCriterionInterface
    {
        foreach ($this->metrics as $metricData) {
            $metricData->createAiTestMetric($aiTestResultOid,$result, $this);
        }

        return $this;
    }
    
    
    
    
    /**
     * @inheritDoc
     */
    public function exportUxonObject()
    {
        return $this->uxon;
    }

    abstract public function getValue(AiResponseInterface $result): string;
}