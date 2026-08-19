<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  mod_healthcheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Module\Healthcheck\Administrator\HtmlHelper;

use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Renders health check results into HTML.
 *
 * This is owned by the module rather than registered as a global HTMLHelper service: nothing
 * outside mod_healthcheck renders health check results, so the template calls these methods
 * directly instead of resolving them through a string key.
 *
 * The layouts themselves stay in the global layouts folder (joomla.healthchecks.*), so template
 * overrides keep working the usual way.
 *
 * @since  __DEPLOY_VERSION__
 */
abstract class HealthChecks
{
    /**
     * Method to generate html code for a list of gauges
     *
     * @param   array  $gauges  Array of gauges
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function gauges($gauges)
    {
        return static::renderAll($gauges, 'gauge');
    }

    /**
     * Method to generate html code for a gauge
     *
     * @param   array  $gauge  Gauge properties
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function gauge($gauge)
    {
        return static::render($gauge, 'gauge');
    }

    /**
     * Method to generate html code for a list of buttons
     *
     * @param   array  $buttons  Array of buttons
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function buttons($buttons)
    {
        return static::renderAll($buttons, 'icon');
    }

    /**
     * Method to generate html code for a button
     *
     * @param   array  $button  Button properties
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function button($button)
    {
        return static::render($button, 'icon');
    }

    /**
     * Method to generate html code for a list of tables
     *
     * @param   array  $tables  Array of tables
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function tables($tables)
    {
        return static::renderAll($tables, 'table');
    }

    /**
     * Method to generate html code for a table
     *
     * @param   array  $table  Table properties
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function table($table)
    {
        return static::render($table, 'table');
    }

    /**
     * Method to generate html code for a list of lists
     *
     * @param   array  $lists  Array of lists
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function lists($lists)
    {
        return static::renderAll($lists, 'list');
    }

    /**
     * Method to generate html code for a list
     *
     * @param   array  $list  List properties
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function list($list)
    {
        return static::render($list, 'list');
    }

    /**
     * Method to generate html code for a set of leading information
     *
     * @param   array  $leadings  Array of leading information
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function leadings($leadings)
    {
        return static::renderAll($leadings, 'leading');
    }

    /**
     * Method to generate html code for leading information
     *
     * @param   array  $leading  Leading properties
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function leading($leading)
    {
        return static::render($leading, 'leading');
    }

    /**
     * Method to generate html code for a set of footer information
     *
     * @param   array  $footers  Array of footer information
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function footers($footers)
    {
        return static::renderAll($footers, 'footer');
    }

    /**
     * Method to generate html code for footer information
     *
     * @param   array  $footer  Footer properties
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function footer($footer)
    {
        return static::render($footer, 'footer');
    }

    /**
     * Render every item of a result set with the given layout.
     *
     * @param   array   $items   The result items to render
     * @param   string  $layout  The name of the layout inside joomla.healthchecks
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    protected static function renderAll($items, string $layout): string
    {
        if (empty($items)) {
            return '';
        }

        $html = [];

        foreach ($items as $item) {
            $html[] = static::render($item, $layout);
        }

        return implode('', $html);
    }

    /**
     * Render a single result item, honouring its access rules.
     *
     * @param   array   $item    The result item to render
     * @param   string  $layout  The name of the layout inside joomla.healthchecks
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    protected static function render($item, string $layout): string
    {
        if (!static::canAccess($item)) {
            return '';
        }

        return (new FileLayout('joomla.healthchecks.' . $layout))->render($item);
    }

    /**
     * Check if an item can be accessed based on access permissions
     *
     * @param   array  $item  Item with access properties
     *
     * @return  bool  True if access is allowed, false otherwise
     *
     * @since   __DEPLOY_VERSION__
     */
    protected static function canAccess($item)
    {
        if (!isset($item['access'])) {
            return true;
        }

        if (\is_bool($item['access'])) {
            return $item['access'];
        }

        // Get the user object to verify permissions
        $user = Factory::getApplication()->getIdentity();

        // Take each pair of permission, context values.
        for ($i = 0, $n = \count($item['access']); $i < $n; $i += 2) {
            if (!$user->authorise($item['access'][$i], $item['access'][$i + 1])) {
                return false;
            }
        }

        return true;
    }
}
