<?php

namespace axenox\GenAI\AI\Metrics;

use axenox\genAI\common\AbstractTestMetric;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\DataSheetFactory;

class TextMatchTestMetric extends  AbstractTestMetric
{

    /**
     * @var string|null Exact match text
     */
    protected ?string $equals = null;

    /**
     * @var string|null Exact match text (case-insensitive)
     */
    protected ?string $equalsIgnoreCase = null;

    /**
     * @var string[]|null All substrings that must be contained (AND)
     */
    protected ?array $containsAll = null;

    /**
     * @var string[]|null Any substrings that may be contained (OR)
     */
    protected ?array $containsAny = null;

    /**
     * @var string[]|null Substrings that must not be contained
     */
    protected ?array $notContainsAny = null;

    /**
     * @var string|null Required prefix
     */
    protected ?string $startsWith = null;

    /**
     * @var string[]|null Any acceptable prefixes
     */
    protected ?array $startsWithAny = null;

    /**
     * @var string|null Required suffix
     */
    protected ?string $endsWith = null;

    /**
     * @var string[]|null Any acceptable suffixes
     */
    protected ?array $endsWithAny = null;

    /**
     * @var string|null Substring that must be contained
     */
    protected ?string $contains = null;

    /**
     * @var string|null Substring that must be contained (case-insensitive)
     */
    protected ?string $containsIgnoreCase = null;
    
    public function createAITestMetric(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): AiTestMetricInterface
    {
        return $this->createAITestResultRating($aiTestResultOid, $response, $criterion);
    }

    public function createAITestResultRating(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): AiTestMetricInterface
    {
        $this->checkIfNewRequest($aiTestResultOid, $response, $criterion);

        //$transaction = $this->workbench->data()->startTransaction();
        $resultRatingSheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_TEST_RESULT_RATING');
        
        $row = [
            'NAME' => $this->getName(),
            'RATING' => $this->getRating($aiTestResultOid, $response, $criterion),
            'AI_TEST_RESULT' => $this->aiTestResultOid,
            'RAW_VALUE' => $this->getResult($response, $criterion),
            'EXPLANATION' => $this->getExplanation($aiTestResultOid, $response, $criterion),
            'PROS' => $this->getpros($aiTestResultOid, $response, $criterion),
            'CONS' => $this->getcons($aiTestResultOid, $response, $criterion),
        ];
        
        $resultRatingSheet->addRow($row);
        
        $resultRatingSheet->dataCreate();
        

        //$transaction->commit();
        
        return $this;
        
    }

    public function getRating(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): int
    {
        $this->checkIfNewRequest($aiTestResultOid, $response, $criterion);
        if($this->rating) return $this->rating;
        
        $value = $this->getResult($response, $criterion);

        /** @var array<string, int> $ratings */
        $ratings = $this->getRatings($value);

        $sum = 0;
        $count = 0;

        $setPros = false;
        if(!$this->pros) $setPros = true;
        
        $setCons = false;
        if(!$this->cons) $setCons = true;
        
        foreach ($ratings as $key => $rating) {
            if ($rating === 3) {
                // neutral value, do not include in average
                continue;
            }

            if ($rating === 5) {
                if($setPros)$this->pros[] = "{$key}: All conditions fulfilled ({$rating})";
            } elseif ($rating === 1) {
                if($setCons)$this->cons[] = "{$key}: Some conditions not fulfilled ({$rating})";
            }

            $sum += $rating;
            $count++;
        }

        if ($count === 0) {
            // no active criteria, all metrics were neutral
            return 3;
        }

        // round normally (0.5 and above goes up)
        return (int) floor($sum / $count);
    }

    public function getExplanation(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): string
    {
        $this->checkIfNewRequest($aiTestResultOid, $response, $criterion);
        // TODO: Implement method.
        return 'Look in Pros & Cons';
    }

    public function getPros(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): string
    {
        $this->checkIfNewRequest($aiTestResultOid, $response, $criterion);

        if(!$this->pros){
            $value = $this->getResult($response, $criterion);
            foreach ($this->getRatings($value) as $key => $rating){
                if ($rating === 5) {
                    $this->pros[] = "{$key}: All conditions fulfilled ({$rating})";
                }
            }
        }

        if(!$this->pros) return '';
        return implode("\n", $this->pros);

    }

    public function getCons(string $aiTestResultOid, AiResponseInterface $response, AiTestCriterionInterface $criterion): string
    {
        $this->checkIfNewRequest($aiTestResultOid, $response, $criterion);
        
        if(!$this->cons){
            
            $value = $this->getResult($response, $criterion);
            foreach ($this->getRatings($value) as $key => $rating){
                if ($rating === 1) {
                    $this->cons[] = "{$key}: Some conditions not fulfilled ({$rating})";
                }
            }
        }
        if(!$this->cons) return '';
        return implode("\n", $this->cons);
    }
    
    private function getResult(AiResponseInterface $response, AiTestCriterionInterface $criterion): string
    {
        if(!$this->result){
            $this->result = $criterion->getValue($response);
        }
        return $this->result;
    }

    /**
     * @param string $value
     * @return array{
     *     Contains: int,
     *     ContainsNot: int,
     *     StartsWith: int,
     *     EndsWith: int,
     *     Equals: int
     * }
     */
    private function getRatings(string $value): array
    {
        return [
            'Contains'    => $this->getContains($value),
            'ContainsNot' => $this->getContainsNot($value),
            'StartsWith'  => $this->getStartsWith($value),
            'EndsWith'    => $this->getEndWith($value),
            'Equals'      => $this->getEquals($value),
        ];
    }

    /**
     * Evaluate all "contains" based criteria for the given value.
     *
     * Returns a score between 1 (worst) and 5 (best).
     * If no contains criteria are configured it returns 3.
     *
     * @param string $value
     * @return int
     */
    protected function getContains(string $value) : int
    {
        $hasConfig = false;

        // containsAll: every configured substring must be present
        if ($this->containsAll !== null && \count($this->containsAll) > 0) {
            $hasConfig = true;

            foreach ($this->containsAll as $needle) {
                if ($needle === '') {
                    continue;
                }

                if (\mb_strpos($value, $needle) === false) {
                    return 1;
                }
            }
        }

        // contains: simple substring requirement (case sensitive)
        if ($this->contains !== null && $this->contains !== '') {
            $hasConfig = true;

            if (\mb_strpos($value, $this->contains) === false) {
                return 1;
            }
        }

        // containsIgnoreCase: simple substring requirement, case insensitive
        if ($this->containsIgnoreCase !== null && $this->containsIgnoreCase !== '') {
            $hasConfig = true;

            if (\mb_stripos($value, $this->containsIgnoreCase) === false) {
                return 1;
            }
        }

        // containsAny: at least one of the configured substrings must be present
        if ($this->containsAny !== null && \count($this->containsAny) > 0) {
            $hasConfig = true;

            $matched = false;
            foreach ($this->containsAny as $needle) {
                if ($needle === '') {
                    continue;
                }

                if (\mb_strpos($value, $needle) !== false) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                // workaround for containsAny: if none of them is found, treat as hard fail
                return 1;
            }
        }

        if (!$hasConfig) {
            // nothing configured for contains metrics
            return 3;
        }

        // all configured conditions passed
        return 5;
    }

    /**
     * Evaluate negative contains criteria for the given value.
     *
     * Uses notContainsAny. If any forbidden substring is found it returns 1.
     * If none of the forbidden substrings are present it returns 5.
     * If no negative criteria are configured it returns 3.
     *
     * @param string $value
     * @return int
     */
    protected function getContainsNot(string $value) : int
    {
        if ($this->notContainsAny === null || \count($this->notContainsAny) === 0) {
            return 3;
        }

        foreach ($this->notContainsAny as $needle) {
            if ($needle === '') {
                continue;
            }

            if (\mb_strpos($value, $needle) !== false) {
                // forbidden substring found
                return 1;
            }
        }

        // no forbidden substring found
        return 5;
    }

    /**
     * Evaluate "starts with" criteria for the given value.
     *
     * Uses startsWith and startsWithAny.
     * If any configured requirement fails it returns 1.
     * If all configured requirements pass it returns 5.
     * If nothing is configured it returns 3.
     *
     * @param string $value
     * @return int
     */
    protected function getStartsWith(string $value) : int
    {
        $hasConfig = false;

        if ($this->startsWith !== null && $this->startsWith !== '') {
            $hasConfig = true;

            $prefix = $this->startsWith;
            $length = \mb_strlen($prefix);

            if ($length === 0 || \mb_substr($value, 0, $length) !== $prefix) {
                return 1;
            }
        }

        if ($this->startsWithAny !== null && \count($this->startsWithAny) > 0) {
            $hasConfig = true;

            $matched = false;

            foreach ($this->startsWithAny as $prefix) {
                if ($prefix === '') {
                    continue;
                }

                $length = \mb_strlen($prefix);

                if ($length > 0 && \mb_substr($value, 0, $length) === $prefix) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return 1;
            }
        }

        if (!$hasConfig) {
            return 3;
        }

        return 5;
    }

    /**
     * Evaluate "ends with" criteria for the given value.
     *
     * Uses endsWith and endsWithAny.
     * If any configured requirement fails it returns 1.
     * If all configured requirements pass it returns 5.
     * If nothing is configured it returns 3.
     *
     * @param string $value
     * @return int
     */
    protected function getEndWith(string $value) : int
    {
        $hasConfig = false;

        if ($this->endsWith !== null && $this->endsWith !== '') {
            $hasConfig = true;

            $suffix = $this->endsWith;
            $length = \mb_strlen($suffix);
            $valueLength = \mb_strlen($value);

            if ($length === 0 || $length > $valueLength) {
                return 1;
            }

            if (\mb_substr($value, $valueLength - $length, $length) !== $suffix) {
                return 1;
            }
        }

        if ($this->endsWithAny !== null && \count($this->endsWithAny) > 0) {
            $hasConfig = true;

            $matched = false;
            $valueLength = \mb_strlen($value);

            foreach ($this->endsWithAny as $suffix) {
                if ($suffix === '') {
                    continue;
                }

                $length = \mb_strlen($suffix);

                if ($length === 0 || $length > $valueLength) {
                    continue;
                }

                if (\mb_substr($value, $valueLength - $length, $length) === $suffix) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return 1;
            }
        }

        if (!$hasConfig) {
            return 3;
        }

        return 5;
    }

    /**
     * Evaluate equality criteria for the given value.
     *
     * Uses equals and equalsIgnoreCase.
     * If neither is configured it returns 3.
     * If a configured condition does not match it returns 1.
     * If all configured equality conditions match it returns 5.
     *
     * @param string $value
     * @return int
     */
    protected function getEquals(string $value) : int
    {
        $hasConfig = false;

        if ($this->equals !== null) {
            $hasConfig = true;

            if ($value !== $this->equals) {
                return 1;
            }
        }

        if ($this->equalsIgnoreCase !== null) {
            $hasConfig = true;

            if (\mb_strtolower($value) !== \mb_strtolower($this->equalsIgnoreCase)) {
                return 1;
            }
        }

        if (!$hasConfig) {
            return 3;
        }

        return 5;
    }


    /**
     * Exact match
     *
     * @uxon-property equals
     * @uxon-type string
     *
     * @param string $text
     * @return TextMatchTestMetric
     */
    protected function setEquals(string $text) : TextMatchTestMetric
    {
        $this->equals = $text;
        return $this;
    }

    /**
     * Exact match ignoring case
     *
     * @uxon-property equals_ignore_case
     * @uxon-type string
     *
     * @param string $text
     * @return TextMatchTestMetric
     */
    protected function setEqualsIgnoreCase(string $text) : TextMatchTestMetric
    {
        $this->equalsIgnoreCase = $text;
        return $this;
    }

    /**
     * Contains all of the given substrings (logical AND)
     *
     * @uxon-property contains_all
     * @uxon-type string[]
     *
     * @param UxonObject $queries
     * @return TextMatchTestMetric
     */
    protected function setContainsAll(UxonObject $queries) : TextMatchTestMetric
    {
        $this->containsAll = $queries->getPropertiesAll();
        return $this;
    }

    /**
     * Contains at least one of the given substrings (logical OR)
     *
     * @uxon-property contains_any
     * @uxon-type string[]
     *
     * @param UxonObject $queries
     * @return TextMatchTestMetric
     */
    protected function setContainsAny(UxonObject $queries) : TextMatchTestMetric
    {
        $this->containsAny = $queries->getPropertiesAll();
        return $this;
    }

    /**
     * Contains none of the given substrings
     *
     * @uxon-property not_contains_any
     * @uxon-type string[]
     *
     * @param UxonObject $queries
     * @return TextMatchTestMetric
     */
    protected function setNotContainsAny(UxonObject $queries) : TextMatchTestMetric
    {
        $this->notContainsAny = $queries->getPropertiesAll();
        return $this;
    }

    /**
     * Starts with the given prefix
     *
     * @uxon-property starts_with
     * @uxon-type string
     *
     * @param string $prefix
     * @return TextMatchTestMetric
     */
    protected function setStartsWith(string $prefix) : TextMatchTestMetric
    {
        $this->startsWith = $prefix;
        return $this;
    }

    /**
     * Starts with any of the given prefixes
     *
     * @uxon-property starts_with_any
     * @uxon-type string[]
     *
     * @param UxonObject $prefixes
     * @return TextMatchTestMetric
     */
    protected function setStartsWithAny(UxonObject $prefixes) : TextMatchTestMetric
    {
        $this->startsWithAny = $prefixes->getPropertiesAll();
        return $this;
    }

    /**
     * Ends with the given suffix
     *
     * @uxon-property ends_with
     * @uxon-type string
     *
     * @param string $suffix
     * @return TextMatchTestMetric
     */
    protected function setEndsWith(string $suffix) : TextMatchTestMetric
    {
        $this->endsWith = $suffix;
        return $this;
    }

    /**
     * Ends with any of the given suffixes
     *
     * @uxon-property ends_with_any
     * @uxon-type string[]
     *
     * @param UxonObject $suffixes
     * @return TextMatchTestMetric
     */
    protected function setEndsWithAny(UxonObject $suffixes) : TextMatchTestMetric
    {
        $this->endsWithAny = $suffixes->getPropertiesAll();
        return $this;
    }

    /**
     * Contains the given substring
     *
     * @uxon-property contains
     * @uxon-type string
     *
     * @param string $query
     * @return TextMatchTestMetric
     */
    protected function setContains(string $query) : TextMatchTestMetric
    {
        $this->contains = $query;
        return $this;
    }

    /**
     * Contains the given substring ignoring case
     *
     * @uxon-property contains_ignore_case
     * @uxon-type string
     *
     * @param string $query
     * @return TextMatchTestMetric
     */
    protected function setContainsIgnoreCase(string $query) : TextMatchTestMetric
    {
        $this->containsIgnoreCase = $query;
        return $this;
    }

}