<?php
declare(strict_types=1);

namespace MatchMe\Quiz;

/**
 * Core calculation service for quiz trait vectors and matching.
 */
final class QuizCalculator
{
    /**
     * Calculate trait vector from answers and quiz configuration.
     *
     * @param array<int, array{question_id: string, option_id: string, value?: int|float}> $answers
     * @param array<string, mixed> $quizConfig Quiz configuration with questions and trait definitions
     * @return array<string, float> Normalized trait vector [0..1] per trait
     */
    public function calculateTraitVector(array $answers, array $quizConfig): array
    {
        $rawScores = $this->accumulateRawScores($answers, $quizConfig);
        $ranges = $this->calculateTraitRanges($quizConfig);
        return $this->normalizeVector($rawScores, $ranges);
    }

    /**
     * Accumulate raw trait scores from answers.
     *
     * @param array<int, array{question_id: string, option_id: string, value?: int|float}> $answers
     * @param array<string, mixed> $quizConfig
     * @return array<string, float> Raw trait scores
     */
    private function accumulateRawScores(array $answers, array $quizConfig): array
    {
        $rawScores = [];
        $questions = $quizConfig['questions'] ?? [];

        foreach ($answers as $answer) {
            $questionId = $answer['question_id'] ?? '';
            $optionId = $answer['option_id'] ?? '';

            if ($questionId === '' || $optionId === '') {
                continue;
            }

            $question = $this->findQuestion($questions, $questionId);
            if ($question === null) {
                continue;
            }

            $weight = (float) ($question['weight'] ?? 1.0);
            $traitMap = $question['trait_map'] ?? [];

            if (!isset($traitMap[$optionId])) {
                continue;
            }

            $contributions = $traitMap[$optionId];
            if (!is_array($contributions)) {
                continue;
            }

            foreach ($contributions as $trait => $value) {
                $traitValue = (float) $value;
                $rawScores[$trait] = ($rawScores[$trait] ?? 0.0) + ($weight * $traitValue);
            }
        }

        return $rawScores;
    }

    /**
     * Find question by ID in questions array.
     *
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>|null
     */
    private function findQuestion(array $questions, string $questionId): ?array
    {
        foreach ($questions as $question) {
            if (($question['id'] ?? '') === $questionId) {
                return $question;
            }
        }
        return null;
    }

    /**
     * Calculate min/max possible ranges for each trait based on quiz configuration.
     *
     * @param array<string, mixed> $quizConfig
     * @return array<string, array{min: float, max: float}> Ranges per trait
     */
    private function calculateTraitRanges(array $quizConfig): array
    {
        $questions = $quizConfig['questions'] ?? [];

        // Correct range computation:
        // For each question, compute (min,max) contribution per trait across its options,
        // then SUM per-question mins/maxes across all questions.
        $ranges = [];

        foreach ($questions as $question) {
            $weight = (float) ($question['weight'] ?? 1.0);
            $traitMap = $question['trait_map'] ?? [];
            if (!is_array($traitMap) || $traitMap === []) {
                continue;
            }

            /** @var array<string, float> $qMin */
            $qMin = [];
            /** @var array<string, float> $qMax */
            $qMax = [];

            foreach ($traitMap as $contributions) {
                if (!is_array($contributions)) {
                    continue;
                }

                foreach ($contributions as $trait => $value) {
                    $weighted = $weight * (float) $value;
                    // Always consider 0 as minimum since you can get 0 by not selecting this option
                    $qMin[$trait] = array_key_exists($trait, $qMin) ? min($qMin[$trait], $weighted, 0.0) : min($weighted, 0.0);
                    $qMax[$trait] = array_key_exists($trait, $qMax) ? max($qMax[$trait], $weighted) : $weighted;
                }
            }

            $traits = array_unique(array_merge(array_keys($qMin), array_keys($qMax)));
            foreach ($traits as $trait) {
                // qMin already includes 0.0 in its calculation, so it accounts for not selecting options with this trait
                // It can be negative if the trait has negative values in some options
                $min = $qMin[$trait] ?? 0.0;
                $max = $qMax[$trait] ?? 0.0;
                if (!isset($ranges[$trait])) {
                    $ranges[$trait] = ['min' => 0.0, 'max' => 0.0];
                }
                $ranges[$trait]['min'] += $min;
                $ranges[$trait]['max'] += $max;
            }
        }

        return $ranges;
    }

    /**
     * Normalize trait vector to [0, 1] range using min-max normalization.
     *
     * @param array<string, float> $rawVector Raw trait scores
     * @param array<string, array{min: float, max: float}> $ranges Min/max ranges per trait
     * @return array<string, float> Normalized vector [0..1]
     */
    public function normalizeVector(array $rawVector, array $ranges): array
    {
        $normalized = [];

        $allTraits = array_unique(array_merge(array_keys($ranges), array_keys($rawVector)));

        foreach ($allTraits as $trait) {
            $value = $rawVector[$trait] ?? 0.0;
            $range = $ranges[$trait] ?? ['min' => 0.0, 'max' => 1.0];
            $min = $range['min'];
            $max = $range['max'];

            if ($max <= $min) {
                // No range, set to 0.5 (middle)
                $normalized[$trait] = 0.5;
                continue;
            }

            // Min-max normalization: (value - min) / (max - min)
            $normalizedValue = ($value - $min) / ($max - $min);

            // Clamp to [0, 1]
            $normalized[$trait] = max(0.0, min(1.0, $normalizedValue));
        }

        return $normalized;
    }

    /**
     * Compute match between two trait vectors using weighted cosine similarity.
     *
     * @param array<string, float> $vectorA First trait vector [0..1]
     * @param array<string, float> $vectorB Second trait vector [0..1]
     * @param array<string, float> $weights Optional trait weights (default: equal weights)
     * @return float Match score 0..100
     */
    public function computeMatch(array $vectorA, array $vectorB, array $weights = []): float
    {
        // Get all unique traits from both vectors
        $allTraits = array_unique(array_merge(array_keys($vectorA), array_keys($vectorB)));

        if (empty($allTraits)) {
            return 0.0;
        }

        // Calculate cosine similarity
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;
        $totalWeight = 0.0;

        foreach ($allTraits as $trait) {
            $valueA = $vectorA[$trait] ?? 0.0;
            $valueB = $vectorB[$trait] ?? 0.0;
            $weight = $weights[$trait] ?? 1.0;

            $dotProduct += $weight * ($valueA * $valueB);
            $magnitudeA += $weight * ($valueA * $valueA);
            $magnitudeB += $weight * ($valueB * $valueB);
            $totalWeight += $weight;
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        $cosineSimilarity = $dotProduct / ($magnitudeA * $magnitudeB);

        // Clamp to [0, 1] and convert to percentage
        $cosineSimilarity = max(0.0, min(1.0, $cosineSimilarity));

        return 100.0 * $cosineSimilarity;
    }

    /**
     * Compute match using weighted Euclidean distance (fallback algorithm).
     *
     * @param array<string, float> $vectorA First trait vector [0..1]
     * @param array<string, float> $vectorB Second trait vector [0..1]
     * @param array<string, float> $weights Optional trait weights
     * @return float Match score 0..100
     */
    public function computeMatchEuclidean(array $vectorA, array $vectorB, array $weights = []): float
    {
        $allTraits = array_unique(array_merge(array_keys($vectorA), array_keys($vectorB)));

        if (empty($allTraits)) {
            return 0.0;
        }

        $squaredDistance = 0.0;
        $totalWeight = 0.0;

        foreach ($allTraits as $trait) {
            $valueA = $vectorA[$trait] ?? 0.0;
            $valueB = $vectorB[$trait] ?? 0.0;
            $weight = $weights[$trait] ?? 1.0;

            $diff = $valueA - $valueB;
            $squaredDistance += $weight * ($diff * $diff);
            $totalWeight += $weight;
        }

        $distance = sqrt($squaredDistance);
        $maxDistance = sqrt($totalWeight); // Maximum possible distance

        if ($maxDistance === 0.0) {
            return 100.0;
        }

        // Convert distance to similarity: 1 - (distance / max_distance)
        $similarity = 1.0 - ($distance / $maxDistance);
        $similarity = max(0.0, min(1.0, $similarity));

        return 100.0 * $similarity;
    }

    /**
     * Compute match using average absolute difference (fallback algorithm).
     *
     * @param array<string, float> $vectorA First trait vector [0..1]
     * @param array<string, float> $vectorB Second trait vector [0..1]
     * @return float Match score 0..100
     */
    public function computeMatchAbsolute(array $vectorA, array $vectorB): float
    {
        $allTraits = array_unique(array_merge(array_keys($vectorA), array_keys($vectorB)));

        if (empty($allTraits)) {
            return 0.0;
        }

        $totalDiff = 0.0;
        $count = 0;

        foreach ($allTraits as $trait) {
            $valueA = $vectorA[$trait] ?? 0.0;
            $valueB = $vectorB[$trait] ?? 0.0;

            $totalDiff += abs($valueA - $valueB);
            $count++;
        }

        if ($count === 0) {
            return 0.0;
        }

        $meanDiff = $totalDiff / $count;
        $similarity = 1.0 - $meanDiff;
        $similarity = max(0.0, min(1.0, $similarity));

        return 100.0 * $similarity;
    }
}

