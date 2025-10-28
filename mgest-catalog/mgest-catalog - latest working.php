<?php
/**
 * Plugin Name: MGest Catalog (Companion)
 * Description: Custom filters + card grid + modal details for Multigestionale inventory.
 * Version: 1.5.0
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
  }

  // === SHORTCODE: Minimal home grid ===
add_shortcode('mgest_home', function() {
    ob_start(); ?>
    <div id="mgestc-home">
        <div id="mgestc-grid" class="mgestc-grid"></div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
      if (window.MGESTC && window.MGESTC.rest) {
        const url = new URL(window.MGESTC.rest.listings);
        url.searchParams.set('per_page', 8);
        url.searchParams.set('page', 1);
        url.searchParams.set('sort', 'insertion_date');
        url.searchParams.set('order', 'desc');

        fetch(url.toString())
          .then(r => r.json())
          .then(data => {
            if (!data?.items) return;
            const grid = document.getElementById('mgestc-grid');
            grid.innerHTML = data.items.map(it => {
              const img = it.images?.[0] || '';
              const price = new Intl.NumberFormat('it-IT', { style:'currency', currency:'EUR', minimumFractionDigits:0 }).format(it.price || 0);
              const year = it.first_registration_date?.match(/\d{4}/)?.[0] || '';
              const km = it.mileage ? new Intl.NumberFormat('it-IT').format(it.mileage) + ' km' : '—';
              const trans = it.transmission_type || '';
              const fuel = it.fuel_type || '';
              return `
              <article class="mgestc-card">
                <div class="mgestc-thumb"><img loading="lazy" src="${img}" alt="${it.title || ''}"></div>
                <div class="mgestc-body">
                  <h3 class="mgestc-title">${it.title || (it.make + ' ' + it.model)}</h3>
                  <ul class="mgestc-specs"><li>${km}</li><li>${year}</li><li>${trans}</li><li>${fuel}</li></ul>
                  <div class="mgestc-price">${price}</div>
                </div>
              </article>`;
            }).join('');
          });
      }
    });
    </script>
    <?php
    return ob_get_clean();
});

  public function enqueue_assets() {
    if (!is_singular()) return;
    global $post;
    if (!isset($post->post_content) || !has_shortcode($post->post_content, 'mgest_catalog')) return;

    // Pre-warm / inline initial facets so the page renders with filters ready
    $facets_key = 'mgest_all_facets_v150';
    $facets = get_transient($facets_key);
    if ($facets === false) {
      $api   = new MGest_API();
      $items = $api->get_listings([
        'engine' => 'car',
        'limit'  => 150,
        'sort'   => 'insertion_date',
        'invert' => 1,
        'show'   => 'all',
      ]);
      $facets = $this->build_facets($items);
      set_transient($facets_key, $facets, 5 * MINUTE_IN_SECONDS);
    }

    wp_enqueue_style('mgest-catalog', MGESTC_URL . 'assets/css/mgest-catalog.css', [], '1.5.0');
    wp_enqueue_script('mgest-catalog', MGESTC_URL . 'assets/js/mgest-catalog.js', [], '1.5.0', true);

    // Boot data (facets) + REST endpoints
    $boot = [
      'facets' => $facets,
      'rest'   => [
        'filters'  => esc_url_raw( rest_url('mgest/v1/filters') ),
        'listings' => esc_url_raw( rest_url('mgest/v1/listings') ),
        'models'   => esc_url_raw( rest_url('mgest/v1/models') ),
      ],
    ];
    wp_add_inline_script('mgest-catalog', 'window.MGESTC_BOOT = '.wp_json_encode($boot).';', 'before');
  }

  public function render_catalog($atts = []) {
    ob_start();
    include MGESTC_PATH . 'templates/catalog.php';
    return ob_get_clean();
  }

  public function register_rest() {
    register_rest_route('mgest/v1', '/filters', [
      'methods'  => 'GET',
      'callback' => [$this, 'rest_filters'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('mgest/v1', '/listings', [
      'methods'  => 'GET',
      'callback' => [$this, 'rest_listings'],
      'permission_callback' => '__return_true',
      'args' => [
        'make' => ['sanitize_callback' => 'sanitize_text_field'],
        'model'=> ['sanitize_callback' => 'sanitize_text_field'],
        'fuel' => ['sanitize_callback' => 'sanitize_text_field'],
        'body' => ['sanitize_callback' => 'sanitize_text_field'],
        'transmission' => ['sanitize_callback' => 'sanitize_text_field'],
        'min_price' => ['sanitize_callback' => 'absint'],
        'max_price' => ['sanitize_callback' => 'absint'],
        'year_from' => ['sanitize_callback' => 'sanitize_text_field'],
        'year_to'   => ['sanitize_callback' => 'sanitize_text_field'],
        'page'      => ['sanitize_callback' => 'absint'],
        'per_page'  => ['sanitize_callback' => 'absint'],
        'sort'      => ['sanitize_callback' => 'sanitize_text_field'],
        'order'     => ['sanitize_callback' => 'sanitize_text_field'],
      ],
    ]);

    register_rest_route('mgest/v1', '/models', [
      'methods'  => 'GET',
      'callback' => [$this, 'rest_models'],
      'permission_callback' => '__return_true',
      'args' => ['make' => ['sanitize_callback' => 'sanitize_text_field']],
    ]);
  }

  /* ----------------- Helpers ----------------- */

  private function norm($s) {
    $s = wp_strip_all_tags((string)$s);
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_D);
    $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
    return $s;
  }
  private function first_val(array $x, array $keys) {
    foreach ($keys as $k) if (isset($x[$k]) && $x[$k] !== '' && $x[$k] !== null) return $x[$k];
    return '';
  }
  private function norm_val(array $x, array $keys) { return $this->norm($this->first_val($x, $keys)); }

  /** build facets (counts) from a set of items */
  private function build_facets(array $items, $selectedMake = ''): array {
    $acc = [
      'makes'=>[], 'models'=>[], 'bodies'=>[], 'fuels'=>[], 'transmissions'=>[], 'years'=>[]
    ];
    $bump = function (&$bucket, $key, $label = null) {
      $key = trim((string)$key);
      if ($key === '') return;
      if (!isset($bucket[$key])) $bucket[$key] = ['key'=>$key, 'value'=>$label ?? $key, 'count'=>0];
      $bucket[$key]['count']++;
    };

    foreach ($items as $x) {
      $make   = $this->first_val($x, ['make','marca']);
      $model  = $this->first_val($x, ['model','modello']);
      $body   = $this->first_val($x, ['vehicle_category','category','carrozzeria']);
      $fuel   = $this->first_val($x, ['fuel_type','fuel','carburante']);
      $trans  = $this->first_val($x, ['transmission_type','drivetrain','traction','trazione','drive']);
      $year   = 0;
      $reg    = $this->first_val($x, ['first_registration_date','registration_date','immatricolazione','year']);
      if ($reg && preg_match('~(\d{4})~', (string)$reg, $m)) $year = (int)$m[1];

      $bump($acc['makes'],  $make,  $make);
      if ($selectedMake === '' || $this->norm($selectedMake) === $this->norm($make)) {
        $bump($acc['models'], $model, $model);
      }
      $bump($acc['bodies'], $body,  $body);
      $bump($acc['fuels'],  $fuel,  $fuel);
      $bump($acc['transmissions'], $trans, $trans);
      if ($year) $bump($acc['years'], $year, $year);
    }
    foreach ($acc as &$grp) { ksort($grp); $grp = array_values($grp); }
    usort($acc['years'], fn($a,$b)=> (int)$b['key'] <=> (int)$a['key']);
    return $acc;
  }

  /* ----------------- Endpoints ----------------- */

  /** GET /mgest/v1/filters – initial facets with caching */
  public function rest_filters(\WP_REST_Request $req) {
    $api = new MGest_API();
    $key = 'mgest_all_facets_v150';
    $facets = get_transient($key);
    if ($facets === false) {
      $items = $api->get_listings([
        'engine' => 'car',
        'limit'  => 150,
        'sort'   => 'insertion_date',
        'invert' => 1,
        'show'   => 'all',
      ]);
      $facets = $this->build_facets($items);
      set_transient($key, $facets, 5 * MINUTE_IN_SECONDS);
    }
    return new \WP_REST_Response([
      'facets' => $facets,
      'price'  => ['min'=>1000, 'max'=>200000],
    ], 200);
  }

  /** GET /mgest/v1/models?make=<make> – cached per make */
  public function rest_models(\WP_REST_Request $req) {
    $make = $req->get_param('make');
    if (!$make) return new \WP_REST_Response([], 200);

    $key = 'mgest_models_' . sanitize_title($make);
    $models = get_transient($key);
    if ($models === false) {
      $api = new MGest_API();
      $models = $api->get_objects('models', 'car', $make);
      set_transient($key, $models, DAY_IN_SECONDS);
    }
    return new \WP_REST_Response($models ?: [], 200);
  }

  /** GET /mgest/v1/listings – cached upstream list, local filter + facets */
  public function rest_listings(\WP_REST_Request $req) {
    $api = new MGest_API();

    $perPage = max(1, (int)$req->get_param('per_page') ?: 12);
    $page    = max(1, (int)$req->get_param('page') ?: 1);

    $sort  = $req->get_param('sort') ?: 'insertion_date';
    $order = strtolower($req->get_param('order')) === 'asc' ? 'asc' : 'desc';
    $invert = ($order === 'asc') ? 0 : 1;

    // Cache the raw upstream list for 60s per sort+order combo
    $cache_key = 'mgest_listings_' . md5(json_encode([$sort,$order]));
    $items = get_transient($cache_key);
    if ($items === false) {
      $items = $api->get_listings([
        'engine' => 'car',
        'limit'  => 150,
        'sort'   => $sort,
        'invert' => $invert,
        'show'   => 'all',
      ]);
      set_transient($cache_key, $items, 60);
    }

    // Requested filters (normalized)
    $wantMake  = $this->norm($req->get_param('make'));
    $wantModel = $this->norm($req->get_param('model'));
    $wantBody  = $this->norm($req->get_param('body'));
    $wantFuel  = $this->norm($req->get_param('fuel'));
    $wantTrans = $this->norm($req->get_param('transmission'));
    $min   = (int)$req->get_param('min_price') ?: 0;
    $max   = (int)$req->get_param('max_price') ?: PHP_INT_MAX;
    $yrFrom= $req->get_param('year_from');
    $yrTo  = $req->get_param('year_to');

    // Local filtering (robust)
    $filtered = array_values(array_filter($items, function($x) use ($wantMake,$wantModel,$wantBody,$wantFuel,$wantTrans,$min,$max,$yrFrom,$yrTo) {
      $rawPrice = $this->first_val($x, ['price','prezzo']);
      $price = (int)preg_replace('/\D+/', '', (string)$rawPrice);
      if (!($price >= $min && $price <= $max)) return false;

      $year = 0;
      $reg = $this->first_val($x, ['first_registration_date','registration_date','immatricolazione','year']);
      if ($reg && preg_match('~(\d{4})~', (string)$reg, $m)) $year = (int)$m[1];
      if ($yrFrom && $year && $year < (int)$yrFrom) return false;
      if ($yrTo   && $year && $year > (int)$yrTo)   return false;

      $valMake  = $this->norm_val($x, ['make','marca']);
      $valModel = $this->norm_val($x, ['model','modello']);
      $valBody  = $this->norm_val($x, ['vehicle_category','category','carrozzeria','body','body_type']);
      $valFuel  = $this->norm_val($x, ['fuel_type','fuel','carburante']);
      $valTrans = $this->norm_val($x, ['transmission_type','drivetrain','traction','trazione','drive','drive_type','wheel_drive']);

      if ($wantMake  !== '' && $valMake  !== $wantMake) return false;
      if ($wantModel !== '' && $valModel !== $wantModel) return false;
      if ($wantBody  !== '' && strpos($valBody,  $wantBody)  === false) return false;
      if ($wantFuel  !== '' && strpos($valFuel,  $wantFuel)  === false) return false;
      if ($wantTrans !== '' && strpos($valTrans, $wantTrans) === false) return false;

      return true;
    }));

    // Facets for THIS filtered set (pre-pagination)
    $selectedMakeLabel = $req->get_param('make') ?: '';
    $facets = $this->build_facets($filtered, $selectedMakeLabel);

    // Pagination
    $total      = count($filtered);
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $paged  = array_slice($filtered, $offset, $perPage);

    return new \WP_REST_Response([
      'total'       => $total,
      'per_page'    => $perPage,
      'page'        => $page,
      'total_pages' => $totalPages,
      'items'       => $paged,
      'facets'      => $facets,
    ], 200);
  }
}

new MGest_Catalog();
