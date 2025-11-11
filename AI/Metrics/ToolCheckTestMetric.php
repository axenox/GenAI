<?php

namespace axenox\GenAI\AI\Metrics;

use axenox\GenAI\AI\Metrics\UxonConfigData\ToolCheckData;
use axenox\genAI\common\AbstractTestMetric;
use axenox\GenAI\Common\AiTestRating;
use axenox\GenAI\Factories\AiTestingFactory;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;
use axenox\GenAI\Interfaces\AiTestRatingInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\DataSheetFactory;


/*
 * Example:
 * 
 * {
  "type": "ToolCheck",
  "tools": [
    {
{
      "name": "search_user", // wird benötigt
      "required" : true //standartmäßig auf true
      "min_calls": 1, // optional
      "max_calls": 2, // optional
      "arguments": {
          "name" : "John",
      }, //wird benötigt
      "allow_additional_arguments": false //standartmäßig auf false
    }
  ],
  "forbidden_tools": ["delete_user"]
}

 * 
 * 
 * 
 * 
 * 
 * 
 */
class ToolCheckTestMetric extends AbstractTestMetric
{
    
    private ?array $forbiddenTools = null;

    /** @var ToolCheckData[] */
    private ?array $tools = null;
    
    private bool $toolCallForbidden = false;


    public function evaluate(AiResponseInterface $response, ?AiTestCriterionInterface $criterion = null): AiTestRatingInterface
    {
        //TODO Improve this process
        //TODO Example when no argument is given or something like that
        //and split in Parts

        $toolCalls = $response->getToolCallResponses();
        
        //TODO cons, pros, Explanation
        if($this->toolCallForbidden){
            $rating = 1;
            if(count($toolCalls) === 0) $rating = 5;
            return new AiTestRating($response, $this,$rating, $criterion);
        }

        // Tool Calls nach Name gruppieren
        $callGroups = [];
        foreach ($toolCalls as $call) {
            $name = $call->getToolName();
            if (!isset($callGroups[$name])) {
                $callGroups[$name] = [];
            }
            $callGroups[$name][] = $call;
        }

        $usageScores = [];
        $argumentScores = [];
        $hasForbiddenTool = false;
        $missingRequiredTool = false;

        // Erwartete Tools aus $this->tools bewerten
        foreach ($this->tools ?? [] as $tool) {
            if (!$tool instanceof ToolCheckData) {
                continue;
            }

            $required         = $tool->isRequired(); // Standard: true
            $allowAdditional  = $tool->isAllowAdditionalArguments();
            $expectedArgs     = \array_keys($tool->getArguments() ?? []);
            $toolName         = $tool->getName();
            $toolCallsForName = $callGroups[$toolName] ?? [];
            $actualCount      = \count($toolCallsForName);

            $minCalls = $tool->getMinCalls();
            if ($minCalls === null) {
                $minCalls = $required ? 1 : 0;
            }

            $maxCalls = $tool->getMaxCalls();
            if ($maxCalls === null) {
                $maxCalls = PHP_INT_MAX;
            }

            // Nutzung Score
            $usageScore = 0.0;
            if ($required && $actualCount === 0) {
                $usageScore = 0.0;
                $missingRequiredTool = true;
            } elseif ($actualCount >= $minCalls && $actualCount <= $maxCalls) {
                $usageScore = 1.0;
            } elseif ($actualCount > 0) {
                $usageScore = 0.5;
            } else {
                // optionales Tool nicht genutzt
                $usageScore = 1.0;
            }
            $usageScores[] = $usageScore;

            // Argumente Score
            if ($actualCount === 0) {
                $argumentScores[] = $required ? 0.0 : 1.0;
            } else {
                $callScores = [];
                foreach ($toolCallsForName as $call) {
                    /** @var AiToolCallResponse $call */
                    $args = $call->getArguments() ?? [];
                    $keys = \array_keys($args);

                    $missing = \array_diff($expectedArgs, $keys);
                    $extra   = \array_diff($keys, $expectedArgs);

                    $okMissing = \count($missing) === 0;
                    $okExtra   = $allowAdditional || \count($extra) === 0;

                    $callScores[] = ($okMissing && $okExtra) ? 1.0 : 0.0;
                }

                if (\count($callScores) > 0) {
                    $argumentScores[] = \array_sum($callScores) / \count($callScores);
                } else {
                    $argumentScores[] = $required ? 0.0 : 1.0;
                }
            }
        }

        // Verbotene Tools bewerten
        $forbiddenScore = 1.0;
        $forbiddenNames = $this->forbiddenTools ?? [];

        if (!empty($forbiddenNames)) {
            $forbiddenLookup = [];
            foreach ($forbiddenNames as $name) {
                $forbiddenLookup[$name] = true;
            }

            foreach ($toolCalls as $call) {
                if (isset($forbiddenLookup[$call->getToolName()])) {
                    $hasForbiddenTool = true;
                    $forbiddenScore   = 0.0;
                    break;
                }
            }
        }

        // Durchschnittswerte
        $usageScore = \count($usageScores) > 0
            ? \array_sum($usageScores) / \count($usageScores)
            : 1.0;

        $argumentScore = \count($argumentScores) > 0
            ? \array_sum($argumentScores) / \count($argumentScores)
            : 1.0;

        // Raw Score wie im TS Code
        $rawScore = $usageScore * 0.5
            + $argumentScore * 0.3
            + $forbiddenScore * 0.2;

        // Clamp auf [0,1]
        if ($rawScore < 0.0) {
            $rawScore = 0.0;
        } elseif ($rawScore > 1.0) {
            $rawScore = 1.0;
        }

        // Rating 1 bis 5
        $rating = (int) \floor($rawScore * 4) + 1;

        if ($hasForbiddenTool) {
            $rating = \min($rating, 2);
        }

        if ($missingRequiredTool) {
            $rating = \min($rating, 2);
        }

        $this->rating = $rating;

        // Pros und Cons aufbauen
        $pros = [];
        $cons = [];

        // Nutzung
        if ($usageScore >= 0.9) {
            $pros[] = 'Tool Nutzung entspricht sehr gut den erwarteten Aufrufen.';
        } elseif ($usageScore >= 0.6) {
            $pros[] = 'Tool Nutzung liegt meist im erwarteten Bereich.';
        } else {
            $cons[] = 'Tool Nutzung weicht deutlich von den erwarteten Aufrufen ab.';
        }

        // Argumente
        if ($argumentScore >= 0.9) {
            $pros[] = 'Argumente passen sehr gut zum erwarteten Schema.';
        } elseif ($argumentScore >= 0.6) {
            $pros[] = 'Argumente passen grob zum erwarteten Schema, haben aber kleinere Abweichungen.';
        } else {
            $cons[] = 'Argumente weichen deutlich vom erwarteten Schema ab.';
        }

        // Verbotene Tools
        if (!$hasForbiddenTool) {
            $pros[] = 'Es wurden keine verbotenen Tools genutzt.';
        } else {
            $cons[] = 'Es wurde mindestens ein verbotenes Tool genutzt.';
        }

        // Fehlende Pflicht Tools
        if ($missingRequiredTool) {
            $cons[] = 'Mindestens ein Pflicht Tool wurde nicht genutzt.';
        }

        $this->pros = $pros;
        $this->cons = $cons;

        // Erklärungstext
        $parts = [];
        $parts[] = \sprintf(
            'Gesamtwert %.2f basiert auf Nutzungsscore %.2f, Argumentscore %.2f und Verbotsscore %.2f.',
            $rawScore,
            $usageScore,
            $argumentScore,
            $forbiddenScore
        );

        if ($missingRequiredTool) {
            $parts[] = 'Ein oder mehrere Pflicht Tools fehlen.';
        }

        if ($hasForbiddenTool) {
            $parts[] = 'Es wurden verbotene Tools ausgeführt.';
        }

        if (!$missingRequiredTool && !$hasForbiddenTool && $rating >= 4) {
            $parts[] = 'Tool Auswahl und Argumente sind weitgehend stimmig.';
        }

        $this->explanation = \implode(' ', $parts);
        
        if(!$this->cons){
            $this->cons = ['Nix anzumerken'];
        }
        
        if(!$this->pros){
            $this->pros = ['Nix anzumerken'];
        }

        $testRating = new AiTestRating($response, $this, $this->rating, $criterion);
        $testRating
            ->setPros(implode("\n", $this->pros))
            ->setCons(implode("\n", $this->cons))
            ->setExplanation($this->explanation);
            

        return $testRating;
    }




    public function getForbiddenTools(): ?array
    {
        if(!$this->forbiddenTools) return [];
        return $this->forbiddenTools;
    }

    /**
     * List of forbidden tools
     *
     * @uxon-property forbidden_tools
     * @uxon-type array
     */
    public function setForbiddenTools(UxonObject $forbiddenTools): ToolCheckTestMetric
    {
        $this->forbiddenTools = $forbiddenTools->getPropertiesAll();
        return $this;
    }


    /**
     * AI agents will be evaluated later using these metrics.
     * For questions: AITestRunHelper
     *
     * @uxon-property tools
     * @uxon-type \axenox\GenAI\AI\Metrics\UxonConfigData\ToolCheckData[]

     */
    protected function setTools(UxonObject $uxon) : ToolCheckTestMetric
    {
        $list = $uxon->getPropertiesAll();
        foreach ($list as $name => $value) {
            $this->tools[] = new ToolCheckData($this->workbench, $value);
        }
        return $this;
    }

    public function getTools(): ?array
    {
        return $this->tools;
    }

    /**
     * If true, best rating (5) when no tool call is made and worst (1) if any occurs.
     * If false or unset, normal criteria evaluation applies.
     *
     * @uxon-property tool_call_forbidden
     * @uxon-type bool
     */
    protected function setToolCallForbidden(bool $toolCallForbidden): ToolCheckTestMetric
    {
        $this->toolCallForbidden = $toolCallForbidden;
        return $this;
    }

    public function isToolCallForbidden(): bool
    {
        return $this->toolCallForbidden;
    }

    
    





}