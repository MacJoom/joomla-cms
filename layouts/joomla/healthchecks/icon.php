<?php

/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HealthCheck\HealthStatus;
use Joomla\CMS\Language\Text;

/*
 * Everything below is escaped by default. A plugin's values are trusted PHP, but the values
 * themselves routinely originate from database content or third party responses, so the display
 * layer never assumes they are safe. A plugin which really needs markup in the text opts in
 * explicitly with 'text_html' => true.
 */
$id      = empty($displayData['id']) ? '' : (' id="' . $this->escape($displayData['id']) . '"');
$target  = empty($displayData['target']) ? '' : (' target="' . $this->escape($displayData['target']) . '"');
$onclick = empty($displayData['onclick']) ? '' : (' data-onclick="' . $this->escape($displayData['onclick']) . '"');
$dataUrl = empty($displayData['ajaxurl']) ? '' : (' data-url="' . $this->escape($displayData['ajaxurl']) . '"');
$link    = empty($displayData['link']) ? '' : $this->escape($displayData['link']);

// The title for the link (a11y)
$title = empty($displayData['title']) ? '' : (' title="' . $this->escape($displayData['title']) . '"');

// The information
if (empty($displayData['text'])) {
    $text = '';
} elseif (!empty($displayData['text_html'])) {
    $text = '<span class="j-links-link">' . $displayData['text'] . '</span>';
} else {
    $text = '<span class="j-links-link">' . $this->escape($displayData['text']) . '</span>';
}

/*
 * The helper resolves 'status' into a HealthStatus, which owns both the contextual class and the
 * filter bucket, so this layout no longer maps status strings itself.
 */
$status       = $displayData['status'] ?? null;
$status       = $status instanceof HealthStatus ? $status : HealthStatus::fromLoose($status);
$class        = $status->getCssClass();
$filterStatus = $status->getFilterBucket();

$class .= empty($displayData['class']) ? '' : (' ' . $this->escape($displayData['class']));
?>
<?php // If it is a button with two links: make it a list
if (isset($displayData['linkadd'])) : ?>
    <li class="quickicon-group" data-healthcheck-status="<?php echo $filterStatus; ?>">
    <ul class="list-unstyled d-flex w-100">
        <li class="quickicon">
<?php else : ?>
    <li class="quickicon quickicon-single" data-healthcheck-status="<?php echo $filterStatus; ?>">
<?php endif; ?>
        <a<?php echo $id; ?> class="<?php echo $class; ?>" href="<?php echo $link; ?>"<?php echo $target . $onclick . $title; ?>>
            <div class="quickicon-info">
                <div class="quickicon-icon">
                <?php if (isset($displayData['image'])) : ?>
                    <div><img src="<?php echo $this->escape($displayData['image']); ?>" width="50" height="50" alt="<?php echo empty($displayData['title']) ? '' : $this->escape($displayData['title']); ?>" /></div>
                <?php elseif (isset($displayData['icon'])) : ?>
                    <div class="<?php echo $this->escape($displayData['icon']); ?>" aria-hidden="true"></div>
                <?php endif; ?>
                </div>
                <?php if (!empty($displayData['ajaxurl'])) : ?>
                    <div class="quickicon-amount"<?php echo $dataUrl; ?> aria-hidden="true">
                        <span class="icon-spinner" aria-hidden="true"></span>
                    </div>
                    <div class="quickicon-sr-desc visually-hidden"></div>
                <?php endif; ?>
                <?php if (isset($displayData['amount'])) : ?>
                    <?php if (isset($displayData['image']) || isset($displayData['icon'])) : ?>
                        <div class="quickicon-amount">
                            <div><?php echo $this->escape((string) $displayData['amount']); ?></div>
                        </div>
                    <?php else : ?>
                        <div class="quickicon-noicon">
                            <div><?php echo $this->escape((string) $displayData['amount']); ?></div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php // Name indicates the component
            if (isset($displayData['name'])) : ?>
                <div class="quickicon-name d-flex align-items-end"<?php echo !empty($displayData['ajaxurl']) ? ' aria-hidden="true"' : ''; ?>>
                    <?php echo $this->escape(Text::_($displayData['name'])); ?>
                </div>
            <?php endif; ?>
            <?php // Information or action from plugins
            if (isset($displayData['text'])) : ?>
                <div class="quickicon-name d-flex align-items-center">
                    <?php echo $text; ?>
                </div>
            <?php endif; ?>
        </a>
    </li>
    <?php // Add the link to the edit-form
    if (isset($displayData['linkadd'])) : ?>
        <li class="quickicon-linkadd j-links-link d-flex">
            <a class="d-flex" href="<?php echo $this->escape($displayData['linkadd']); ?>" title="<?php echo $this->escape(Text::_($displayData['name'] . '_ADD')); ?>">
                <span class="icon-plus" aria-hidden="true"></span>
            </a>
        </li>
    </ul>
    </li>
    <?php endif; ?>
