<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO;
use Throwable;

/**
 * PerformanceTracker
 * 
 * Tracks real performance metrics for transfers:
 * - Time from "released for packing" to "marked complete" 
 * - Hidden from staff but recorded for management reporting
 * - Measures actual work time, not page load time
 */
final class PerformanceTracker
{
    private PDO $pdo;

    public function __construct()
    {
        // Use the global database connection function that's available in the CIS system
        $this->pdo = cis_pdo();
        $this->createTableIfNotExists();
    }

    private function createTableIfNotExists(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS transfer_performance_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transfer_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                event_type ENUM('RELEASED_FOR_PACKING', 'PACKING_STARTED', 'PACKING_COMPLETED', 'CANCELLED') NOT NULL,
                event_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                session_id VARCHAR(100) NULL,
                user_agent TEXT NULL,
                additional_data JSON NULL,
                INDEX idx_transfer_events (transfer_id, event_type),
                INDEX idx_user_performance (user_id, event_timestamp),
                INDEX idx_timing_analysis (transfer_id, event_type, event_timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Record when a transfer is released for packing
     * This starts the performance timer
     */
    public function recordReleasedForPacking(int $transferId, int $managerId, array $additionalData = []): void
    {
        $this->logEvent($transferId, $managerId, 'RELEASED_FOR_PACKING', $additionalData);
    }

    /**
     * Record when someone starts packing (acquires lock)
     * This marks the actual start of work
     */
    public function recordPackingStarted(int $transferId, int $userId, string $sessionId = null): void
    {
        // Only record if we don't already have a PACKING_STARTED for this transfer
        $existing = $this->pdo->prepare("
            SELECT id FROM transfer_performance_logs 
            WHERE transfer_id = ? AND event_type = 'PACKING_STARTED' 
            LIMIT 1
        ");
        $existing->execute([$transferId]);
        
        if (!$existing->fetchColumn()) {
            $this->logEvent($transferId, $userId, 'PACKING_STARTED', [
                'session_id' => $sessionId,
                'started_from_lock_acquire' => true
            ]);
        }
    }

    /**
     * Record when packing is completed
     * This ends the performance timer
     */
    public function recordPackingCompleted(int $transferId, int $userId, array $packingData = []): void
    {
        $this->logEvent($transferId, $userId, 'PACKING_COMPLETED', $packingData);
    }

    /**
     * Record when transfer is cancelled
     */
    public function recordCancelled(int $transferId, int $userId, string $reason = ''): void
    {
        $this->logEvent($transferId, $userId, 'CANCELLED', ['reason' => $reason]);
    }

    /**
     * Get performance metrics for a transfer
     */
    public function getTransferMetrics(int $transferId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                event_type,
                event_timestamp,
                user_id,
                additional_data
            FROM transfer_performance_logs 
            WHERE transfer_id = ? 
            ORDER BY event_timestamp ASC
        ");
        $stmt->execute([$transferId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($events)) {
            return null;
        }

        $metrics = [
            'transfer_id' => $transferId,
            'events' => $events,
            'timings' => []
        ];

        // Calculate timing metrics
        $releasedTime = null;
        $startedTime = null;
        $completedTime = null;

        foreach ($events as $event) {
            switch ($event['event_type']) {
                case 'RELEASED_FOR_PACKING':
                    $releasedTime = strtotime($event['event_timestamp']);
                    break;
                case 'PACKING_STARTED':
                    $startedTime = strtotime($event['event_timestamp']);
                    break;
                case 'PACKING_COMPLETED':
                    $completedTime = strtotime($event['event_timestamp']);
                    break;
            }
        }

        if ($releasedTime && $startedTime) {
            $metrics['timings']['queue_time_seconds'] = $startedTime - $releasedTime;
            $metrics['timings']['queue_time_formatted'] = $this->formatDuration($startedTime - $releasedTime);
        }

        if ($startedTime && $completedTime) {
            $metrics['timings']['packing_time_seconds'] = $completedTime - $startedTime;
            $metrics['timings']['packing_time_formatted'] = $this->formatDuration($completedTime - $startedTime);
        }

        if ($releasedTime && $completedTime) {
            $metrics['timings']['total_time_seconds'] = $completedTime - $releasedTime;
            $metrics['timings']['total_time_formatted'] = $this->formatDuration($completedTime - $releasedTime);
        }

        return $metrics;
    }

    /**
     * Get performance report for date range
     */
    public function getPerformanceReport(string $startDate, string $endDate, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                tpl.transfer_id,
                MIN(CASE WHEN tpl.event_type = 'RELEASED_FOR_PACKING' THEN tpl.event_timestamp END) as released_at,
                MIN(CASE WHEN tpl.event_type = 'PACKING_STARTED' THEN tpl.event_timestamp END) as started_at,
                MIN(CASE WHEN tpl.event_type = 'PACKING_COMPLETED' THEN tpl.event_timestamp END) as completed_at,
                MIN(CASE WHEN tpl.event_type = 'PACKING_STARTED' THEN tpl.user_id END) as packer_user_id,
                COUNT(DISTINCT CASE WHEN tpl.event_type = 'PACKING_STARTED' THEN tpl.user_id END) as unique_packers,
                
                -- Calculate timing differences
                TIMESTAMPDIFF(SECOND, 
                    MIN(CASE WHEN tpl.event_type = 'RELEASED_FOR_PACKING' THEN tpl.event_timestamp END),
                    MIN(CASE WHEN tpl.event_type = 'PACKING_STARTED' THEN tpl.event_timestamp END)
                ) as queue_time_seconds,
                
                TIMESTAMPDIFF(SECOND, 
                    MIN(CASE WHEN tpl.event_type = 'PACKING_STARTED' THEN tpl.event_timestamp END),
                    MIN(CASE WHEN tpl.event_type = 'PACKING_COMPLETED' THEN tpl.event_timestamp END)
                ) as packing_time_seconds,
                
                TIMESTAMPDIFF(SECOND, 
                    MIN(CASE WHEN tpl.event_type = 'RELEASED_FOR_PACKING' THEN tpl.event_timestamp END),
                    MIN(CASE WHEN tpl.event_type = 'PACKING_COMPLETED' THEN tpl.event_timestamp END)
                ) as total_time_seconds

            FROM transfer_performance_logs tpl
            WHERE tpl.event_timestamp BETWEEN ? AND ?
            GROUP BY tpl.transfer_id
            HAVING released_at IS NOT NULL
            ORDER BY released_at DESC
            LIMIT ?
        ");
        
        $stmt->execute([$startDate, $endDate, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format durations for display
        foreach ($results as &$result) {
            if ($result['queue_time_seconds']) {
                $result['queue_time_formatted'] = $this->formatDuration($result['queue_time_seconds']);
            }
            if ($result['packing_time_seconds']) {
                $result['packing_time_formatted'] = $this->formatDuration($result['packing_time_seconds']);
            }
            if ($result['total_time_seconds']) {
                $result['total_time_formatted'] = $this->formatDuration($result['total_time_seconds']);
            }
        }

        return $results;
    }

    /**
     * Get user performance statistics
     */
    public function getUserStats(int $userId, string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(DISTINCT tpl.transfer_id) as transfers_completed,
                AVG(TIMESTAMPDIFF(SECOND, 
                    (SELECT event_timestamp FROM transfer_performance_logs t2 
                     WHERE t2.transfer_id = tpl.transfer_id AND t2.event_type = 'PACKING_STARTED' LIMIT 1),
                    tpl.event_timestamp
                )) as avg_packing_time_seconds,
                MIN(TIMESTAMPDIFF(SECOND, 
                    (SELECT event_timestamp FROM transfer_performance_logs t2 
                     WHERE t2.transfer_id = tpl.transfer_id AND t2.event_type = 'PACKING_STARTED' LIMIT 1),
                    tpl.event_timestamp
                )) as fastest_packing_time_seconds,
                MAX(TIMESTAMPDIFF(SECOND, 
                    (SELECT event_timestamp FROM transfer_performance_logs t2 
                     WHERE t2.transfer_id = tpl.transfer_id AND t2.event_type = 'PACKING_STARTED' LIMIT 1),
                    tpl.event_timestamp
                )) as slowest_packing_time_seconds
            FROM transfer_performance_logs tpl
            WHERE tpl.user_id = ? 
                AND tpl.event_type = 'PACKING_COMPLETED'
                AND tpl.event_timestamp BETWEEN ? AND ?
        ");
        
        $stmt->execute([$userId, $startDate, $endDate]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stats['avg_packing_time_seconds']) {
            $stats['avg_packing_time_formatted'] = $this->formatDuration((int)$stats['avg_packing_time_seconds']);
        }
        if ($stats['fastest_packing_time_seconds']) {
            $stats['fastest_packing_time_formatted'] = $this->formatDuration($stats['fastest_packing_time_seconds']);
        }
        if ($stats['slowest_packing_time_seconds']) {
            $stats['slowest_packing_time_formatted'] = $this->formatDuration($stats['slowest_packing_time_seconds']);
        }

        return $stats ?: [];
    }

    /**
     * Check if transfer is currently being timed
     */
    public function isTransferBeingTimed(int $transferId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM transfer_performance_logs 
            WHERE transfer_id = ? 
                AND event_type IN ('RELEASED_FOR_PACKING', 'PACKING_STARTED')
                AND transfer_id NOT IN (
                    SELECT transfer_id FROM transfer_performance_logs 
                    WHERE event_type IN ('PACKING_COMPLETED', 'CANCELLED')
                )
            LIMIT 1
        ");
        $stmt->execute([$transferId]);
        return (bool)$stmt->fetchColumn();
    }

    private function logEvent(int $transferId, int $userId, string $eventType, array $additionalData = []): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO transfer_performance_logs 
                (transfer_id, user_id, event_type, session_id, user_agent, additional_data) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $sessionId = session_id() ?: null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $jsonData = !empty($additionalData) ? json_encode($additionalData) : null;
            
            $stmt->execute([
                $transferId, 
                $userId, 
                $eventType, 
                $sessionId, 
                $userAgent, 
                $jsonData
            ]);
        } catch (Throwable $e) {
            // Log error but don't break the main flow
            error_log("PerformanceTracker::logEvent failed: " . $e->getMessage());
        }
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return $minutes . 'm ' . $remainingSeconds . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }
}