<?php

namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;

interface AiTestMetricInterface extends iCanBeConvertedToUxon
{
    public function getType():string;

    
    public function getName():string;

    
    public function getWeight():int;

    public function createAITestMetric(string $aiTestResultOid, AiResponseInterface $response , AiTestCriterionInterface $criterion) : AiTestMetricInterface;
    public function createAITestResultRating(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion) :AiTestMetricInterface; //the same like createAITestMetric
    
    public function getRating(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion) : int;
    public function getExplanation(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion) : string;
    public function getPros(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): string;
    public function getCons(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): string;
    
    
    
    
}