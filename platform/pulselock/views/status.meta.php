<?php
declare(strict_types=1);

/**
 * File: modules/platform/pulselock/views/status.meta.php
 * Purpose: Metadata declaration for PulseLock status hub routing integration.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-25
 */

return [
    'title' => 'PulseLock Status Hub',
    'icon' => 'fa-solid fa-heartbeat',
    'breadcrumbs' => [
        ['label' => 'Platform', 'url' => '/platform'],
        ['label' => 'PulseLock Status Hub'],
    ],
    'nav' => [
        'section' => 'platform',
        'active' => 'pulselock-status',
    ],
    'permissions' => ['view_pulselock'],
    'layout' => 'app',
];
