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

        $checks['articles']  = $this->getArticlesWithPhp();
        $checks['modules']   = $this->getModulesWithPhp();
        $checks['sourcerer'] = $this->getSourcererTags();

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
