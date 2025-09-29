<?php
declare(strict_types=1);

namespace CIS\Shared\Contracts;

/**
 * Errors.php
 *
 * Canonical error and warning codes for shared orchestration.
 *
 * @package CIS\Shared\Contracts
 */
final class Errors
{
    public const MISSING_PARAM = 'MISSING_PARAM';
    public const VALIDATION = 'VALIDATION';
    public const SYSTEM_RED = 'SYSTEM_RED';
    public const IDEMPOTENT_REPLAY = 'IDEMPOTENT_REPLAY';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public const VEND_UPSERT_FAIL = 'VEND_UPSERT_FAIL';
    public const PLANNER_UNAVAILABLE = 'PLANNER_UNAVAILABLE';
    public const UNKNOWN_TOTALS = 'UNKNOWN_TOTALS';
}
