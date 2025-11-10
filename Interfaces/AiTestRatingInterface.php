<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;

interface AiTestRatingInterface
{
    /**
     * @return AiTestMetricInterface
     */
    public function getMetric() : AiTestMetricInterface;

    /**
     * @return AiResponseInterface
     */
    public function getResponse() : AiResponseInterface;

    /**
     * @return AiTestCriterionInterface|null
     */
    public function getCriterion() : ?AiTestCriterionInterface;

    /**
     * @return int
     */
    public function getRating() : int;

    /**
     * @return string
     */
    public function getExplanation() : ?string;

    /**
     * @return string
     */
    public function getExplanationPros(): ?string;

    /**
     * @return string
     */
    public function getExplanationCons(): ?string;    
}