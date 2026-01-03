<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db;

/**
 * Repository for match_me_comparisons table.
 */
final class ComparisonRepository
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    private function tableName(): string
    {
        return $this->wpdb->prefix . 'match_me_comparisons';
    }

    /**
     * Insert a new comparison.
     *
     * @param int $resultA
     * @param int $resultB
     * @param string $shareToken
     * @param float $matchScore
     * @param array<string, mixed> $breakdown
     * @param string $algorithm
     * @return int comparison id
     */
    public function insert(
        int $resultA,
        int $resultB,
        string $shareToken,
        float $matchScore,
        array $breakdown,
        string $algorithm,
        ?string $summaryShort = null,
        ?string $summaryLong = null,
        ?string $summaryQuizVersion = null
    ): int {
        $table = $this->tableName();
        $breakdownJson = json_encode($breakdown, JSON_THROW_ON_ERROR);

        $data = [
            'result_a' => $resultA,
            'result_b' => $resultB,
            'share_token' => $shareToken,
            'match_score' => $matchScore,
            'breakdown' => $breakdownJson,
            'comparison_summary_short' => $summaryShort,
            'comparison_summary_long' => $summaryLong,
            'comparison_summary_quiz_version' => $summaryQuizVersion ?? '',
            'algorithm_used' => $algorithm,
        ];

        $format = ['%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s'];

        $result = $this->wpdb->insert($table, $data, $format);

        if ($result === false) {
            throw new \RuntimeException('Failed to insert comparison: ' . $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Find comparison by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $comparisonId): ?array
    {
        $table = $this->tableName();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, result_a, result_b, share_token, match_score, breakdown, comparison_summary_short, comparison_summary_long, comparison_summary_quiz_version, algorithm_used, created_at 
                 FROM $table 
                 WHERE id = %d 
                 LIMIT 1",
                $comparisonId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Find comparison by share token.
     *
     * @return array<string, mixed>|null
     */
    public function findByShareToken(string $shareToken): ?array
    {
        $table = $this->tableName();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, result_a, result_b, share_token, match_score, breakdown, comparison_summary_short, comparison_summary_long, comparison_summary_quiz_version, algorithm_used, created_at
                 FROM $table
                 WHERE share_token = %s
                 LIMIT 1",
                $shareToken
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function updateSummaries(int $comparisonId, string $short, string $long, string $quizVersion): bool
    {
        $table = $this->tableName();
        $updated = $this->wpdb->update(
            $table,
            [
                'comparison_summary_short' => $short,
                'comparison_summary_long' => $long,
                'comparison_summary_quiz_version' => $quizVersion,
            ],
            ['id' => $comparisonId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }
}


