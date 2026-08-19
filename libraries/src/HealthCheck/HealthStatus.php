<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\HealthCheck;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The health status a check result can carry.
 *
 * A health check plugin reports the severity of its result with this enum. The dashboard maps it to
 * both a presentation class and a filter bucket, so plugins never need to know either of them.
 *
 * @since  __DEPLOY_VERSION__
 */
enum HealthStatus: string
{
    /**
     * The check passed, nothing to do.
     *
     * @since __DEPLOY_VERSION__
     */
    case Success = 'success';

    /**
     * The check produced a result which deserves attention but is not a failure.
     *
     * @since __DEPLOY_VERSION__
     */
    case Warning = 'warning';

    /**
     * The check failed, or the checked condition is broken.
     *
     * @since __DEPLOY_VERSION__
     */
    case Error = 'error';

    /**
     * The check reports a value which carries no judgement at all.
     *
     * @since __DEPLOY_VERSION__
     */
    case Info = 'info';

    /**
     * Resolve a loosely typed status coming from a plugin into a case of this enum.
     *
     * Anything unrecognised - including null, objects and misspellings - resolves to Info so a
     * sloppy plugin degrades to a neutral result instead of being rendered as a false "healthy".
     *
     * @param   mixed  $status  The status to resolve.
     *
     * @return  self
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function fromLoose($status): self
    {
        if ($status instanceof self) {
            return $status;
        }

        if (!\is_string($status) && !\is_int($status)) {
            return self::Info;
        }

        return match (strtolower((string) $status)) {
            'success', 'ok', 'healthy', 'green'                    => self::Success,
            'warning', 'warn', 'alert', 'yellow'                   => self::Warning,
            'error', 'danger', 'critical', 'fail', 'failed', 'red' => self::Error,
            default                                                => self::Info,
        };
    }

    /**
     * The filter bucket this status belongs to.
     *
     * The dashboard filter bar only distinguishes three buckets, so Info and Success share one.
     *
     * @return  string  One of "healthy", "warning" or "critical".
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getFilterBucket(): string
    {
        return match ($this) {
            self::Success, self::Info => 'healthy',
            self::Warning             => 'warning',
            self::Error               => 'critical',
        };
    }

    /**
     * The Bootstrap contextual class used to render this status.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getCssClass(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Warning => 'warning',
            self::Error   => 'danger',
            self::Info    => 'info',
        };
    }
}
