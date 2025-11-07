<?php
namespace axenox\genAI\common;

use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * 
 */
abstract class AbstractTestMetric implements AiTestMetricInterface
{
    use ICanBeConvertedToUxonTrait;
    
    protected WorkbenchInterface $workbench;
    
    private ?string $type;
    private ?string $name;

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

    protected function checkIfNewRequest(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): AbstractTestMetric
    {
        $newResult = $criterion->getValue($response);

        if ($this->result !== $newResult || $this->aiTestResultOid !== $aiTestResultOid) {

            $this->aiTestResultOid = $aiTestResultOid;
            $this->result = $newResult;
            $this->rating = null;
            $this->explanation = null;
            $this->pros = null;
            $this->cons = null;
        }


        return $this;
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
        if(!$this->name) return $this->getName();
        return $this->name;
    }

    public function getWeight(): int
    {
        return 1;
        // TODO: Implement getWeight() method.
    }


    /**
     * @uxon-property weight
     * @uxon-type int
     *
     */
    public function setWeight(int $weight) : AiTestMetricInterface
    {
        // TODO: Implement setWeight() method.
        return $this;
    }

    /**
     * Create a new AI test result rating (same as createAITestResultRating).
     */
    abstract public function createAITestMetric(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): AiTestMetricInterface;

    /**
     * Create a new AI test result rating (same as createAITestMetric).
     */
    abstract public function createAITestResultRating(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): AiTestMetricInterface;

    /**
     * Get the numeric rating for a test result.
     */
    abstract public function getRating(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): int;

    /**
     * Get an explanation for the rating.
     */
    abstract public function getExplanation(string $aiTestResultOid, AiResponseInterface $response ,AiTestCriterionInterface $criterion): string;

    /**
     * Get the positive aspects of the test result.
     */
    abstract public function getPros(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): string;

    /**
     * Get the negative aspects of the test result.
     */
    abstract public function getCons(string $aiTestResultOid, AiResponseInterface $response,AiTestCriterionInterface $criterion): string;

    
}