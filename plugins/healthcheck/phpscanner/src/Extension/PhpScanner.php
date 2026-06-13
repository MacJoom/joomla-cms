<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.PhpScanner
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\PhpScanner\Extension;

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

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Plugin to scan stored content for PHP code artifacts (e.g. "<?php", "<?=").
 *
 * Stored content such as articles or custom modules should never contain executable
 * PHP. The presence of PHP opening tags is a strong indicator of left-over code or a
 * code-injection attempt and should be reviewed.
 *
 * @since    __DEPLOY_VERSION__
 */
final class PhpScanner extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * The PHP artifact patterns to look for. These are matched as literal LIKE
     * fragments ("%" and "_" are the only LIKE wildcards, neither appears here).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const PHP_PATTERNS = ['%<?php%', '%<?=%'];

    /**
     * The Sourcerer (Regular Labs) code-tag patterns to look for. Sourcerer executes
     * PHP/JS embedded in content via "{source}...{/source}" tags, so their presence in
     * stored content is a code-execution surface worth inventorying.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const SOURCERER_PATTERNS = ['%{source%'];

    /**
     * Legacy Joomla API tokens treated as "deprecated code". These are the removed/legacy
     * "J*" classes and loaders from Joomla 3 and earlier. Matched as literal substrings
     * (in files) and as "%token%" LIKE fragments (in content).
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
     * Code fragments that indicate an extension is *redefining* outdated Joomla classes or
     * functions (a backward-compatibility shim). Matched as literal substrings in PHP files.
     * These are deliberately high-signal: "class_alias(" alone is too common in bundled
     * libraries, whereas these are Joomla-specific compat patterns.
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
     * Regular expressions (without delimiters) that match guarded compatibility shims /
     * polyfills, i.e. a definition wrapped in a negated existence check such as
     * "if (!function_exists('x')) { function x() {} }". Whitespace tolerant.
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

        $checks['articles']            = $this->getArticlesWithPhp();
        $checks['modules']             = $this->getModulesWithPhp();
        $checks['sourcerer']           = $this->getSourcererTags();
        $checks['deprecatedcontent']   = $this->getDeprecatedInContent();
        $checks['deprecatedoverrides'] = $this->getDeprecatedInOverrides();
        $checks['redefinitions']       = $this->getExtensionsRedefiningClasses();
        $checks['shims']               = $this->getCompatibilityShims();

        // Add the buttons to the result array
        $result = $event->getArgument('result', []);

        $checkResults = [];
        foreach ($checks as $key => $check) {
            if (isset($check['error'])) {
                continue;
            }
            $checkResults[] = [
                'link'   => $check['link'],
                'icon'   => $check['icon'] ?? 'fas fa-file-code',
                'amount' => $check['result'],
                'text'   => $check['text'],
                'id'     => 'plg_healthcheck_phpscanner_' . strtolower($key),
                'status' => ($check['result'] > 0) ? ($check['statusOnFound'] ?? 'critical') : 'success',
            ];
        }

        $result[] = $checkResults;

        $event->setArgument('result', $result);
    }

    /**
     * Returns the number of articles whose intro or full text contains PHP artifacts.
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getArticlesWithPhp(): array
    {
        $item = [];

        if ($this->params->get('scanArticles', '1') == '1') {
            try {
                $item['text'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_ARTICLES_LISTTEXT');
                $item['note'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_ARTICLES_LISTNOTE');
                $item['link'] = Uri::base() . 'index.php?option=com_content&view=articles';

                $item['result'] = $this->countArtifacts('#__content', ['introtext', 'fulltext'], self::PHP_PATTERNS);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETARTICLES_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of modules whose content contains PHP artifacts.
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getModulesWithPhp(): array
    {
        $item = [];

        if ($this->params->get('scanModules', '1') == '1') {
            try {
                $item['text'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_MODULES_LISTTEXT');
                $item['note'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_MODULES_LISTNOTE');
                $item['link'] = Uri::base() . 'index.php?option=com_modules&view=modules';

                $item['result'] = $this->countArtifacts('#__modules', ['content'], self::PHP_PATTERNS);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETMODULES_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of articles and modules whose content contains Sourcerer code tags.
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getSourcererTags(): array
    {
        $item = [];

        if ($this->params->get('scanSourcerer', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-wand-magic-sparkles';
                $item['statusOnFound'] = 'warning';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_SOURCERER_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_SOURCERER_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_content&view=articles';

                $item['result'] = $this->countArtifacts('#__content', ['introtext', 'fulltext'], self::SOURCERER_PATTERNS)
                    + $this->countArtifacts('#__modules', ['content'], self::SOURCERER_PATTERNS);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETSOURCERER_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
    }

    /**
     * Returns the number of articles and modules whose embedded code uses deprecated Joomla APIs.
     *
     * @return  array  Array containing result count, link, text/note labels, or an error key.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getDeprecatedInContent(): array
    {
        $item = [];

        if ($this->params->get('scanDeprecatedContent', '1') == '1') {
            try {
                $item['icon']          = 'fas fa-clock-rotate-left';
                $item['statusOnFound'] = 'warning';
                $item['text']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_DEPRECONTENT_LISTTEXT');
                $item['note']          = Text::_('PLG_HEALTHCHECK_PHPSCANNER_DEPRECONTENT_LISTNOTE');
                $item['link']          = Uri::base() . 'index.php?option=com_content&view=articles';

                $patterns = array_map(static fn(string $token): string => '%' . $token . '%', $this->getDeprecatedTokens());

                $item['result'] = $this->countArtifacts('#__content', ['introtext', 'fulltext'], $patterns)
                    + $this->countArtifacts('#__modules', ['content'], $patterns);
            } catch (\Exception $e) {
                $this->handleErrorMsg(Text::_('PLG_HEALTHCHECK_PHPSCANNER_GETDEPRECONTENT_ERROR') . ' / ' . $e->getMessage(), 'ERROR');
                $item['error'] = $e->getMessage();
            }
        } else {
            $item['error'] = Text::_('PLG_HEALTHCHECK_PHPSCANNER_CHECKISDEACTIVATED');
        }

        return $item;
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

                $item['result'] = $this->countDeprecatedOverrides();
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
     * classes or functions (backward-compatibility shims).
     *
     * Core extensions are excluded (they are protected or locked); the legitimate core
     * compatibility plugin therefore never counts.
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

                $item['result'] = $this->countNonCoreExtensions(
                    fn(string $dir): bool => $this->dirHasRedefinition($dir, $patterns)
                );
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
     * Returns the number of installed third-party extensions that ship guarded compatibility
     * shims / polyfills (definitions wrapped in negated function_exists/class_exists/etc. checks).
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

                $item['result'] = $this->countNonCoreExtensions(
                    fn(string $dir): bool => $this->dirHasRegexMatch($dir, self::SHIM_PATTERNS)
                );
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
     * Counts the non-core extensions for which the given file test matches at least one of
     * their base directories. The plugin's own directory is always skipped.
     *
     * @param   callable  $dirTest  A callback receiving a directory and returning a boolean.
     *
     * @return  integer
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function countNonCoreExtensions(callable $dirTest): int
    {
        // This plugin's own directory contains the pattern strings, so never scan it.
        $ownDir = realpath(\dirname(__DIR__, 2)) ?: '';
        $count  = 0;

        foreach ($this->getNonCoreExtensionDirs() as $dirs) {
            foreach ($dirs as $dir) {
                if ($ownDir !== '' && realpath($dir) === $ownDir) {
                    continue;
                }

                if ($dirTest($dir)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Builds a map of installed non-core extensions to their on-disk base directories.
     *
     * Only third-party extensions (not protected and not locked) are returned, so core
     * extensions - including the backward-compatibility plugin - are never scanned.
     *
     * @return  array  Map of extension key => list of existing base directories.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getNonCoreExtensionDirs(): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['type', 'element', 'folder', 'client_id']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('protected') . ' = 0')
            ->where($db->quoteName('locked') . ' = 0')
            ->where($db->quoteName('state') . ' = 0')
            ->whereIn($db->quoteName('type'), ['component', 'plugin', 'module', 'library', 'template'], ParameterType::STRING);

        $db->setQuery($query);

        $map = [];

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

            $key       = $row->type . ':' . $row->folder . ':' . $row->element;
            $map[$key] = array_values(array_filter($dirs, 'is_dir'));
        }

        return $map;
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
     * Returns the list of deprecated API tokens to look for.
     *
     * Uses the tokens configured in the plugin options (one per line or comma separated);
     * when the option is empty it falls back to the built-in {@see self::DEPRECATED_TOKENS}.
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
     * Counts the template override files (site and administrator) that contain a deprecated Joomla API token.
     *
     * Only the "html" override folders of installed templates are scanned, keeping the
     * filesystem traversal bounded.
     *
     * @return  integer  The number of override files containing deprecated tokens.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function countDeprecatedOverrides(): int
    {
        $count  = 0;
        $tokens = $this->getDeprecatedTokens();

        foreach ([JPATH_SITE . '/templates', JPATH_ADMINISTRATOR . '/templates'] as $templatesPath) {
            if (!is_dir($templatesPath)) {
                continue;
            }

            foreach (Folder::folders($templatesPath) as $template) {
                $htmlPath = $templatesPath . '/' . $template . '/html';

                if (!is_dir($htmlPath)) {
                    continue;
                }

                $files = Folder::files($htmlPath, '\.php$', true, true);

                foreach ($files as $file) {
                    $contents = file_get_contents($file);

                    if ($contents === false) {
                        continue;
                    }

                    foreach ($tokens as $token) {
                        if (str_contains($contents, $token)) {
                            $count++;
                            break;
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Counts the rows of a table where any of the given columns contains one of the patterns.
     *
     * @param   string    $table     The (prefixed) table name, e.g. "#__content".
     * @param   string[]  $columns   The text columns to scan.
     * @param   string[]  $patterns  The LIKE patterns to match (e.g. self::PHP_PATTERNS).
     *
     * @return  integer  The number of matching rows.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function countArtifacts(string $table, array $columns, array $patterns): int
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('COUNT(*) AS ' . $db->quoteName('number'))
            ->from($db->quoteName($table));

        // Keep the bound values alive in this array so the by-reference binding stays valid.
        $values     = [];
        $conditions = [];
        $i          = 0;

        foreach ($columns as $column) {
            foreach ($patterns as $pattern) {
                $key          = ':artifact' . $i;
                $values[$i]   = $pattern;
                $conditions[] = $db->quoteName($column) . ' LIKE ' . $key;

                $query->bind($key, $values[$i], ParameterType::STRING);

                $i++;
            }
        }

        $query->where('(' . implode(' OR ', $conditions) . ')');

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
