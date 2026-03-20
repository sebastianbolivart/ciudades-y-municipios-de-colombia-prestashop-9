/**
 * ps_colombia_address — checkout.js
 *
 * Adds the Colombian address hierarchy behaviour to the PrestaShop
 * address form in both the back-office and the front-office checkout.
 *
 * Responsibilities
 * ────────────────
 * 1. Detect when the user changes the State/Department dropdown.
 * 2. Request municipalities via AJAX from the module's front controller.
 * 3. Populate the municipality <select> element.
 * 4. Autofill the postal-code input from the selected municipality.
 * 5. Write the DANE code into a hidden input.
 * 6. Keep the native PrestaShop `city` field in sync so form
 *    validation and normal data-flow continue to work.
 * 7. Re-initialise after PrestaShop single-page checkout (AJAX) refreshes.
 *
 * Security
 * ────────
 * • The static shop token is sent with every request (set via Media::addJsDef).
 * • Output from the AJAX response is escaped before injection into the DOM.
 * • No eval, no innerHTML with raw server values.
 * • Content Security Policy: no inline event handlers; all listeners attached
 *   via addEventListener.
 *
 * Dependencies: vanilla JS (ES2017+). No jQuery required, but works
 * alongside it if present.
 *
 * Runtime config is injected by the module's hookDisplayHeader() into
 * window.colombiaAddressConfig:
 *   {
 *     baseUrl:            string   AJAX endpoint
 *     autofillPostal:     boolean
 *     logisticsMode:      boolean
 *     enableAutocomplete: boolean
 *     token:              string   PrestaShop static front token
 *   }
 */

'use strict';

(function () {
  // ── Guard: only run when both config and the DOM are ready ──────────────

  if (typeof window.colombiaAddressConfig === 'undefined') {
    return;
  }

  const CONFIG = window.colombiaAddressConfig;

  // ── Selectors ──────────────────────────────────────────────────────────

  /**
   * Return the address form's state/department <select>.
   * PrestaShop uses id_state in both the checkout and back-office forms.
   *
   * @returns {HTMLSelectElement|null}
   */
  function getDepartmentSelect() {
    return (
      document.querySelector('select[name="id_state"]') ||
      document.querySelector('select[name="address[id_state]"]') ||
      document.querySelector('#id_state') ||
      null
    );
  }

  /**
   * Return the Colombian municipality <select> added by the Symfony form modifier.
   *
   * @returns {HTMLSelectElement|null}
   */
  function getMunicipalitySelect() {
    return (
      document.querySelector('[data-colombia-municipality]') ||
      document.querySelector('select[name="colombia_municipality"]') ||
      document.querySelector('select[name="address[colombia_municipality]"]') ||
      null
    );
  }

  /**
   * Return the native city field.
   *
   * @returns {HTMLInputElement|null}
   */
  function getCityField() {
    return (
      document.querySelector('input[name="city"]') ||
      document.querySelector('input[name="address[city]"]') ||
      document.querySelector('#city') ||
      null
    );
  }

  /**
   * Return the postal-code input.
   *
   * @returns {HTMLInputElement|null}
   */
  function getPostalCodeField() {
    return (
      document.querySelector('input[name="postcode"]') ||
      document.querySelector('input[name="address[postcode]"]') ||
      document.querySelector('#postcode') ||
      null
    );
  }

  /**
   * Return the hidden DANE code input added by the form modifier.
   *
   * @returns {HTMLInputElement|null}
   */
  function getDaneCodeField() {
    return (
      document.querySelector('[data-colombia-dane-code]') ||
      document.querySelector('input[name="colombia_dane_code"]') ||
      document.querySelector('input[name="address[colombia_dane_code]"]') ||
      null
    );
  }

  // ── Utilities ──────────────────────────────────────────────────────────

  /**
   * Escape a string so it is safe to use as text content in the DOM.
   * Never use innerHTML with this — use textContent or createTextNode instead.
   *
   * @param {string} str
   * @returns {string}
   */
  function escapeHtml(str) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(str).replace(/[&<>"']/g, function (c) { return map[c]; });
  }

  /**
   * Create a single <option> element safely (no innerHTML).
   *
   * @param {string} value
   * @param {string} label
   * @param {boolean} [selected=false]
   * @returns {HTMLOptionElement}
   */
  function createOption(value, label, selected) {
    const opt = document.createElement('option');
    opt.value       = value;
    opt.textContent = label;        // textContent is XSS-safe
    if (selected) opt.selected = true;
    return opt;
  }

  // ── State ──────────────────────────────────────────────────────────────

  /** In-memory cache to avoid duplicate AJAX calls per page load. */
  const municipalitiesCache = Object.create(null);

  // ── Core logic ─────────────────────────────────────────────────────────

  /**
   * Fetch municipalities for a department and populate the select element.
   *
   * @param {string} departmentName  Human-readable department name (e.g. "Antioquia")
   * @param {string} [preselect=''] Municipality to pre-select after loading
   */
  function loadMunicipalities(departmentName, preselect) {
    if (!departmentName) return;

    preselect = preselect || '';

    const municipalitySelect = getMunicipalitySelect();
    if (!municipalitySelect) return;

    // Use cache if available.
    if (municipalitiesCache[departmentName]) {
      populateSelect(municipalitySelect, municipalitiesCache[departmentName], preselect);
      return;
    }

    // Show loading state.
    municipalitySelect.disabled = true;
    municipalitySelect.innerHTML = '';
    municipalitySelect.appendChild(createOption('', 'Cargando…'));

    // Build the AJAX URL, appending the security token.
    const url = new URL(CONFIG.baseUrl, window.location.href);
    url.searchParams.set('department', departmentName);
    url.searchParams.set('token', CONFIG.token);

    fetch(url.toString(), {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        if (!data || !Array.isArray(data.municipalities)) {
          throw new Error('Unexpected response format');
        }

        // Store in cache.
        municipalitiesCache[departmentName] = data.municipalities;

        populateSelect(municipalitySelect, data.municipalities, preselect);
        municipalitySelect.disabled = false;
      })
      .catch(function (err) {
        console.error('[ps_colombia_address] Failed to load municipalities:', err);
        municipalitySelect.innerHTML = '';
        municipalitySelect.appendChild(createOption('', '— Error al cargar —'));
        municipalitySelect.disabled = false;
      });
  }

  /**
   * Fill the municipality <select> with an array of municipality objects.
   *
   * @param {HTMLSelectElement} selectEl
   * @param {Array<{name:string,postal_code:string,dane_code:string,latitude:string,longitude:string}>} municipalities
   * @param {string} preselect
   */
  function populateSelect(selectEl, municipalities, preselect) {
    selectEl.innerHTML = '';
    selectEl.appendChild(createOption('', '— Seleccione un municipio —'));

    municipalities.forEach(function (m) {
      const opt = createOption(m.name, m.name, m.name === preselect);
      // Store extra data as data attributes on the option element.
      opt.dataset.postalCode = m.postal_code;
      opt.dataset.daneCode   = m.dane_code;
      opt.dataset.lat        = m.latitude;
      opt.dataset.lon        = m.longitude;
      selectEl.appendChild(opt);
    });

    // If preselect was set, trigger change to auto-fill fields.
    if (preselect) {
      onMunicipalityChange({ target: selectEl });
    }
  }

  /**
   * Handle municipality selection change.
   * Syncs city, postal_code, and DANE code fields.
   *
   * @param {Event} event
   */
  function onMunicipalityChange(event) {
    const select = event.target;
    const selected = select.options[select.selectedIndex];

    const cityField     = getCityField();
    const postalField   = getPostalCodeField();
    const daneCodeField = getDaneCodeField();

    // Sync city (always)
    if (cityField) {
      cityField.value = selected ? (selected.value || '') : '';
    }

    // Autofill postal code if enabled
    if (CONFIG.autofillPostal && postalField && selected) {
      const postal = selected.dataset.postalCode || '';
      if (postal) {
        postalField.value = postal;
      }
    }

    // Store DANE code if logistics mode is enabled
    if (CONFIG.logisticsMode && daneCodeField && selected) {
      daneCodeField.value = selected.dataset.daneCode || '';
    }
  }

  /**
   * Handle department/state selection change.
   * Derives the department name from the selected <option> text.
   *
   * @param {Event} event
   */
  function onDepartmentChange(event) {
    const select  = event.target;
    const selected = select.options[select.selectedIndex];
    const deptName = selected ? (selected.textContent || '').trim() : '';

    // Reset municipality and city / postal / DANE when department changes.
    const municipalitySelect = getMunicipalitySelect();
    const cityField          = getCityField();
    const postalField        = getPostalCodeField();
    const daneCodeField      = getDaneCodeField();

    if (municipalitySelect) {
      municipalitySelect.innerHTML = '';
      municipalitySelect.appendChild(createOption('', '— Seleccione un municipio —'));
    }
    if (cityField)     cityField.value     = '';
    if (daneCodeField) daneCodeField.value = '';
    // Do not clear postal code automatically; user may have typed it.

    if (deptName) {
      loadMunicipalities(deptName);
    }
  }

  // ── Autocomplete (optional) ────────────────────────────────────────────

  /**
   * Convert the municipality select into an autocomplete text input.
   * Only activated when window.colombiaAddressConfig.enableAutocomplete is true.
   */
  function initAutocomplete() {
    const municipalitySelect = getMunicipalitySelect();
    if (!municipalitySelect) return;

    // Replace the <select> with a text <input> + a hidden backing field.
    const textInput = document.createElement('input');
    textInput.type        = 'text';
    textInput.className   = municipalitySelect.className + ' colombia-autocomplete';
    textInput.placeholder = 'Buscar municipio…';
    textInput.autocomplete = 'off';
    textInput.setAttribute('aria-label', 'Municipio');

    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = municipalitySelect.name;

    municipalitySelect.parentNode.insertBefore(textInput, municipalitySelect);
    municipalitySelect.parentNode.insertBefore(hiddenInput, municipalitySelect);
    municipalitySelect.style.display = 'none';

    // Suggestion list
    const suggestionList = document.createElement('ul');
    suggestionList.className = 'colombia-autocomplete-suggestions';
    suggestionList.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #ccc;list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto;min-width:200px;';
    textInput.parentNode.style.position = 'relative';
    textInput.parentNode.insertBefore(suggestionList, textInput.nextSibling);

    function clearSuggestions() {
      suggestionList.innerHTML = '';
      suggestionList.style.display = 'none';
    }

    textInput.addEventListener('input', function () {
      const query   = textInput.value.trim().toLowerCase();
      const options = Array.from(municipalitySelect.options);

      clearSuggestions();
      if (!query) return;

      const matches = options.filter(function (o) {
        return o.value && o.textContent.toLowerCase().includes(query);
      });

      if (!matches.length) return;

      matches.slice(0, 10).forEach(function (opt) {
        const li = document.createElement('li');
        li.textContent = opt.textContent;  // safe: text only
        li.style.cssText = 'padding:6px 10px;cursor:pointer;';
        li.addEventListener('mousedown', function () {
          textInput.value    = opt.textContent.trim();
          hiddenInput.value  = opt.value;
          clearSuggestions();

          // Trigger the municipality change side-effects.
          municipalitySelect.value = opt.value;
          onMunicipalityChange({ target: municipalitySelect });
        });
        suggestionList.appendChild(li);
      });
      suggestionList.style.display = 'block';
    });

    document.addEventListener('click', function (e) {
      if (!textInput.contains(e.target) && !suggestionList.contains(e.target)) {
        clearSuggestions();
      }
    });
  }

  // ── Initialisation ─────────────────────────────────────────────────────

  /**
   * Attach all event listeners.
   * Safe to call multiple times (e.g. after checkout AJAX refresh).
   */
  function init() {
    const deptSelect = getDepartmentSelect();
    if (!deptSelect) return;

    // Avoid double-binding.
    if (deptSelect.dataset.colombiaInit === '1') return;
    deptSelect.dataset.colombiaInit = '1';

    deptSelect.addEventListener('change', onDepartmentChange);

    const municipalitySelect = getMunicipalitySelect();
    if (municipalitySelect) {
      municipalitySelect.addEventListener('change', onMunicipalityChange);
    }

    if (CONFIG.enableAutocomplete) {
      initAutocomplete();
    }

    // If a department is already selected (edit form), load its municipalities.
    const currentDept = deptSelect.options[deptSelect.selectedIndex];
    if (currentDept && currentDept.value) {
      const preselectedCity = (getCityField() || {}).value || '';
      loadMunicipalities(currentDept.textContent.trim(), preselectedCity);
    }
  }

  // ── Bootstrap ──────────────────────────────────────────────────────────

  // Standard DOM-ready + PrestaShop checkout AJAX re-init.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // PrestaShop fires this custom event after checkout sections are refreshed.
  document.addEventListener('updatedAddressForm', init);
  document.addEventListener('addressFormUpdated', init);

  // Also re-init when PrestaShop fires the generic prestashop:* events.
  document.addEventListener('prestashop:payment-updated', init);

  // Expose re-init for third-party modules / themes.
  window.ColombiaAddress = { init: init, loadMunicipalities: loadMunicipalities };
}());
