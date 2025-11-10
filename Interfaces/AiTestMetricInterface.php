<?php

namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;

interface AiTestMetricInterface extends iCanBeConvertedToUxon
{
    /**
     * @return string
     */
    public function getName() : string;

    /**
     * @param AiResponseInterface $response
     * @param AiTestCriterionInterface|null $criterion
     * @return AiTestRatingInterface
     */
    public function evaluate(AiResponseInterface $response, ?AiTestCriterionInterface $criterion = null) : AiTestRatingInterface;
}