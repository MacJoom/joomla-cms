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
