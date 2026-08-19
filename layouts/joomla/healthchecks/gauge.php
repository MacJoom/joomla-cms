<?php

/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HealthCheck\HealthStatus;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

Factory::getApplication()->getLanguage()->load('mod_healthcheck', JPATH_ADMINISTRATOR);

// Get gauge parameters with defaults
$id        = $displayData['id'] ?? '';
$label     = $displayData['label'] ?? '';
$sublabel  = $displayData['sublabel'] ?? '';
$note      = $displayData['note'] ?? '';
$link      = $displayData['link'] ?? '';
$linkTitle = $displayData['link_title'] ?? '';

// Auto-detect external links and set target to _blank
$linktarget = '';

if (!empty($link) && preg_match('/^https?:\/\//', $link)) {
    $currentDomain = Uri::getInstance()->getHost();
    $linkDomain    = parse_url($link, PHP_URL_HOST);

    // If different domain or no current domain info, treat as external
    if (empty($currentDomain) || $linkDomain !== $currentDomain) {
        $linktarget = '_blank';
    }
}

$score                   = (float) ($displayData['score'] ?? 0);
$unit                    = $displayData['unit'] ?? '%';
$score_min               = (float) ($displayData['score_min'] ?? 0);
$score_max               = (float) ($displayData['score_max'] ?? 100);
$score_threshold_error   = (float) ($displayData['score_threshold_error'] ?? 0);
$score_threshold_warning = (float) ($displayData['score_threshold_warning'] ?? 50);
$score_threshold_success = (float) ($displayData['score_threshold_success'] ?? 90);

/*
 * The helper resolves 'status' into a HealthStatus. When a plugin sends no status at all, derive it
 * from the score thresholds instead.
 */
if (($displayData['status'] ?? null) instanceof HealthStatus) {
    $filterStatus = $displayData['status']->getFilterBucket();
} elseif ($score < $score_threshold_warning) {
    $filterStatus = 'critical';
} elseif ($score < $score_threshold_success) {
    $filterStatus = 'warning';
} else {
    $filterStatus = 'healthy';
}

// Calculate percentage for the pie chart
$percentage = ($score_max > $score_min) ? (($score - $score_min) / ($score_max - $score_min)) * 100 : 0;
$percentage = max(0, min(100, $percentage)); // Clamp between 0-100

// Calculate SVG path for pie chart
$radius           = 45;
$circumference    = 2 * M_PI * $radius;
$strokeDasharray  = $circumference;
$strokeDashoffset = $circumference * (1 - $percentage / 100);

// SVG viewBox and center
$size   = 120;
$center = $size / 2;

$hasLink = !empty($link);

/*
 * The gauge is described once, in reading order, by the visible label plus a visually hidden
 * sentence. The SVG itself is decorative: everything it shows is repeated as text, so it is hidden
 * from assistive technology rather than carrying a competing title/desc of its own.
 */
if ($score >= $score_threshold_success) {
    $statusText = Text::_('MOD_HEALTHCHECK_GAUGE_STATUS_EXCELLENT');
} elseif ($score >= $score_threshold_warning) {
    $statusText = Text::_('MOD_HEALTHCHECK_GAUGE_STATUS_GOOD');
} else {
    $statusText = Text::_('MOD_HEALTHCHECK_GAUGE_STATUS_ATTENTION');
}
?>
<li class="healthcheck-gauge"<?php echo $id ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
    data-healthcheck-status="<?php echo htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8'); ?>"
    data-score="<?php echo htmlspecialchars((string) $score, ENT_QUOTES, 'UTF-8'); ?>"
    data-max="<?php echo htmlspecialchars((string) $score_max, ENT_QUOTES, 'UTF-8'); ?>"
    data-percentage="<?php echo number_format($percentage, 1); ?>">

    <?php if ($hasLink) : ?>
        <a href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>"
           class="gauge-link d-block text-decoration-none"
            <?php echo $linktarget ? ' target="' . htmlspecialchars($linktarget, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer"' : ''; ?>
            <?php echo $linkTitle ? ' title="' . htmlspecialchars($linkTitle, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
    <?php endif; ?>

    <div class="gauge-container text-center">
        <?php if (!empty($label)) : ?>
            <h4 class="gauge-label mb-1"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></h4>
        <?php endif; ?>

        <?php if (!empty($sublabel)) : ?>
            <p class="gauge-sublabel text-muted small mb-2"><?php echo htmlspecialchars($sublabel, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <div class="gauge-chart-container position-relative d-inline-block">

            <svg width="<?php echo $size; ?>"
                 height="<?php echo $size; ?>"
                 viewBox="0 0 <?php echo $size; ?> <?php echo $size; ?>"
                 class="gauge-svg"
                 aria-hidden="true"
                 focusable="false">

                <!-- Background circle -->
                <circle
                    cx="<?php echo $center; ?>"
                    cy="<?php echo $center; ?>"
                    r="<?php echo $radius; ?>"
                    class="gauge-track-circle"
                    fill="none"
                    stroke-width="8"
                />

                <!-- Progress circle -->
                <circle
                    cx="<?php echo $center; ?>"
                    cy="<?php echo $center; ?>"
                    r="<?php echo $radius; ?>"
                    class="gauge-progress-circle"
                    fill="none"
                    stroke-width="8"
                    stroke-linecap="round"
                    stroke-dasharray="<?php echo $strokeDasharray; ?>"
                    stroke-dashoffset="<?php echo $strokeDashoffset; ?>"
                    transform="rotate(-90 <?php echo $center; ?> <?php echo $center; ?>)"
                />

                <!-- Score text in center -->
                <text
                    x="<?php echo $center; ?>"
                    y="<?php echo $center - 5; ?>"
                    text-anchor="middle"
                    dominant-baseline="middle"
                    class="gauge-score-text"
                >
                    <?php echo htmlspecialchars((string) $score, ENT_QUOTES, 'UTF-8'); ?>
                </text>

                <!-- Unit text -->
                <text
                    x="<?php echo $center; ?>"
                    y="<?php echo $center + 15; ?>"
                    text-anchor="middle"
                    dominant-baseline="middle"
                    class="gauge-unit-text"
                >
                    <?php echo htmlspecialchars($unit, ENT_QUOTES, 'UTF-8'); ?>
                </text>
            </svg>

            <!-- The single text alternative for the decorative chart above -->
            <span class="visually-hidden">
                <?php echo htmlspecialchars(Text::sprintf('MOD_HEALTHCHECK_GAUGE_SR_SCORE', $score, $unit, $score_max), ENT_QUOTES, 'UTF-8'); ?>
                <?php echo htmlspecialchars(Text::sprintf('MOD_HEALTHCHECK_GAUGE_SR_RANGE', number_format($percentage, 1), $score_min, $score_max), ENT_QUOTES, 'UTF-8'); ?>
                <?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?>
            </span>

            <!-- Percentage indicator; duplicates the hidden sentence above for sighted users -->
            <div class="gauge-percentage small text-muted mt-1" aria-hidden="true">
                <?php echo htmlspecialchars(Text::sprintf('MOD_HEALTHCHECK_GAUGE_PERCENT_OF_RANGE', number_format($percentage, 2)), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

        <?php if (!empty($note)) : ?>
            <p class="gauge-note small mt-2"><?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if (JDEBUG) : ?>
            <div class="gauge-debug small mt-2">
                <?php echo htmlspecialchars(Text::sprintf('MOD_HEALTHCHECK_GAUGE_DEBUG_RANGE', $score_min, $score_max), ENT_QUOTES, 'UTF-8'); ?> |
                <?php echo htmlspecialchars(Text::sprintf('MOD_HEALTHCHECK_GAUGE_DEBUG_THRESHOLDS', $score_threshold_error, $score_threshold_warning, $score_threshold_success), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($hasLink) : ?>
        </a>
    <?php endif; ?>
</li>
