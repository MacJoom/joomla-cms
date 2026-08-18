/**
 * TableColumns class for toggle visibility of <table> columns.
 */
class TableColumns {
  constructor($table, tableName) {
    this.$table = $table;
    this.tableName = tableName;
    this.storageKey = `joomla-tablecolumns-${this.tableName}`;

    this.$headers = Array.from($table.querySelector('thead tr').children);
    this.$rows = $table.querySelectorAll('tbody tr');
    this.listOfHidden = [];

    // Load previous state
    this.loadState();

    // Find protected columns
    this.protectedCols = [0];
    if (this.$rows[0]) {
      Array.from(this.$rows[0].children).forEach(($el, index) => {
        if ($el.nodeName === 'TH') {
          this.protectedCols.push(index);

          // Make sure it's not in the list of hidden
          const ih = this.listOfHidden.indexOf(index);
          if (ih !== -1) {
            this.listOfHidden.splice(ih, 1);
          }
        }
      });
    }

    // Set up toggle menu
    this.createControls();

    // Restore state
    this.listOfHidden.forEach((index) => {
      this.toggleColumn(index, true);
    });
  }

  /**
   * Parse a comma-separated string of column indices into an array of integers.
   *
   * @param {String} str
   * @returns {Number[]}
   */
  parseIndices(str) {
    return str.split(',').map((val) => parseInt(val, 10)).filter((val) => !isNaN(val));
  }

  /**
   * Create a controls to select visible columns
   */
  createControls() {
    const $divouter = document.createElement('div');
    $divouter.setAttribute('class', 'dropdown float-end pb-2');

    const $divinner = document.createElement('div');
    $divinner.setAttribute('class', 'dropdown-menu dropdown-menu-end');
    $divinner.setAttribute('data-bs-popper', 'static');

    // Create a toggle button
    const $button = document.createElement('button');
    $button.type = 'button';
    $button.textContent = Joomla.Text._('JGLOBAL_COLUMNS');
    $button.classList.add('btn', 'btn-primary', 'btn-sm', 'dropdown-toggle');
    $button.setAttribute('data-bs-toggle', 'dropdown');
    $button.setAttribute('data-bs-auto-close', 'false');
    $button.setAttribute('aria-haspopup', 'true');
    $button.setAttribute('aria-expanded', 'false');

    const $ul = document.createElement('ul');
    $ul.setAttribute('class', 'list-unstyled p-2 text-nowrap mb-0');
    $ul.setAttribute('id', 'columnList');

    // Collect a list of headers for dropdown
    this.$headers.forEach(($el, index) => {
      // Skip the first column, unless it's a th, as we don't want to display the checkboxes
      if (index === 0 && $el.nodeName !== 'TH') return;

      const $li = document.createElement('li');
      const $label = document.createElement('label');
      const $input = document.createElement('input');
      $input.classList.add('form-check-input', 'me-1');
      $input.type = 'checkbox';
      $input.name = 'table[column][]';
      $input.checked = !this.listOfHidden.includes(index);
      $input.disabled = this.protectedCols.includes(index);
      $input.value = index;

      // Find the header name
      let $titleEl = $el.querySelector('span');
      let title = $titleEl ? $titleEl.textContent.trim() : '';

      if (!title) {
        $titleEl = $el.querySelector('span.visually-hidden') || $el;
        title = $titleEl.textContent.trim();
      }

      if (title.includes(':')) {
        title = title.split(':', 2)[1].trim();
      }

      $label.textContent = title;
      $label.insertAdjacentElement('afterbegin', $input);
      $li.appendChild($label);
      $ul.appendChild($li);
    });

    // Add "Save hidden columns" button at the bottom of the dropdown (admin only)
    if (Joomla.getOptions('table.columns.sync', false)) {
      const $saveLi = document.createElement('li');
      $saveLi.classList.add('pt-2', 'mt-1', 'border-top');

      const $saveButton = document.createElement('button');
      $saveButton.type = 'button';
      $saveButton.textContent = Joomla.Text._('JGLOBAL_COLUMNS_SAVE_HIDDEN');
      $saveButton.classList.add('btn', 'btn-secondary', 'btn-sm', 'w-100');
      $saveButton.addEventListener('click', () => {
        this.syncToServer();
        bootstrap.Dropdown.getInstance($button)?.hide();
      });

      $saveLi.appendChild($saveButton);
      $ul.appendChild($saveLi);
    }

    this.$table.insertAdjacentElement('beforebegin', $divouter);
    $divouter.appendChild($button);
    $divouter.appendChild($divinner);
    $divinner.appendChild($ul);

    // Listen to checkboxes change
    $ul.addEventListener('change', (event) => {
      this.toggleColumn(parseInt(event.target.value, 10));
      this.saveState();
    });

    // Remove "media query" classes, which may prevent toggling from working.
    this.$headers.forEach(($el) => {
      $el.classList.remove('d-none', 'd-xs-table-cell', 'd-sm-table-cell', 'd-md-table-cell', 'd-lg-table-cell', 'd-xl-table-cell', 'd-xxl-table-cell');
    });
    this.$rows.forEach(($row) => {
      Array.from($row.children).forEach(($el) => {
        $el.classList.remove('d-none', 'd-xs-table-cell', 'd-sm-table-cell', 'd-md-table-cell', 'd-lg-table-cell', 'd-xl-table-cell', 'd-xxl-table-cell');
      });
    });

    this.$button = $button;
    this.$menu = $ul;
    this.updateCounter();
  }

  /**
   * Update button text
   */
  updateCounter() {
    // Don't count the checkboxes column in the total
    const total = this.$headers.length - 1;
    const visible = total - this.listOfHidden.length;

    this.$button.textContent = `${visible}/${total} ${Joomla.Text._('JGLOBAL_COLUMNS')}`;
  }

  /**
   * Toggle column visibility
   *
   * @param {Number} index  The column index
   * @param {Boolean} force To force hide
   */
  toggleColumn(index, force) {
    // Skip incorrect index
    if (!this.$headers[index]) return;

    // Skip the protected columns
    if (this.protectedCols.includes(index)) return;

    const i = this.listOfHidden.indexOf(index);

    if (i === -1) {
      this.listOfHidden.push(index);
    } else if (force !== true) {
      this.listOfHidden.splice(i, 1);
    }

    this.$headers[index].classList.toggle('d-none', force);

    this.$rows.forEach(($col) => {
      $col.children[index].classList.toggle('d-none', force);
    });

    this.updateCounter();
  }

  /**
   * Save state to localStorage and mark as dirty (unsaved changes pending).
   * The dirty flag stores the current session token so it becomes stale on login.
   */
  saveState() {
    const value = this.listOfHidden.join(',');
    window.localStorage.setItem(this.storageKey, value);
    window.localStorage.setItem(`${this.storageKey}-dirty`, Joomla.getOptions('csrf.token', '1'));
  }

  /**
   * Sync current hidden columns to the server (fire-and-forget).
   * Only called explicitly via the "Save hidden columns" button.
   * Clears the dirty flag so other browsers pick up the new server state.
   */
  syncToServer() {
    const token = Joomla.getOptions('csrf.token', '');
    if (!token) return;

    const value = this.listOfHidden.join(',');
    const body = new URLSearchParams({
      [token]: '1',
      tableName: this.tableName,
      hidden: value,
    });
    fetch(
      'index.php?option=com_ajax&plugin=usercolumns&group=system&format=json',
      { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }
    ).then(() => {
      window.localStorage.setItem(this.storageKey, value);
      window.localStorage.removeItem(`${this.storageKey}-dirty`);
    }).catch(() => {});
  }

  /**
   * Load state, list of hidden columns.
   * If there are unsaved local changes (dirty flag set), localStorage wins.
   * Otherwise server state is authoritative so that saves from other browsers
   * are picked up correctly.
   */
  loadState() {
    const serverState = Joomla.getOptions('table.columns.state', {});
    const currentToken = Joomla.getOptions('csrf.token', '');
    const dirtyToken = window.localStorage.getItem(`${this.storageKey}-dirty`);
    const dirty = dirtyToken !== null && dirtyToken === currentToken;

    // Use localStorage only when the user has unsaved local changes
    if (dirty) {
      const stored = window.localStorage.getItem(this.storageKey);
      if (stored !== null) {
        this.listOfHidden = this.parseIndices(stored);
        return;
      }
    }

    // Server state is authoritative (explicitly saved, possibly from another browser)
    if (serverState[this.tableName] !== undefined) {
      const value = serverState[this.tableName];
      this.listOfHidden = this.parseIndices(value);
      window.localStorage.setItem(this.storageKey, value);
      window.localStorage.removeItem(`${this.storageKey}-dirty`);
      return;
    }

    // Fall back to localStorage (guests / no server state yet)
    const stored = window.localStorage.getItem(this.storageKey);
    if (stored) {
      this.listOfHidden = this.parseIndices(stored);
    }
  }
}

if (window.innerWidth > 992) {
  // Look for dataset name else page-title
  [...document.querySelectorAll('table:not(.columns-order-ignore)')].forEach(($table) => {
    const tableName = ($table.dataset.name ? $table.dataset.name : document.querySelector('.page-title')
      .textContent.trim()
      .replace(/[^a-z0-9]/gi, '-')
      .toLowerCase()
    );

    // Skip unnamed table
    if (!tableName) {
      return;
    }

    new TableColumns($table, tableName);
  });
}
