<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db;

/**
 * Repository for the match_me_quizzes table.
 */
final class QuizRepository
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    private function tableName(): string
    {
        return $this->wpdb->prefix . 'match_me_quizzes';
    }

    /**
     * Find quiz by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $table = $this->tableName();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT quiz_id, slug, title, version, aspect_id, meta, created_at, updated_at FROM $table WHERE slug = %s LIMIT 1",
                $slug
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Get or create quiz by slug.
     * Returns the quiz_id (database ID).
     *
     * @param string $slug Quiz slug identifier
     * @param string $title Quiz title
     * @param string $version Quiz version
     * @param array<string, mixed> $meta Additional metadata (JSON)
     * @return int quiz_id
     */
    public function getOrCreate(string $slug, string $title, string $version = '1.0', array $meta = []): int
    {
        $existing = $this->findBySlug($slug);
        if ($existing !== null) {
            return (int) $existing['quiz_id'];
        }

        // Create new quiz
        $table = $this->tableName();
        $metaJson = json_encode($meta, JSON_THROW_ON_ERROR);

        $data = [
            'slug' => $slug,
            'title' => $title,
            'version' => $version,
            'meta' => $metaJson,
        ];
        $format = ['%s', '%s', '%s', '%s'];

        $result = $this->wpdb->insert($table, $data, $format);

        if ($result === false) {
            throw new \RuntimeException('Failed to insert quiz: ' . $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }
}

