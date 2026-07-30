<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.PhpScannerBaseline
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\PhpScannerBaseline\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Flags the PHP Scanner integrity baseline for rebuild after an extension is installed, updated or
 * uninstalled, or after a Joomla core update — so legitimate changes are not later reported as
 * tampering. The rebuild itself is deferred to the next integrity scan (it is not run during the
 * install request), keeping installs fast.
 *
 * @since    __DEPLOY_VERSION__
 */
final class PhpScannerBaseline extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * @inheritDoc
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onExtensionAfterInstall'   => 'flagRebuild',
            'onExtensionAfterUpdate'    => 'flagRebuild',
            'onExtensionAfterUninstall' => 'flagRebuild',
            'onJoomlaAfterUpdate'       => 'flagRebuild',
        ];
    }

    /**
     * Sets the PHP Scanner plugin's "rebuild baseline" option so the next integrity scan re-hashes
     * the (now legitimately changed) files, and drops the cached comparison.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function flagRebuild(): void
    {
        $plugin = PluginHelper::getPlugin('healthcheck', 'phpscanner');

        if (!\is_object($plugin)) {
            return;
        }

        $params = new Registry($plugin->params ?? '');

        // Respect the integrity feature and its auto-baseline option being enabled.
        if ($params->get('scanIntegrity', '1') != '1' || $params->get('autoBaselineOnUpdate', '1') != '1') {
            return;
        }

        $params->set('rebuildBaseline', '1');

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote($params->toString()))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('healthcheck'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('phpscanner'));

        $db->setQuery($query)->execute();

        @unlink(JPATH_CACHE . '/phpscanner_integrity.json');
    }
}
