/**
 * @package     Joomla.Administrator
 * @subpackage  mod_healthcheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Fills any Health Check quick-icon that carries a data-url by fetching its value from
 * com_ajax. When the response includes an item list, clicking the icon toggles that list
 * instead of navigating, so each matched item can be opened directly.
 *
 * The data-url attribute is kept on the element so the check can be run again. A refresh is
 * requested by dispatching the "joomla:healthcheck-refresh" event on the document.
 */
((Joomla, document) => {
  'use strict';

  const STATUS_CLASS = {
    success: 'success', warning: 'warning', error: 'danger', critical: 'danger', info: 'info',
  };
  const STATUS_FILTER = {
    success: 'healthy', warning: 'warning', error: 'critical', critical: 'critical', info: 'healthy',
  };
  const ALL_STATUS_CLASSES = ['info', 'success', 'warning', 'danger'];

  const translate = (key, fallback) => {
    if (Joomla && Joomla.Text && typeof Joomla.Text._ === 'function') {
      const value = Joomla.Text._(key);

      if (value && value !== key) {
        return value;
      }
    }

    return fallback;
  };

  let codeModal = null;
  let lastFocused = null;

  /**
   * Lazily create the single reusable dialog which shows an item's affected source code. Checks
   * such as the PHP scanner's redefinition and shim findings deliver a "code" field instead of a
   * link, because there is no edit screen to send the user to.
   */
  const ensureCodeModal = () => {
    if (codeModal) {
      return codeModal;
    }

    const overlay = document.createElement('div');
    overlay.className = 'healthcheck-code-modal';
    overlay.hidden = true;

    const backdrop = document.createElement('div');
    backdrop.className = 'healthcheck-code-modal-backdrop';

    const dialog = document.createElement('div');
    dialog.className = 'healthcheck-code-modal-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'healthcheck-code-modal-title');

    const header = document.createElement('div');
    header.className = 'healthcheck-code-modal-header';

    const heading = document.createElement('h3');
    heading.className = 'healthcheck-code-modal-title';
    heading.id = 'healthcheck-code-modal-title';

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn-close';
    closeButton.setAttribute('aria-label', translate('JCLOSE', 'Close'));

    const body = document.createElement('pre');
    body.className = 'healthcheck-code-modal-body';
    body.appendChild(document.createElement('code'));

    header.appendChild(heading);
    header.appendChild(closeButton);
    dialog.appendChild(header);
    dialog.appendChild(body);
    overlay.appendChild(backdrop);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    const hide = () => {
      overlay.hidden = true;

      // Send the user back to the link they opened the dialog from.
      if (lastFocused && typeof lastFocused.focus === 'function') {
        lastFocused.focus();
        lastFocused = null;
      }
    };

    closeButton.addEventListener('click', hide);
    backdrop.addEventListener('click', hide);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !overlay.hidden) {
        hide();
      }
    });

    codeModal = overlay;

    return overlay;
  };

  // Populate and open the code dialog. Title and code are set via textContent, never injected.
  const showCodeModal = (title, code) => {
    const overlay = ensureCodeModal();

    lastFocused = document.activeElement;

    overlay.querySelector('.healthcheck-code-modal-title').textContent = title;
    overlay.querySelector('.healthcheck-code-modal-body code').textContent = code;
    overlay.hidden = false;
    overlay.querySelector('.btn-close').focus();
  };

  const buildItemList = (link, items) => {
    const container = link.closest('li') || link.parentNode;
    const list = document.createElement('ul');
    list.className = 'healthcheck-itemlist list-unstyled';
    list.hidden = true;

    items.forEach((item) => {
      const row = document.createElement('li');

      if (item.code) {
        // The item carries source code (e.g. redefinitions): open it in a dialog instead of a link.
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'healthcheck-code-trigger btn btn-link p-0 text-start';
        button.textContent = item.title;
        button.addEventListener('click', (event) => {
          event.preventDefault();
          showCodeModal(item.title, item.code);
        });
        row.appendChild(button);
      } else if (item.link) {
        const anchor = document.createElement('a');
        anchor.href = item.link;
        anchor.textContent = item.title;
        row.appendChild(anchor);
      } else {
        // No edit screen (e.g. malware files): render the path as plain, easy-to-copy text.
        const code = document.createElement('code');
        code.textContent = item.title;
        row.appendChild(code);
      }

      list.appendChild(row);
    });

    container.classList.add('has-itemlist');
    container.appendChild(list);

    // Open the list on click rather than following the icon's own link. While open, the icon spans
    // every dashboard column (via .is-expanded) so the list gets the full width.
    link.addEventListener('click', (event) => {
      event.preventDefault();
      const show = list.hidden;
      list.hidden = !show;
      container.classList.toggle('is-expanded', show);
    });
  };

  /**
   * Return the icon to its pre-fetch state so a refresh cannot stack list elements or classes.
   */
  const resetIcon = (amountEl, link) => {
    const container = link ? (link.closest('li') || link.parentNode) : null;

    if (container) {
      container.querySelectorAll('.healthcheck-itemlist').forEach((el) => el.remove());
      container.classList.remove('has-itemlist', 'is-expanded');
    }

    if (link) {
      ALL_STATUS_CLASSES.forEach((c) => link.classList.remove(c));
      link.classList.remove('pe-none');
    }

    amountEl.innerHTML = '<span class="icon-spinner" aria-hidden="true"></span>';
  };

  const fillIcon = async (amountEl) => {
    const url = amountEl.getAttribute('data-url');

    if (!url) {
      return;
    }

    // Guard against overlapping runs instead of dropping the URL, which would make the icon
    // unrefreshable for the rest of the page's life.
    if (amountEl.dataset.hcLoading === '1') {
      return;
    }

    amountEl.dataset.hcLoading = '1';

    let link = amountEl.closest('a');

    resetIcon(amountEl, link);

    // A rebuilt icon needs a fresh listener set, so re-resolve the anchor after the reset.
    link = amountEl.closest('a');

    try {
      const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await response.json();
      const payload = json && json.data ? json.data : null;
      const data = Array.isArray(payload) ? payload[0] : payload;

      if (!data || typeof data.amount === 'undefined') {
        amountEl.innerHTML = '';
        const zero = document.createElement('div');
        zero.textContent = '0';
        amountEl.appendChild(zero);
        return;
      }

      const amount = parseInt(data.amount, 10) || 0;
      const amountWrapper = document.createElement('div');
      amountWrapper.textContent = amount;
      amountEl.innerHTML = '';
      amountEl.appendChild(amountWrapper);

      const status = data.status || 'success';

      if (link) {
        link.classList.add(STATUS_CLASS[status] || 'info');

        if (amount > 0 && Array.isArray(data.items) && data.items.length) {
          buildItemList(link, data.items);
        } else if (amount === 0) {
          // Nothing to drill into — make the icon non-interactive.
          link.classList.add('pe-none');
        }
      }

      const wrapper = amountEl.closest('[data-healthcheck-status]');

      if (wrapper) {
        wrapper.setAttribute('data-healthcheck-status', STATUS_FILTER[status] || 'healthy');
      }
    } catch (e) {
      amountEl.innerHTML = '';
      const failed = document.createElement('div');
      failed.textContent = '!';
      amountEl.appendChild(failed);
    } finally {
      delete amountEl.dataset.hcLoading;
    }
  };

  const runAll = () => {
    document.querySelectorAll('.health-checks [data-url]').forEach(fillIcon);
  };

  const init = () => {
    runAll();

    // The refresh button asks for a genuine re-run of every asynchronous check.
    document.addEventListener('joomla:healthcheck-refresh', runAll);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window.Joomla, document);
