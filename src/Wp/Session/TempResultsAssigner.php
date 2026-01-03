<?php
declare(strict_types=1);

namespace MatchMe\Wp\Session;

use MatchMe\Infrastructure\Db\QuizResultRepository;
use MatchMe\Infrastructure\Db\ResultRepository;

final class TempResultsAssigner
{
    public function __construct(
        private QuizResultRepository $oldRepo,
        private ?ResultRepository $newRepo = null
    ) {
    }

    public function assignFromSessionToUser(int $userId): int
    {
        if (!session_id()) {
            session_start();
        }

        $totalAssigned = 0;

        // Assign old system results
        $oldIds = $_SESSION['temp_results'] ?? [];
        if (is_array($oldIds) && !empty($oldIds)) {
            $attemptIds = [];
            foreach ($oldIds as $id) {
                $attemptIds[] = (int) $id;
            }
            $totalAssigned += $this->oldRepo->reassignAttemptsToUser($attemptIds, $userId);
            $_SESSION['temp_results'] = [];
        }

        // Assign new system results
        if ($this->newRepo !== null) {
            $newIds = $_SESSION['match_me_temp_results'] ?? [];
            if (is_array($newIds) && !empty($newIds)) {
                $resultIds = [];
                foreach ($newIds as $id) {
                    $resultIds[] = (int) $id;
                }
                $totalAssigned += $this->newRepo->reassignResultsToUser($resultIds, $userId);
                $_SESSION['match_me_temp_results'] = [];
            }
        }

        return $totalAssigned;
    }
}



