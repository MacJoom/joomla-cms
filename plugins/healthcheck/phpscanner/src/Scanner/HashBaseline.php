<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.PhpScanner
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\PhpScanner\Scanner;

use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Stores and compares the file-integrity baseline (path => hash) in the #__phpscanner_baseline table.
 *
 * The baseline is established once (the trusted "original" hashes); later scans compare the current
 * hashes against it to detect changed, added or missing PHP files.
 *
 * @since    __DEPLOY_VERSION__
 */
final class HashBaseline
{
    /**
     * @param   DatabaseInterface  $db  The database driver.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Returns the number of baselined files (0 when no baseline has been established).
     *
     * @return  integer
     *
     * @since    __DEPLOY_VERSION__
     */
    public function count(): int
    {
        $this->ensureTable();

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__phpscanner_baseline'));

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Whether a baseline has yet to be established.
     *
     * @return  boolean
     *
     * @since    __DEPLOY_VERSION__
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Loads the stored baseline as a path => hash map.
     *
     * @return  array<string, string>
     *
     * @since    __DEPLOY_VERSION__
     */
    public function load(): array
    {
        $this->ensureTable();

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['path', 'hash']))
            ->from($this->db->quoteName('#__phpscanner_baseline'));

        $rows = $this->db->setQuery($query)->loadObjectList() ?: [];
        $out  = [];

        foreach ($rows as $row) {
            $out[$row->path] = $row->hash;
        }

        return $out;
    }

    /**
     * Replaces the baseline with the given path => hash map.
     *
     * @param   array<string, string>  $hashes  The new baseline.
     *
     * @return  integer  The number of stored entries.
     *
     * @since    __DEPLOY_VERSION__
     */
    public function store(array $hashes): int
    {
        $this->ensureTable();

        $this->db->truncateTable('#__phpscanner_baseline');

        $now    = gmdate('Y-m-d H:i:s');
        $table  = $this->db->quoteName('#__phpscanner_baseline');
        $cols   = $this->db->quoteName(['path', 'hash', 'created']);
        $stored = 0;

        foreach (array_chunk($hashes, 1000, true) as $chunk) {
            $values = [];

            foreach ($chunk as $path => $hash) {
                $values[] = '(' . $this->db->quote($path) . ', ' . $this->db->quote($hash) . ', ' . $this->db->quote($now) . ')';
            }

            $query = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ' . implode(', ', $values);
            $this->db->setQuery($query)->execute();

            $stored += \count($values);
        }

        return $stored;
    }

    /**
     * Compares a current path => hash map against the stored baseline.
     *
     * @param   array<string, string>  $current  The current hashes.
     *
     * @return  array  ['changed' => string[], 'added' => string[], 'missing' => string[]].
     *
     * @since    __DEPLOY_VERSION__
     */
    public function compare(array $current): array
    {
        $baseline = $this->load();
        $changed  = [];
        $added    = [];
        $missing  = [];

        foreach ($current as $path => $hash) {
            if (!isset($baseline[$path])) {
                $added[] = $path;
            } elseif ($baseline[$path] !== $hash) {
                $changed[] = $path;
            }
        }

        foreach ($baseline as $path => $hash) {
            if (!isset($current[$path])) {
                $missing[] = $path;
            }
        }

        sort($changed);
        sort($added);
        sort($missing);

        return ['changed' => $changed, 'added' => $added, 'missing' => $missing];
    }

    /**
     * Creates the baseline table if it does not exist (so the feature works without a reinstall).
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    private function ensureTable(): void
    {
        $table = $this->db->quoteName('#__phpscanner_baseline');

        if ($this->db->getServerType() === 'postgresql') {
            $sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
                . $this->db->quoteName('id') . ' serial NOT NULL, '
                . $this->db->quoteName('path') . ' varchar(1024) NOT NULL, '
                . $this->db->quoteName('hash') . ' char(64) NOT NULL, '
                . $this->db->quoteName('created') . ' timestamp without time zone DEFAULT NULL, '
                . 'PRIMARY KEY (' . $this->db->quoteName('id') . ')'
                . ')';
        } else {
            $sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
                . $this->db->quoteName('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . $this->db->quoteName('path') . ' VARCHAR(1024) NOT NULL, '
                . $this->db->quoteName('hash') . ' CHAR(64) NOT NULL, '
                . $this->db->quoteName('created') . ' DATETIME NULL, '
                . 'PRIMARY KEY (' . $this->db->quoteName('id') . ')'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        $this->db->setQuery($sql)->execute();
    }
}
