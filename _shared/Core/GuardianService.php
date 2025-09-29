<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Config;
use CIS\Shared\Contracts\GuardianServiceInterface;

/**
 * GuardianService.php
 *
 * Minimal guardian-tier implementation.
 *
 * @package CIS\Shared\Core
 */
final class GuardianService implements GuardianServiceInterface
{
    /**
     * {@inheritDoc}
     */
    public function tier(): string
    {
        if (!Config::guardianEnabled()) {
            return 'green';
        }

        return 'green';
    }
}
