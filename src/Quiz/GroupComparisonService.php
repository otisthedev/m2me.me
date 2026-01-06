<?php
declare(strict_types=1);

namespace MatchMe\Quiz;

/**
 * Service for calculating group comparison insights.
 */
final class GroupComparisonService
{
    public function __construct(
        private QuizCalculator $calculator
    ) {
    }

    /**
     * Calculate group comparison insights for 3+ participants.
     *
     * @param array<int, array<string, float>> $results Array of [result_id => trait_vector]
     * @param array<string, string> $traitLabels
     * @param string $quizSlug
     * @return array<string, mixed>
     */
    public function calculateGroupInsights(
        array $results,
        array $traitLabels,
        string $quizSlug
    ): array {
        $participantCount = count($results);
        if ($participantCount < 3) {
            throw new \InvalidArgumentException('Group comparison requires at least 3 participants');
        }

        // Calculate pairwise similarities
        $pairwiseMatches = [];
        $resultIds = array_keys($results);

        for ($i = 0; $i < count($resultIds); $i++) {
            for ($j = $i + 1; $j < count($resultIds); $j++) {
                $resultA = $results[$resultIds[$i]];
                $resultB = $results[$resultIds[$j]];
                $matchScorePercent = $this->calculator->computeMatch($resultA, $resultB); // Returns 0-100
                $matchScore = $matchScorePercent / 100.0; // Convert to 0-1 for calculations
                $pairwiseMatches[] = [
                    'result_a' => $resultIds[$i],
                    'result_b' => $resultIds[$j],
                    'match_score' => $matchScore, // Store as 0-1 for consistency
                    'match_score_percent' => $matchScorePercent, // Also store as 0-100 if needed
                ];
            }
        }

        // Calculate average match score
        $avgMatch = count($pairwiseMatches) > 0
            ? array_sum(array_column($pairwiseMatches, 'match_score')) / count($pairwiseMatches)
            : 0.0;

        // Find trait distributions across group
        $traitDistributions = $this->calculateTraitDistributions($results, $traitLabels);

        // Generate group insights
        $insights = $this->generateGroupInsights($traitDistributions, $avgMatch, $participantCount);

        return [
            'average_match_score' => $avgMatch,
            'pairwise_matches' => $pairwiseMatches,
            'trait_distributions' => $traitDistributions,
            'insights' => $insights,
            'participant_count' => $participantCount,
        ];
    }

    /**
     * Calculate trait distributions across all participants.
     *
     * @param array<int, array<string, float>> $results
     * @param array<string, string> $traitLabels
     * @return array<string, array{mean: float, min: float, max: float, variance: float}>
     */
    private function calculateTraitDistributions(array $results, array $traitLabels): array
    {
        $distributions = [];

        foreach ($traitLabels as $trait => $label) {
            $values = [];
            foreach ($results as $traitVector) {
                $values[] = $traitVector[$trait] ?? 0.0;
            }

            if (count($values) > 0) {
                $distributions[$trait] = [
                    'mean' => array_sum($values) / count($values),
                    'min' => min($values),
                    'max' => max($values),
                    'variance' => $this->calculateVariance($values),
                ];
            }
        }

        return $distributions;
    }

    /**
     * Generate group insights based on trait distributions and match scores.
     *
     * @param array<string, array{mean: float, min: float, max: float, variance: float}> $distributions
     * @param float $avgMatch
     * @param int $count
     * @return array<string>
     */
    private function generateGroupInsights(array $distributions, float $avgMatch, int $count): array
    {
        $insights = [];

        // Find most consistent trait (lowest variance)
        $mostConsistent = null;
        $lowestVariance = 1.0;
        foreach ($distributions as $trait => $dist) {
            if ($dist['variance'] < $lowestVariance) {
                $lowestVariance = $dist['variance'];
                $mostConsistent = $trait;
            }
        }

        if ($mostConsistent && $lowestVariance < 0.1) {
            $insights[] = "Your group is most aligned in {$mostConsistent} (variance: " . round($lowestVariance, 2) . ")";
        }

        // Find most diverse trait (highest variance)
        $mostDiverse = null;
        $highestVariance = 0.0;
        foreach ($distributions as $trait => $dist) {
            if ($dist['variance'] > $highestVariance) {
                $highestVariance = $dist['variance'];
                $mostDiverse = $trait;
            }
        }

        if ($mostDiverse && $highestVariance > 0.1) {
            $insights[] = "Your group shows the most diversity in {$mostDiverse}";
        }

        // Overall match assessment
        if ($avgMatch >= 0.75) {
            $insights[] = "Your group has high alignment (" . round($avgMatch * 100) . "% average match)";
        } elseif ($avgMatch >= 0.60) {
            $insights[] = "Your group has moderate alignment (" . round($avgMatch * 100) . "% average match)";
        } else {
            $insights[] = "Your group has diverse styles (" . round($avgMatch * 100) . "% average match)";
        }

        return $insights;
    }

    /**
     * Calculate variance of values.
     *
     * @param array<float> $values
     * @return float
     */
    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += pow($v - $mean, 2);
        }

        return $variance / count($values);
    }
}

