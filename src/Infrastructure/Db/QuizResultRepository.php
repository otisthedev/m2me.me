<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db;

final class QuizResultRepository
{
    public function __construct(private \wpdb $wpdb, private QuizResultsTable $table)
    {
    }

    /**
     * @return int attempt_id
     */
    public function insert(int $userId, string $quizId, string $scoresJson, string $contentHtml): int
    {
        $quizId = sanitize_text_field($quizId);
        if ($quizId === '') {
            throw new \InvalidArgumentException('quiz_id is required.');
        }

        $tableName = $this->table->name();

        $data = [
            'user_id' => $userId,
            'quiz_id' => $quizId,
            'scores' => sanitize_text_field($scoresJson),
            'content' => wp_kses_post($contentHtml),
            'created_at' => current_time('mysql'),
        ];

        $ok = $this->wpdb->insert($tableName, $data, ['%d', '%s', '%s', '%s', '%s']);
        if ($ok === false) {
            throw new \RuntimeException('Failed to save results: ' . $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByAttemptId(int $attemptId, string $quizId): ?array
    {
        $tableName = $this->table->name();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT attempt_id, user_id, quiz_id, scores, content, created_at FROM $tableName WHERE attempt_id = %d AND quiz_id = %s LIMIT 1",
                $attemptId,
                $quizId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findLatestByUserAndQuiz(int $userId, string $quizId): ?array
    {
        $tableName = $this->table->name();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT attempt_id, user_id, quiz_id, scores, content, created_at FROM $tableName WHERE user_id = %d AND quiz_id = %s ORDER BY created_at DESC LIMIT 1",
                $userId,
                $quizId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Get latest attempt for each quiz for a user.
     *
     * @return array<string, array<string,mixed>>
     */
    public function latestAttemptsByUserGroupedByQuiz(int $userId): array
    {
        $tableName = $this->table->name();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT quiz_id, MAX(created_at) AS latest_created, 
                        SUBSTRING_INDEX(GROUP_CONCAT(attempt_id ORDER BY created_at DESC), ',', 1) AS latest_attempt
                 FROM $tableName 
                 WHERE user_id = %d 
                 GROUP BY quiz_id",
                $userId
            ),
            ARRAY_A
        );

        $result = [];
        foreach ($rows as $row) {
            $quizId = (string) $row['quiz_id'];
            $attemptId = (int) $row['latest_attempt'];
            $result[$quizId] = [
                'quiz_id' => $quizId,
                'latest_attempt' => $attemptId,
                'created_at' => (string) $row['latest_created'],
            ];
        }

        return $result;
    }

    /**
     * Reassign anonymous quiz attempts to a user.
     * Updates user_id for attempts that were created with user_id = 9999 (anonymous placeholder).
     *
     * @param array<int> $attemptIds Array of attempt IDs to reassign
     * @param int $userId Target user ID
     * @return int Number of reassigned attempts
     */
    public function reassignAttemptsToUser(array $attemptIds, int $userId): int
    {
        if (empty($attemptIds)) {
            return 0;
        }

        $tableName = $this->table->name();
        $placeholders = implode(',', array_fill(0, count($attemptIds), '%d'));

        // Only update attempts with user_id = 9999 (anonymous placeholder)
        $sql = "UPDATE $tableName SET user_id = %d WHERE attempt_id IN ($placeholders) AND user_id = 9999";
        
        $prepared = $this->wpdb->prepare($sql, $userId, ...$attemptIds);
        
        $updated = $this->wpdb->query($prepared);
        return $updated !== false ? (int) $updated : 0;
    }

    /**
     * Find all quiz results for a user (for GDPR export).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $userId): array
    {
        $tableName = $this->table->name();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT attempt_id, user_id, quiz_id, scores, content, created_at FROM $tableName WHERE user_id = %d ORDER BY created_at DESC",
                $userId
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    /**
     * Delete all quiz results for a user (for GDPR deletion).
     *
     * @return int Number of deleted rows
     */
    public function deleteByUser(int $userId): int
    {
        $tableName = $this->table->name();
        $deleted = $this->wpdb->delete($tableName, ['user_id' => $userId], ['%d']);
        return $deleted !== false ? (int) $deleted : 0;
    }
}
