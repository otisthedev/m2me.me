<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db;

/**
 * Repository for the new match_me_results table.
 */
final class ResultRepository
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    private function tableName(): string
    {
        return $this->wpdb->prefix . 'match_me_results';
    }

    /**
     * Find result by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $resultId): ?array
    {
        $table = $this->tableName();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT result_id, quiz_id, quiz_slug, user_id, trait_vector, share_token, share_mode, quiz_version, created_at, revoked_at 
                 FROM $table 
                 WHERE result_id = %d 
                 LIMIT 1",
                $resultId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Find result by share token.
     *
     * @return array<string, mixed>|null
     */
    public function findByShareToken(string $shareToken): ?array
    {
        $table = $this->tableName();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT result_id, quiz_id, quiz_slug, user_id, trait_vector, share_token, share_mode, quiz_version, created_at, revoked_at 
                 FROM $table 
                 WHERE share_token = %s 
                 LIMIT 1",
                $shareToken
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Insert a new result.
     *
     * @param int $quizId
     * @param string $quizSlug
     * @param int|null $userId
     * @param array<string, float> $traitVector
     * @param string $shareToken
     * @param string $shareMode
     * @param string $quizVersion
     * @return int result_id
     */
    public function insert(
        int $quizId,
        string $quizSlug,
        ?int $userId,
        array $traitVector,
        string $shareToken,
        string $shareMode,
        string $quizVersion
    ): int {
        $table = $this->tableName();
        $traitVectorJson = json_encode($traitVector, JSON_THROW_ON_ERROR);

        $data = [
            'quiz_id' => $quizId,
            'quiz_slug' => $quizSlug,
            'user_id' => $userId,
            'trait_vector' => $traitVectorJson,
            'share_token' => $shareToken,
            'share_mode' => $shareMode,
            'quiz_version' => $quizVersion,
        ];

        $format = ['%d', '%s', '%d', '%s', '%s', '%s', '%s'];

        $result = $this->wpdb->insert($table, $data, $format);

        if ($result === false) {
            throw new \RuntimeException('Failed to insert result: ' . $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Revoke a share token.
     */
    public function revokeToken(int $resultId): bool
    {
        $table = $this->tableName();
        $result = $this->wpdb->update(
            $table,
            ['revoked_at' => current_time('mysql')],
            ['result_id' => $resultId],
            ['%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Find results by user and quiz.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUserAndQuiz(int $userId, int $quizId): array
    {
        $table = $this->tableName();
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT result_id, quiz_id, user_id, trait_vector, share_token, share_mode, quiz_version, created_at 
                 FROM $table 
                 WHERE user_id = %d AND quiz_id = %d 
                 ORDER BY created_at DESC",
                $userId,
                $quizId
            ),
            ARRAY_A
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Latest result per quiz for a given user (grouped by quiz_slug).
     *
     * @return array<int, array{quiz_slug:string, result_id:int, share_token:string, share_mode:string, created_at:string}>
     */
    public function latestByUserGroupedByQuizSlug(int $userId): array
    {
        $table = $this->tableName();

        // MySQL-compatible: join against a grouped subquery.
        $sql = "
            SELECT r.quiz_slug, r.result_id, r.share_token, r.share_mode, r.created_at
            FROM $table r
            INNER JOIN (
                SELECT quiz_slug, MAX(created_at) AS max_created
                FROM $table
                WHERE user_id = %d
                GROUP BY quiz_slug
            ) latest
              ON latest.quiz_slug = r.quiz_slug AND latest.max_created = r.created_at
            WHERE r.user_id = %d
            ORDER BY r.created_at DESC
        ";

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $userId, $userId), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['quiz_slug']) || empty($row['result_id'])) {
                continue;
            }
            $out[] = [
                'quiz_slug' => (string) $row['quiz_slug'],
                'result_id' => (int) $row['result_id'],
                'share_token' => (string) ($row['share_token'] ?? ''),
                'share_mode' => (string) ($row['share_mode'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }
}

