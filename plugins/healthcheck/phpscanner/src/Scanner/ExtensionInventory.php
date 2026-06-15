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
 * Builds an inventory of installed extensions from the #__extensions table, resolving for each one
 * its on-disk directories and the modification time of its manifest (used as the install/update time).
 *
 * Used both to list installed extensions with their install date and to decide which files on disk
 * legitimately belong to a registered extension.
 *
 * @since    __DEPLOY_VERSION__
 */
final class ExtensionInventory
{
    /**
     * @param   DatabaseInterface  $db    The database driver.
     * @param   string             $root  The absolute site root (no trailing slash).
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private DatabaseInterface $db, private string $root)
    {
    }

    /**
     * Returns every installed extension with its directories, manifest path and manifest mtime.
     *
     * @return  array  List of ['name','type','element','folder','client','dirs'=>string[],'manifest'=>?string,'mtime'=>int].
     *
     * @since    __DEPLOY_VERSION__
     */
    public function list(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(
                ['extension_id', 'package_id', 'name', 'type', 'element', 'folder', 'client_id', 'protected', 'locked', 'state']
            ))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('element') . ' != ' . $this->db->quote(''));

        $rows = $this->db->setQuery($query)->loadObjectList() ?: [];
        $out  = [];

        foreach ($rows as $row) {
            [$dirs, $manifest] = $this->paths($row);

            $mtime = ($manifest !== null && is_file($this->root . '/' . $manifest))
                ? (int) filemtime($this->root . '/' . $manifest)
                : 0;

            $out[] = [
                'extension_id' => (int) $row->extension_id,
                'package_id'   => (int) $row->package_id,
                'name'         => $row->name,
                'label'        => $this->label($row),
                'type'         => $row->type,
                'element'      => $row->element,
                'folder'       => $row->folder,
                'client'       => (int) $row->client_id,
                'protected'    => (int) $row->protected,
                'locked'       => (int) $row->locked,
                'state'        => (int) $row->state,
                'dirs'         => $dirs,
                'manifest'     => $manifest,
                'mtime'        => $mtime,
            ];
        }

        return $out;
    }

    /**
     * Returns a flat directory => reference-mtime ownership index, sorted longest path first so the
     * most specific directory wins.
     *
     * Protected (core) extensions are updated together with the Joomla core, so their reference is
     * the core update time rather than their own (often older) manifest. Third-party extensions use
     * their manifest mtime, falling back to $coreMtime when it cannot be read.
     *
     * @param   integer  $coreMtime  The core update time (last core manifest mtime).
     *
     * @return  array  List of ['dir' => string, 'mtime' => int].
     *
     * @since    __DEPLOY_VERSION__
     */
    public function ownership(int $coreMtime): array
    {
        $owners = [];

        foreach ($this->list() as $ext) {
            $ref = $ext['protected']
                ? max($coreMtime, $ext['mtime'])
                : ($ext['mtime'] ?: $coreMtime);

            foreach ($ext['dirs'] as $dir) {
                if ($dir !== '') {
                    $owners[] = ['dir' => $dir, 'mtime' => $ref];
                }
            }
        }

        usort($owners, static fn(array $a, array $b): int => \strlen($b['dir']) <=> \strlen($a['dir']));

        return $owners;
    }

    /**
     * Resolves an extension row to its on-disk directories and manifest path (all relative to root).
     *
     * @param   object  $row  An #__extensions row (type, element, folder, client_id).
     *
     * @return  array  [string[] $dirs, ?string $manifest].
     *
     * @since    __DEPLOY_VERSION__
     */
    private function paths(object $row): array
    {
        $element = $row->element;
        $folder  = $row->folder;
        $isAdmin = (int) $row->client_id === 1;

        switch ($row->type) {
            case 'component':
                $admin    = 'administrator/components/' . $element;
                $site     = 'components/' . $element;
                $stripped = preg_replace('/^com_/', '', $element);

                // The component manifest is usually named after the element without the "com_" prefix.
                $manifest = $this->firstFile([
                    $admin . '/' . $stripped . '.xml',
                    $site . '/' . $stripped . '.xml',
                    $admin . '/' . $element . '.xml',
                    $site . '/' . $element . '.xml',
                ]);

                return [[$admin, $site, 'media/' . $element], $manifest];

            case 'module':
                $dir = ($isAdmin ? 'administrator/modules/' : 'modules/') . $element;

                return [[$dir, 'media/' . $element], $dir . '/' . $element . '.xml'];

            case 'plugin':
                $dir = 'plugins/' . $folder . '/' . $element;

                return [[$dir, 'media/plg_' . $folder . '_' . $element], $dir . '/' . $element . '.xml'];

            case 'template':
                $dir = ($isAdmin ? 'administrator/templates/' : 'templates/') . $element;

                return [[$dir, 'media/templates/' . ($isAdmin ? 'administrator' : 'site') . '/' . $element], $dir . '/templateDetails.xml'];

            case 'library':
                return [['libraries/' . $element, 'media/lib_' . $element], 'administrator/manifests/libraries/' . $element . '.xml'];

            case 'language':
                $dir = ($isAdmin ? 'administrator/language/' : 'language/') . $element;

                return [[$dir], $dir . '/' . $element . '.xml'];

            case 'package':
                return [[], 'administrator/manifests/packages/' . $element . '.xml'];

            case 'file':
                return [[], 'administrator/manifests/files/' . $element . '.xml'];

            default:
                return [[], null];
        }
    }

    /**
     * Builds the canonical extension name for display (e.g. plg_healthcheck_phpscanner), so the
     * plugin group / extension type is never ambiguous.
     *
     * @param   object  $row  An #__extensions row.
     *
     * @return  string
     *
     * @since    __DEPLOY_VERSION__
     */
    private function label(object $row): string
    {
        return match ($row->type) {
            'plugin'   => 'plg_' . $row->folder . '_' . $row->element,
            'template' => 'tpl_' . $row->element,
            'library'  => 'lib_' . $row->element,
            'file'     => 'files_' . $row->element,
            'language' => 'lang_' . $row->element . '/' . $this->clientName((int) $row->client_id),
            default    => $row->element,
        };
    }

    /**
     * Maps a client id to its name (languages and modules install separately per client).
     *
     * @param   integer  $clientId  The #__extensions client_id.
     *
     * @return  string
     *
     * @since    __DEPLOY_VERSION__
     */
    private function clientName(int $clientId): string
    {
        return match ($clientId) {
            0       => 'site',
            1       => 'administrator',
            3       => 'api',
            default => 'client' . $clientId,
        };
    }

    /**
     * Returns the first candidate path (relative to root) that exists on disk, or null.
     *
     * @param   string[]  $candidates  Relative paths to test in order.
     *
     * @return  string|null
     *
     * @since    __DEPLOY_VERSION__
     */
    private function firstFile(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_file($this->root . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
