<?php
declare(strict_types=1);

namespace MatchMe\Tests\Integration;

/**
 * End-to-end integration test for quiz flow.
 *
 * This test simulates the complete flow:
 * 1. Submit quiz → receive result
 * 2. Open share link → view result
 * 3. Take quiz → compare → receive match report
 */
final class QuizFlowTest
{
    /**
     * Run smoke test (manual verification steps).
     *
     * This is a guide for manual testing since we don't have a full test environment set up.
     */
    public static function smokeTestSteps(): array
    {
        return [
            'Step 1: Submit Quiz' => [
                'endpoint' => 'POST /wp-json/match-me/v1/quiz/communication-style-v1/submit',
                'payload' => [
                    'answers' => [
                        ['question_id' => 'q1', 'option_id' => 'opt_1'],
                        ['question_id' => 'q2', 'option_id' => 'opt_1'],
                        ['question_id' => 'q3', 'option_id' => 'opt_1'],
                        ['question_id' => 'q4', 'option_id' => 'opt_1'],
                        ['question_id' => 'q5', 'option_id' => 'opt_1'],
                        ['question_id' => 'q6', 'option_id' => 'opt_1'],
                    ],
                    'share_mode' => 'share_match',
                ],
                'expected' => [
                    'result_id' => 'integer',
                    'trait_vector' => 'array with directness, empathy, clarity',
                    'share_token' => '32+ character string',
                    'share_urls' => 'object with view and compare URLs',
                ],
            ],
            'Step 2: Get Result by Share Token' => [
                'endpoint' => 'GET /wp-json/match-me/v1/result/{share_token}',
                'note' => 'Use share_token from Step 1',
                'expected' => [
                    'result_id' => 'integer',
                    'trait_summary' => 'array',
                    'textual_summary' => 'string',
                    'can_compare' => 'boolean (true)',
                ],
            ],
            'Step 3: Compare Results' => [
                'endpoint' => 'POST /wp-json/match-me/v1/result/{share_token}/compare',
                'payload' => [
                    'quiz_id' => 'communication-style-v1',
                    'answers' => [
                        ['question_id' => 'q1', 'option_id' => 'opt_2'],
                        ['question_id' => 'q2', 'option_id' => 'opt_2'],
                        ['question_id' => 'q3', 'option_id' => 'opt_2'],
                        ['question_id' => 'q4', 'option_id' => 'opt_2'],
                        ['question_id' => 'q5', 'option_id' => 'opt_2'],
                        ['question_id' => 'q6', 'option_id' => 'opt_2'],
                    ],
                    'algorithm' => 'cosine',
                ],
                'expected' => [
                    'comparison_id' => 'integer',
                    'match_score' => 'float between 0-100',
                    'breakdown' => 'object with overall and traits',
                    'algorithm_used' => 'cosine',
                ],
            ],
            'Step 4: Verify Match Calculation' => [
                'check' => 'Match score should be calculated correctly',
                'note' => 'User A (all opt_1) vs User B (all opt_2) should show different trait vectors',
                'expected_match_range' => '0-100% (likely 50-80% for these answers)',
            ],
        ];
    }

    /**
     * Expected trait vector for sample answers (all opt_1).
     *
     * Based on communication-style-v1.json:
     * - Each opt_1 gives: directness=2, empathy=0, clarity=1
     * - 6 questions × weight 1.0 = directness=12, empathy=0, clarity=6
     * - Normalized (assuming max=12 for all): directness=1.0, empathy=0.0, clarity=0.5
     */
    public static function expectedTraitVectorForAllOpt1(): array
    {
        return [
            'directness' => 1.0, // 12/12 = 1.0
            'empathy' => 0.0,    // 0/12 = 0.0
            'clarity' => 0.5,    // 6/12 = 0.5
        ];
    }

    /**
     * Expected trait vector for sample answers (all opt_2).
     *
     * Based on communication-style-v1.json:
     * - Each opt_2 gives: directness=0, empathy=2, clarity=0
     * - 6 questions × weight 1.0 = directness=0, empathy=12, clarity=0
     * - Normalized: directness=0.0, empathy=1.0, clarity=0.0
     */
    public static function expectedTraitVectorForAllOpt2(): array
    {
        return [
            'directness' => 0.0,
            'empathy' => 1.0,
            'clarity' => 0.0,
        ];
    }

    /**
     * Expected match score between opt_1 and opt_2 results.
     *
     * Vector A: {directness: 1.0, empathy: 0.0, clarity: 0.5}
     * Vector B: {directness: 0.0, empathy: 1.0, clarity: 0.0}
     *
     * Cosine similarity:
     * - Dot product: (1.0×0.0) + (0.0×1.0) + (0.5×0.0) = 0.0
     * - Magnitude A: sqrt(1.0² + 0.0² + 0.5²) = sqrt(1.25) = 1.118
     * - Magnitude B: sqrt(0.0² + 1.0² + 0.0²) = 1.0
     * - Cosine: 0.0 / (1.118 × 1.0) = 0.0
     *
     * Match score: 0% (opposite communication styles)
     */
    public static function expectedMatchScoreOpt1VsOpt2(): float
    {
        return 0.0; // Opposite vectors = 0% match
    }
}


