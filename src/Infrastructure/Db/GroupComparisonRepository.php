<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db;

/**
 * Repository for group comparison database operations.
 */
final class GroupComparisonRepository
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    private function tableName(): string
    {
        return $this->wpdb->prefix . 'match_me_group_comparisons';
    }

    private function participantsTableName(): string
    {
        return $this->wpdb->prefix . 'match_me_group_participants';
    }

    /**
     * Create a new group comparison.
     */
    public function createGroup(
        string $quizSlug,
        int $createdByUserId,
        ?string $groupName = null
    ): int {
        $shareToken = $this->generateShareToken();
        $table = $this->tableName();

        $result = $this->wpdb->insert(
            $table,
            [
                'quiz_slug' => $quizSlug,
                'group_name' => $groupName,
                'share_token' => $shareToken,
                'created_by_user_id' => $createdByUserId,
                'status' => 'inviting',
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );

        if ($result === false) {
            throw new \RuntimeException('Failed to create group: ' . $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Add participant to group.
     */
    public function addParticipant(
        int $groupId,
        int $invitedByUserId,
        ?int $userId = null,
        ?string $email = null,
        ?string $name = null
    ): string {
        $inviteToken = $this->generateInviteToken();
        $table = $this->participantsTableName();

        $result = $this->wpdb->insert(
            $table,
            [
                'group_id' => $groupId,
                'user_id' => $userId,
                'invite_token' => $inviteToken,
                'invite_email' => $email,
                'invite_name' => $name,
                'status' => $userId ? 'pending' : 'invited',
                'invited_by_user_id' => $invitedByUserId,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%d']
        );

        if ($result === false) {
            throw new \RuntimeException('Failed to add participant: ' . $this->wpdb->last_error);
        }

        return $inviteToken;
    }

    /**
     * Get group with all participants.
     *
     * @return array<string, mixed>|null
     */
    public function getGroupWithParticipants(int $groupId): ?array
    {
        $groupTable = $this->tableName();
        $participantsTable = $this->participantsTableName();

        $group = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT group_id, quiz_slug, group_name, share_token, created_by_user_id, status, created_at, completed_at
                 FROM {$groupTable}
                 WHERE group_id = %d
                 LIMIT 1",
                $groupId
            ),
            ARRAY_A
        );

        if (!is_array($group)) {
            return null;
        }

        $participants = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, result_id, user_id, invite_token, invite_email, invite_name, status, invited_at, completed_at
                 FROM {$participantsTable}
                 WHERE group_id = %d
                 ORDER BY invited_at ASC",
                $groupId
            ),
            ARRAY_A
        );

        $group['participants'] = is_array($participants) ? $participants : [];

        return $group;
    }

    /**
     * Find group by share token.
     *
     * @return array<string, mixed>|null
     */
    public function findByShareToken(string $shareToken): ?array
    {
        $table = $this->tableName();
        $group = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT group_id, quiz_slug, group_name, share_token, created_by_user_id, status, created_at, completed_at
                 FROM {$table}
                 WHERE share_token = %s
                 LIMIT 1",
                $shareToken
            ),
            ARRAY_A
        );

        if (!is_array($group)) {
            return null;
        }

        return $this->getGroupWithParticipants((int) $group['group_id']);
    }

    /**
     * Find participant by invite token.
     *
     * @return array<string, mixed>|null
     */
    public function findParticipantByInviteToken(string $inviteToken): ?array
    {
        $table = $this->participantsTableName();
        $participant = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, group_id, result_id, user_id, invite_token, invite_email, invite_name, status, invited_at, completed_at
                 FROM {$table}
                 WHERE invite_token = %s
                 LIMIT 1",
                $inviteToken
            ),
            ARRAY_A
        );

        return is_array($participant) ? $participant : null;
    }

    /**
     * Mark participant as completed.
     */
    public function markParticipantCompleted(int $participantId, int $resultId): bool
    {
        $table = $this->participantsTableName();
        $updated = $this->wpdb->update(
            $table,
            [
                'result_id' => $resultId,
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
            ],
            ['id' => $participantId],
            ['%d', '%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * Check if group is ready (all participants completed).
     */
    public function isGroupReady(int $groupId): bool
    {
        $table = $this->participantsTableName();
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE group_id = %d",
                $groupId
            )
        );
        $completed = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE group_id = %d AND status = 'completed'",
                $groupId
            )
        );

        return $total > 0 && $completed === $total;
    }

    /**
     * Update group status.
     */
    public function updateGroupStatus(int $groupId, string $status): bool
    {
        $table = $this->tableName();
        $data = ['status' => $status];
        if ($status === 'completed') {
            $data['completed_at'] = current_time('mysql');
        }

        $updated = $this->wpdb->update(
            $table,
            $data,
            ['group_id' => $groupId],
            $status === 'completed' ? ['%s', '%s'] : ['%s'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * Get all results for a group.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGroupResults(int $groupId): array
    {
        $participantsTable = $this->participantsTableName();
        $resultsTable = $this->wpdb->prefix . 'match_me_results';

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT p.id as participant_id, p.user_id, p.invite_name, p.invite_email,
                        r.result_id, r.trait_vector, r.share_token
                 FROM {$participantsTable} p
                 INNER JOIN {$resultsTable} r ON p.result_id = r.result_id
                 WHERE p.group_id = %d AND p.status = 'completed'
                 ORDER BY p.completed_at ASC",
                $groupId
            ),
            ARRAY_A
        );

        return is_array($results) ? $results : [];
    }

    private function generateShareToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function generateInviteToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}

