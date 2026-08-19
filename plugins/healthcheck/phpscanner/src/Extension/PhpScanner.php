<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.PhpScanner
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\PhpScanner\Extension;

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\Folder;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Plugin\Healthcheck\PhpScanner\Scanner\ExtensionInventory;
use Joomla\Plugin\Healthcheck\PhpScanner\Scanner\HashBaseline;
use Joomla\Plugin\Healthcheck\PhpScanner\Scanner\HashScanner;
use Joomla\Plugin\Healthcheck\PhpScanner\Scanner\MalwareScanner;
use Joomla\Plugin\Healthcheck\PhpScanner\Scanner\ParamList;
use Joomla\Plugin\Healthcheck\PhpScanner\Scanner\RecentFileScanner;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Health Check plugin that scans content and extensions for PHP/legacy code artifacts.
 *
 * Content findings (articles and modules) are shown as one quick-icon per matching item,
 * linking straight to that item's edit screen; for articles an extra summary icon links to
 * the filtered article list. The filesystem-scanning checks are loaded asynchronously.
 *
 * @since    __DEPLOY_VERSION__
 */
final class PhpScanner extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Default PHP artifact fragments searched for in content (wrapped as %fragment% for the query).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const PHP_PATTERNS = ['<?php', '<?='];

    /**
     * Default 3rd party tool code-tag fragments (wrapped as %fragment% for the query).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const SOURCERER_PATTERNS = ['{source'];

    /**
     * Legacy Joomla "J*" classes/loaders treated as deprecated code.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const DEPRECATED_TOKENS = [
        'JFactory',
        'JText',
        'JRoute',
        'JUri',
        'JRequest',
        'JResponse',
        'JHtml',
        'JModel',
        'JTable',
        'JController',
        'JComponentHelper',
        'JPluginHelper',
        'JFolder',
        'JFile',
        'JArrayHelper',
        'JString',
        'JError',
        'jimport',
        'JLoader::import',
    ];

    /**
     * High-signal fragments indicating an extension redefines outdated Joomla classes/functions.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const REDEFINITION_PATTERNS = [
        'JLoader::registerAlias\s*\(\s*[\'"]J[A-Z]',
        'function\s+jimport',
        'function\s+jexit',
        'function\s+jdebug',
    ];

    /**
     * The previous over-broad registerAlias pattern, migrated on load to the refined regex above.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const LEGACY_REGISTERALIAS_PATTERN = 'JLoader::registerAlias(';

    /**
     * Regexes (no delimiters) matching guarded compatibility shims / polyfills, whitespace tolerant.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const SHIM_PATTERNS = [
        '!\s*function_exists\s*\(\s*[\'"]j(import|exit|debug)[\'"]',
        '!\s*class_exists\s*\(\s*[\'"]J[A-Z]',
        '!\s*interface_exists\s*\(\s*[\'"]J[A-Z]',
        '!\s*trait_exists\s*\(\s*[\'"]J[A-Z]',
    ];

    /**
     * The previous over-broad shim guards, migrated on load to the refined regexes above so a shim
     * around an extension's own symbols is no longer flagged.
     *
     * @var    array<string, string>
     * @since  __DEPLOY_VERSION__
     */
    private const LEGACY_SHIM_PATTERNS = [
        '!\s*function_exists\s*\('  => '!\s*function_exists\s*\(\s*[\'"]j(import|exit|debug)[\'"]',
        '!\s*class_exists\s*\('     => '!\s*class_exists\s*\(\s*[\'"]J[A-Z]',
        '!\s*interface_exists\s*\(' => '!\s*interface_exists\s*\(\s*[\'"]J[A-Z]',
        '!\s*trait_exists\s*\('     => '!\s*trait_exists\s*\(\s*[\'"]J[A-Z]',
    ];

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onHealthcheckGetIcons' => 'onHealthcheckGetIcons', //  per-item content icons + async placeholders
            'onAjaxPhpscanner'      => 'onAjaxPhpscanner',       //  async loading of the heavy checks
        ];
    }

    /**
     * Builds the QuickIcons for the matching context: one icon per matching content item, an
     * article summary icon per issue, and placeholders for the asynchronous filesystem checks.
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

        // Every check is loaded asynchronously via com_ajax: the module JS fills the count and
        // opens the matched-item list when the icon is clicked.
        $icons = [];

        foreach ($this->asyncCheckDescriptors() as $key => $descriptor) {
            if (!$descriptor['enabled']) {
                continue;
            }

            $icons[] = $this->buildAsyncPlaceholder($key, $descriptor);
        }

        $result   = $event->getArgument('result', []);
        $result[] = $icons;

        $event->setArgument('result', $result);
    }

    /**
     * Runs a content issue (PHP artifacts, 3rd party tool tags or deprecated APIs) over articles and
     * modules and returns the result with one item per matching article/module.
     *
     * @param   string  $issue  One of 'phpcontent', 'sourcerer', 'deprecatedcontent'.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    private function getContentCheck(string $issue): array
    {
        [$patterns, $scanArticles, $scanModules, $status, $icon, $textKey] = match ($issue) {
            'phpcontent' => [
                $this->getPhpPatterns(),
                $this->params->get('scanArticles', '1') == '1',
                $this->params->get('scanModules', '1') == '1',
                'error',
                'fas fa-file-code',
                'PLG_HEALTHCHECK_PHPSCANNER_PHPCONTENT_LISTTEXT',
            ],
            'sourcerer' => [
                $this->getSourcererPatterns(),
                true,
                true,
                'warning',
                'fas fa-wand-magic-sparkles',
                'PLG_HEALTHCHECK_PHPSCANNER_SOURCERER_LISTTEXT',
            ],
            'deprecatedcontent' => [
                array_map(static fn (string $token): string => '%' . $token . '%', $this->getDeprecatedTokens()),
                true,
                true,
                'warning',
                'fas fa-clock-rotate-left',
                'PLG_HEALTHCHECK_PHPSCANNER_DEPRECONTENT_LISTTEXT',
            ],
        };

        $items = [];

        if ($scanArticles) {
            $items = array_merge($items, $this->contentItems('#__content', 'com_content&task=article.edit', 'PLG_HEALTHCHECK_PHPSCANNER_TYPE_ARTICLE', ['introtext', 'fulltext'], $patterns));
        }

        if ($scanModules) {
            $items = array_merge($items, $this->contentItems('#__modules', 'com_modules&task=module.edit', 'PLG_HEALTHCHECK_PHPSCANNER_TYPE_MODULE', ['content'], $patterns));
        }

        return [
            'icon'          => $icon,
            'statusOnFound' => $status,
            'text'          => Text::_($textKey),
            'link'          => Uri::base() . 'index.php?option=com_content&view=articles',
            'items'         => $items,
            'result'        => \count($items),
        ];
    }

    /**
     * Returns matching rows of a content table as items linking to their edit screen.
     *
     * @param   string    $table     The (prefixed) table name.
     * @param   string    $editTask  The com_* option and edit task (e.g. "com_content&task=article.edit").
     * @param   string    $typeKey   Language key for the item type label.
     * @param   string[]  $columns   The text columns to scan.
     * @param   string[]  $patterns  The LIKE patterns to match.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    private function contentItems(string $table, string $editTask, string $typeKey, array $columns, array $patterns): array
    {
        $items = [];

        foreach ($this->findMatchingRows($table, $columns, $patterns) as $row) {
            $items[] = [
                'title' => Text::_($typeKey) . ': ' . $this->shorten($row->title),
                'link'  => Uri::base() . 'index.php?option=' . $editTask . '&id=' . (int) $row->id,
            ];
        }

        return $items;
    }

    /**
     * Truncates a title so per-item icons stay compact.
     *
     * @param   string  $title  The item title.
     *
     * @return  string
     *
     * @since    __DEPLOY_VERSION__
     */
    private function shorten(string $title): string
    {
        return mb_strlen($title) > 40 ? mb_substr($title, 0, 39) . "\u{2026}" : $title;
    }

    /**
     * Returns the id and title of rows where any of the columns contains one of the patterns.
     *
     * @param   string    $table     The (prefixed) table name.
     * @param   string[]  $columns   The text columns to scan.
     * @param   string[]  $patterns  The LIKE patterns to match.
     *
     * @return  object[]  Capped at 100 rows.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function findMatchingRows(string $table, array $columns, array $patterns): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName($table));

        $values     = [];
        $conditions = [];
        $i          = 0;

        foreach ($columns as $column) {
            foreach ($patterns as $pattern) {
                $key          = ':row' . $i;
                $values[$i]   = $pattern;
                $conditions[] = $db->quoteName($column) . ' LIKE ' . $key;

                $query->bind($key, $values[$i], ParameterType::STRING);

                $i++;
            }
        }

        $query->where('(' . implode(' OR ', $conditions) . ')');

        $db->setQuery($query, 0, 100);

        return $db->loadObjectList();
    }

    /**
     * Asynchronous (com_ajax) entry point for the expensive, filesystem-scanning checks.
     *
     * Requested per check via
     * index.php?option=com_ajax&group=healthcheck&plugin=phpscanner&format=json&check=<key>
     * so the dashboard renders instantly and fills these icons in the background.
     *
     * @param   AjaxEvent  $event  The com_ajax event.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onAjaxPhpscanner(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        // These checks expose site internals, so only serve them to authenticated backend users.
        if (!$app->isClient('administrator') || $app->getIdentity()->guest) {
            return;
        }

        $this->loadLanguage();

        $key = $app->getInput()->getCmd('check', '');

        $result = match ($key) {
            'phpcontent'          => $this->getContentCheck('phpcontent'),
            'sourcerer'           => $this->getContentCheck('sourcerer'),
            'deprecatedcontent'   => $this->getContentCheck('deprecatedcontent'),
            'deprecatedoverrides' => $this->getDeprecatedInOverrides(),
            'redefinitions'       => $this->getExtensionsRedefiningClasses(),
            'shims'               => $this->getCompatibilityShims(),
            'malware'             => $this->getMalwareCheck(),
            'oversized'           => $this->getOversizedCheck(),
            'recentfiles'         => $this->getRecentFilesCheck(),
            'integrity'           => $this->getIntegrityCheck(),
            'extensions'          => $this->getInstalledExtensionsCheck(),
            default               => null,
        };

        if ($result === null || isset($result['error'])) {
            return;
        }

        $icons = $this->buildIcons([$key => $result]);

        $event->addResult($icons[0] ?? []);
    }

    /**
     * Returns the descriptors of the checks that are loaded asynchronously.
     *
     * @return  array  Map of check key => ['param' => string, 'icon' => string, 'text' => string, 'link' => string].
     *
     * @since    __DEPLOY_VERSION__
     */
    private function asyncCheckDescriptors(): array
    {
        return [
            'phpcontent' => [
                'enabled' => $this->params->get('scanArticles', '1') == '1' || $this->params->get('scanModules', '1') == '1',
                'icon'    => 'fas fa-file-code',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_PHPCONTENT_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_PHPCONTENT_HINT',
                'link'    => 'index.php?option=com_content&view=articles',
            ],
            'sourcerer' => [
                'enabled' => $this->params->get('scanSourcerer', '1') == '1',
                'icon'    => 'fas fa-wand-magic-sparkles',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_SOURCERER_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_SOURCERER_HINT',
                'link'    => 'index.php?option=com_content&view=articles',
            ],
            'deprecatedcontent' => [
                'enabled' => $this->params->get('scanDeprecatedContent', '1') == '1',
                'icon'    => 'fas fa-clock-rotate-left',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_DEPRECONTENT_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_DEPRECONTENT_HINT',
                'link'    => 'index.php?option=com_content&view=articles',
            ],
            'deprecatedoverrides' => [
                'enabled' => $this->params->get('scanDeprecatedOverrides', '1') == '1',
                'icon'    => 'fas fa-code-compare',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_DEPREOVERRIDES_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_DEPREOVERRIDES_HINT',
                'link'    => 'index.php?option=com_templates&view=templates',
            ],
            'redefinitions' => [
                'enabled' => $this->params->get('scanRedefinitions', '1') == '1',
                'icon'    => 'fas fa-clone',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_REDEFINE_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_REDEFINE_HINT',
                'link'    => 'index.php?option=com_installer&view=manage',
            ],
            'shims' => [
                'enabled' => $this->params->get('scanShims', '1') == '1',
                'icon'    => 'fas fa-bandage',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_SHIMS_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_SHIMS_HINT',
                'link'    => 'index.php?option=com_installer&view=manage',
            ],
            'malware' => [
                'enabled' => $this->params->get('scanMalware', '1') == '1',
                'icon'    => 'fas fa-bug',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_MALWARE_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_MALWARE_HINT',
                'link'    => 'index.php?option=com_admin&view=sysinfo',
            ],
            'oversized' => [
                'enabled' => $this->params->get('scanMalware', '1') == '1',
                'icon'    => 'fas fa-weight-hanging',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_OVERSIZED_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_OVERSIZED_HINT',
                'link'    => 'index.php?option=com_admin&view=sysinfo',
            ],
            'recentfiles' => [
                'enabled' => $this->params->get('scanRecentFiles', '1') == '1',
                'icon'    => 'fas fa-file-circle-plus',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_RECENTFILES_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_RECENTFILES_HINT',
                'link'    => 'index.php?option=com_admin&view=sysinfo',
            ],
            'integrity' => [
                'enabled' => $this->params->get('scanIntegrity', '1') == '1',
                'icon'    => 'fas fa-fingerprint',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_INTEGRITY_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_INTEGRITY_HINT',
                'link'    => 'index.php?option=com_admin&view=sysinfo',
            ],
            'extensions' => [
                'enabled' => $this->params->get('scanExtensionList', '1') == '1',
                'icon'    => 'fas fa-list',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_EXTLIST_LISTTEXT',
                'hint'    => 'PLG_HEALTHCHECK_PHPSCANNER_EXTLIST_HINT',
                'link'    => 'index.php?option=com_installer&view=manage',
            ],
        ];
    }

    /**
     * Builds a spinner placeholder icon whose value is fetched from com_ajax by the module JS.
     *
     * @param   string  $key         The check key.
     * @param   array   $descriptor  The check descriptor (see asyncCheckDescriptors()).
     *
     * @return  array  An icon descriptor carrying an "ajaxurl" instead of an "amount".
     *
     * @since    __DEPLOY_VERSION__
     */
    private function buildAsyncPlaceholder(string $key, array $descriptor): array
    {
        return [
            'link'    => Uri::base() . $descriptor['link'],
            'icon'    => $descriptor['icon'],
            'text'    => Text::_($descriptor['text']),
            'title'   => Text::_($descriptor['hint']),
            'id'      => 'plg_healthcheck_phpscanner_' . $key,
            'ajaxurl' => Uri::base() . 'index.php?option=com_ajax&group=healthcheck&plugin=phpscanner&format=json&check=' . $key,
        ];
    }

    /**
     * Builds QuickIcon descriptors from a map of raw check results (used by the async endpoint).
     *
     * @param   array  $checks  Map of check key => result array (as returned by the get*() methods).
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    private function buildIcons(array $checks): array
    {
        $icons = [];

        foreach ($checks as $key => $check) {
            if (isset($check['error'])) {
                continue;
            }

            $found = (int) $check['result'] > 0;

            $icon = [
                'link'   => $found ? $check['link'] : '#',
                'icon'   => $check['icon'] ?? 'fas fa-file-code',
                'amount' => $check['result'],
                'text'   => $check['text'],
                'id'     => 'plg_healthcheck_phpscanner_' . strtolower($key),
                'status' => $found ? ($check['statusOnFound'] ?? 'error') : 'success',
            ];

            // Nothing to drill into when the count is zero, so make the icon non-interactive.
            if (!$found) {
                $icon['class'] = 'pe-none';
            }

            // The matched items (the module JS opens this list when the icon is clicked).
            if (!empty($check['items'])) {
                $icon['items'] = $check['items'];
            }

            $icons[] = $icon;
        }

        return $icons;
    }

    /**
     * Returns the number of template override files (templates/&#42;/html) that use deprecated Joomla APIs.
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getDeprecatedInOverrides(): array
    {
        $item = [];

        if ($this->params->get('scanDeprecatedOverrides', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-code-compare';
                $item['statusOnFound'] = 'warning';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_DEPREOVERRIDES_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_DEPREOVERRIDES_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_templates&view=templates';

                $item['items']  = $this->overrideItems();
                $item['result'] = \count($item['items']);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETDEPREOVERRIDES_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of installed third-party extensions that redefine outdated Joomla
     * classes or functions (core extensions are excluded).
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getExtensionsRedefiningClasses(): array
    {
        $item = [];

        if ($this->params->get('scanRedefinitions', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-clone';
                $item['statusOnFound'] = 'warning';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_REDEFINE_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_REDEFINE_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_installer&view=manage';

                $patterns = $this->getRedefinitionPatterns();

                $item['items']  = $this->redefinitionItems($patterns);
                $item['result'] = \count($item['items']);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETREDEFINE_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of installed third-party extensions that ship guarded compatibility shims.
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getCompatibilityShims(): array
    {
        $item = [];

        if ($this->params->get('scanShims', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-bandage';
                $item['statusOnFound'] = 'warning';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_SHIMS_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_SHIMS_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_installer&view=manage';

                $item['items']  = $this->shimItems($this->getShimPatterns());
                $item['result'] = \count($item['items']);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETSHIMS_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns suspicious (possible-malware) PHP files found anywhere under the site root.
     *
     * The full-site walk is cached so it runs at most once per TTL (it is also refreshed by the
     * scheduled-task plugin), since arbitrary-path upload flaws can drop shells outside the usual
     * writable folders.
     *
     * @return  array  Array containing result count, link, text/note labels, items, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getMalwareCheck(): array
    {
        $item = [];

        if ($this->params->get('scanMalware', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-bug';
                $item['statusOnFound'] = 'error';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_MALWARE_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_MALWARE_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_admin&view=sysinfo';

                $files = $this->malwareScanResult();

                // Suspicious files have no admin edit screen, so the item is the path only (no link).
                $item['items']  = array_map(static fn (array $file): array => ['title' => $file['path'], 'link' => ''], $files);
                $item['result'] = \count($files);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETMALWARE_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns PHP files too large to be signature-scanned (skipped by the malware walk). A very large
     * PHP file is abnormal and worth reviewing, so it is surfaced as its own finding.
     *
     * @return  array  Array containing result count, link, text/note labels, items, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getOversizedCheck(): array
    {
        $item = [];

        if ($this->params->get('scanMalware', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-weight-hanging';
                $item['statusOnFound'] = 'warning';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_OVERSIZED_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_OVERSIZED_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_admin&view=sysinfo';

                $files = $this->oversizedScanResult();

                // These have no admin edit screen, so render the path with its size as plain text.
                $item['items'] = array_map(
                    static fn (array $file): array => [
                        'title' => $file['path'] . '  (' . self::formatBytes((int) ($file['size'] ?? 0)) . ')',
                        'link'  => '',
                    ],
                    $files
                );
                $item['result'] = \count($files);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETOVERSIZED_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Formats a byte count as a compact human-readable size (e.g. "1.6 GB").
     *
     * @param   int  $bytes  The size in bytes.
     *
     * @return  string
     *
     * @since    __DEPLOY_VERSION__
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, \count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }

    /**
     * Returns the cached malware-scan result, running (and caching) a fresh full-site scan when stale.
     *
     * @return  array  List of ['path' => string] items.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function malwareScanResult(): array
    {
        $this->ensureMalwareScan();

        return $this->readJsonCache('phpscanner_malware.json');
    }

    /**
     * Returns the oversized PHP files the malware scan skipped (path + size), from the cache written
     * alongside the malware-match cache.
     *
     * @return  array  List of ['path' => string, 'size' => int] items.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function oversizedScanResult(): array
    {
        $this->ensureMalwareScan();

        return $this->readJsonCache('phpscanner_oversized.json');
    }

    /**
     * Runs the full-site malware walk when its cache is stale, writing both the malware-match cache
     * and the oversized-files cache the dashboard reads.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    private function ensureMalwareScan(): void
    {
        $cacheFile = JPATH_CACHE . '/phpscanner_malware.json';
        $ttl       = 21600; // 6 hours

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            return;
        }

        $scanner = new MalwareScanner($this->malwareSignatures(), $this->malwareExcludes());
        $items   = $scanner->scan(JPATH_ROOT);

        @file_put_contents($cacheFile, json_encode($items));
        @file_put_contents(JPATH_CACHE . '/phpscanner_oversized.json', json_encode($scanner->getOversized()));
    }

    /**
     * Reads and decodes a JSON cache file under JPATH_CACHE, returning [] when absent or invalid.
     *
     * @param   string  $name  The cache file name.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    private function readJsonCache(string $name): array
    {
        $file = JPATH_CACHE . '/' . $name;

        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);

            if (\is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Returns the configured malware signatures, falling back to the built-in defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    private function malwareSignatures(): array
    {
        return ParamList::lines((string) $this->params->get('malwareSignatures', ''), MalwareScanner::DEFAULT_SIGNATURES);
    }

    /**
     * Returns the configured excluded directory names, falling back to the built-in defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    private function malwareExcludes(): array
    {
        return ParamList::commaList((string) $this->params->get('malwareExcludes', ''), MalwareScanner::DEFAULT_EXCLUDES);
    }

    /**
     * Returns PHP files that don't look like part of a regular install: orphans (owned by no
     * registered extension) or files modified after their owning extension was installed.
     *
     * A registered extension whose usual files exist (manifest, src, language) is trusted; what is
     * reported is anything outside that structure, a classic indicator of a planted shell. The
     * result is cached and refreshed like the malware scan.
     *
     * @return  array  Array containing result count, link, text/note labels, items, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getRecentFilesCheck(): array
    {
        $item = [];

        if ($this->params->get('scanRecentFiles', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-file-circle-plus';
                $item['statusOnFound'] = 'warning';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_RECENTFILES_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_RECENTFILES_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_admin&view=sysinfo';

                $files = $this->recentFilesScanResult();

                $reasons = [
                    'orphan'  => Text::_('PLG_HEALTHCHECK_PHPSCANNER_RECENTFILES_REASON_ORPHAN'),
                    'changed' => Text::_('PLG_HEALTHCHECK_PHPSCANNER_RECENTFILES_REASON_CHANGED'),
                ];

                // These have no admin edit screen, so render date, reason and path as plain copyable text.
                $item['items'] = array_map(
                    static fn (array $file): array => [
                        'title' => date('Y-m-d H:i', $file['mtime']) . '  [' . ($reasons[$file['reason']] ?? $file['reason']) . ']  ' . $file['path'],
                        'link'  => '',
                    ],
                    $files
                );
                $item['result'] = \count($files);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETRECENTFILES_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the cached recent-files result, running (and caching) a fresh ownership-aware walk
     * when stale.
     *
     * @return  array  List of ['path' => string, 'mtime' => int, 'reason' => string] items.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function recentFilesScanResult(): array
    {
        $cacheFile = JPATH_CACHE . '/phpscanner_recentfiles.json';
        $ttl       = 21600; // 6 hours

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $data = json_decode((string) file_get_contents($cacheFile), true);

            if (\is_array($data)) {
                return $data;
            }
        }

        $coreMtime = $this->coreUpdateTime();
        $owners    = (new ExtensionInventory($this->getDatabase(), JPATH_ROOT))->ownership($coreMtime);

        $items = (new RecentFileScanner(
            $owners,
            $coreMtime,
            RecentFileScanner::DEFAULT_CORE_PREFIXES,
            $this->recentFilesExcludes()
        ))->scan(JPATH_ROOT);

        @file_put_contents($cacheFile, json_encode($items));

        return $items;
    }

    /**
     * Returns the mtime of the core manifest (the last core update), used as the reference for
     * core-owned files.
     *
     * @return  integer  A Unix timestamp, or 0 when it cannot be determined.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function coreUpdateTime(): int
    {
        $core = JPATH_ADMINISTRATOR . '/manifests/files/joomla.xml';

        return is_file($core) ? (int) filemtime($core) : 0;
    }

    /**
     * Returns the configured excluded directory names, falling back to the built-in defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    private function recentFilesExcludes(): array
    {
        return ParamList::commaList((string) $this->params->get('recentFilesExcludes', ''), RecentFileScanner::DEFAULT_EXCLUDES);
    }

    /**
     * Compares every PHP file against the stored integrity baseline (the trusted original hashes),
     * reporting changed, added and missing files. Establishes the baseline on first run, or when the
     * "rebuild baseline" option is set.
     *
     * @return  array  Array containing result count, link, text/note labels, items, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getIntegrityCheck(): array
    {
        $item = [];

        if ($this->params->get('scanIntegrity', '1') != '1') {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');

            return $item;
        }

        try {
            $item['icon'] = 'fas fa-fingerprint';
            $item['text'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_INTEGRITY_LISTTEXT');
            $item['link'] = Uri::base() . 'index.php?option=com_admin&view=sysinfo';

            $baseline = new HashBaseline($this->getDatabase());
            $rebuild  = $this->params->get('rebuildBaseline', '0') == '1';

            // Establish (or re-establish) the trusted baseline, then report nothing to compare yet.
            if ($rebuild || $baseline->isEmpty()) {
                $stored = $baseline->store((new HashScanner($this->integrityExcludes()))->scan(JPATH_ROOT));

                if ($rebuild) {
                    $this->clearFlag('rebuildBaseline');
                }

                @unlink(JPATH_CACHE . '/phpscanner_integrity.json');

                $item['statusOnFound'] = 'info';
                $item['note']          = Text::sprintf('PLG_HEALTHCHECK_PHPSCANNER_INTEGRITY_BASELINED', $stored);
                $item['result']        = 0;

                return $item;
            }

            // One-shot re-scan: drop the cached comparison so it is recomputed now.
            if ($this->params->get('rescanIntegrity', '0') == '1') {
                @unlink(JPATH_CACHE . '/phpscanner_integrity.json');
                $this->clearFlag('rescanIntegrity');
            }

            $item['statusOnFound'] = 'error';
            $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_INTEGRITY_LISTNOTE');

            $diff    = $this->integrityResult($baseline);
            $reasons = [
                'changed' => Text::_('PLG_HEALTHCHECK_PHPSCANNER_INTEGRITY_REASON_CHANGED'),
                'added'   => Text::_('PLG_HEALTHCHECK_PHPSCANNER_INTEGRITY_REASON_ADDED'),
                'missing' => Text::_('PLG_HEALTHCHECK_PHPSCANNER_INTEGRITY_REASON_MISSING'),
            ];

            $items = [];

            foreach (['changed', 'added', 'missing'] as $reason) {
                foreach ($diff[$reason] as $path) {
                    $items[] = ['title' => '[' . $reasons[$reason] . ']  ' . $path, 'link' => ''];
                }
            }

            $item['items']  = $items;
            $item['result'] = \count($items);
        } catch (\Exception $e) {
            $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETINTEGRITY_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
            $item['error'] = $e->getMessage();
        }

        return $item;
    }

    /**
     * Returns the cached integrity comparison, running (and caching) a fresh full-site hash + compare
     * when stale.
     *
     * @param   HashBaseline  $baseline  The baseline repository.
     *
     * @return  array  ['changed' => string[], 'added' => string[], 'missing' => string[]].
     *
     * @since    __DEPLOY_VERSION__
     */
    private function integrityResult(HashBaseline $baseline): array
    {
        $cacheFile = JPATH_CACHE . '/phpscanner_integrity.json';
        $ttl       = 900; // 15 minutes (integrity is time-sensitive; use the re-scan option for an immediate refresh)

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $data = json_decode((string) file_get_contents($cacheFile), true);

            if (\is_array($data) && isset($data['changed'], $data['added'], $data['missing'])) {
                return $data;
            }
        }

        $diff = $baseline->compare((new HashScanner($this->integrityExcludes()))->scan(JPATH_ROOT));

        @file_put_contents($cacheFile, json_encode($diff));

        return $diff;
    }

    /**
     * Returns the configured integrity excluded directory names, falling back to the defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    private function integrityExcludes(): array
    {
        return ParamList::commaList((string) $this->params->get('integrityExcludes', ''), HashScanner::DEFAULT_EXCLUDES);
    }

    /**
     * Resets a one-shot option (e.g. rebuildBaseline, rescanIntegrity) back to off after it has run,
     * by updating the plugin's stored parameters.
     *
     * @param   string  $param  The parameter name to reset to "0".
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    private function clearFlag(string $param): void
    {
        $this->params->set($param, '0');

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote($this->params->toString()))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('healthcheck'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('phpscanner'));

        $db->setQuery($query)->execute();
    }

    /**
     * Returns the installed extensions with their install/update time (the mtime of their manifest).
     *
     * @return  array  Array containing result count, link, text/note labels, items, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getInstalledExtensionsCheck(): array
    {
        $item = [];

        if ($this->params->get('scanExtensionList', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-list';
                $item['statusOnFound'] = 'info';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_EXTLIST_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_EXTLIST_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_installer&view=manage';

                $extensions = (new ExtensionInventory($this->getDatabase(), JPATH_ROOT))->list();

                // Only third-party extensions are of interest; core ones are locked (or protected).
                $extensions = array_values(array_filter(
                    $extensions,
                    static fn (array $e): bool => $e['protected'] === 0 && $e['locked'] === 0 && $e['state'] === 0
                ));

                $item['items']  = $this->groupExtensionItems($extensions);
                $item['result'] = \count($extensions);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETEXTLIST_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Turns the flat extension inventory into a grouped item list: package children are nested under
     * their parent package, standalone extensions are listed on their own, all newest first.
     *
     * @param   array  $extensions  The inventory from ExtensionInventory::list().
     *
     * @return  array  List of ['title' => string, 'link' => string] items.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function groupExtensionItems(array $extensions): array
    {
        $children = [];
        $topLevel = [];

        foreach ($extensions as $ext) {
            if ($ext['package_id'] > 0) {
                $children[$ext['package_id']][] = $ext;
            } else {
                $topLevel[] = $ext;
            }
        }

        // Package children carried no package_id of their own get listed under their parent, so only
        // packages and genuinely standalone extensions remain at the top level, sorted newest first.
        usort($topLevel, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        $labelSort = static fn (array $a, array $b): int => strcmp($a['label'], $b['label']);
        $items     = [];

        foreach ($topLevel as $ext) {
            $items[] = $this->extensionListItem($ext, false);

            if ($ext['type'] !== 'package' || empty($children[$ext['extension_id']])) {
                continue;
            }

            $group = $children[$ext['extension_id']];
            usort($group, $labelSort);

            foreach ($group as $child) {
                $items[] = $this->extensionListItem($child, true);
            }
        }

        return $items;
    }

    /**
     * Builds a single extension-list item (date + canonical label + type), indented when it is a
     * package child.
     *
     * @param   array    $ext      An inventory entry.
     * @param   boolean  $isChild  Whether to render it indented under its package.
     *
     * @return  array  ['title' => string, 'link' => string].
     *
     * @since    __DEPLOY_VERSION__
     */
    private function extensionListItem(array $ext, bool $isChild): array
    {
        $when    = $ext['mtime'] ? date('Y-m-d H:i', $ext['mtime']) : '----------';
        $prefix  = $isChild ? '↳ ' : ($when . '  ');
        $name    = $this->displayName($ext);
        $caption = $name === $ext['label'] ? $ext['label'] : $name . '  ·  ' . $ext['label'];

        return [
            'title' => $prefix . $caption,
            'link'  => Uri::base() . 'index.php?option=com_installer&view=manage&filter[search]='
                . rawurlencode($ext['element']),
        ];
    }

    /**
     * Resolves a human-readable extension title by loading the extension's own ".sys" language and
     * translating its manifest name, falling back to the canonical label when no translation exists.
     *
     * @param   array  $ext  An inventory entry from ExtensionInventory::list().
     *
     * @return  string
     *
     * @since    __DEPLOY_VERSION__
     */
    private function displayName(array $ext): string
    {
        $lang    = $this->getApplication()->getLanguage();
        $element = $ext['element'];
        $folder  = $ext['folder'];
        $path    = $ext['client'] === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE;

        switch ($ext['type']) {
            case 'component':
                $lang->load("$element.sys", JPATH_ADMINISTRATOR)
                    || $lang->load("$element.sys", JPATH_ADMINISTRATOR . '/components/' . $element);
                break;
            case 'module':
                $lang->load("$element.sys", $path) || $lang->load("$element.sys", $path . '/modules/' . $element);
                break;
            case 'plugin':
                $name = 'plg_' . $folder . '_' . $element;
                $lang->load("$name.sys", JPATH_ADMINISTRATOR)
                    || $lang->load("$name.sys", JPATH_PLUGINS . '/' . $folder . '/' . $element);
                break;
            case 'template':
                $lang->load('tpl_' . $element . '.sys', $path)
                    || $lang->load('tpl_' . $element . '.sys', $path . '/templates/' . $element);
                break;
            case 'library':
                $lang->load('lib_' . $element . '.sys', $path)
                    || $lang->load('lib_' . $element . '.sys', JPATH_LIBRARIES . '/' . $element);
                break;
            case 'package':
            default:
                $lang->load($element . '.sys', JPATH_SITE);
                break;
        }

        $title = trim(Text::_($ext['name']));

        // An untranslated key (all caps / underscores) or an empty value is not a usable name.
        if ($title === '' || preg_match('/^[A-Z0-9_]+$/', $title)) {
            return $ext['label'];
        }

        return $title;
    }

    /**
     * Counts the non-core extensions matched by the given file test.
     *
     * @param   callable  $dirTest  A callback receiving a directory and returning a boolean.
     *
     * @return  integer
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function countNonCoreExtensions(callable $dirTest): int
    {
        return \count($this->matchedExtensions($dirTest));
    }

    /**
     * Maximum number of matching source lines collected per extension for the code preview.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const MAX_CODE_MATCHES = 100;

    /**
     * Default number of context lines shown before and after each matching line in the code modal,
     * used when the "contextLines" parameter is not set.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const CODE_CONTEXT = 2;

    /**
     * Builds the matched-extension items for the redefinitions check. Each item carries the offending
     * source (file, line number and the matching line) in a "code" field, which the module renders in
     * a modal instead of linking to the extension.
     *
     * @param   string[]  $patterns  The redefinition patterns to look for.
     *
     * @return  array  List of ['title' => string, 'code' => string] items.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function redefinitionItems(array $patterns): array
    {
        return $this->codeMatchItems(fn (string $dir): array => $this->collectLineMatches(
            $dir,
            static fn (string $text): bool => self::matchesAny($text, $patterns)
        ));
    }

    /**
     * Builds the matched-extension items for the shims check, each carrying the offending source for
     * the code modal (see redefinitionItems()).
     *
     * @param   string[]  $regexes  The shim regexes (without delimiters).
     *
     * @return  array  List of ['title' => string, 'code' => string] items.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function shimItems(array $regexes): array
    {
        return $this->codeMatchItems(fn (string $dir): array => $this->collectLineMatches(
            $dir,
            static fn (string $text): bool => self::matchesAny($text, $regexes),
            static fn (array $lines, int $index, int $total): bool => self::guardDeclaresLegacySymbol($lines, $index, $total)
        ));
    }

    /**
     * Decides whether a matched shim guard (e.g. "if (!class_exists('JFactory'))") actually ships a
     * shim — i.e. its guarded block declares the Joomla legacy symbol (a "class/interface/trait J…"
     * declaration, a class_alias() to a J-class, or a jimport/jexit/jdebug function). A guard that
     * merely checks for Joomla and bails (return/throw) is a compatibility check, not a shim, so it
     * is not flagged.
     *
     * @param   array<int, string>  $lines  All lines of the file.
     * @param   integer             $index  Zero-based index of the guard line.
     * @param   integer             $total  Total number of lines.
     *
     * @return  boolean
     *
     * @since    __DEPLOY_VERSION__
     */
    private static function guardDeclaresLegacySymbol(array $lines, int $index, int $total): bool
    {
        $declaration = '/class_alias\s*\([^,)]+,\s*[\'"]\\\\?J[A-Z]'
            . '|\b(?:class|interface|trait)\s+\\\\?J[A-Z]'
            . '|\bfunction\s+j(?:import|exit|debug)\b/';

        $depth  = 0;
        $opened = false;
        $limit  = min($total - 1, $index + 40);

        for ($k = $index; $k <= $limit; $k++) {
            $text = $lines[$k];

            if (preg_match($declaration, $text)) {
                return true;
            }

            $depth += substr_count($text, '{') - substr_count($text, '}');

            if (str_contains($text, '{')) {
                $opened = true;
            }

            // The guarded block opened and closed again with no legacy declaration inside.
            if ($opened && $depth <= 0) {
                return false;
            }

            // A brace-less guard (single statement) whose own line held no declaration is not a shim.
            if (!$opened && $k > $index && trim($text) !== '') {
                return false;
            }
        }

        return false;
    }

    /**
     * Builds matched-extension items (title + offending code) from a per-directory match collector.
     * The plugin's own directory is always skipped, and the collected code is capped per extension.
     *
     * @param   callable  $dirCollector  Receives a directory, returns its match rows (see
     *                                    collectLineMatches()).
     *
     * @return  array  List of ['title' => string, 'code' => string] items.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function codeMatchItems(callable $dirCollector): array
    {
        // This plugin's own directory contains the pattern strings, so never scan it.
        $ownDir = realpath(\dirname(__DIR__, 2)) ?: '';
        $items  = [];

        foreach ($this->getNonCoreExtensions() as $ext) {
            $matches = [];

            foreach ($ext->dirs as $dir) {
                if ($ownDir !== '' && realpath($dir) === $ownDir) {
                    continue;
                }

                foreach ($dirCollector($dir) as $match) {
                    $matches[] = $match;

                    if (\count($matches) >= self::MAX_CODE_MATCHES) {
                        break 2;
                    }
                }
            }

            if (!$matches) {
                continue;
            }

            $items[] = [
                'title' => Text::_($ext->name),
                'code'  => $this->formatCodeMatches($matches),
            ];
        }

        return $items;
    }

    /**
     * Returns the lines of a directory's non-vendor PHP files for which the matcher returns true.
     *
     * @param   string     $dir      The base directory to scan.
     * @param   callable   $matcher  Receives a line (or the whole file for the cheap reject) and
     *                               returns whether it matches.
     * @param   ?callable  $accept   Optional second-stage filter receiving (lines, index, total) for
     *                               a matched line; the match is kept only when it returns true.
     *
     * @return  array<int, array{file: string, line: int, window: array<int, string>}>  Each match
     *          carries the matching line number and a window of surrounding lines keyed by line number.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function collectLineMatches(string $dir, callable $matcher, ?callable $accept = null): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $matches = [];
        $context = $this->getContextLines();

        foreach (Folder::files($dir, '\.php$', true, true) as $file) {
            // Skip bundled third-party libraries, which legitimately use class_alias() etc.
            if (str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = @file_get_contents($file);

            // Cheap whole-file reject before splitting into lines.
            if ($contents === false || !$matcher($contents)) {
                continue;
            }

            $rel   = str_replace('\\', '/', substr($file, \strlen(JPATH_ROOT)));
            $lines = explode("\n", $contents);
            $total = \count($lines);

            foreach ($lines as $i => $line) {
                if ($matcher($line) && ($accept === null || $accept($lines, $i, $total))) {
                    // Capture the matching line plus a few lines of context, keyed by line number.
                    $window = [];

                    for ($j = max(0, $i - $context), $end = min($total - 1, $i + $context); $j <= $end; $j++) {
                        $window[$j + 1] = rtrim($lines[$j]);
                    }

                    $matches[] = ['file' => $rel, 'line' => $i + 1, 'window' => $window];
                }

                if (\count($matches) >= self::MAX_CODE_MATCHES) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    /**
     * Returns true if the subject matches any of the regexes (given without delimiters).
     *
     * @param   string    $subject  The text to test.
     * @param   string[]  $regexes  The regular expressions without delimiters.
     *
     * @return  boolean
     *
     * @since    __DEPLOY_VERSION__
     */
    private static function matchesAny(string $subject, array $regexes): bool
    {
        foreach ($regexes as $regex) {
            if (@preg_match('/' . $regex . '/', $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Formats collected matches into a readable, multi-line code block grouped by file for the code
     * modal. Context windows are merged and deduplicated per file, the offending lines are marked
     * with ">", and a "..." separator is shown between non-contiguous chunks.
     *
     * @param   array<int, array{file: string, line: int, window: array<int, string>}>  $matches
     *
     * @return  string
     *
     * @since    __DEPLOY_VERSION__
     */
    private function formatCodeMatches(array $matches): string
    {
        $byFile = [];

        foreach ($matches as $match) {
            $file = $match['file'];

            $byFile[$file] ??= ['lines' => [], 'matched' => []];

            foreach ($match['window'] as $number => $text) {
                $byFile[$file]['lines'][$number] = $text;
            }

            $byFile[$file]['matched'][$match['line']] = true;
        }

        $blocks = [];

        foreach ($byFile as $file => $data) {
            ksort($data['lines']);

            $rows     = [$file];
            $previous = null;

            foreach ($data['lines'] as $number => $text) {
                if ($previous !== null && $number > $previous + 1) {
                    $rows[] = '        ...';
                }

                $rows[]   = \sprintf('%s %5d: %s', isset($data['matched'][$number]) ? '>' : ' ', $number, $text);
                $previous = $number;
            }

            $blocks[] = implode("\n", $rows);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Returns the non-core extensions for which the file test matches a base directory.
     * The plugin's own directory is always skipped.
     *
     * @param   callable  $dirTest  A callback receiving a directory and returning a boolean.
     *
     * @return  object[]
     *
     * @since    __DEPLOY_VERSION__
     */
    private function matchedExtensions(callable $dirTest): array
    {
        // This plugin's own directory contains the pattern strings, so never scan it.
        $ownDir  = realpath(\dirname(__DIR__, 2)) ?: '';
        $matched = [];

        foreach ($this->getNonCoreExtensions() as $ext) {
            foreach ($ext->dirs as $dir) {
                if ($ownDir !== '' && realpath($dir) === $ownDir) {
                    continue;
                }

                if ($dirTest($dir)) {
                    $matched[] = $ext;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * Returns installed non-core (not protected, not locked) extensions with their base directories.
     *
     * @return  object[]  Each row carries extension_id, name, type, element, folder and a "dirs" array.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getNonCoreExtensions(): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'name', 'type', 'element', 'folder', 'client_id']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('protected') . ' = 0')
            ->where($db->quoteName('locked') . ' = 0')
            ->where($db->quoteName('state') . ' = 0')
            ->whereIn($db->quoteName('type'), ['component', 'plugin', 'module', 'library', 'template'], ParameterType::STRING);

        $db->setQuery($query);

        $extensions = [];

        foreach ($db->loadObjectList() as $row) {
            $dirs = [];

            switch ($row->type) {
                case 'plugin':
                    $dirs[] = JPATH_PLUGINS . '/' . $row->folder . '/' . $row->element;
                    break;
                case 'module':
                    $base   = ((int) $row->client_id === 1) ? JPATH_ADMINISTRATOR : JPATH_SITE;
                    $dirs[] = $base . '/modules/' . $row->element;
                    break;
                case 'component':
                    $dirs[] = JPATH_ADMINISTRATOR . '/components/' . $row->element;
                    $dirs[] = JPATH_SITE . '/components/' . $row->element;
                    break;
                case 'library':
                    $dirs[] = JPATH_LIBRARIES . '/' . $row->element;
                    break;
                case 'template':
                    $base   = ((int) $row->client_id === 1) ? JPATH_ADMINISTRATOR : JPATH_SITE;
                    $dirs[] = $base . '/templates/' . $row->element;
                    break;
            }

            $row->dirs    = array_values(array_filter($dirs, 'is_dir'));
            $extensions[] = $row;
        }

        return $extensions;
    }

    /**
     * Returns the number of context lines to show around each match in the code modal, clamped to a
     * sane range.
     *
     * @return  integer
     *
     * @since    __DEPLOY_VERSION__
     */
    private function getContextLines(): int
    {
        return max(0, min(20, (int) $this->params->get('contextLines', self::CODE_CONTEXT)));
    }

    /**
     * Returns the configured redefinition patterns, falling back to the built-in defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getRedefinitionPatterns(): array
    {
        $patterns = ParamList::lines((string) $this->params->get('redefinitionPatterns', ''), self::REDEFINITION_PATTERNS);

        // Migrate the previous over-broad registerAlias pattern (it matched an extension aliasing its
        // own classes, not only Joomla legacy ones) to the refined regex requiring a J-prefixed alias.
        return array_map(
            static fn (string $pattern): string => $pattern === self::LEGACY_REGISTERALIAS_PATTERN ? self::REDEFINITION_PATTERNS[0] : $pattern,
            $patterns
        );
    }

    /**
     * Returns the configured PHP-artifact content patterns (wrapped as %fragment% LIKE patterns),
     * falling back to the built-in defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getPhpPatterns(): array
    {
        return $this->likePatterns('phpPatterns', self::PHP_PATTERNS);
    }

    /**
     * Returns the configured 3rd party tool content patterns (wrapped as %fragment% LIKE patterns),
     * falling back to the built-in defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getSourcererPatterns(): array
    {
        return $this->likePatterns('sourcererPatterns', self::SOURCERER_PATTERNS);
    }

    /**
     * Returns the configured compatibility-shim regexes (no delimiters), falling back to the
     * built-in defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getShimPatterns(): array
    {
        $patterns = ParamList::lines((string) $this->params->get('shimPatterns', ''), self::SHIM_PATTERNS);

        // Migrate the previous over-broad guards (which matched an extension guarding its own
        // functions/classes) to the refined regexes requiring a Joomla legacy symbol.
        return array_map(
            static fn (string $pattern): string => self::LEGACY_SHIM_PATTERNS[$pattern] ?? $pattern,
            $patterns
        );
    }

    /**
     * Reads a newline-separated parameter of raw fragments and wraps each as a %fragment% LIKE
     * pattern, falling back to the given defaults when the parameter is empty.
     *
     * @param   string    $param     The parameter name holding the raw fragments.
     * @param   string[]  $defaults  Raw default fragments.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    private function likePatterns(string $param, array $defaults): array
    {
        $fragments = ParamList::lines((string) $this->params->get($param, ''), $defaults);

        return array_map(static fn (string $fragment): string => '%' . $fragment . '%', $fragments);
    }

    /**
     * Returns the configured deprecated API tokens, falling back to the built-in defaults.
     *
     * @return  string[]
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getDeprecatedTokens(): array
    {
        return ParamList::commaList((string) $this->params->get('deprecatedTokens', ''), self::DEPRECATED_TOKENS);
    }

    /**
     * Returns the template override files containing a deprecated token as items, each linking
     * to that file in the template editor.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    private function overrideItems(): array
    {
        $items  = [];
        $tokens = $this->getDeprecatedTokens();

        foreach ([JPATH_SITE . '/templates' => 0, JPATH_ADMINISTRATOR . '/templates' => 1] as $templatesPath => $clientId) {
            if (!is_dir($templatesPath)) {
                continue;
            }

            foreach (Folder::folders($templatesPath) as $template) {
                $htmlPath = $templatesPath . '/' . $template . '/html';

                if (!is_dir($htmlPath)) {
                    continue;
                }

                $extensionId = $this->templateExtensionId($template, $clientId);

                foreach (Folder::files($htmlPath, '\.php$', true, true) as $file) {
                    $contents = file_get_contents($file);

                    if ($contents === false) {
                        continue;
                    }

                    foreach ($tokens as $token) {
                        if (str_contains($contents, $token)) {
                            $relative = 'html/' . ltrim(str_replace('\\', '/', substr($file, \strlen($htmlPath))), '/');

                            $items[] = [
                                'title' => $template . ': ' . $relative,
                                'link'  => $extensionId
                                    ? Uri::base() . 'index.php?option=com_templates&view=template&id=' . $extensionId . '&file=' . rawurlencode(base64_encode('/' . $relative))
                                    : Uri::base() . 'index.php?option=com_templates&view=templates',
                            ];

                            break;
                        }
                    }
                }
            }
        }

        return $items;
    }

    /**
     * Returns the extension_id of an installed template, or 0 when not found.
     *
     * @param   string   $template  The template element.
     * @param   integer  $clientId  0 for site, 1 for administrator.
     *
     * @return  integer
     *
     * @since    __DEPLOY_VERSION__
     */
    private function templateExtensionId(string $template, int $clientId): int
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('client_id') . ' = :client')
            ->bind(':element', $template, ParameterType::STRING)
            ->bind(':client', $clientId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult();
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
        $logging    = $this->params->get('logging', 0);    // How to handle errors
        switch ($logging) {
            case 3: // enqueue Message
                Factory::getApplication()->enqueueMessage($msgContext . ' ' . $msg, $msgLevel);
                break;
            case 2: // log in JoomlaLog
                Log::add($msgContext . ' ' . $msg, Log::ERROR, 'plg_healthcheck_phpscanner');
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
