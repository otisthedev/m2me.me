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
                "SELECT result_id, quiz_id, user_id, trait_vector, share_token, share_mode, quiz_version, created_at, revoked_at 
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
                "SELECT result_id, quiz_id, user_id, trait_vector, share_token, share_mode, quiz_version, created_at, revoked_at 
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
}

