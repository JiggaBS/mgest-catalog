<?php
if (!defined('ABSPATH')) exit;

class MGest_API {
  private $base = 'https://motori.multigestionale.com/api/xml/';
  private $cc;

  public function __construct() {
    // Preferred: define('MGEST_CC', 'YOUR_API_CODE') in wp-config.php
    if (defined('MGEST_CC') && MGEST_CC) {
      $this->cc = MGEST_CC;
      return;
    }
    // Fallback: environment var
    $env = getenv('MGEST_CC');
    if (!empty($env)) {
      $this->cc = $env;
      return;
    }
    // 3) Hard-coded fallback (set it here)
    $this->cc = 'MG4703-ZT4703XXWUQLU'; // ← REPLACE with your real User API (cc)
    
    // Final fallback: try official plugin options
    if (empty($this->cc)) {
      $this->cc = get_option('wp_mgest_user_api');
      if (!$this->cc) {
        $settings = get_option('wp_mgest_settings');
        if (is_array($settings) && !empty($settings['user_api'])) {
          $this->cc = $settings['user_api'];
        }
      }
    }
  }

  private function get_cc() {
    return $this->cc ?: '';
  }

  public function get_objects($obj, $engine = 'car', $make_id = null) {
    $url = $this->base . 'getObjects.php';
    $args = ['obj' => $obj, 'engine' => $engine];
    if ($make_id) $args['make_id'] = $make_id;

    $res = wp_remote_get(add_query_arg($args, $url), ['timeout' => 15]);
    if (is_wp_error($res)) return [];
    $xml = @simplexml_load_string(wp_remote_retrieve_body($res));
    if (!$xml) return [];

    $out = [];
    foreach ($xml->children() as $el) {
      $out[] = [
        'key'   => isset($el->key) ? (string)$el->key : '',
        'value' => isset($el->value) ? (string)$el->value : '',
      ];
    }
    return $out;
  }

  public function get_listings($params = []) {
    $url = $this->base;
    $query = array_merge([
      'cc'     => $this->get_cc(),
      'engine' => 'car',
      'show'   => 'all',
      'limit'  => 50,
      'sort'   => 'insertion_date',
      'invert' => 1,
    ], $params);

    $res = wp_remote_get(add_query_arg($query, $url), ['timeout' => 20]);
    if (is_wp_error($res)) return [];

    $xml = @simplexml_load_string(wp_remote_retrieve_body($res));
    if (!$xml) return [];

    $items = [];
    foreach ($xml->children() as $el) {
      $items[] = [
        'ad_number' => (string)($el->ad_number ?? ''),
        'title'     => (string)($el->title ?? ''),
        'sub_title' => (string)($el->sub_title ?? ''),
        'make'      => (string)($el->make ?? ''),
        'model'     => (string)($el->model ?? ''),
        'version'   => (string)($el->version ?? ''),
        'vehicle_class'     => (string)($el->vehicle_class ?? ''),
        'vehicle_category'  => (string)($el->vehicle_category ?? ''),
        'first_registration_date' => (string)($el->first_registration_date ?? ''),
        'mileage'   => (string)($el->mileage ?? ''),
        'power_kw'  => (string)($el->power_kw ?? ''),
        'power_cv'  => (string)($el->power_cv ?? ''),
        'transmission_type' => (string)($el->transmission_type ?? ''),
        'fuel_type' => (string)($el->fuel_type ?? ''),
        'color'     => (string)($el->color ?? ''),
        'price'     => (string)($el->price ?? ''),
        'images_number' => (int)($el->images_number ?? 0),
        'images'    => $this->extract_images($el),
        'last_update' => (string)($el->last_update ?? ''),
        'link_ads'  => (string)($el->link_ads ?? ''),
        'company_logo' => (string)($el->company_logo ?? ''),
        'description'  => (string)($el->description ?? ''),
        'optionals'     => $this->extract_optionals($el), // for modal
        'dealer_name'   => (string)($el->name ?? ''),
      ];
    }
    return $items;
  }

  private function extract_images($el) {
    $out = [];
    if (!empty($el->images) && $el->images->children()) {
      foreach ($el->images->children() as $img) {
        $out[] = (string)$img;
      }
    }
    return $out;
  }

  private function extract_optionals($el){
    $out = [];
    if (!empty($el->optionals) && $el->optionals->children()){
      foreach ($el->optionals->children() as $opt){
        $txt = trim((string)$opt);
        if ($txt !== '') $out[] = $txt;
      }
    }
    return $out;
  }
}
