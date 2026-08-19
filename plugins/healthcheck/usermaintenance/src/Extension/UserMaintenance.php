<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.UserMaintenance
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\UserMaintenance\Extension;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\HealthCheck\HealthStatus;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Plugin to check usermaintenance issues
 *
 * @since    __DEPLOY_VERSION__
 */
final class UserMaintenance extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        // which of the available Healthcheck events does the subscriber listen to?
        return [
            'onHealthcheckGetIcons' => 'onHealthcheckGetIcons', //  creates JSON array of QuickIcons
        ];
    }

    /**
     * Returns the array of individual check-results in the layout of "QuickIcons"
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onHealthcheckGetIcons(HealthChecksEvent $event): void
    {
        $context = $event->getContext();

        if ($context !== $this->params->get('context', 'general')) {
            $this->handleErrorMsg('onHealthcheckGetIcons wrong context: ' . $context, 'WARNING');
            return;
        }

        $this->loadLanguage();

        $checks = [];

        $checks['inactiveUsers']      = $this->getInactiveUsers();
        $checks['neverloggedinUsers'] = $this->getNeverLoggedinUsers();
        $checks['unactivatedUsers']   = $this->getUnactivatedUsers();
        $checks['orphanUsers']        = $this->getOrphanUsers();

        if (PluginHelper::isEnabled('multifactorauth')) {
            $checks['nonMFAUsers'] = $this->getNonMFAUsers();
        }

        $checks['privilegedUsers'] = $this->getPrivilegedUsers();

        // Add the buttons to the result array
        $result = $event->getArgument('result', []);

        $checkResults = [];

        foreach ($checks as $key => $check) {
            if (isset($check['error'])) {
                continue;
            }

            $checkResults[] = [
                'link'   => $check['link'],
                'icon'   => 'fas fa-users-gear',
                'amount' => $check['result'],
                'text'   => $check['text'],
                'title'  => $check['note'] ?? null,
                'id'     => 'plg_healthcheck_usermaintenance_' . strtolower($key),
                // Each check decides its own severity; a count above zero is not automatically a warning.
                'status' => $check['status'] ?? (($check['result'] > 0) ? HealthStatus::Warning : HealthStatus::Success),
            ];
        }

        $result[] = $checkResults;

        $event->setArgument('result', $result);
    }

    /**
     * Returns the number of users who did not login within the defined timespan (see plugin options)
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getInactiveUsers(): array
    {
        $item = [];

        if ($this->params->get('inactive_users', true)) {
            try {
                $inactiveTimespan = (int) $this->params->get('inactive_timespan', 180); // Days since the last login

                $item['text'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_INACTIVE_FIELD_LABEL');
                $item['note'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_INACTIVE_FIELD_DESC');
                $item['link'] = Uri::base() . 'index.php?option=com_users&view=users&filter[lastvisitrange]=inactive_' . $inactiveTimespan;

                $db      = $this->getDatabase();
                $nowDate = Factory::getDate()->toSql();

                $query = $db->createQuery();
                $query->select('COUNT(*) AS ' . $db->quoteName('number'))
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('lastvisitDate') . ' < ' . $query->dateAdd($db->quote($nowDate), -$inactiveTimespan, 'DAY'))
                    ->where($db->quoteName('lastvisitDate') . ' IS NOT NULL');

                $db->setQuery($query);

                $inactive = $db->loadObject();
                if (\is_object($inactive)) {
                    $number = $inactive->number;
                } else {
                    $number = 0;
                }
                $item['result'] = $number;
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_GETINACTIVE_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of users who never logged in
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getNeverLoggedinUsers(): array
    {
        $item = [];

        if ($this->params->get('neverloggedin_users', true)) {
            try {
                $item['text'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_NEVERLOGGEDIN_FIELD_LABEL');
                $item['note'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_NEVERLOGGEDIN_FIELD_DESC');
                $item['link'] = Uri::base() . 'index.php?option=com_users&view=users&filter[lastvisitrange]=never';

                $db = $this->getDatabase();

                $query = $db->createQuery()
                    ->select('COUNT(*) AS ' . $db->quoteName('number'))
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('lastvisitDate') . ' IS NULL');

                $db->setQuery($query);

                $neverLoggedin = $db->loadObject();
                if (\is_object($neverLoggedin)) {
                    $number = $neverLoggedin->number;
                } else {
                    $number = 0;
                }
                $item['result'] = $number;
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_GETNEVERLOGGEDIN_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of users who created an account, but did not activate it
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getUnactivatedUsers(): array
    {
        $item = [];

        if ($this->params->get('unactivated_users', true)) {
            try {
                $item['text'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_UNACTIVATED_FIELD_LABEL');
                $item['note'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_UNACTIVATED_FIELD_DESC');
                $item['link'] = Uri::base() . 'index.php?option=com_users&view=users&filter[active]=1';

                $db = $this->getDatabase();

                $query = $db->createQuery()
                    ->select('COUNT(*) AS ' . $db->quoteName('number'))
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('activation') . ' <> ' . $db->quote(''))
                    ->where($db->quoteName('block') . ' = ' . $db->quote('1'));

                $db->setQuery($query);

                $unactivated = $db->loadObject();
                if (\is_object($unactivated)) {
                    $number = $unactivated->number;
                } else {
                    $number = 0;
                }
                $item['result'] = $number;
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_GETUNACTIVATED_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of users not assigned any user group
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getOrphanUsers(): array
    {
        $item = [];

        if ($this->params->get('orphan_users', true)) {
            try {
                $item['text'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_ORPHAN_FIELD_LABEL');
                $item['note'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_ORPHAN_FIELD_DESC');
                $item['link'] = Uri::base() . 'index.php?option=com_users&view=users';

                $db = $this->getDatabase();

                $query = $db->createQuery()
                    ->select('COUNT(*) AS ' . $db->quoteName('number'))
                    ->from($db->quoteName('#__users', 'u'))
                    ->leftJoin(
                        $db->quoteName('#__user_usergroup_map', 'm'),
                        $db->quoteName('u.id') . ' = ' . $db->quoteName('m.user_id')
                    )
                    ->where($db->quoteName('m.user_id') . ' IS NULL');

                $db->setQuery($query);

                $orphan = $db->loadObject();
                if (\is_object($orphan)) {
                    $number = $orphan->number;
                } else {
                    $number = 0;
                }
                $item['result'] = $number;
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_GETORPHAN_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of users who have no MFA method configured
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getNonMFAUsers(): array
    {
        $item = [];

        if ($this->params->get('nonmfa_users', true)) {
            try {
                $item['text'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_NONMFA_FIELD_LABEL');
                $item['note'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_NONMFA_FIELD_DESC');
                $item['link'] = Uri::base() . 'index.php?option=com_users&view=users&filter[mfa]=0&filter[state]=0';

                $db = $this->getDatabase();

                /*
                 * Mirror the criteria com_users applies for its "mfa = 0" filter: a user counts as
                 * having no MFA only when they have neither an #__user_mfa record nor a legacy
                 * otpKey. Leaving otpKey out would count users here who do not appear in the linked
                 * result list.
                 */
                $query = $db->createQuery()
                    ->select('COUNT(DISTINCT ' . $db->quoteName('u.id') . ') AS ' . $db->quoteName('number'))
                    ->from($db->quoteName('#__users', 'u'))
                    ->leftJoin(
                        $db->quoteName('#__user_mfa', 'm'),
                        $db->quoteName('u.id') . ' = ' . $db->quoteName('m.user_id')
                    )
                    ->where($db->quoteName('m.id') . ' IS NULL')
                    ->where(
                        '(' . $db->quoteName('u.otpKey') . ' IS NULL OR '
                        . $db->quoteName('u.otpKey') . ' = ' . $db->quote('') . ')'
                    )
                    ->where($db->quoteName('u.block') . ' = 0');

                $db->setQuery($query);

                $nonmfa = $db->loadObject();
                if (\is_object($nonmfa)) {
                    $number = $nonmfa->number;
                } else {
                    $number = 0;
                }
                $item['result'] = $number;
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_GETNONMFA_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of "high privileged" users
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getPrivilegedUsers(): array
    {
        $item = [];

        if ($this->params->get('privileged_users', true)) {
            try {
                $adminGroupIds = $this->getAdminGroupIds();

                $item['text'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_PRIVILEGED_FIELD_LABEL');
                $item['note'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_PRIVILEGED_FIELD_DESC');

                /*
                 * Only filter the linked user list by group when a single privileged group exists;
                 * com_users takes one group id, so with several groups any fixed value would
                 * contradict the number shown on the icon.
                 */
                $item['link'] = Uri::base() . 'index.php?option=com_users&view=users';

                if (\count($adminGroupIds) === 1) {
                    $item['link'] .= '&filter[group_id]=' . (int) reset($adminGroupIds);
                }

                if (empty($adminGroupIds)) {
                    $item['result'] = 0;
                    $item['status'] = HealthStatus::Info;

                    return $item;
                }

                $db = $this->getDatabase();

                // COUNT DISTINCT: a user belonging to several privileged groups is still one user.
                $query = $db->createQuery()
                    ->select('COUNT(DISTINCT ' . $db->quoteName('u.id') . ') AS ' . $db->quoteName('number'))
                    ->from($db->quoteName('#__users', 'u'))
                    ->innerJoin(
                        $db->quoteName('#__user_usergroup_map', 'm'),
                        $db->quoteName('u.id') . ' = ' . $db->quoteName('m.user_id')
                    )
                    ->whereIn($db->quoteName('m.group_id'), $adminGroupIds, ParameterType::INTEGER);

                $db->setQuery($query);

                $privileged = $db->loadObject();

                if (\is_object($privileged)) {
                    $number = $privileged->number;
                } else {
                    $number = 0;
                }

                $item['result'] = $number;

                /*
                 * Every working site has at least one privileged user, so a non-zero count is normal
                 * and reported as information. Only an unusually large number is worth a warning.
                 */
                $threshold      = (int) $this->params->get('privileged_threshold', 3);
                $item['status'] = ($threshold > 0 && $number > $threshold)
                    ? HealthStatus::Warning
                    : HealthStatus::Info;
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_GETPRIVILEGED_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_USERMAINTENANCE_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Get the ids of any user group which has core.admin privileges
     *
     * @return  array  List of integer group IDs that hold core.admin permission.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getAdminGroupIds(): array
    {
        /*
         * Ask the ACL for every group rather than reading the root asset rules directly. Access
         * resolves the group tree, so a group which inherits core.admin from a parent is included
         * even though it carries no rule of its own.
         */
        $db = $this->getDatabase();

        $query = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__usergroups'));

        $db->setQuery($query);

        $adminGroupIds = [];

        foreach ($db->loadColumn() as $groupId) {
            if (Access::checkGroup((int) $groupId, 'core.admin')) {
                $adminGroupIds[] = (int) $groupId;
            }
        }

        return $adminGroupIds;
    }

    /**
     * Handle an error or warning message according to the configured logging strategy.
     *
     * @param   string  $msg       The message to log.
     * @param   string  $msgLevel  The severity level (e.g. 'ERROR', 'WARNING').
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function handleErrorMsg(string $msg, string $msgLevel): void
    {
        $msgContext = '[' . $this->_type . '-' . $this->_name . ']';
        $logging    = $this->params->get('logging', 0); // How to handle errors
        switch ($logging) {
            case 3: // enqueue Message
                Factory::getApplication()->enqueueMessage($msgContext . ' ' . $msg, $msgLevel);
                break;
            case 2: // log in JoomlaLog
                Log::add($msgContext . ' ' . $msg, Log::ERROR, 'plg_healthcheck_usermaintenance');
                break;
            case 1: // log in PHP error log
                error_log($msgContext . ' ' . $msg, 0);
                break;
            case 0:
            default:
                // Do not log anywhere
        }
    }
}
