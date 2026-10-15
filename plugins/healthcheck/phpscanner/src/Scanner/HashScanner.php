<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.PhpScanner
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\PhpScanner\Scanner;

use Joomla\Filesystem\Folder;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Computes a SHA-256 hash for every PHP file below a root, used to build and compare a file-integrity
 * baseline (tamper detection).
 *
 * Standalone (no Joomla application/database dependencies) so it can be reused by the Health Check
 * plugin and the scheduled-task plugin.
 *
 * @since    __DEPLOY_VERSION__
 */
final class HashScanner
{
    /**
     * Directory names skipped during the walk (runtime/VCS dirs that legitimately churn).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    public const DEFAULT_EXCLUDES = ['cache', 'tmp', 'logs', 'node_modules', '.git', '.svn'];

    /**
     * @param   string[]  $excludeDirs  Directory names to skip.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private array $excludeDirs = self::DEFAULT_EXCLUDES)
    {
    }

    /**
     * Returns a map of relative path => SHA-256 hash for every PHP file below $root.
     *
     * @param   string  $root  The absolute directory to scan.
     *
     * @return  array<string, string>
     *
     * @since    __DEPLOY_VERSION__
     */
    public function scan(string $root): array
    {
        $hashes = [];

        if (!is_dir($root)) {
            return $hashes;
        }

        $exclude = array_merge(['CVS', '.DS_Store', '__MACOSX'], $this->excludeDirs);

        foreach (Folder::files($root, '\.php$', true, true, $exclude) as $file) {
            $hash = @hash_file('sha256', $file);

            if ($hash === false) {
                continue;
            }

            $rel = '/' . ltrim(str_replace('\\', '/', substr($file, \strlen($root))), '/');

            $hashes[$rel] = $hash;
        }

        return $hashes;
    }
}
