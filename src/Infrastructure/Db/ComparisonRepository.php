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

    /**
     * Latest comparisons involving a given user (by result_a.user_id or result_b.user_id).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestByUser(int $userId, int $limit = 6): array
    {
        $userId = (int) $userId;
        $limit = max(1, min(50, (int) $limit));

        $c = $this->tableName();
        $r = $this->wpdb->prefix . 'match_me_results';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "
            SELECT
              c.id,
              c.share_token,
              c.match_score,
              c.created_at,
              c.result_a,
              c.result_b,
              ra.user_id AS user_a,
              rb.user_id AS user_b,
              ra.quiz_slug AS quiz_slug_a,
              rb.quiz_slug AS quiz_slug_b
            FROM {$c} c
            LEFT JOIN {$r} ra ON ra.result_id = c.result_a
            LEFT JOIN {$r} rb ON rb.result_id = c.result_b
            WHERE (ra.user_id = %d OR rb.user_id = %d)
            ORDER BY c.created_at DESC
            LIMIT %d
        ";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $userId, $userId, $limit),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Latest comparisons where the given user is the "owner" of result_a (i.e. others compared with them).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestForOwnerUser(int $ownerUserId, int $limit = 10, int $sinceTs = 0): array
    {
        $ownerUserId = (int) $ownerUserId;
        $limit = max(1, min(50, (int) $limit));
        $sinceTs = max(0, (int) $sinceTs);

        $c = $this->tableName();
        $r = $this->wpdb->prefix . 'match_me_results';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "
            SELECT
              c.id,
              c.share_token,
              c.match_score,
              c.created_at,
              c.result_a,
              c.result_b,
              rb.user_id AS viewer_user_id,
              ra.quiz_slug AS quiz_slug
            FROM {$c} c
            INNER JOIN {$r} ra ON ra.result_id = c.result_a
            LEFT JOIN {$r} rb ON rb.result_id = c.result_b
            WHERE ra.user_id = %d
              AND c.created_at > FROM_UNIXTIME(%d)
              AND (rb.user_id IS NULL OR rb.user_id <> %d)
            ORDER BY c.created_at DESC
            LIMIT %d
        ";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $ownerUserId, $sinceTs, $ownerUserId, $limit),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Distinct counterpart users that have compared with the given user (either direction).
     *
     * @return array<int, array{other_user_id:int, last_at:string}>
     */
    public function counterpartUsersForUser(int $userId, int $limit = 30): array
    {
        $userId = (int) $userId;
        $limit = max(1, min(100, (int) $limit));

        $c = $this->tableName();
        $r = $this->wpdb->prefix . 'match_me_results';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "
            SELECT
              CASE
                WHEN ra.user_id = %d THEN rb.user_id
                ELSE ra.user_id
              END AS other_user_id,
              MAX(c.created_at) AS last_at
            FROM {$c} c
            LEFT JOIN {$r} ra ON ra.result_id = c.result_a
            LEFT JOIN {$r} rb ON rb.result_id = c.result_b
            WHERE (
                ra.user_id = %d AND rb.user_id IS NOT NULL AND rb.user_id <> %d
              ) OR (
                rb.user_id = %d AND ra.user_id IS NOT NULL AND ra.user_id <> %d
              )
            GROUP BY other_user_id
            ORDER BY last_at DESC
            LIMIT %d
        ";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $userId, $userId, $userId, $userId, $userId, $limit),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $oid = isset($row['other_user_id']) ? (int) $row['other_user_id'] : 0;
            if ($oid <= 0) {
                continue;
            }
            $out[] = [
                'other_user_id' => $oid,
                'last_at' => (string) ($row['last_at'] ?? ''),
            ];
        }

        return $out;
    }

    public function latestShareTokenBetweenUsers(int $userIdA, int $userIdB): string
    {
        $userIdA = (int) $userIdA;
        $userIdB = (int) $userIdB;
        if ($userIdA <= 0 || $userIdB <= 0) {
            return '';
        }

        $c = $this->tableName();
        $r = $this->wpdb->prefix . 'match_me_results';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "
            SELECT c.share_token
            FROM {$c} c
            LEFT JOIN {$r} ra ON ra.result_id = c.result_a
            LEFT JOIN {$r} rb ON rb.result_id = c.result_b
            WHERE (
                ra.user_id = %d AND rb.user_id = %d
              ) OR (
                ra.user_id = %d AND rb.user_id = %d
              )
            ORDER BY c.created_at DESC
            LIMIT 1
        ";

        $token = $this->wpdb->get_var(
            $this->wpdb->prepare($sql, $userIdA, $userIdB, $userIdB, $userIdA)
        );

        return is_string($token) ? $token : '';
    }
}


