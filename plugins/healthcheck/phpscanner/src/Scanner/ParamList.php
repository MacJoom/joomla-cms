<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.PhpScanner
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\PhpScanner\Scanner;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Parses newline- or comma-separated plugin parameters into trimmed lists, falling back to a given
 * default when empty. Shared by the Health Check plugin and the scheduled-task plugin so both apply
 * the same parsing and the same defaults.
 *
 * @since    __DEPLOY_VERSION__
 */
final class ParamList
{
    /**
     * Splits a value on newlines only (use for patterns/regexes, which may contain commas).
     *
     * @param   string    $raw      The raw parameter value.
     * @param   string[]  $default  The default list when $raw is empty.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    public static function lines(string $raw, array $default): array
    {
        $list = array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw)));

        return $list ?: $default;
    }

    /**
     * Splits a value on newlines and commas (use for simple token / directory-name lists).
     *
     * @param   string    $raw      The raw parameter value.
     * @param   string[]  $default  The default list when $raw is empty.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    public static function commaList(string $raw, array $default): array
    {
        $list = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw)));

        return $list ?: $default;
    }
}
