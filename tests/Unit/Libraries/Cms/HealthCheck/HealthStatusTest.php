<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  HealthCheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\HealthCheck;

use Joomla\CMS\HealthCheck\HealthStatus;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for HealthStatus
 *
 * @package     Joomla.UnitTest
 * @subpackage  HealthCheck
 *
 * @testdox     The HealthStatus enum
 *
 * @since       __DEPLOY_VERSION__
 */
class HealthStatusTest extends UnitTestCase
{
    /**
     * Data provider for the loose status resolution.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function looseStatusProvider(): array
    {
        return [
            'canonical success' => ['success', HealthStatus::Success],
            'canonical warning' => ['warning', HealthStatus::Warning],
            'canonical error'   => ['error', HealthStatus::Error],
            'canonical info'    => ['info', HealthStatus::Info],
            'uppercase'         => ['WARNING', HealthStatus::Warning],
            'mixed case'        => ['Error', HealthStatus::Error],
            'alias ok'          => ['ok', HealthStatus::Success],
            'alias healthy'     => ['healthy', HealthStatus::Success],
            'alias danger'      => ['danger', HealthStatus::Error],
            'alias critical'    => ['critical', HealthStatus::Error],
            'alias failed'      => ['failed', HealthStatus::Error],
            'alias alert'       => ['alert', HealthStatus::Warning],
            'unknown string'    => ['bogus', HealthStatus::Info],
            'empty string'      => ['', HealthStatus::Info],
            'null'              => [null, HealthStatus::Info],
            'boolean'           => [true, HealthStatus::Info],
            'array'             => [['warning'], HealthStatus::Info],
            'object'            => [new \stdClass(), HealthStatus::Info],
        ];
    }

    /**
     * @param   mixed         $input     The loosely typed status a plugin might deliver
     * @param   HealthStatus  $expected  The case it must resolve to
     *
     * @return  void
     *
     * @dataProvider looseStatusProvider
     *
     * @testdox     resolves a loose status into a known case
     *
     * @since       __DEPLOY_VERSION__
     */
    public function testFromLooseResolvesToAKnownCase($input, HealthStatus $expected): void
    {
        $this->assertSame($expected, HealthStatus::fromLoose($input));
    }

    /**
     * @return  void
     *
     * @testdox  returns the same instance when it already is a HealthStatus
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testFromLooseIsIdempotent(): void
    {
        $this->assertSame(HealthStatus::Warning, HealthStatus::fromLoose(HealthStatus::Warning));
    }

    /**
     * @return  void
     *
     * @testdox  maps every case onto one of the three filter buckets
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testFilterBuckets(): void
    {
        $this->assertSame('healthy', HealthStatus::Success->getFilterBucket());
        $this->assertSame('healthy', HealthStatus::Info->getFilterBucket());
        $this->assertSame('warning', HealthStatus::Warning->getFilterBucket());
        $this->assertSame('critical', HealthStatus::Error->getFilterBucket());
    }

    /**
     * @return  void
     *
     * @testdox  maps every case onto a Bootstrap contextual class
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testCssClasses(): void
    {
        $this->assertSame('success', HealthStatus::Success->getCssClass());
        $this->assertSame('warning', HealthStatus::Warning->getCssClass());
        $this->assertSame('danger', HealthStatus::Error->getCssClass());
        $this->assertSame('info', HealthStatus::Info->getCssClass());
    }

    /**
     * The filter bar and the async script both key off these exact strings.
     *
     * @return  void
     *
     * @testdox  never produces a filter bucket the dashboard does not know
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testFilterBucketsAreLimitedToTheKnownSet(): void
    {
        foreach (HealthStatus::cases() as $case) {
            $this->assertContains($case->getFilterBucket(), ['healthy', 'warning', 'critical']);
        }
    }
}
