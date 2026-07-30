<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Showcase
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\Healthcheck\Showcase\Extension\Showcase;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(Showcase::class, function (Container $container): Showcase {
                $plugin = new Showcase(
                    (array) PluginHelper::getPlugin('healthcheck', 'showcase')
                );

                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
