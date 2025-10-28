(function () {
  function qs(s) { return document.querySelector(s); }

  var grid = qs('#mgestc-grid');
  var count = qs('#mgestc-count');
  var pager = qs('#mgestc-pager');
  var modal = qs('#mgestc-modal');
  var modalContent = qs('#mgestc-modal-content');
  var lb = qs('#mgestc-lightbox');
  var lbImg = qs('#mgestc-lb-img');

  // Resolve REST endpoints robustly (localized object OR hidden div fallback)
  var elEP = document.querySelector('#mgestc-endpoints');
  var REST =
    (window.MGESTC && window.MGESTC.rest) ||
    (window.MGESTC_BOOT && window.MGESTC_BOOT.rest) ||
    (elEP ? {
      filters: elEP.getAttribute('data-filters'),
      listings: elEP.getAttribute('data-listings'),
      models: elEP.getAttribute('data-models')
    } : { filters: '', listings: '', models: '' });

  var state = {
    make: '', model: '', fuel: '', body: '', transmission: '',
    min_price: '', max_price: '', year_from: '', year_to: '',
    sort: 'insertion_date', order: 'desc',
    page: 1, per_page: 12
  };
  var pending = copy(state);
  var allFacets = (window.MGESTC_BOOT && window.MGESTC_BOOT.facets) ? window.MGESTC_BOOT.facets : null;

  var itemsIndex = {};
  var lbImages = []; var lbIdx = 0;
  var lastTotal = 0;

  function copy(o) { var k, r = {}; for (k in o) r[k] = o[k]; return r; }

  function fmtPrice(v) {
    var n = parseInt(String(v).replace(/\D+/g, ''), 10);
    if (isNaN(n)) return v || '';
    try {
      return new Intl.NumberFormat('it-IT', {
        style: 'currency', currency: 'EUR',
        minimumFractionDigits: 0, maximumFractionDigits: 0
      }).format(n);
    } catch (e) { return n + ' €'; }
  }

  function clearAdParam() {
    try {
      var u = new URL(location.href);
      if (u.searchParams.has('ad')) {
        u.searchParams.delete('ad');
        history.replaceState(null, '', u.toString());
      }
    } catch (_) {}
  }

  function fillSelect(sel, items, any) {
    if (!sel) return;
    if (!any) any = 'Tutte';
    var cur = sel.value;
    var html = '<option value="">' + any + '</option>';
    (items || []).forEach(function (x) {
      html += '<option value="' + String(x.key) + '">' + String(x.value) + (x.count ? ' (' + x.count + ')' : '') + '</option>';
    });
    sel.innerHTML = html;
    var exists = (items || []).some(function (x) { return String(x.key) === String(cur); });
    sel.value = exists ? cur : '';
  }

  function setApplyLabel(){
    var btn = document.getElementById('f_apply');
    if (btn) btn.textContent = 'Applica';
  }

  // ---- Year compatibility shim (handles multiple API variants) ----
  function addYearParams(u, yFrom, yTo) {
    function setIf(v, key) { if (v !== '' && v != null) u.searchParams.set(key, v); }
    // Canonical keys
    setIf(yFrom, 'year_from');
    setIf(yTo,   'year_to');

    // Common aliases present in different backends
    setIf(yFrom, 'from_year');
    setIf(yTo,   'to_year');
    setIf(yFrom, 'first_registration_year_from');
    setIf(yTo,   'first_registration_year_to');
    setIf(yFrom, 'immatricolazione_da');
    setIf(yTo,   'immatricolazione_a');

    // Some servers accept a single "year" when both are equal
    if (yFrom && yTo && String(yFrom) === String(yTo)) {
      u.searchParams.set('year', yFrom);
    }
  }
  // ----------------------------------------------------------------

  // --------- PRICE SANITIZATION ----------
  function sanitizePriceInput(el) {
    if (!el) return '';
    var v = el.value.trim();
    var n = parseInt(v.replace(/[^\d]/g, ''), 10);
    if (isNaN(n) || n < 0) { el.value = ''; return ''; }
    el.value = String(n);
    return String(n);
  }
  function preventMinusPlusE(e) {
    var k = e.key;
    if (k === '-' || k === '+' || (k && k.toLowerCase() === 'e')) e.preventDefault();
  }
  function normalizePrices(obj) {
    var min = obj.min_price, max = obj.max_price;
    var mi = (min === '' || min == null) ? '' : parseInt(min, 10);
    var ma = (max === '' || max == null) ? '' : parseInt(max, 10);
    if (isNaN(mi) || mi < 0) mi = '';
    if (isNaN(ma) || ma < 0) ma = '';
    if (mi !== '' && ma !== '' && ma < mi) { var t = mi; mi = ma; ma = t; }
    obj.min_price = mi === '' ? '' : String(mi);
    obj.max_price = ma === '' ? '' : String(ma);
  }
  // --------------------------------------

  function rebuildFromFacets(currentFacets) {
    if (!currentFacets) return;

    var chosen = {
      make: pending.make, model: pending.model, body: pending.body,
      fuel: pending.fuel, trans: pending.transmission,
      year_from: pending.year_from, year_to: pending.year_to
    };

    function sourceFor(facetKey) {
      var map = { makes: 'make', models: 'model', bodies: 'body', fuels: 'fuel', transmissions: 'trans' };
      var selKey = map[facetKey];
      if (!selKey) return currentFacets[facetKey];
      var isSel = !!chosen[selKey];
      if (isSel && allFacets && allFacets[facetKey]) return allFacets[facetKey];
      return currentFacets[facetKey];
    }

    fillSelect(document.getElementById('f_body'), sourceFor('bodies'), 'Tutte');
    fillSelect(document.getElementById('f_make'), sourceFor('makes'), 'Tutte');
    fillSelect(document.getElementById('f_model'), sourceFor('models'), 'Tutti');
    fillSelect(document.getElementById('f_fuel'), sourceFor('fuels'), 'Tutte');
    fillSelect(document.getElementById('f_trans'), sourceFor('transmissions'), 'Tutte');

    // Always show ALL years (from initial facets if available)
    var yearsAll = (allFacets && allFacets.years) ? allFacets.years : (currentFacets.years || []);
    var yFromSel = document.getElementById('f_year_from');
    fillSelect(yFromSel, yearsAll, 'Qualsiasi');
    // (No year_to select in this build)
  }

  function loadFilters() {
    if (allFacets) { rebuildFromFacets(allFacets); enableModel(); return Promise.resolve(); }
    if (!REST.filters) { enableModel(); return Promise.resolve(); }
    return fetch(REST.filters)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        allFacets = data && data.facets ? data.facets : null;
        rebuildFromFacets(allFacets || {});
        enableModel();
      })
      .catch(function (e) { console.error('[MGEST] filters fetch failed', e); enableModel(); });
  }

  function enableModel() {
    var modelSel = document.getElementById('f_model');
    if (modelSel) modelSel.disabled = false;
    var perSel = document.getElementById('f_per_page');
    if (perSel) perSel.value = String(state.per_page);
  }

  var facetTimer = null;
  function debounceFacets() {
    if (facetTimer) clearTimeout(facetTimer);
    facetTimer = setTimeout(updateFacetsOnly, 300);
  }

  function updateFacetsOnly() {
    if (!REST.listings) return Promise.resolve();
    var url = new URL(REST.listings);
    var tmp = copy(pending); tmp.page = 1; tmp.per_page = 1;

    // Clean prices for the preview call
    normalizePrices(tmp);

    Object.keys(tmp).forEach(function (k) {
      var v = tmp[k]; if (v !== '' && v != null) url.searchParams.set(k, v);
    });

    // Normalize single year bound to exact year
    var yF_p = tmp.year_from, yT_p = tmp.year_to;
    if (yF_p && !yT_p) yT_p = yF_p;
    if (yT_p && !yF_p) yF_p = yT_p;
    addYearParams(url, yF_p, yT_p);

    return fetch(url.toString())
      .then(function (r) { return r.json(); })
      .then(function (data) {
        lastTotal = (data && data.total) || 0; setApplyLabel();
        rebuildFromFacets((data && data.facets) || {});
      })
      .catch(function (e) { console.error('[MGEST] facets fetch failed', e); });
  }

  function loadModelsForMake(makeLabel) {
    var modelSel = document.getElementById('f_model'); if (!modelSel) return Promise.resolve();
    modelSel.disabled = true; modelSel.innerHTML = '<option value="">Caricamento…</option>';
    if (!makeLabel || !REST.models) { modelSel.innerHTML = '<option value="">Tutti</option>'; modelSel.disabled = false; return Promise.resolve(); }
    var url = new URL(REST.models); url.searchParams.set('make', makeLabel);
    return fetch(url.toString())
      .then(function (r) { return r.json(); })
      .then(function (models) {
        var rows = (models || []).map(function (m) {
          var key = (m && (m.key != null ? m.key : m.value != null ? m.value : m)) || '';
          var val = (m && (m.value != null ? m.value : m.key != null ? m.key : m)) || '';
          return { key: key, value: val };
        });
        fillSelect(modelSel, rows, 'Tutti');
      })
      .catch(function () { modelSel.innerHTML = '<option value="">Tutti</option>'; })
      .finally(function () { modelSel.disabled = false; });
  }

  function cardHTML(it) {
    var img = (it.images && it.images.length) ? it.images[0] : (it.company_logo || '');
    var price = fmtPrice(it.price);

    // Format mileage ONCE with unit
    var kmText = (it.mileage != null && it.mileage !== '')
      ? new Intl.NumberFormat('it-IT').format(parseInt(it.mileage, 10)) + ' km'
      : '—';

    var year = '';
    if (it.first_registration_date) {
      var m = String(it.first_registration_date).match(/(\d{4})/);
      year = m ? m[1] : '';
    }
    var trans = it.transmission_type || '';
    var fuel = it.fuel_type || '';

    var html = '';
    html += '<article class="mgestc-card">';
    html += '  <div class="mgestc-thumb">';
    if (img) {
      html += '    <img loading="lazy" decoding="async" src="' + img + '" alt="' + (it.title || '') + '" sizes="(max-width:640px) 100vw, (max-width:1024px) 50vw, 25vw">';
    } else {
      html += '    <div class="mgestc-noimg">No Image</div>';
    }
    html += '  </div>';
    html += '  <div class="mgestc-body">';
    html += '    <h3 class="mgestc-title">' + (it.title || ((it.make || '') + ' ' + (it.model || '') + ' ' + year)) + '</h3>';
    html += '    <ul class="mgestc-specs"><li>' + kmText + '</li><li>' + (year || '—') + '</li><li>' + (trans || '—') + '</li><li>' + (fuel || '—') + '</li></ul>';
    html += '    <div class="mgestc-price">' + price + '</div>';
    html += '  </div>';
    html += '</article>';
    return html;
  }

  function renderPages(totalPages) {
    var wrap = document.getElementById('mgestc-pages'); if (!wrap) return;
    var cur = state.page; var out = []; var ell = '<span class="mgestc-ellipsis">…</span>';
    function btn(p, a) { out.push('<button class="mgestc-page' + (a ? ' is-active' : '') + '" data-page="' + p + '">' + p + '</button>'); }
    if (totalPages <= 7) { for (var p1 = 1; p1 <= totalPages; p1++) btn(p1, p1 === cur); }
    else {
      btn(1, cur === 1);
      if (cur > 4) out.push(ell);
      var s = Math.max(2, cur - 2), e = Math.min(totalPages - 1, cur + 2);
      for (var p2 = s; p2 <= e; p2++) btn(p2, p2 === cur);
      if (cur < totalPages - 3) out.push(ell);
      btn(totalPages, cur === totalPages);
    }
    wrap.innerHTML = out.join('');
  }

  function openModal(html) {
    if (!modal || !modalContent) return;
    modalContent.innerHTML = html;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    clearAdParam();
    closeLightbox();
  }

  function paneTabsJS() {
    var tabs = modal.querySelectorAll('.mgestc-tab');
    var panes = modal.querySelectorAll('.mgestc-pane');
    Array.prototype.forEach.call(tabs, function (b) {
      b.addEventListener('click', function () {
        var t = b.getAttribute('data-target');
        Array.prototype.forEach.call(tabs, function (x) { x.classList.remove('is-active'); });
        Array.prototype.forEach.call(panes, function (p) { p.classList.remove('is-active'); });
        b.classList.add('is-active');
        modal.querySelector('#' + t).classList.add('is-active');
      });
    });
  }

  // Details modal
  function detailHTML(it) {
    var imgs = (it.images && it.images.length) ? it.images : (it.company_logo ? [it.company_logo] : []);
    var main = imgs[0] || '';
    var year = '';
    if (it.first_registration_date) {
      var m = String(it.first_registration_date).match(/(\d{4})/);
      year = m ? m[1] : '';
    }

    var specs = [
      ['Tipologia', it.vehicle_class || '—'],
      ['Marca', it.make || '—'],
      ['Modello', it.model || '—'],
      ['Colore', it.color || '—'],
      ['Carburante', it.fuel_type || '—'],
      ['Immatricolazione', it.first_registration_date || '—'],
      ['Chilometraggio', it.mileage || '—'],
      ['kW', it.power_kw || '—']
    ];
    var kv = '<ul class="mgestc-kv">';
    specs.forEach(function (pair) { kv += '<li><strong>' + pair[0] + '</strong></li><li>' + pair[1] + '</li>'; });
    kv += '</ul>';

    var opts = (it.optionals && it.optionals.length)
      ? ('<ul class="mgestc-opts">' + it.optionals.map(function (o) { return '<li>' + o + '</li>'; }).join('') + '</ul>')
      : '<p>Nessun optional indicato.</p>';

    var html = '';
    html += '<div class="mgestc-modal-body">';

    // LEFT: gallery
    html += '  <div>';
    html += '    <div class="mgestc-gallery">';
    if (main) {
      html += '      <img src="' + main + '" alt="' + (it.title || '') + '" id="mgestc-mainimg" loading="eager" decoding="async">';
    }
    html += '      <button class="mgestc-gprev" aria-label="Prev">&#10094;</button>';
    html += '      <button class="mgestc-gnext" aria-label="Next">&#10095;</button>';
    html += '    </div>';
    html += '  </div>';

    // RIGHT: title + price + details (always visible)
    html += '  <div class="mgestc-rightcol">';
    html += '    <h2 id="mgestc-modal-title" class="mgestc-title-xl">' + (it.title || ((it.make || '') + ' ' + (it.model || '') + ' ' + year)) + '</h2>';
    html += '    <div class="mgestc-price mgestc-price-lg">' + fmtPrice(it.price) + '</div>';
    html +=        kv;
    html += '  </div>';

    html += '</div>';

    // Tabs (Descrizione + Optional)
    html += '<div class="mgestc-tabs">';
    html += '  <button class="mgestc-tab is-active" data-target="pane-desc">Descrizione</button>';
    html += '  <button class="mgestc-tab" data-target="pane-opts">Optional</button>';
    html += '</div>';
    html += '<div class="mgestc-tabpanes">';
    html += '  <div id="pane-desc" class="mgestc-pane is-active">' + (it.description || '<p>Nessuna descrizione.</p>') + '</div>';
    html += '  <div id="pane-opts" class="mgestc-pane">' + opts + '</div>';
    html += '</div>';

    return html;
  }

  function wireModalInteractions(it) {
    var main = modal.querySelector('#mgestc-mainimg');
    var gp = modal.querySelector('.mgestc-gprev');
    var gn = modal.querySelector('.mgestc-gnext');
    var imgs = (it.images && it.images.length) ? it.images : (it.company_logo ? [it.company_logo] : []);
    var i = 0;
    function setImg(n) { if (!main || !imgs.length) return; i = (n + imgs.length) % imgs.length; main.src = imgs[i]; }
    if (gp) gp.onclick = function () { setImg(i - 1); };
    if (gn) gn.onclick = function () { setImg(i + 1); };
    if (main) main.onclick = function () { openLightbox(imgs, i); };

    // --- swipe gestures for gallery (phones/tablets) ---
    (function(){
      var el = modal.querySelector('.mgestc-gallery');
      if (!el) return;
      var startX = 0, endX = 0;

      el.addEventListener('touchstart', function(e){
        if (!e.changedTouches || !e.changedTouches.length) return;
        startX = e.changedTouches[0].clientX;
      }, { passive:true });

      el.addEventListener('touchend', function(e){
        if (!e.changedTouches || !e.changedTouches.length) return;
        endX = e.changedTouches[0].clientX;
        var dx = endX - startX;
        if (Math.abs(dx) > 40){
          if (dx < 0) { if (typeof gn !== 'undefined' && gn) gn.click(); }
          else        { if (typeof gp !== 'undefined' && gp) gp.click(); }
        }
      }, { passive:true });
    })();
  }

  function openLightbox(imgs, idx) {
    if (!lb) return;
    lbImages = imgs || [];
    lbIdx = Math.max(0, Math.min(idx || 0, lbImages.length - 1));
    if (!lbImages.length) return;
    lbImg.src = lbImages[lbIdx] || '';
    lb.classList.add('is-open');
    lb.setAttribute('aria-hidden', 'false');
  }
  function closeLightbox() { if (!lb) return; lb.classList.remove('is-open'); lb.setAttribute('aria-hidden', 'true'); }
  function lbNext() { if (!lbImages.length) return; lbIdx = (lbIdx + 1) % lbImages.length; lbImg.src = lbImages[lbIdx]; }
  function lbPrev() { if (!lbImages.length) return; lbIdx = (lbIdx - 1 + lbImages.length) % lbImages.length; lbImg.src = lbImages[lbIdx]; }

  function loadListings() {
    if (!REST.listings) return Promise.resolve();
    var url = new URL(REST.listings);

    Object.keys(state).forEach(function (k) {
      var v = state[k]; if (v !== '' && v != null) url.searchParams.set(k, v);
    });

    // Year aliases for actual listing fetch (normalize single bound => exact)
    var yF = state.year_from, yT = state.year_to;
    if (yF && !yT) yT = yF;
    if (yT && !yF) yF = yT;
    addYearParams(url, yF, yT);

    grid.innerHTML = '<div class="mgestc-loading">Loading…</div>';
    return fetch(url.toString())
      .then(function (r) { return r.json(); })
      .then(function (data) {
        lastTotal = (data && data.total) || 0; setApplyLabel();
        if (count) count.textContent = lastTotal + ' risultati';

        var html = '';
        if (data && data.items && data.items.length) html = data.items.map(cardHTML).join('');
        else html = '<div class="mgestc-empty">Nessun risultato</div>';
        grid.innerHTML = html;

        itemsIndex = {};
        (data.items || []).forEach(function (it) { if (it.ad_number) itemsIndex[it.ad_number] = it; });
        Array.prototype.forEach.call(grid.querySelectorAll('.mgestc-card'), function (el, i) {
          var it = data.items[i]; if (it && it.ad_number) el.dataset.ad = it.ad_number;
        });

        rebuildFromFacets((data && data.facets) || {});

        var totalPages = (data && (data.total_pages || Math.ceil((data.total || 0) / state.per_page))) || 0;
        if (pager) {
          pager.style.display = (totalPages > 1) ? 'flex' : 'none';
          renderPages(totalPages);
          var prev = pager.querySelector('button[data-dir="prev"]');
          var next = pager.querySelector('button[data-dir="next"]');
          if (prev) prev.disabled = (state.page <= 1);
          if (next) next.disabled = (state.page >= totalPages);
        }

        try {
          var urlAd = new URLSearchParams(location.search).get('ad');
          if (urlAd && itemsIndex[urlAd]) {
            openModal(detailHTML(itemsIndex[urlAd]));
            wireModalInteractions(itemsIndex[urlAd]);
            paneTabsJS();
          }
        } catch (_) {}
      })
      .catch(function (err) {
        console.error('[MGEST] Listings fetch failed', err);
        grid.innerHTML = '<div class="mgestc-empty">Impossibile caricare gli annunci (controlla console).</div>';
        if (count) count.textContent = '0 risultati';
      });
  }

  function bind() {
    var map = {
      'f_body': 'body', 'f_make': 'make', 'f_model': 'model', 'f_fuel': 'fuel', 'f_trans': 'transmission',
      'f_min_price': 'min_price', 'f_max_price': 'max_price',
      'f_year_from': 'year_from', 'f_year_to': 'year_to',
      'f_sort': 'sort', 'f_order': 'order', 'f_per_page': 'per_page'
    };

    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id); if (!el) return;
      el.addEventListener('change', function (e) {
        var v = e.target.value;
        pending[map[id]] = v;
        if (id === 'f_make') {
          pending.model = '';
          var ms = document.getElementById('f_model');
          if (ms) ms.value = '';
          loadModelsForMake(v).then(function () { });
        }
        debounceFacets();
      });
    });

    // Price fields: block invalid keys and sanitize values
    var elMin = document.getElementById('f_min_price');
    var elMax = document.getElementById('f_max_price');

    [elMin, elMax].forEach(function (el) {
      if (!el) return;
      el.addEventListener('keydown', preventMinusPlusE);
      el.addEventListener('input', function () {
        var val = sanitizePriceInput(el);
        if (el === elMin) pending.min_price = val;
        else pending.max_price = val;
        debounceFacets();
      });
    });

    var applyBtn = document.getElementById('f_apply');
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        state = copy(state);
        Object.keys(pending).forEach(function (k) { state[k] = pending[k]; });
        state.page = 1;

        // Ensure clean non-negative prices and valid range
        normalizePrices(state);

        clearAdParam();
        loadListings();
      });
    }

    var resetBtn = document.getElementById('f_reset');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        var keep = state.per_page;
        state = {
          make: '', model: '', fuel: '', body: '', transmission: '',
          min_price: '', max_price: '', year_from: '', year_to: '',
          sort: 'insertion_date', order: 'desc', page: 1, per_page: keep
        };
        pending = copy(state);
        Array.prototype.forEach.call(document.querySelectorAll('#mgestc-filters select,#mgestc-filters input'), function (el) {
          if (el.id === 'f_per_page') { el.value = String(keep); return; }
          if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
        });
        rebuildFromFacets(allFacets || {});
        loadModelsForMake('').then(function () { });
        clearAdParam();
        loadListings();
      });
    }

    // --- SAFE grid interactions (no open while highlighting / dragging) ---
    var dragStartX = 0, dragStartY = 0;

    grid.addEventListener('mousedown', function (e) {
      dragStartX = e.clientX || 0;
      dragStartY = e.clientY || 0;
    });

    grid.addEventListener('click', function (e) {
      // left click only
      if (e.button !== 0) return;

      // ignore if there is selected text (user highlighted something)
      try {
        var sel = window.getSelection ? String(window.getSelection()).trim() : '';
        if (sel) return;
      } catch (_) {}

      // ignore if the click was actually a drag
      var dx = Math.abs((e.clientX || 0) - dragStartX);
      var dy = Math.abs((e.clientY || 0) - dragStartY);
      if (dx > 3 || dy > 3) return;

      // ignore interactive elements
      if (e.target.closest('a,button,input,select,textarea,label')) return;

      var card = e.target.closest('.mgestc-card');
      if (!card || !card.dataset.ad) return;

      var it = itemsIndex[card.dataset.ad];
      if (!it) return;

      try {
        var u = new URL(location.href);
        u.searchParams.set('ad', it.ad_number);
        history.replaceState(null, '', u.toString());
      } catch (_) {}

      openModal(detailHTML(it));
      wireModalInteractions(it);
      paneTabsJS();
    });

    // ----- Mobile filter sheet toggle -----
    (function(){
      var filters = document.getElementById('mgestc-filters');
      if (!filters) return;

      // create floating button once
      var fab = document.getElementById('mgestc-filter-fab');
      if (!fab){
        fab = document.createElement('button');
        fab.id = 'mgestc-filter-fab';
        fab.type = 'button';
        fab.textContent = 'Filtri';
        document.body.appendChild(fab);
      }

      function isSmall(){ return window.matchMedia('(max-width: 640px)').matches; }
      function openSheet(){
        filters.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        // add safe-area padding so buttons aren't behind the home bar
        filters.style.paddingBottom = 'calc(16px + env(safe-area-inset-bottom))';
      }
      function closeSheet(){
        filters.classList.remove('is-open');
        document.body.style.overflow = '';
        filters.style.paddingBottom = '';
      }

      fab.addEventListener('click', function(){ if (isSmall()) openSheet(); });

      var aBtn = document.getElementById('f_apply');
      var rBtn = document.getElementById('f_reset');
      if (aBtn) aBtn.addEventListener('click', closeSheet);
      if (rBtn) rBtn.addEventListener('click', closeSheet);

      // optional: close on scroll
      window.addEventListener('scroll', function(){
        if (isSmall() && filters.classList.contains('is-open')) closeSheet();
      }, { passive:true });
    })();

    // Pagination
    if (pager) {
      pager.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        if (b.dataset.page) {
          var p = parseInt(b.dataset.page, 10);
          if (!isNaN(p) && p !== state.page) { state.page = p; clearAdParam(); loadListings(); }
          return;
        }
        var dir = b.dataset.dir;
        if (dir === 'prev' && state.page > 1) state.page--;
        if (dir === 'next') state.page++;
        clearAdParam(); loadListings();
      });
    }

    // Modal close
    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target.classList.contains('mgestc-close') || e.target === modal) closeModal();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
      });
    }
    if (lb) {
      lb.addEventListener('click', function (e) {
        if (e.target.classList.contains('mgestc-lb-close') || e.target === lb) { closeLightbox(); return; }
        if (e.target.classList.contains('mgestc-lb-prev')) { lbPrev(); return; }
        if (e.target.classList.contains('mgestc-lb-next')) { lbNext(); return; }
      });
      document.addEventListener('keydown', function (e) {
        if (!lb.classList.contains('is-open')) return;
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowRight') lbNext();
        if (e.key === 'ArrowLeft') lbPrev();
      });
    }
  }

  (function init() { loadFilters().then(function () { bind(); return loadListings(); }); })();
})();
