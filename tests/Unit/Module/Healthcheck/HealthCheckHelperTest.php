<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  mod_healthcheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Module\Healthcheck;

use Joomla\CMS\HealthCheck\HealthStatus;
use Joomla\Tests\Unit\UnitTestCase;

/*
 * Module classes are registered through the extension namespace map, which needs a database. Load
 * the helper directly so this test runs without one.
 */
// phpcs:disable PSR1.Files.SideEffects
require_once JPATH_ADMINISTRATOR . '/modules/mod_healthcheck/src/Helper/HealthCheckHelper.php';
// phpcs:enable PSR1.Files.SideEffects

/**
 * Test class for HealthCheckHelper
 *
 * @package     Joomla.UnitTest
 * @subpackage  mod_healthcheck
 *
 * @testdox     The health check helper
 *
 * @since       __DEPLOY_VERSION__
 */
class HealthCheckHelperTest extends UnitTestCase
{
    /**
     * The helper under test, with the normalisation entry points made reachable.
     *
     * @var    object
     * @since  __DEPLOY_VERSION__
     */
    private $helper;

    /**
     * The defaults of the button/icon result type.
     *
     * @var    array
     * @since  __DEPLOY_VERSION__
     */
    private $defaults = [
        'id'     => null,
        'link'   => null,
        'text'   => null,
        'name'   => null,
        'amount' => null,
        'status' => null,
        'access' => true,
        'group'  => 'general',
    ];

    /**
     * The required field groups of the button/icon result type.
     *
     * @var    array
     * @since  __DEPLOY_VERSION__
     */
    private $requiredFields = [
        ['link'],
        ['text', 'name'],
    ];

    /**
     * Build the helper subclass which exposes the protected normalisation methods.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new class () extends \Joomla\Module\Healthcheck\Administrator\Helper\HealthCheckHelper {
            public function callNormaliseItem($item, array $defaults, array $requiredFields, string $type): ?array
            {
                return $this->normaliseItem($item, $defaults, $requiredFields, $type);
            }

            public function callIsUsableValue($value): bool
            {
                return $this->isUsableValue($value);
            }

            // Swallow the log call so the test does not need a configured logger.
            protected function logCheckFailure(string $eventName, \Throwable $e): void
            {
            }
        };
    }

    /**
     * @return  void
     *
     * @testdox  keeps a well formed item and fills in the declared defaults
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testAWellFormedItemSurvivesNormalisation(): void
    {
        $item = $this->helper->callNormaliseItem(
            ['link' => 'index.php', 'text' => 'Inactive users', 'amount' => 7],
            $this->defaults,
            $this->requiredFields,
            'buttons'
        );

        $this->assertIsArray($item);
        $this->assertSame('index.php', $item['link']);
        $this->assertSame(7, $item['amount']);

        // Defaults must be merged in so a layout never reads an undefined key.
        $this->assertArrayHasKey('group', $item);
        $this->assertSame('general', $item['group']);
        $this->assertTrue($item['access']);
    }

    /**
     * A plugin handing over a scalar used to reach array_merge() and raise a TypeError.
     *
     * @param   mixed  $item  The malformed item
     *
     * @return  void
     *
     * @dataProvider malformedItemProvider
     *
     * @testdox     rejects an item which is not an array instead of raising a TypeError
     *
     * @since       __DEPLOY_VERSION__
     */
    public function testANonArrayItemIsRejected($item): void
    {
        $this->assertNull(
            $this->helper->callNormaliseItem($item, $this->defaults, $this->requiredFields, 'buttons')
        );
    }

    /**
     * Data provider for malformed items.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function malformedItemProvider(): array
    {
        return [
            'string'  => ['a string'],
            'integer' => [42],
            'float'   => [1.5],
            'boolean' => [true],
            'null'    => [null],
            'object'  => [new \stdClass()],
        ];
    }

    /**
     * @return  void
     *
     * @testdox  drops an item which satisfies no alternative of a required field group
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testAnItemMissingARequiredFieldIsDropped(): void
    {
        // No link at all.
        $this->assertNull($this->helper->callNormaliseItem(
            ['text' => 'Inactive users'],
            $this->defaults,
            $this->requiredFields,
            'buttons'
        ));

        // Neither text nor name.
        $this->assertNull($this->helper->callNormaliseItem(
            ['link' => 'index.php'],
            $this->defaults,
            $this->requiredFields,
            'buttons'
        ));
    }

    /**
     * @return  void
     *
     * @testdox  accepts an item which satisfies a required group through its alternative
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testEitherAlternativeSatisfiesARequiredGroup(): void
    {
        $viaName = $this->helper->callNormaliseItem(
            ['link' => 'index.php', 'name' => 'COM_USERS'],
            $this->defaults,
            $this->requiredFields,
            'buttons'
        );

        $this->assertIsArray($viaName);
        $this->assertSame('COM_USERS', $viaName['name']);
    }

    /**
     * @return  void
     *
     * @testdox  resolves a loose status string into a HealthStatus
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testTheStatusIsResolvedIntoTheEnum(): void
    {
        $item = $this->helper->callNormaliseItem(
            ['link' => 'index.php', 'text' => 'Orphans', 'status' => 'danger'],
            $this->defaults,
            $this->requiredFields,
            'buttons'
        );

        $this->assertSame(HealthStatus::Error, $item['status']);
    }

    /**
     * An unknown status must not silently render as a healthy result.
     *
     * @return  void
     *
     * @testdox  degrades an unrecognised status to info rather than success
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testAnUnknownStatusBecomesInfo(): void
    {
        $item = $this->helper->callNormaliseItem(
            ['link' => 'index.php', 'text' => 'Orphans', 'status' => 'totally-made-up'],
            $this->defaults,
            $this->requiredFields,
            'buttons'
        );

        $this->assertSame(HealthStatus::Info, $item['status']);
    }

    /**
     * @return  void
     *
     * @testdox  leaves an absent status as null so a layout can derive it
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testAnAbsentStatusStaysNull(): void
    {
        $item = $this->helper->callNormaliseItem(
            ['link' => 'index.php', 'text' => 'Orphans'],
            $this->defaults,
            $this->requiredFields,
            'buttons'
        );

        $this->assertNull($item['status']);
    }

    /**
     * @return  void
     *
     * @testdox  treats null and empty arrays as unusable values
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testUsableValueDetection(): void
    {
        $this->assertFalse($this->helper->callIsUsableValue(null));
        $this->assertFalse($this->helper->callIsUsableValue([]));

        $this->assertTrue($this->helper->callIsUsableValue(0));
        $this->assertTrue($this->helper->callIsUsableValue('0'));
        $this->assertTrue($this->helper->callIsUsableValue(''));
        $this->assertTrue($this->helper->callIsUsableValue(['row']));
        $this->assertTrue($this->helper->callIsUsableValue(false));
    }

    /**
     * A list or table without rows carries nothing to render and must not reach a layout.
     *
     * @return  void
     *
     * @testdox  drops a list whose items array is empty
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testAnEmptyItemsArrayIsRejected(): void
    {
        $this->assertNull($this->helper->callNormaliseItem(
            ['items' => []],
            ['items' => null, 'status' => null, 'access' => true],
            [['items']],
            'lists'
        ));
    }

    /**
     * A count of zero is a legitimate result and must not be filtered out.
     *
     * @return  void
     *
     * @testdox  keeps an item whose amount is zero
     *
     * @since    __DEPLOY_VERSION__
     */
    public function testAZeroAmountIsKept(): void
    {
        $item = $this->helper->callNormaliseItem(
            ['link' => 'index.php', 'text' => 'Orphans', 'amount' => 0],
            $this->defaults,
            $this->requiredFields,
            'buttons'
        );

        $this->assertIsArray($item);
        $this->assertSame(0, $item['amount']);
    }
}
