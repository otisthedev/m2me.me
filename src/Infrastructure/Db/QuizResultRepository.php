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
                "SELECT attempt_id, user_id, quiz_id, scores, content, created_at FROM $tableName WHERE user_id = %d AND quiz_id = %s ORDER BY attempt_id DESC LIMIT 1",
                $userId,
                $quizId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int,array{quiz_id:string,latest_attempt:int}>
     */
    public function latestAttemptsByUserGroupedByQuiz(int $userId): array
    {
        $tableName = $this->table->name();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT quiz_id, MAX(attempt_id) as latest_attempt FROM $tableName WHERE user_id = %d GROUP BY quiz_id",
                $userId
            ),
            ARRAY_A
        );

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['quiz_id'], $row['latest_attempt'])) {
                continue;
            }
            $out[] = [
                'quiz_id' => (string) $row['quiz_id'],
                'latest_attempt' => (int) $row['latest_attempt'],
            ];
        }
        return $out;
    }

    /**
     * @param array<int,int> $attemptIds
     */
    public function reassignAttemptsToUser(array $attemptIds, int $userId): int
    {
        $tableName = $this->table->name();
        $updatedCount = 0;

        foreach ($attemptIds as $attemptId) {
            $attemptId = (int) $attemptId;
            if ($attemptId <= 0) {
                continue;
            }

            $updated = $this->wpdb->update(
                $tableName,
                ['user_id' => $userId],
                ['attempt_id' => $attemptId],
                ['%d'],
                ['%d']
            );

            if ($updated !== false && $updated > 0) {
                $updatedCount++;
            }
        }

        return $updatedCount;
    }
}


