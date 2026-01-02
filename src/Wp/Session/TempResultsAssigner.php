<?php
declare(strict_types=1);

namespace MatchMe\Wp\Session;

use MatchMe\Infrastructure\Db\QuizResultRepository;

final class TempResultsAssigner
{
    public function __construct(private QuizResultRepository $repo)
    {
    }

    public function assignFromSessionToUser(int $userId): int
    {
        if (!session_id()) {
            session_start();
        }

        $ids = $_SESSION['temp_results'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return 0;
        }

        $attemptIds = [];
        foreach ($ids as $id) {
            $attemptIds[] = (int) $id;
        }

        $updated = $this->repo->reassignAttemptsToUser($attemptIds, $userId);
        $_SESSION['temp_results'] = [];
        return $updated;
    }
}



