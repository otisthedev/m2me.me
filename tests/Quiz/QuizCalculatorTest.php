<?php
declare(strict_types=1);

namespace MatchMe\Tests\Quiz;

use MatchMe\Quiz\QuizCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuizCalculator.
 */
final class QuizCalculatorTest extends TestCase
{
    private QuizCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new QuizCalculator();
    }

    public function testCalculateTraitVectorBasic(): void
    {
        $quizConfig = [
            'questions' => [
                [
                    'id' => 'q1',
                    'weight' => 1.0,
                    'trait_map' => [
                        'opt_1' => ['directness' => 2, 'empathy' => 0, 'clarity' => 1],
                        'opt_2' => ['directness' => 0, 'empathy' => 2, 'clarity' => 0],
                    ],
                ],
                [
                    'id' => 'q2',
                    'weight' => 1.0,
                    'trait_map' => [
                        'opt_1' => ['directness' => 2, 'empathy' => 0, 'clarity' => 1],
                        'opt_2' => ['directness' => 0, 'empathy' => 2, 'clarity' => 0],
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'q1', 'option_id' => 'opt_1'],
            ['question_id' => 'q2', 'option_id' => 'opt_1'],
        ];

        $result = $this->calculator->calculateTraitVector($answers, $quizConfig);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('directness', $result);
        $this->assertArrayHasKey('empathy', $result);
        $this->assertArrayHasKey('clarity', $result);

        // Raw scores: directness=4, empathy=0, clarity=2
        // After normalization (assuming max possible: directness=4, empathy=4, clarity=2)
        // directness: 4/4 = 1.0, empathy: 0/4 = 0.0, clarity: 2/2 = 1.0
        $this->assertGreaterThanOrEqual(0.0, $result['directness']);
        $this->assertLessThanOrEqual(1.0, $result['directness']);
        $this->assertEquals(0.0, $result['empathy']);
    }

    public function testNormalizeVector(): void
    {
        $rawVector = ['trait1' => 8.0, 'trait2' => 5.0, 'trait3' => 12.0];
        $ranges = [
            'trait1' => ['min' => 0.0, 'max' => 10.0],
            'trait2' => ['min' => 0.0, 'max' => 10.0],
            'trait3' => ['min' => 0.0, 'max' => 12.0],
        ];

        $result = $this->calculator->normalizeVector($rawVector, $ranges);

        $this->assertEquals(0.8, $result['trait1'], 'trait1 should be 0.8 (8/10)');
        $this->assertEquals(0.5, $result['trait2'], 'trait2 should be 0.5 (5/10)');
        $this->assertEquals(1.0, $result['trait3'], 'trait3 should be 1.0 (12/12)');
    }

    public function testComputeMatchCosine(): void
    {
        $vectorA = ['directness' => 0.8, 'empathy' => 0.5, 'clarity' => 1.0];
        $vectorB = ['directness' => 0.7, 'empathy' => 0.6, 'clarity' => 0.9];

        $matchScore = $this->calculator->computeMatch($vectorA, $vectorB);

        $this->assertGreaterThanOrEqual(0.0, $matchScore);
        $this->assertLessThanOrEqual(100.0, $matchScore);
        $this->assertGreaterThan(70.0, $matchScore, 'Similar vectors should have high match');
    }

    public function testComputeMatchEuclidean(): void
    {
        $vectorA = ['directness' => 0.8, 'empathy' => 0.5, 'clarity' => 1.0];
        $vectorB = ['directness' => 0.7, 'empathy' => 0.6, 'clarity' => 0.9];

        $matchScore = $this->calculator->computeMatchEuclidean($vectorA, $vectorB);

        $this->assertGreaterThanOrEqual(0.0, $matchScore);
        $this->assertLessThanOrEqual(100.0, $matchScore);
    }

    public function testComputeMatchAbsolute(): void
    {
        $vectorA = ['directness' => 0.8, 'empathy' => 0.5, 'clarity' => 1.0];
        $vectorB = ['directness' => 0.7, 'empathy' => 0.6, 'clarity' => 0.9];

        $matchScore = $this->calculator->computeMatchAbsolute($vectorA, $vectorB);

        $this->assertGreaterThanOrEqual(0.0, $matchScore);
        $this->assertLessThanOrEqual(100.0, $matchScore);
    }

    public function testComputeMatchIdenticalVectors(): void
    {
        $vectorA = ['directness' => 0.8, 'empathy' => 0.5, 'clarity' => 1.0];
        $vectorB = ['directness' => 0.8, 'empathy' => 0.5, 'clarity' => 1.0];

        $matchScore = $this->calculator->computeMatch($vectorA, $vectorB);

        $this->assertEquals(100.0, $matchScore, 'Identical vectors should have 100% match');
    }

    public function testComputeMatchOppositeVectors(): void
    {
        $vectorA = ['directness' => 1.0, 'empathy' => 0.0, 'clarity' => 1.0];
        $vectorB = ['directness' => 0.0, 'empathy' => 1.0, 'clarity' => 0.0];

        $matchScore = $this->calculator->computeMatch($vectorA, $vectorB);

        $this->assertLessThan(50.0, $matchScore, 'Opposite vectors should have low match');
    }

    public function testCalculateTraitVectorWithWeights(): void
    {
        $quizConfig = [
            'questions' => [
                [
                    'id' => 'q1',
                    'weight' => 2.0, // Double weight
                    'trait_map' => [
                        'opt_1' => ['directness' => 1],
                    ],
                ],
                [
                    'id' => 'q2',
                    'weight' => 1.0,
                    'trait_map' => [
                        'opt_1' => ['directness' => 1],
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'q1', 'option_id' => 'opt_1'],
            ['question_id' => 'q2', 'option_id' => 'opt_1'],
        ];

        $result = $this->calculator->calculateTraitVector($answers, $quizConfig);

        // q1 contributes 2.0 * 1 = 2.0, q2 contributes 1.0 * 1 = 1.0, total = 3.0
        // After normalization, should reflect the weighted contribution
        $this->assertArrayHasKey('directness', $result);
        $this->assertGreaterThan(0.0, $result['directness']);
    }
}


