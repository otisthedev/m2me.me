<?php
declare(strict_types=1);

namespace MatchMe\Quiz;

use MatchMe\Infrastructure\Db\ResultRepository;

/**
 * Service for matching quiz results and managing comparisons.
 */
final class MatchingService
{
    public function __construct(
        private QuizCalculator $calculator,
        private ResultRepository $resultRepository
    ) {
    }

    /**
     * Match two results by their IDs.
     *
     * @param int $resultIdA First result ID
     * @param int $resultIdB Second result ID
     * @param string $algorithm Algorithm to use: 'cosine', 'euclidean', or 'absolute'
     * @return array<string, mixed> Match result with score and breakdown
     */
    public function matchResults(int $resultIdA, int $resultIdB, string $algorithm = 'cosine'): array
    {
        $resultA = $this->resultRepository->findById($resultIdA);
        $resultB = $this->resultRepository->findById($resultIdB);

        if ($resultA === null || $resultB === null) {
            throw new \InvalidArgumentException('One or both results not found');
        }

        $vectorA = $this->decodeTraitVector($resultA['trait_vector']);
        $vectorB = $this->decodeTraitVector($resultB['trait_vector']);

        return $this->computeMatchWithBreakdown($vectorA, $vectorB, $algorithm);
    }

    /**
     * Match results on partial aspects (only shared completed aspects).
     *
     * @param array<string, array<string, float>> $aspectsA Map of aspect_id => trait_vector for user A
     * @param array<string, array<string, float>> $aspectsB Map of aspect_id => trait_vector for user B
     * @param array<string, float> $aspectWeights Map of aspect_id => weight
     * @param string $algorithm Algorithm to use
     * @return array<string, mixed> Match result with per-aspect breakdown
     */
    public function matchPartialAspects(
        array $aspectsA,
        array $aspectsB,
        array $aspectWeights = [],
        string $algorithm = 'cosine'
    ): array {
        $sharedAspects = array_intersect_key($aspectsA, $aspectsB);

        if (empty($sharedAspects)) {
            return [
                'match_score' => 0.0,
                'breakdown' => [
                    'overall' => 0.0,
                    'aspects' => [],
                    'message' => 'No shared aspects found',
                ],
                'algorithm_used' => $algorithm,
            ];
        }

        $aspectMatches = [];
        $totalWeight = 0.0;
        $weightedSum = 0.0;

        foreach ($sharedAspects as $aspectId => $vectorA) {
            $vectorB = $aspectsB[$aspectId];
            $aspectWeight = $aspectWeights[$aspectId] ?? 1.0;

            $matchResult = $this->computeMatchWithBreakdown($vectorA, $vectorB, $algorithm);
            $matchScore = $matchResult['match_score'];

            $aspectMatches[$aspectId] = [
                'match_score' => $matchScore,
                'traits' => $matchResult['breakdown']['traits'] ?? [],
            ];

            $weightedSum += $aspectWeight * $matchScore;
            $totalWeight += $aspectWeight;
        }

        $overallMatch = $totalWeight > 0 ? ($weightedSum / $totalWeight) : 0.0;

        return [
            'match_score' => $overallMatch,
            'breakdown' => [
                'overall' => $overallMatch,
                'aspects' => $aspectMatches,
                'shared_aspects' => array_keys($sharedAspects),
            ],
            'algorithm_used' => $algorithm,
        ];
    }

    /**
     * Compute match with detailed breakdown.
     *
     * @param array<string, float> $vectorA
     * @param array<string, float> $vectorB
     * @param string $algorithm
     * @return array<string, mixed>
     */
    private function computeMatchWithBreakdown(array $vectorA, array $vectorB, string $algorithm): array
    {
        $matchScore = match ($algorithm) {
            'euclidean' => $this->calculator->computeMatchEuclidean($vectorA, $vectorB),
            'absolute' => $this->calculator->computeMatchAbsolute($vectorA, $vectorB),
            default => $this->calculator->computeMatch($vectorA, $vectorB),
        };

        $traitBreakdown = $this->computeTraitBreakdown($vectorA, $vectorB);

        return [
            'match_score' => $matchScore,
            'breakdown' => [
                'overall' => $matchScore,
                'traits' => $traitBreakdown,
            ],
            'algorithm_used' => $algorithm,
        ];
    }

    /**
     * Compute per-trait similarity breakdown.
     *
     * @param array<string, float> $vectorA
     * @param array<string, float> $vectorB
     * @return array<string, array<string, mixed>>
     */
    private function computeTraitBreakdown(array $vectorA, array $vectorB): array
    {
        $allTraits = array_unique(array_merge(array_keys($vectorA), array_keys($vectorB)));
        $breakdown = [];

        foreach ($allTraits as $trait) {
            $valueA = $vectorA[$trait] ?? 0.0;
            $valueB = $vectorB[$trait] ?? 0.0;

            // Per-trait similarity: 1 - |a - b| (since traits are normalized 0..1)
            $similarity = 1.0 - abs($valueA - $valueB);
            $similarity = max(0.0, min(1.0, $similarity));

            $breakdown[$trait] = [
                'a' => $valueA,
                'b' => $valueB,
                'similarity' => $similarity,
            ];
        }

        return $breakdown;
    }

    /**
     * Decode trait vector from JSON string or array.
     *
     * @param string|array<string, float> $traitVector
     * @return array<string, float>
     */
    private function decodeTraitVector($traitVector): array
    {
        if (is_array($traitVector)) {
            return $traitVector;
        }

        if (is_string($traitVector)) {
            $decoded = json_decode($traitVector, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}


