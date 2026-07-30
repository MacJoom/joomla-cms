<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Showcase
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Showcase\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Showcase plugin demonstrating all available healthcheck layouts for UI testing.
 *
 * @since    __DEPLOY_VERSION__
 */
final class Showcase extends CMSPlugin implements SubscriberInterface
{
    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onHealthcheckGetLeading' => 'onHealthcheckGetLeading',
            'onHealthcheckGetIcons'   => 'onHealthcheckGetIcons',
            'onHealthcheckGetGauges'  => 'onHealthcheckGetGauges',
            'onHealthcheckGetLists'   => 'onHealthcheckGetLists',
            'onHealthcheckGetTables'  => 'onHealthcheckGetTables',
            'onHealthcheckGetFooter'  => 'onHealthcheckGetFooter',
        ];
    }

    /**
     * Returns leading (header) content shown above all other items.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onHealthcheckGetLeading(HealthChecksEvent $event): void
    {
        if ($event->getContext() !== $this->params->get('context', 'testing')) {
            return;
        }

        $leadings = [];

        $leadings[] = [
            'id'   => 'plg_healthcheck_showcase_leading',
            'info' => '<div class="alert alert-info mb-0" role="alert">'
                . '<strong>Showcase plugin</strong> &mdash; '
                . 'Demonstrating all available healthcheck layouts: '
                . '<em>leading, icons, gauges, lists, tables, footer</em>. '
                . 'Context: <code>' . htmlspecialchars($this->params->get('context', 'testing'), ENT_QUOTES, 'UTF-8') . '</code>.'
                . '</div>',
        ];

        $result   = $event->getArgument('result', []);
        $result[] = $leadings;
        $event->setArgument('result', $result);
    }

    /**
     * Returns icon items covering success, warning, and error statuses.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onHealthcheckGetIcons(HealthChecksEvent $event): void
    {
        if ($event->getContext() !== $this->params->get('context', 'testing')) {
            return;
        }

        $checks = [];

        $checks[] = [
            'link'   => 'index.php?option=com_config',
            'icon'   => 'fas fa-circle-check',
            'amount' => 0,
            'text'   => 'All systems operational',
            'id'     => 'plg_healthcheck_showcase_icon_success',
            'status' => 'success',
        ];

        $checks[] = [
            'link'   => 'index.php?option=com_config',
            'icon'   => 'fas fa-triangle-exclamation',
            'amount' => 3,
            'text'   => 'Items need attention',
            'id'     => 'plg_healthcheck_showcase_icon_warning',
            'status' => 'warning',
        ];

        $checks[] = [
            'link'   => 'index.php?option=com_config',
            'icon'   => 'fas fa-circle-xmark',
            'amount' => 1,
            'text'   => 'Critical issue found',
            'id'     => 'plg_healthcheck_showcase_icon_error',
            'status' => 'error',
        ];

        $checks[] = [
            'link'   => 'index.php?option=com_config',
            'icon'   => 'fas fa-shield-halved',
            'amount' => 12,
            'text'   => 'Icon without status (default)',
            'id'     => 'plg_healthcheck_showcase_icon_default',
        ];

        $result   = $event->getArgument('result', []);
        $result[] = $checks;
        $event->setArgument('result', $result);
    }

    /**
     * Returns gauge items covering success, warning, and error score ranges.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onHealthcheckGetGauges(HealthChecksEvent $event): void
    {
        if ($event->getContext() !== $this->params->get('context', 'testing')) {
            return;
        }

        $gauges = [];

        $gauges[] = [
            'id'                      => 'plg_healthcheck_showcase_gauge_success',
            'label'                   => 'Cache Hit Rate',
            'sublabel'                => 'Page cache',
            'note'                    => 'Target is above 90%.',
            'score'                   => 95,
            'unit'                    => '%',
            'score_min'               => 0,
            'score_max'               => 100,
            'score_threshold_warning' => 70,
            'score_threshold_success' => 85,
            'link'                    => 'index.php?option=com_config',
            'linktitle'               => 'Open Global Configuration',
            'status'                  => 'success',
        ];

        $gauges[] = [
            'id'                      => 'plg_healthcheck_showcase_gauge_warning',
            'label'                   => 'Disk Usage',
            'sublabel'                => 'Upload directory',
            'note'                    => 'Consider archiving old media.',
            'score'                   => 68,
            'unit'                    => '%',
            'score_min'               => 0,
            'score_max'               => 100,
            'score_threshold_warning' => 60,
            'score_threshold_success' => 80,
            'link'                    => 'index.php?option=com_media',
            'linktitle'               => 'Open Media Manager',
            'status'                  => 'warning',
        ];

        $gauges[] = [
            'id'                      => 'plg_healthcheck_showcase_gauge_error',
            'label'                   => 'Memory Pressure',
            'sublabel'                => 'PHP process',
            'note'                    => 'Memory usage is critically low.',
            'score'                   => 22,
            'unit'                    => '%',
            'score_min'               => 0,
            'score_max'               => 100,
            'score_threshold_warning' => 50,
            'score_threshold_success' => 70,
            'link'                    => 'index.php?option=com_config',
            'linktitle'               => 'Open Global Configuration',
            'status'                  => 'error',
        ];

        $gauges[] = [
            'id'        => 'plg_healthcheck_showcase_gauge_nounit',
            'label'     => 'Active Sessions',
            'sublabel'  => 'Current users online',
            'score'     => 42,
            'unit'      => '',
            'score_min' => 0,
            'score_max' => 200,
            'status'    => 'success',
        ];

        $result   = $event->getArgument('result', []);
        $result[] = $gauges;
        $event->setArgument('result', $result);
    }

    /**
     * Returns list items in ul, ol, and div types with various statuses.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onHealthcheckGetLists(HealthChecksEvent $event): void
    {
        if ($event->getContext() !== $this->params->get('context', 'testing')) {
            return;
        }

        $lists = [];

        $lists[] = [
            'id'        => 'plg_healthcheck_showcase_list_ul_success',
            'class'     => 'list-group list-group-flush',
            'itemClass' => 'list-group-item list-group-item-success',
            'type'      => 'ul',
            'status'    => 'success',
            'items'     => [
                'PHP version meets minimum requirements',
                'Database connection is healthy',
                'File permissions are correctly set',
                'SSL certificate is valid',
            ],
        ];

        $lists[] = [
            'id'        => 'plg_healthcheck_showcase_list_ol_warning',
            'class'     => 'list-group list-group-flush',
            'itemClass' => 'list-group-item list-group-item-warning',
            'type'      => 'ol',
            'status'    => 'warning',
            'items'     => [
                'Review user session timeout settings',
                'Consider enabling two-factor authentication',
                'Update outdated extensions',
            ],
        ];

        $lists[] = [
            'id'        => 'plg_healthcheck_showcase_list_div_error',
            'class'     => 'list-group list-group-flush',
            'itemClass' => 'list-group-item list-group-item-danger',
            'type'      => 'div',
            'status'    => 'error',
            'items'     => [
                'Debug mode is enabled on a live site',
                'Error reporting is set to maximum',
            ],
        ];

        $result   = $event->getArgument('result', []);
        $result[] = $lists;
        $event->setArgument('result', $result);
    }

    /**
     * Returns table items covering all supported column types.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onHealthcheckGetTables(HealthChecksEvent $event): void
    {
        if ($event->getContext() !== $this->params->get('context', 'testing')) {
            return;
        }

        $tables = [];

        // Comprehensive table covering all supported column types
        $tables[] = [
            'id'      => 'plg_healthcheck_showcase_table_full',
            'caption' => 'All column types',
            'class'   => 'table-sm table-striped',
            'status'  => 'warning',
            'columns' => [
                [
                    'key'   => 'name',
                    'title' => 'Name',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'updated',
                    'title' => 'Last Updated',
                    'type'  => 'date',
                ],
                [
                    'key'   => 'active',
                    'title' => 'Active',
                    'type'  => 'boolean',
                ],
                [
                    'key'        => 'risk',
                    'title'      => 'Risk',
                    'type'       => 'badge',
                    'badgeClass' => static function ($value) {
                        return match ($value) {
                            'low'    => 'success',
                            'medium' => 'warning',
                            default  => 'danger',
                        };
                    },
                ],
                [
                    'key'   => 'score',
                    'title' => 'Score',
                    'type'  => 'progress',
                ],
                [
                    'key'   => 'actionLabel',
                    'title' => 'Action',
                    'type'  => 'link',
                    'url'   => static fn ($value, $item) => $item['actionUrl'],
                ],
                [
                    'key'   => 'icon',
                    'title' => 'Icon',
                    'type'  => 'icon',
                ],
            ],
            'data' => [
                [
                    'name'        => 'Alpha Component',
                    'updated'     => '2026-06-01 10:00:00',
                    'active'      => 1,
                    'risk'        => 'low',
                    'score'       => 92,
                    'actionLabel' => 'Check updates',
                    'actionUrl'   => 'index.php?option=com_installer&view=update',
                    'icon'        => 'fas fa-cube',
                ],
                [
                    'name'        => 'Beta Plugin',
                    'updated'     => '2026-03-15 14:22:00',
                    'active'      => 1,
                    'risk'        => 'medium',
                    'score'       => 58,
                    'actionLabel' => 'Check updates',
                    'actionUrl'   => 'index.php?option=com_installer&view=update',
                    'icon'        => 'fas fa-plug',
                ],
                [
                    'name'        => 'Gamma Module',
                    'updated'     => '2025-11-20 08:45:00',
                    'active'      => 0,
                    'risk'        => 'high',
                    'score'       => 15,
                    'actionLabel' => 'Check updates',
                    'actionUrl'   => 'index.php?option=com_installer&view=update',
                    'icon'        => 'fas fa-puzzle-piece',
                ],
                [
                    'name'        => 'Delta Template',
                    'updated'     => '2026-05-30 16:10:00',
                    'active'      => 1,
                    'risk'        => 'low',
                    'score'       => 87,
                    'actionLabel' => 'Manage templates',
                    'actionUrl'   => 'index.php?option=com_templates',
                    'icon'        => 'fas fa-palette',
                ],
            ],
        ];

        // Simple table — success status, minimal columns
        $tables[] = [
            'id'      => 'plg_healthcheck_showcase_table_simple',
            'caption' => 'Simple table (success)',
            'status'  => 'success',
            'columns' => [
                ['key' => 'check', 'title' => 'Check'],
                ['key' => 'value', 'title' => 'Value', 'type' => 'text'],
            ],
            'data' => [
                ['check' => 'PHP version',      'value' => '8.3.12'],
                ['check' => 'Database version', 'value' => 'MariaDB 10.11'],
                ['check' => 'Joomla version',   'value' => '6.2.0'],
            ],
        ];

        $result   = $event->getArgument('result', []);
        $result[] = $tables;
        $event->setArgument('result', $result);
    }

    /**
     * Returns footer content shown below all other items.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onHealthcheckGetFooter(HealthChecksEvent $event): void
    {
        if ($event->getContext() !== $this->params->get('context', 'testing')) {
            return;
        }

        $footers = [];

        $footers[] = [
            'id'   => 'plg_healthcheck_showcase_footer',
            'info' => '<p class="text-muted small mb-0">'
                . '<i class="fas fa-circle-info me-1"></i>'
                . 'This output is generated by <strong>plg_healthcheck_showcase</strong> for UI testing purposes only. '
                . 'All data shown is hardcoded and does not reflect actual system state.'
                . '</p>',
        ];

        $result   = $event->getArgument('result', []);
        $result[] = $footers;
        $event->setArgument('result', $result);
    }
}
