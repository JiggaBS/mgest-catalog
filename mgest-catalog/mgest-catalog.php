<?php
/**
 * Plugin Name: MGest Catalog (Persona Edition)
 * Description: Custom filters, cards, and modal details for Multigestionale inventory. Persona theme styling.
 * Version: 1.6.0
 * Author: Andrew
 */

if (!defined('ABSPATH')) exit;

define('MGESTC_PATH', plugin_dir_path(__FILE__));
define('MGESTC_URL',  plugin_dir_url(__FILE__));

require_once MGESTC_PATH . 'includes/class-mgest-api.php';

class MGest_Catalog {
  public function __construct() {
    add_action('init',               [$this, 'register_shortcodes']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('rest_api_init',      [$this, 'register_rest']);
  }

  public function register_shortcodes() {
    add_shortcode('mgest_catalog', [$this, 'render_catalog']);
    add_shortcode('mgest_home',    [$this, 'render_home']);
  }

  /** HOME SHORTCODE (8 cards only) **/
  public function render_home() {
    ob_start(); ?>
    <div id="mgestc-home" class="mgestc-home-grid"></div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      if (!window.MGESTC_BOOT?.rest?.listings) return;
      const url = new URL(window.MGESTC_BOOT.rest.listings);
      url.searchParams.set('per_page', 8);
      url.searchParams.set('page', 1);
      url.searchParams.set('sort', 'insertion_date');
      url.searchParams.set('order', 'desc');

      fetch(url)
        .then(r => r.json())
        .then(data => {
          const grid = document.getElementById('mgestc-home');
          if (!data?.items?.length || !grid) return;
          grid.innerHTML = data.items.map(it => {
            const img = it.images?.[0] || '';
            const price = new Intl.NumberFormat('it-IT', { style:'currency', currency:'EUR', minimumFractionDigits:0 }).format(it.price || 0);
            const year = it.first_registration_date?.match(/\d{4}/)?.[0] || '';
            const km = it.mileage ? new Intl.NumberFormat('it-IT').format(it.mileage) + ' km' : '—';
            const fuel = it.fuel_type || '';
            return `
              <article class="mgestc-card">
                <div class="mgestc-thumb"><img src="${img}" alt="${it.title || ''}"></div>
                <div class="mgestc-body">
                  <h3>${it.title || (it.make + ' ' + it.model)}</h3>
                  <p class="mgestc-meta">${km} • ${year} • ${fuel}</p>
                  <div class="mgestc-price">${price}</div>
                </div>
              </article>`;
          }).join('');
        });
    });
    </script>
    <?php
    return ob_get_clean();
  }

  /** FULL CATALOG SHORTCODE **/
  public function render_catalog($atts = []) {
    ob_start();
    include MGESTC_PATH . 'templates/catalog.php';
    return ob_get_clean();
  }

  /** ENQUEUE ASSETS **/
  public function enqueue_assets() {
    wp_enqueue_style('mgest-catalog', MGESTC_URL . 'assets/css/mgest-catalog.css', [], '1.6.0');
    wp_enqueue_script('mgest-catalog', MGESTC_URL . 'assets/js/mgest-catalog.js', ['jquery'], '1.6.0', true);

    $boot = [
      'rest' => [
        'filters'  => esc_url_raw(rest_url('mgest/v1/filters')),
        'listings' => esc_url_raw(rest_url('mgest/v1/listings')),
        'models'   => esc_url_raw(rest_url('mgest/v1/models')),
      ],
    ];
    wp_add_inline_script('mgest-catalog', 'window.MGESTC_BOOT = '.wp_json_encode($boot).';', 'before');
  }

  /** REST API **/
  public function register_rest() {
    register_rest_route('mgest/v1', '/filters', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_filters'],
      'permission_callback' => '__return_true'
    ]);

    register_rest_route('mgest/v1', '/listings', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_listings'],
      'permission_callback' => '__return_true'
    ]);

    register_rest_route('mgest/v1', '/models', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_models'],
      'permission_callback' => '__return_true'
    ]);
  }

  /** FILTERS ENDPOINT **/
  public function rest_filters() {
    $api = new MGest_API();
    $items = $api->get_listings(['engine'=>'car','limit'=>150,'sort'=>'insertion_date','invert'=>1,'show'=>'all']);
    $facets = $this->build_facets($items);
    return new WP_REST_Response(['facets'=>$facets,'price'=>['min'=>1000,'max'=>200000]],200);
  }

  /** MODELS ENDPOINT **/
  public function rest_models($req) {
    $make = $req->get_param('make');
    if (!$make) return new WP_REST_Response([],200);
    $api = new MGest_API();
    $models = $api->get_objects('models','car',$make);
    return new WP_REST_Response($models ?: [],200);
  }

  /** LISTINGS ENDPOINT **/
  public function rest_listings($req) {
    $api = new MGest_API();
    $items = $api->get_listings([
      'engine'=>'car','limit'=>150,'sort'=>'insertion_date','invert'=>1,'show'=>'all'
    ]);

    $min = max(0,(int)$req->get_param('min_price') ?: 0);
    $max = max($min,(int)$req->get_param('max_price') ?: PHP_INT_MAX);
    $filtered = array_filter($items, function($x) use($min,$max){
      $price = (int)preg_replace('/\D+/','',$x['price'] ?? 0);
      return ($price >= $min && $price <= $max);
    });
    $facets = $this->build_facets($filtered);
    return new WP_REST_Response(['items'=>$filtered,'facets'=>$facets],200);
  }

  /** BUILD FACETS **/
  private function build_facets($items) {
    $out = ['makes'=>[],'models'=>[],'years'=>[]];
    foreach ($items as $x) {
      if (!empty($x['make']))  $out['makes'][$x['make']]  = ($out['makes'][$x['make']] ?? 0)+1;
      if (!empty($x['model'])) $out['models'][$x['model']] = ($out['models'][$x['model']] ?? 0)+1;
      if (!empty($x['year']))  $out['years'][$x['year']]  = ($out['years'][$x['year']] ?? 0)+1;
    }
    return $out;
  }
}

new MGest_Catalog();
