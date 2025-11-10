<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiTestRatingInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;

class AiTestRating implements AiTestRatingInterface
{
    private AiTestMetricInterface $metric;
    private ?AiTestCriterionInterface $criterion = null;
    private AiResponseInterface $response;
    private int $rating;
    
    private ?string $explanation = null;
    private ?string $pros = null;
    private ?string $cons = null;
    
    public function __construct(AiResponseInterface $response, AiTestMetricInterface $metric, int $rating, ?AiTestCriterionInterface $criterion = null)
    {
        $this->response = $response;
        $this->metric = $metric;
        $this->criterion = $criterion;
        $this->rating = $rating;
    }
    
    /**
     * @inheritDoc
     */
    public function getMetric(): AiTestMetricInterface
    {
        return $this->metric;
    }

    /**
     * @inheritDoc
     */
    public function getResponse(): AiResponseInterface
    {
        return $this->response;
    }

    /**
     * @inheritDoc
     */
    public function getCriterion(): ?AiTestCriterionInterface
    {
        return $this->criterion;
    }

    /**
     * @inheritDoc
     */
    public function getRating(): int
    {
        return $this->rating;
    }

    /**
     * @inheritDoc
     */
    public function getExplanation(): ?string
    {
        return $this->explanation;
    }

    /**
     * @inheritDoc
     */
    public function getExplanationPros(): ?string
    {
        return $this->pros;
    }

    /**
     * @inheritDoc
     */
    public function getExplanationCons(): ?string
    {
        return $this->cons;
    }
    
    // TODO add setter methods
}