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
      document.querySelector('[data-colombia-department]') ||
      document.querySelector('select[name="id_state"]') ||
      document.querySelector('select[name="address[id_state]"]') ||
      document.querySelector('#id_state') ||
      null
    );
  }

  function getNativeDepartmentSelect() {
    return (
      document.querySelector('select[name="id_state"]') ||
      document.querySelector('select[name="address[id_state]"]') ||
      document.querySelector('#id_state') ||
      null
    );
  }

  function getCountrySelect() {
    return (
      document.querySelector('select[name="id_country"]') ||
      document.querySelector('select[name="address[id_country]"]') ||
      document.querySelector('#id_country') ||
      null
    );
  }

  function getSelectedOptionText(select) {
    if (!select || !select.options || select.selectedIndex < 0) return '';
    const selected = select.options[select.selectedIndex];
    if (!selected) return '';
    return String(selected.textContent || '').trim();
  }

  function getInitialDepartmentName() {
    const dynamicSelect = getDynamicDepartmentSelect();
    if (dynamicSelect && dynamicSelect.value) {
      return String(dynamicSelect.value || '').trim();
    }

    const nativeSelect = getNativeDepartmentSelect();
    if (!nativeSelect) return '';
    if (!nativeSelect.value) return '';

    return getSelectedOptionText(nativeSelect);
  }

  function isColombiaSelected() {
    const countrySelect = getCountrySelect();
    if (!countrySelect) return false;

    const selected = countrySelect.options[countrySelect.selectedIndex];
    const code = (selected && selected.dataset && selected.dataset.isoCode) ? String(selected.dataset.isoCode).toUpperCase() : '';
    const text = selected ? String(selected.textContent || '').trim().toLowerCase() : '';
    const value = countrySelect.value;

    return code === 'CO' || value === '69' || text === 'colombia';
  }

  function ensureDepartmentSelect() {
    let select = getDynamicDepartmentSelect();
    if (select) return select;

    const cityField = getCityField();
    if (!cityField) return null;

    const cityContainer = cityField.closest('.form-group') || cityField.parentNode;
    if (!cityContainer || !cityContainer.parentNode) return null;

    const wrapper = document.createElement('div');
    wrapper.className = 'form-group row colombia-department-group';

    const labelCol = document.createElement('label');
    labelCol.className = 'col-md-3 form-control-label required';
    labelCol.setAttribute('for', 'colombia_department');
    labelCol.textContent = 'Departamento';

    const inputCol = document.createElement('div');
    inputCol.className = 'col-md-6 js-input-column';

    select = document.createElement('select');
    select.id = 'colombia_department';
    select.name = 'colombia_department';
    select.className = 'form-control form-control-select';
    select.setAttribute('data-colombia-department', '1');
    select.appendChild(createOption('', '— Seleccione un departamento —'));

    inputCol.appendChild(select);
    wrapper.appendChild(labelCol);
    wrapper.appendChild(inputCol);

    cityContainer.parentNode.insertBefore(wrapper, cityContainer);

    return select;
  }

  function getDynamicDepartmentSelect() {
    return document.querySelector('[data-colombia-department]');
  }

  function getDynamicDepartmentGroup() {
    return document.querySelector('.colombia-department-group');
  }

  function getDynamicMunicipalityGroup() {
    return document.querySelector('.colombia-municipality-group');
  }

  function getCityMunicipalitySelect() {
    return document.querySelector('select[data-colombia-city-select]');
  }

  function setColombiaUiVisible(visible) {
    const deptGroup = getDynamicDepartmentGroup();
    const muniGroup = getDynamicMunicipalityGroup();
    const cityField = getCityField();
    const citySelect = getCityMunicipalitySelect();

    if (deptGroup) {
      deptGroup.style.display = visible ? '' : 'none';
    }
    if (muniGroup) {
      muniGroup.style.display = visible ? '' : 'none';
    }

    if (cityField) {
      cityField.style.display = visible ? 'none' : '';
      cityField.disabled = !!visible;
    }
    if (citySelect) {
      citySelect.style.display = visible ? '' : 'none';
      citySelect.disabled = !visible;
    }
  }

  function setNativeDepartmentVisible(visible) {
    const nativeSelect = getNativeDepartmentSelect();
    if (!nativeSelect) return;

    const nativeGroup = nativeSelect.closest('.form-group') || nativeSelect.parentNode;
    if (nativeGroup && nativeGroup.style) {
      nativeGroup.style.display = visible ? '' : 'none';
    }
  }

  /**
   * Return the Colombian municipality <select> added by the Symfony form modifier.
   *
   * @returns {HTMLSelectElement|null}
   */
  function getMunicipalitySelect() {
    const existing = (
      getCityMunicipalitySelect() ||
      document.querySelector('[data-colombia-municipality]') ||
      document.querySelector('select[name="colombia_municipality"]') ||
      document.querySelector('select[name="address[colombia_municipality]"]') ||
      null
    );

    if (existing) return existing;

    return ensureMunicipalitySelect();
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
    const existing = (
      document.querySelector('[data-colombia-dane-code]') ||
      document.querySelector('input[name="colombia_dane_code"]') ||
      document.querySelector('input[name="address[colombia_dane_code]"]') ||
      null
    );

    if (existing) return existing;

    return ensureDaneCodeField();
  }

  /**
   * Create municipality select dynamically when the form hook did not
   * inject it (theme/custom form compatibility fallback).
   *
   * @returns {HTMLSelectElement|null}
   */
  function ensureMunicipalitySelect() {
    const cityField = getCityField();
    if (!cityField || !cityField.parentNode) return null;

    const existing = getCityMunicipalitySelect();
    if (existing) return existing;

    const select = document.createElement('select');
    select.id = 'colombia_city_select';
    select.name = cityField.name || 'city';
    select.className = 'form-control form-control-select colombia-municipality-select';
    select.setAttribute('data-colombia-city-select', '1');
    select.setAttribute('data-autofill-postal', CONFIG.autofillPostal ? '1' : '0');
    select.appendChild(createOption('', '— Seleccione un municipio —'));

    cityField.parentNode.insertBefore(select, cityField.nextSibling);
    cityField.style.display = 'none';

    return select;
  }

  /**
   * Create hidden DANE code field when missing.
   *
   * @returns {HTMLInputElement|null}
   */
  function ensureDaneCodeField() {
    const cityField = getCityField();
    if (!cityField || !cityField.form) return null;

    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'colombia_dane_code';
    hidden.setAttribute('data-colombia-dane-code', '1');

    cityField.form.appendChild(hidden);

    return hidden;
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
  const departmentsCache = [];

  function loadDepartments(preselect, preselectMunicipality) {
    const deptSelect = ensureDepartmentSelect();
    if (!deptSelect) return;

    preselect = preselect || '';
    preselectMunicipality = preselectMunicipality || '';

    if (departmentsCache.length > 0) {
      populateDepartments(deptSelect, departmentsCache, preselect);
      if (deptSelect.value) {
        loadMunicipalities(deptSelect.value, preselectMunicipality);
      }
      return;
    }

    deptSelect.disabled = true;
    deptSelect.innerHTML = '';
    deptSelect.appendChild(createOption('', 'Cargando…'));

    const url = new URL(CONFIG.baseUrl, window.location.href);
    url.searchParams.set('list', 'departments');
    url.searchParams.set('token', CONFIG.token);

    fetch(url.toString(), {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (response) {
        if (!response.ok) {
          return response.text().then(function (body) {
            throw new Error('HTTP ' + response.status + ' - ' + body);
          });
        }
        return response.json();
      })
      .then(function (data) {
        if (!data || !Array.isArray(data.departments)) {
          throw new Error('Unexpected response format');
        }

        departmentsCache.length = 0;
        Array.prototype.push.apply(departmentsCache, data.departments);
        populateDepartments(deptSelect, data.departments, preselect);
        deptSelect.disabled = false;

        if (deptSelect.value) {
          loadMunicipalities(deptSelect.value, preselectMunicipality);
        }
      })
      .catch(function (err) {
        console.error('[ps_colombia_address] Failed to load departments:', err);
        deptSelect.innerHTML = '';
        deptSelect.appendChild(createOption('', '— Error al cargar —'));
        deptSelect.disabled = false;
      });
  }

  function populateDepartments(selectEl, departments, preselect) {
    selectEl.innerHTML = '';
    selectEl.appendChild(createOption('', '— Seleccione un departamento —'));

    departments.forEach(function (departmentName) {
      selectEl.appendChild(createOption(departmentName, departmentName, departmentName === preselect));
    });
  }

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
    if (!isColombiaSelected()) {
      return;
    }

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

    // When municipality is rendered directly as the city-bound select,
    // keep a single control (select) to avoid duplicate UI.
    if (municipalitySelect.hasAttribute('data-colombia-city-select')) {
      return;
    }

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
    const countrySelect = getCountrySelect();
    const colombia = isColombiaSelected();
    const cityField = getCityField();
    const preselectedCity = cityField ? String(cityField.value || '').trim() : '';
    const preselectedDepartment = getInitialDepartmentName();

    if (!colombia) {
      setNativeDepartmentVisible(true);
      setColombiaUiVisible(false);
      return;
    }

    setNativeDepartmentVisible(false);
    setColombiaUiVisible(true);

    const deptSelect = ensureDepartmentSelect();

    if (!deptSelect) return;

    // Avoid double-binding AND prevent infinite loop caused by MutationObserver:
    // loadDepartments() modifies the DOM (options), which would re-trigger the
    // observer → init() → loadDepartments() → observer → ... freeze.
    if (deptSelect.dataset.colombiaInit === '1') return;
    deptSelect.dataset.colombiaInit = '1';

    loadDepartments(preselectedDepartment, preselectedCity);

    deptSelect.addEventListener('change', onDepartmentChange);

    if (countrySelect && countrySelect.dataset.colombiaInit !== '1') {
      countrySelect.dataset.colombiaInit = '1';
      countrySelect.addEventListener('change', function () {
        if (isColombiaSelected()) {
          setNativeDepartmentVisible(false);
          setColombiaUiVisible(true);
          loadDepartments();
          init();
        } else {
          setNativeDepartmentVisible(true);
          setColombiaUiVisible(false);
        }
      });
    }

    const municipalitySelect = getMunicipalitySelect();
    if (municipalitySelect) {
      municipalitySelect.addEventListener('change', onMunicipalityChange);
    }

    if (CONFIG.enableAutocomplete && !getCityMunicipalitySelect()) {
      initAutocomplete();
    }

    // Initial municipalities load is handled by loadDepartments(preselectDept, preselectCity).
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

  const formHost = document.querySelector('.js-address-form') || document.body;
  if (formHost && typeof MutationObserver !== 'undefined') {
    let mutationDebounceTimer = null;
    const observer = new MutationObserver(function () {
      clearTimeout(mutationDebounceTimer);
      mutationDebounceTimer = setTimeout(init, 150);
    });
    observer.observe(formHost, { childList: true, subtree: true });
  }

  // Expose re-init for third-party modules / themes.
  window.ColombiaAddress = { init: init, loadMunicipalities: loadMunicipalities };
}());
