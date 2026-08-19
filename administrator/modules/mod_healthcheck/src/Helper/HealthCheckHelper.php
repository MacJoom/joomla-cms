<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  mod_healthcheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Module\Healthcheck\Administrator\Helper;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\HealthCheck\HealthStatus;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Helper for mod_healthcheck
 *
 * Collects health check results from the healthcheck plugin group and normalises them into the
 * documented result contract before they reach a layout. Every plugin is invoked in isolation, so a
 * plugin which throws only loses its own results instead of taking down the whole dashboard.
 *
 * @since  __DEPLOY_VERSION__
 */
class HealthCheckHelper
{
    /**
     * Stack to hold gauges
     *
     * @var     array[]
     * @since   __DEPLOY_VERSION__
     */
    protected $gauges = [];

    /**
     * Stack to hold buttons
     *
     * @var     array[]
     * @since   __DEPLOY_VERSION__
     */
    protected $buttons = [];

    /**
     * Stack to hold lists
     *
     * @var     array[]
     * @since   __DEPLOY_VERSION__
     */
    protected $lists = [];

    /**
     * Stack to hold tables
     *
     * @var     array[]
     * @since   __DEPLOY_VERSION__
     */
    protected $tables = [];

    /**
     * Stack to hold leading information
     *
     * @var     array[]
     * @since   __DEPLOY_VERSION__
     */
    protected $leading = [];

    /**
     * Stack to hold footer information
     *
     * @var     array[]
     * @since   __DEPLOY_VERSION__
     */
    protected $footer = [];

    /**
     * Generic helper method to get health check data from plugins
     *
     * @param   Registry             $params          The module parameters
     * @param   string               $type            The type of data (gauges, buttons, lists, etc.)
     * @param   string               $eventName       The event name to trigger
     * @param   array                $defaults        Default values for the data items
     * @param   array                $requiredFields  Groups of alternative field names; each group
     *                                                must contribute at least one usable value
     * @param   CMSApplication|null  $application     The application
     *
     * @return  array  An array of health check data items
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function getHealthCheckData(
        Registry $params,
        string $type,
        string $eventName,
        array $defaults,
        array $requiredFields,
        ?CMSApplication $application = null
    ) {
        if ($application === null) {
            $application = Factory::getApplication();
        }

        $key      = (string) $params;
        $context  = (string) $params->get('context', 'general');
        $property = $type;

        if (isset($this->{$property}[$key])) {
            return $this->{$property}[$key];
        }

        // Load mod_healthcheck language file in case this method is called before rendering the module
        $application->getLanguage()->load('mod_healthcheck');

        $this->{$property}[$key] = [];

        PluginHelper::importPlugin('healthcheck');

        foreach ($this->collectResults($eventName, $context) as $item) {
            $item = $this->normaliseItem($item, $defaults, $requiredFields, $type);

            if ($item !== null) {
                $this->{$property}[$key][] = $item;
            }
        }

        return $this->{$property}[$key];
    }

    /**
     * Dispatch a health check event to every listener separately and collect the raw result items.
     *
     * Listeners are invoked one by one rather than through a single dispatch call, so a plugin which
     * throws cannot prevent the remaining plugins from contributing their results.
     *
     * @param   string  $eventName  The event name to trigger
     * @param   string  $context    The context the checks are requested for
     *
     * @return  array  The flattened list of raw result items
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function collectResults(string $eventName, string $context): array
    {
        $dispatcher = Factory::getContainer()->get(DispatcherInterface::class);
        $event      = new HealthChecksEvent($eventName, ['context' => $context, 'result' => []]);

        foreach ($dispatcher->getListeners($eventName) as $listener) {
            if ($event->isStopped()) {
                break;
            }

            try {
                $listener($event);
            } catch (\Throwable $e) {
                $this->logCheckFailure($eventName, $e);
            }
        }

        $result = $event->getArgument('result');

        if (!\is_array($result)) {
            return [];
        }

        // Each listener contributes an array of items; flatten one level and drop malformed responses.
        $items = [];

        foreach ($result as $response) {
            if (!\is_array($response)) {
                continue;
            }

            foreach ($response as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Normalise and validate a single raw result item against the contract for its type.
     *
     * @param   mixed   $item            The raw item as delivered by a plugin
     * @param   array   $defaults        Default values for the item
     * @param   array   $requiredFields  Groups of alternative field names
     * @param   string  $type            The result type the item belongs to
     *
     * @return  array|null  The normalised item, or null when the item is unusable
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function normaliseItem($item, array $defaults, array $requiredFields, string $type): ?array
    {
        // A plugin may hand over a scalar or an object; neither can satisfy the contract.
        if (!\is_array($item)) {
            $this->logCheckFailure($type, new \UnexpectedValueException(
                \sprintf('Health check result must be an array, %s given.', \gettype($item))
            ));

            return null;
        }

        $item = array_merge($defaults, $item);

        foreach ($requiredFields as $fieldGroup) {
            $hasAnyRequired = false;

            foreach ($fieldGroup as $field) {
                if (\array_key_exists($field, $item) && $this->isUsableValue($item[$field])) {
                    $hasAnyRequired = true;
                    break;
                }
            }

            if (!$hasAnyRequired) {
                return null;
            }
        }

        // Resolve the status into the enum so layouts never interpret a raw string themselves.
        if (\array_key_exists('status', $item)) {
            $item['status'] = $item['status'] === null
                ? null
                : HealthStatus::fromLoose($item['status']);
        }

        return $item;
    }

    /**
     * Check whether a value can satisfy a required field.
     *
     * Null is always unusable. An empty array is unusable too, because a list or table without rows
     * carries nothing to render.
     *
     * @param   mixed  $value  The value to inspect
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function isUsableValue($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (\is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * Log a failing health check without interrupting the dashboard.
     *
     * @param   string      $eventName  The event or result type the failure belongs to
     * @param   \Throwable  $e          The error which was caught
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function logCheckFailure(string $eventName, \Throwable $e): void
    {
        Log::add(
            \sprintf('Health check "%s" failed: %s', $eventName, $e->getMessage()),
            Log::WARNING,
            'mod_healthcheck'
        );
    }

    /**
     * Helper method to return gauge list.
     *
     * @param   Registry         $params       The module parameters
     * @param   ?CMSApplication  $application  The application
     *
     * @return  array  An array of gauges
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getGauges(Registry $params, ?CMSApplication $application = null)
    {
        $defaults = [
            'id'                      => null,
            'score'                   => null,
            'unit'                    => null,
            'score_min'               => null,
            'score_max'               => null,
            'score_threshold_warning' => null,
            'score_threshold_success' => null,
            'label'                   => null,
            'sublabel'                => null,
            'note'                    => null,
            'link'                    => null,
            'link_title'              => null,
            'status'                  => null,
            'access'                  => true,
            'class'                   => null,
            'group'                   => 'general',
        ];

        $requiredFields = [
            ['score'],
            ['unit'],
        ];

        return $this->getHealthCheckData(
            $params,
            'gauges',
            'onHealthcheckGetGauges',
            $defaults,
            $requiredFields,
            $application
        );
    }

    /**
     * Helper method to return button list.
     *
     * @param   Registry         $params       The module parameters
     * @param   ?CMSApplication  $application  The application
     *
     * @return  array  An array of buttons
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getButtons(Registry $params, ?CMSApplication $application = null)
    {
        $defaults = [
            'id'          => null,
            'link'        => null,
            'image'       => null,
            'icon'        => null,
            'amount'      => null,
            'text'        => null,
            'name'        => null,
            'title'       => null,
            'linkadd'     => null,
            'linkaddicon' => null,
            'ajaxurl'     => null,
            'access'      => true,
            'status'      => null,
            'class'       => null,
            'group'       => 'general',
        ];

        $requiredFields = [
            ['link'],
            ['text', 'name'], // Must have either text or name
        ];

        return $this->getHealthCheckData(
            $params,
            'buttons',
            'onHealthcheckGetIcons',
            $defaults,
            $requiredFields,
            $application
        );
    }

    /**
     * Helper method to return list list.
     *
     * @param   Registry         $params       The module parameters
     * @param   ?CMSApplication  $application  The application
     *
     * @return  array  An array of lists
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getLists(Registry $params, ?CMSApplication $application = null)
    {
        $defaults = [
            'id'     => null,
            'items'  => null,
            'status' => null,
            'access' => true,
            'class'  => null,
            'group'  => 'general',
        ];

        $requiredFields = [
            ['items'],
        ];

        return $this->getHealthCheckData(
            $params,
            'lists',
            'onHealthcheckGetLists',
            $defaults,
            $requiredFields,
            $application
        );
    }

    /**
     * Helper method to return table list.
     *
     * @param   Registry         $params       The module parameters
     * @param   ?CMSApplication  $application  The application
     *
     * @return  array  An array of tables
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getTables(Registry $params, ?CMSApplication $application = null)
    {
        $defaults = [
            'id'      => null,
            'columns' => null,
            'data'    => null,
            'caption' => null,
            'status'  => null,
            'access'  => true,
            'class'   => null,
            'group'   => 'general',
        ];

        $requiredFields = [
            ['columns'],
            ['data'],
        ];

        return $this->getHealthCheckData(
            $params,
            'tables',
            'onHealthcheckGetTables',
            $defaults,
            $requiredFields,
            $application
        );
    }

    /**
     * Helper method to return leading information.
     *
     * @param   Registry         $params       The module parameters
     * @param   ?CMSApplication  $application  The application
     *
     * @return  array  An array of leading information
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getLeading(Registry $params, ?CMSApplication $application = null)
    {
        $defaults = [
            'id'     => null,
            'info'   => null,
            'access' => true,
            'class'  => null,
            'group'  => 'general',
        ];

        $requiredFields = [
            ['info'],
        ];

        return $this->getHealthCheckData(
            $params,
            'leading',
            'onHealthcheckGetLeading',
            $defaults,
            $requiredFields,
            $application
        );
    }

    /**
     * Helper method to return footer information.
     *
     * @param   Registry         $params       The module parameters
     * @param   ?CMSApplication  $application  The application
     *
     * @return  array  An array of footer information
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getFooter(Registry $params, ?CMSApplication $application = null)
    {
        $defaults = [
            'id'     => null,
            'info'   => null,
            'access' => true,
            'class'  => null,
            'group'  => 'general',
        ];

        $requiredFields = [
            ['info'],
        ];

        return $this->getHealthCheckData(
            $params,
            'footer',
            'onHealthcheckGetFooter',
            $defaults,
            $requiredFields,
            $application
        );
    }
}
