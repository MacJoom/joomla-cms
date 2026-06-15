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
use Joomla\Plugin\Healthcheck\PhpScanner\Scanner\MalwareScanner;
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
     * PHP artifact LIKE patterns ("%" and "_" are the only LIKE wildcards).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const PHP_PATTERNS = ['%<?php%', '%<?=%'];

    /**
     * Sourcerer (Regular Labs) code-tag LIKE patterns.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const SOURCERER_PATTERNS = ['%{source%'];

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
        'JLoader::registerAlias(',
        'function jimport',
        'function jexit',
        'function jdebug',
    ];

    /**
     * Regexes (no delimiters) matching guarded compatibility shims / polyfills, whitespace tolerant.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const SHIM_PATTERNS = [
        '!\s*function_exists\s*\(',
        '!\s*class_exists\s*\(',
        '!\s*interface_exists\s*\(',
        '!\s*trait_exists\s*\(',
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
     * Runs a content issue (PHP artifacts, Sourcerer tags or deprecated APIs) over articles and
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
                self::PHP_PATTERNS,
                $this->params->get('scanArticles', '1') == '1',
                $this->params->get('scanModules', '1') == '1',
                'error',
                'fas fa-file-code',
                'PLG_HEALTHCHECK_PHPSCANNER_PHPCONTENT_LISTTEXT',
            ],
            'sourcerer' => [
                self::SOURCERER_PATTERNS,
                true,
                true,
                'warning',
                'fas fa-wand-magic-sparkles',
                'PLG_HEALTHCHECK_PHPSCANNER_SOURCERER_LISTTEXT',
            ],
            'deprecatedcontent' => [
                array_map(static fn(string $token): string => '%' . $token . '%', $this->getDeprecatedTokens()),
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
            'recentfiles'         => $this->getRecentFilesCheck(),
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
                'link'    => 'index.php?option=com_content&view=articles',
            ],
            'sourcerer' => [
                'enabled' => $this->params->get('scanSourcerer', '1') == '1',
                'icon'    => 'fas fa-wand-magic-sparkles',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_SOURCERER_LISTTEXT',
                'link'    => 'index.php?option=com_content&view=articles',
            ],
            'deprecatedcontent' => [
                'enabled' => $this->params->get('scanDeprecatedContent', '1') == '1',
                'icon'    => 'fas fa-clock-rotate-left',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_DEPRECONTENT_LISTTEXT',
                'link'    => 'index.php?option=com_content&view=articles',
            ],
            'deprecatedoverrides' => [
                'enabled' => $this->params->get('scanDeprecatedOverrides', '1') == '1',
                'icon'    => 'fas fa-code-compare',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_DEPREOVERRIDES_LISTTEXT',
                'link'    => 'index.php?option=com_templates&view=templates',
            ],
            'redefinitions' => [
                'enabled' => $this->params->get('scanRedefinitions', '1') == '1',
                'icon'    => 'fas fa-clone',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_REDEFINE_LISTTEXT',
                'link'    => 'index.php?option=com_installer&view=manage',
            ],
            'shims' => [
                'enabled' => $this->params->get('scanShims', '1') == '1',
                'icon'    => 'fas fa-bandage',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_SHIMS_LISTTEXT',
                'link'    => 'index.php?option=com_installer&view=manage',
            ],
            'malware' => [
                'enabled' => $this->params->get('scanMalware', '1') == '1',
                'icon'    => 'fas fa-bug',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_MALWARE_LISTTEXT',
                'link'    => 'index.php?option=com_admin&view=sysinfo',
            ],
            'recentfiles' => [
                'enabled' => $this->params->get('scanRecentFiles', '1') == '1',
                'icon'    => 'fas fa-file-circle-plus',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_RECENTFILES_LISTTEXT',
                'link'    => 'index.php?option=com_admin&view=sysinfo',
            ],
            'extensions' => [
                'enabled' => $this->params->get('scanExtensionList', '1') == '1',
                'icon'    => 'fas fa-list',
                'text'    => 'PLG_HEALTHCHECK_PHPSCANNER_EXTLIST_LISTTEXT',
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

                $item['items']  = $this->extensionItems(fn(string $dir): bool => $this->dirHasRedefinition($dir, $patterns));
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

                $item['items']  = $this->extensionItems(fn(string $dir): bool => $this->dirHasRegexMatch($dir, self::SHIM_PATTERNS));
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
                $item['items']  = array_map(static fn(array $file): array => ['title' => $file['path'], 'link' => ''], $files);
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
     * Returns the cached malware-scan result, running (and caching) a fresh full-site scan when stale.
     *
     * @return  array  List of ['path' => string] items.
     *
     * @since    __DEPLOY_VERSION__
     */
    private function malwareScanResult(): array
    {
        $cacheFile = JPATH_CACHE . '/phpscanner_malware.json';
        $ttl       = 21600; // 6 hours

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $data = json_decode((string) file_get_contents($cacheFile), true);

            if (\is_array($data)) {
                return $data;
            }
        }

        $items = (new MalwareScanner($this->malwareSignatures(), $this->malwareExcludes()))->scan(JPATH_ROOT);

        @file_put_contents($cacheFile, json_encode($items));

        return $items;
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
        $configured = (string) $this->params->get('malwareSignatures', '');
        $list       = array_filter(array_map('trim', preg_split('/[\r\n]+/', $configured)));

        return $list ?: MalwareScanner::DEFAULT_SIGNATURES;
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
        $configured = (string) $this->params->get('malwareExcludes', '');
        $list       = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $configured)));

        return $list ?: MalwareScanner::DEFAULT_EXCLUDES;
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
                    static fn(array $file): array => [
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
        $configured = (string) $this->params->get('recentFilesExcludes', '');
        $list       = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $configured)));

        return $list ?: RecentFileScanner::DEFAULT_EXCLUDES;
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

                usort($extensions, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

                $item['items'] = array_map(
                    static function (array $ext): array {
                        $when = $ext['mtime'] ? date('Y-m-d H:i', $ext['mtime']) : '----------';

                        return [
                            'title' => $when . '  ' . $ext['element'] . ' (' . $ext['type'] . ')',
                            'link'  => Uri::base() . 'index.php?option=com_installer&view=manage&filter[search]=' . rawurlencode($ext['element']),
                        ];
                    },
                    $extensions
                );
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
     * Returns the matched non-core extensions as items (title + link to the extension).
     *
     * @param   callable  $dirTest  A callback receiving a directory and returning a boolean.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    private function extensionItems(callable $dirTest): array
    {
        $items = [];

        foreach ($this->matchedExtensions($dirTest) as $ext) {
            if ($ext->type === 'plugin') {
                $link = Uri::base() . 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . (int) $ext->extension_id;
            } else {
                $link = Uri::base() . 'index.php?option=com_installer&view=manage&filter[search]=' . rawurlencode($ext->element);
            }

            $items[] = [
                'title' => Text::_($ext->name),
                'link'  => $link,
            ];
        }

        return $items;
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
     * Returns true if any non-vendor PHP file in the directory contains a redefinition pattern.
     *
     * @param   string    $dir       The base directory to scan.
     * @param   string[]  $patterns  The redefinition patterns to look for.
     *
     * @return  boolean
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function dirHasRedefinition(string $dir, array $patterns): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        foreach (Folder::files($dir, '\.php$', true, true) as $file) {
            // Skip bundled third-party libraries, which legitimately use class_alias() etc.
            if (str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (str_contains($contents, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns true if any non-vendor PHP file in the directory matches one of the regexes.
     *
     * @param   string    $dir      The base directory to scan.
     * @param   string[]  $regexes  Regular expressions without delimiters.
     *
     * @return  boolean
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function dirHasRegexMatch(string $dir, array $regexes): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        foreach (Folder::files($dir, '\.php$', true, true) as $file) {
            if (str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            foreach ($regexes as $regex) {
                if (preg_match('/' . $regex . '/', $contents) === 1) {
                    return true;
                }
            }
        }

        return false;
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
        $configured = (string) $this->params->get('redefinitionPatterns', '');

        $patterns = array_filter(array_map('trim', preg_split('/[\r\n]+/', $configured)));

        return $patterns ?: self::REDEFINITION_PATTERNS;
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
        $configured = (string) $this->params->get('deprecatedTokens', '');

        $tokens = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $configured)));

        return $tokens ?: self::DEPRECATED_TOKENS;
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
