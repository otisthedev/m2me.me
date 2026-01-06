<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Infrastructure\Retention\RetentionPolicy;

/**
 * Schedules and runs data retention cleanup tasks.
 */
final class RetentionScheduler
{
    private const CRON_HOOK = 'match_me_retention_cleanup';
    private const CRON_INTERVAL = 'daily'; // Run once per day

    public function __construct(
        private RetentionPolicy $retentionPolicy
    ) {
    }

    public function register(): void
    {
        // Schedule the cleanup task
        add_action('init', [$this, 'scheduleCleanup']);
        
        // Register the cleanup handler
        add_action(self::CRON_HOOK, [$this, 'runCleanup']);
    }

    /**
     * Schedule the retention cleanup task if not already scheduled.
     */
    public function scheduleCleanup(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    /**
     * Run the retention cleanup task.
     */
    public function runCleanup(): void
    {
        $this->retentionPolicy->runCleanup();
    }

    /**
     * Unschedule the cleanup task (for deactivation).
     */
    public function unscheduleCleanup(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
}


