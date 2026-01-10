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

    /**
     * Get transient key for anonymous user based on session token or IP.
     */
    private function getTransientKey(): string
    {
        // Try to get session token first (more reliable for logged-in users who just registered)
        $sessionToken = wp_get_session_token();
        if ($sessionToken !== '') {
            return 'temp_results_' . md5($sessionToken);
        }

        // Fallback to IP-based key for anonymous users
        $ip = $this->getClientIp();
        return 'temp_results_' . md5($ip);
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                return sanitize_text_field((string) wp_unslash($_SERVER[$key]));
            }
        }
        return '0.0.0.0';
    }

    public function assignFromSessionToUser(int $userId): int
    {
        $totalAssigned = 0;
        $transientKey = $this->getTransientKey();

        // Get stored results from transient
        $storedResults = get_transient($transientKey);
        if (!is_array($storedResults)) {
            $storedResults = ['old' => [], 'new' => []];
        }

        // Assign old system results
        $oldIds = $storedResults['old'] ?? [];
        if (is_array($oldIds) && !empty($oldIds)) {
            $attemptIds = [];
            foreach ($oldIds as $id) {
                $attemptIds[] = (int) $id;
            }
            $totalAssigned += $this->oldRepo->reassignAttemptsToUser($attemptIds, $userId);
        }

        // Assign new system results
        if ($this->newRepo !== null) {
            $newIds = $storedResults['new'] ?? [];
            if (is_array($newIds) && !empty($newIds)) {
                $resultIds = [];
                foreach ($newIds as $id) {
                    $resultIds[] = (int) $id;
                }
                $totalAssigned += $this->newRepo->reassignResultsToUser($resultIds, $userId);
            }
        }

        // Clean up transient after assignment
        delete_transient($transientKey);

        return $totalAssigned;
    }
}



