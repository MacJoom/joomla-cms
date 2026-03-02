<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.usercolumns
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\UserColumns\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * User Columns plugin.
 *
 * Persists admin list column visibility preferences per user account
 * by storing them in #__users.params (key: hidden_tablecolumns).
 *
 * @since  __DEPLOY_VERSION__
 */
final class UserColumns extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Returns an array of events this subscriber wants to listen to.
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeRender'    => 'injectColumnState',
            'onAjaxUsercolumns' => 'saveColumnState',
        ];
    }

    /**
     * Inject saved column preferences into page script options so the JS
     * can read them without an extra network request.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function injectColumnState(): void
    {
        $app = $this->getApplication();

        if (!($app instanceof AdministratorApplication)) {
            return;
        }

        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            return;
        }

        // Tell the JS whether it is safe to sync column state to the server.
        $app->getDocument()->addScriptOptions('table.columns.sync', (bool) $this->params->get('save_database', 1));

        $stored = $user->getParam('hidden_tablecolumns', '');

        if (empty($stored)) {
            return;
        }

        $prefs = json_decode($stored, true);

        if (!\is_array($prefs) || empty($prefs)) {
            return;
        }

        $app->getDocument()->addScriptOptions('table.columns.state', $prefs);
    }

    /**
     * Handle AJAX request from the column toggle JS.
     * Saves the hidden-column list for a given table to the user's params.
     *
     * Called via: index.php?option=com_ajax&plugin=usercolumns&group=system&format=json
     *
     * POST params:
     *   - tableName  string  The table identifier (e.g. "articles")
     *   - hidden     string  Comma-separated hidden column indices, or empty string for none
     *   - {token}    1       Joomla CSRF token
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function saveColumnState(): void
    {
        $app = $this->getApplication();

        if (!($app instanceof AdministratorApplication)) {
            return;
        }

        if (!(bool) $this->params->get('save_database', 1)) {
            return;
        }

        if (!Session::checkToken('request')) {
            throw new \RuntimeException('Invalid token', 403);
        }

        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            throw new \RuntimeException('Not authenticated', 401);
        }

        $input     = $app->getInput();
        $tableName = trim($input->getString('tableName', ''));
        $hidden    = trim($input->getString('hidden', ''));

        if ($tableName === '') {
            return;
        }

        // Sanitise: allow only digits and commas
        $hidden = preg_replace('/[^0-9,]/', '', $hidden);

        $stored = $user->getParam('hidden_tablecolumns', '');
        $prefs  = !empty($stored) ? (json_decode($stored, true) ?: []) : [];

        if ($hidden === '') {
            unset($prefs[$tableName]);
        } else {
            $prefs[$tableName] = $hidden;
        }

        $user->setParam('hidden_tablecolumns', json_encode($prefs));
        $user->save();
    }
}
