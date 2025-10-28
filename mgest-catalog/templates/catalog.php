<?php if (!defined('ABSPATH')) exit; ?>

<?php
  // Fallback REST endpoints for JS (used if the localized object is missing)
  $mgest_base = rest_url('mgest/v1/');
?>
<div id="mgestc-endpoints"
     data-listings="<?php echo esc_url( $mgest_base . 'listings' ); ?>"
     data-filters="<?php echo esc_url( $mgest_base . 'filters' ); ?>"
     data-models="<?php echo esc_url( $mgest_base . 'models' ); ?>"
     style="display:none"></div>

<div class="mgestc-wrap is-topbar">

  <!-- FILTER BAR -->
  <div class="mgestc-quickbar" id="mgestc-filters">
    <div class="mgestc-quick-row">

      <div class="mgestc-qfield">
        <label for="f_body">Carrozzeria</label>
        <select id="f_body"><option value="">Tutte</option></select>
      </div>

      <div class="mgestc-qfield">
        <label for="f_make">Marca</label>
        <select id="f_make"><option value="">Tutte</option></select>
      </div>

      <div class="mgestc-qfield">
        <label for="f_model">Modello</label>
        <select id="f_model" disabled><option value="">Tutti</option></select>
      </div>

      <div class="mgestc-qfield">
        <label for="f_fuel">Alimentazione</label>
        <select id="f_fuel"><option value="">Tutte</option></select>
      </div>

      <div class="mgestc-qfield">
        <label for="f_trans">Trasmissione</label>
        <select id="f_trans"><option value="">Tutte</option></select>
      </div>

      <!-- YEARS (single select only) -->
      <div class="mgestc-qfield">
        <label for="f_year_from">Anno</label>
        <select id="f_year_from"><option value="">Qualsiasi</option></select>
      </div>

      <!-- PRICE (with min=0 + step=1) -->
      <div class="mgestc-qfield mgestc-qprice">
        <label for="f_min_price">Prezzo da (€)</label>
        <input id="f_min_price" type="number" inputmode="numeric" min="0" step="1" placeholder="es. 5000">
      </div>

      <div class="mgestc-qfield mgestc-qprice">
        <label for="f_max_price">Prezzo fino a (€)</label>
        <input id="f_max_price" type="number" inputmode="numeric" min="0" step="1" placeholder="es. 20000">
      </div>

      <div class="mgestc-qactions">
        <button id="f_apply" class="mgestc-apply" type="button">Applica</button>
        <button id="f_reset" class="mgestc-reset" type="button">Reset</button>
      </div>
    </div>

    <div class="mgestc-quick-meta">
      <strong id="mgestc-count" class="mgestc-count">0 risultati</strong>

      <!-- Sort UI (hidden by default; enable later if needed) -->
      <div class="mgestc-sorts" style="display:none">
        <label for="f_sort">Ordina</label>
        <select id="f_sort">
          <option value="insertion_date">Data: più recenti</option>
          <option value="price">Prezzo</option>
          <option value="mileage">Chilometraggio</option>
          <option value="year">Anno</option>
          <option value="title">Titolo</option>
        </select>
        <select id="f_order">
          <option value="desc">Desc</option>
          <option value="asc">Asc</option>
        </select>
        <select id="f_per_page">
          <option value="12">12</option>
          <option value="24">24</option>
          <option value="36">36</option>
        </select>
      </div>
    </div>
  </div>

  <!-- GRID -->
  <div id="mgestc-grid" class="mgestc-grid"></div>

  <!-- PAGER -->
  <div id="mgestc-pager" class="mgestc-pager" style="display:none">
    <button type="button" data-dir="prev" aria-label="Pagina precedente">&laquo;</button>
    <div id="mgestc-pages" class="mgestc-pages"></div>
    <button type="button" data-dir="next" aria-label="Pagina successiva">&raquo;</button>
  </div>
</div>

<!-- MODAL -->
<div id="mgestc-modal" class="mgestc-modal" aria-hidden="true">
  <div class="mgestc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mgestc-modal-title">
    <button class="mgestc-close" aria-label="Chiudi">&times;</button>
    <div id="mgestc-modal-content" class="mgestc-modal-content"></div>
  </div>
</div>

<!-- LIGHTBOX -->
<div id="mgestc-lightbox" class="mgestc-lightbox" aria-hidden="true">
  <button class="mgestc-lb-close" aria-label="Chiudi">&times;</button>
  <button class="mgestc-lb-prev" aria-label="Indietro">&#10094;</button>
  <img id="mgestc-lb-img" alt="">
  <button class="mgestc-lb-next" aria-label="Avanti">&#10095;</button>
</div>
