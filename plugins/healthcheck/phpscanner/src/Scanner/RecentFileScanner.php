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
 * Walks a directory tree for PHP files that do not look like part of a regular install: files that
 * belong to no registered extension (orphans), or that were modified after their owning extension
 * was installed. Both are classic indicators of a file dropped outside the installer, e.g. a shell.
 *
 * Standalone (no Joomla application/database dependencies); the ownership index is supplied by the
 * caller (see ExtensionInventory) so the class can be reused by the Health Check and task plugins.
 *
 * @since    __DEPLOY_VERSION__
 */
final class RecentFileScanner
{
    /**
     * Path prefixes (relative to root) owned by the core "files" extension / framework, whose
     * reference time is the last core update.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    public const DEFAULT_CORE_PREFIXES = [
        'includes/',
        'libraries/',
        'api/',
        'cli/',
        'layouts/',
        'media/',
        'plugins/',
        'modules/',
        'installation/',
        'administrator/includes/',
        'administrator/templates/system/',
        'templates/system/',
    ];

    /**
     * Directory names skipped during the walk (these churn for legitimate reasons).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    public const DEFAULT_EXCLUDES = ['cache', 'tmp', 'logs', 'node_modules', '.git', '.svn', 'manifests', 'build', 'tests'];

    /**
     * Seconds a file may be newer than its owning extension's manifest before it is flagged (absorbs
     * the small mtime spread between extracting files and writing the manifest during an install).
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const TOLERANCE = 300;

    /**
     * Number of files (within a 1-second window) that must share a timestamp inside the same owner
     * for that timestamp to be treated as a bulk operation (install/update/deploy) rather than an
     * isolated change. Late-modified files belonging to such a cohort are not flagged.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const COHORT_MIN = 4;

    /**
     * Number of files (within a 1-second window) that must share a timestamp **site-wide** for that
     * timestamp to be treated as a bulk event (core update, deploy, checkout, migration). Such waves
     * touch files across many extensions without rewriting each manifest; files in them are trusted.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const GLOBAL_COHORT_MIN = 15;

    /**
     * @param   array     $owners        Ownership index of ['dir' => string, 'mtime' => int], longest dir first.
     * @param   integer   $coreMtime     Reference time for core-owned paths (last core update).
     * @param   string[]  $corePrefixes  Path prefixes treated as core-owned.
     * @param   string[]  $excludeDirs   Directory names to skip.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(
        private array $owners,
        private int $coreMtime,
        private array $corePrefixes = self::DEFAULT_CORE_PREFIXES,
        private array $excludeDirs = self::DEFAULT_EXCLUDES
    ) {
    }

    /**
     * Scans the tree below $root for orphan or late-modified PHP files.
     *
     * @param   string  $root  The absolute directory to scan.
     *
     * @return  array  List of ['path' => string, 'mtime' => int, 'reason' => 'orphan'|'changed'] items,
     *                 newest first, capped at 500.
     *
     * @since    __DEPLOY_VERSION__
     */
    public function scan(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $exclude = array_merge(['CVS', '.DS_Store', '__MACOSX'], $this->excludeDirs);

        // First pass: collect every PHP file with its owner, and build both a per-owner and a
        // site-wide timestamp histogram so we can tell a bulk operation (many files sharing a time)
        // from a lone change.
        $files      = [];
        $hist       = [];
        $globalHist = [];

        foreach (Folder::files($root, '\.php$', true, true, $exclude) as $file) {
            $mtime = @filemtime($file);

            if ($mtime === false) {
                continue;
            }

            $rel = ltrim(str_replace('\\', '/', substr($file, \strlen($root))), '/');

            [$ref, $orphan, $key] = $this->resolve($rel);

            $files[] = ['rel' => $rel, 'mtime' => $mtime, 'ref' => $ref, 'orphan' => $orphan, 'key' => $key];

            if (!$orphan) {
                $hist[$key][$mtime]   = ($hist[$key][$mtime] ?? 0) + 1;
                $globalHist[$mtime]   = ($globalHist[$mtime] ?? 0) + 1;
            }
        }

        // Second pass: classify. Buckets are capped independently so the high-signal orphans are
        // never crowded out by a large number of "changed" files.
        $orphans = [];
        $changed = [];

        foreach ($files as $f) {
            if ($f['orphan']) {
                if (\count($orphans) < 500) {
                    $orphans[] = ['path' => '/' . $f['rel'], 'mtime' => $f['mtime'], 'reason' => 'orphan'];
                }

                continue;
            }

            if ($f['mtime'] <= $f['ref'] + self::TOLERANCE) {
                continue;
            }

            // A core update touches files across many extensions at the same instant; a file whose
            // mtime coincides with the core update is part of that wave, whoever nominally owns it.
            if (abs($f['mtime'] - $this->coreMtime) <= self::TOLERANCE) {
                continue;
            }

            // A timestamp shared by many files site-wide is a bulk event (deploy, checkout, update)
            // that touched files without rewriting manifests — trust files belonging to it.
            if ($this->cohortSize($globalHist, $f['mtime']) >= self::GLOBAL_COHORT_MIN) {
                continue;
            }

            // A file laid down together with several siblings of the same extension/owner is part of
            // a bulk operation, not a planted file — trust it even though it post-dates the manifest.
            if ($this->cohortSize($hist[$f['key']] ?? [], $f['mtime']) >= self::COHORT_MIN) {
                continue;
            }

            if (\count($changed) < 500) {
                $changed[] = ['path' => '/' . $f['rel'], 'mtime' => $f['mtime'], 'reason' => 'changed'];
            }
        }

        $sort = static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime'];
        usort($orphans, $sort);
        usort($changed, $sort);

        return array_merge($orphans, $changed);
    }

    /**
     * Counts how many files of the same owner share the given timestamp within a 1-second window.
     *
     * @param   array    $histogram  Map of mtime => file count for one owner.
     * @param   integer  $mtime      The timestamp to test.
     *
     * @return  integer
     *
     * @since    __DEPLOY_VERSION__
     */
    private function cohortSize(array $histogram, int $mtime): int
    {
        return ($histogram[$mtime - 1] ?? 0) + ($histogram[$mtime] ?? 0) + ($histogram[$mtime + 1] ?? 0);
    }

    /**
     * Resolves the reference time and owner key for a relative path, or marks it as an orphan.
     *
     * @param   string  $rel  The path relative to root (forward slashes, no leading slash).
     *
     * @return  array  [int $referenceMtime, bool $isOrphan, string $ownerKey].
     *
     * @since    __DEPLOY_VERSION__
     */
    private function resolve(string $rel): array
    {
        foreach ($this->owners as $owner) {
            if (str_starts_with($rel, $owner['dir'] . '/')) {
                return [$owner['mtime'], false, $owner['dir']];
            }
        }

        foreach ($this->corePrefixes as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return [$this->coreMtime, false, 'core'];
            }
        }

        // A file sitting directly in the site root (index.php) or an application root
        // (administrator/index.php, api/index.php, ...) is part of the core install.
        $segments = explode('/', $rel);

        if (\count($segments) === 1) {
            return [$this->coreMtime, false, 'core'];
        }

        if (\count($segments) === 2 && \in_array($segments[0], ['administrator', 'api', 'cli', 'installation'], true)) {
            return [$this->coreMtime, false, 'core'];
        }

        return [0, true, ''];
    }
}
