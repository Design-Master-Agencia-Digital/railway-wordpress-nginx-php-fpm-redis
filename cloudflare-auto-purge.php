<?php
/**
 * Plugin Name: Cloudflare Auto Purge
 * Description: Automatically purges Cloudflare cache when WooCommerce products or posts are published/updated.
 * Version: 1.0
 * Author: Design Master
 */

if (!defined('ABSPATH')) exit;

class CF_Auto_Purge {

    private $zone_id;
    private $api_token;
    private $site_url;
    private $purge_queue = [];

    public function __construct() {
        $this->zone_id   = getenv('CLOUDFLARE_ZONE_ID') ?: '';
        $this->api_token = getenv('CLOUDFLARE_API_TOKEN') ?: '';
        $this->site_url  = rtrim(get_option('siteurl'), '/');

        if (empty($this->zone_id) || empty($this->api_token)) return;

        // Product and post publish/update
        add_action('transition_post_status', [$this, 'on_post_status_change'], 10, 3);

        // WooCommerce specific
        add_action('woocommerce_update_product', [$this, 'on_product_update']);
        add_action('edited_product_cat', [$this, 'on_category_update']);
        add_action('edited_product_tag', [$this, 'on_tag_update']);

        // Menu/widget changes
        add_action('wp_update_nav_menu', [$this, 'purge_all_pages']);

        // Execute queued purges at shutdown (batched)
        add_action('shutdown', [$this, 'execute_purge']);
    }

    public function on_post_status_change($new_status, $old_status, $post) {
        // Only act on publish or when coming from/going to publish
        if ($new_status !== 'publish' && $old_status !== 'publish') return;

        $type = get_post_type($post);
        if (!in_array($type, ['product', 'post', 'page'])) return;

        // Purge the post/product URL
        $this->queue_url(get_permalink($post));

        // Purge common pages that list products/posts
        $this->queue_url($this->site_url . '/');
        $this->queue_url($this->site_url . '/loja/');

        // Purge category/tag pages for this product
        if ($type === 'product') {
            $terms = wp_get_post_terms($post->ID, 'product_cat');
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $this->queue_url(get_term_link($term));
                }
            }
        }
    }

    public function on_product_update($product_id) {
        $this->queue_url(get_permalink($product_id));
        $this->queue_url($this->site_url . '/');
        $this->queue_url($this->site_url . '/loja/');
    }

    public function on_category_update($term_id) {
        $this->queue_url(get_term_link((int)$term_id, 'product_cat'));
        $this->queue_url($this->site_url . '/loja/');
        $this->queue_url($this->site_url . '/');
    }

    public function on_tag_update($term_id) {
        $this->queue_url(get_term_link((int)$term_id, 'product_tag'));
    }

    public function purge_all_pages() {
        $this->purge_everything();
    }

    private function queue_url($url) {
        if (is_wp_error($url) || !is_string($url)) return;
        $this->purge_queue[] = $url;
    }

    public function execute_purge() {
        if (empty($this->purge_queue)) return;

        $urls = array_unique(array_slice($this->purge_queue, 0, 30)); // CF limit: 30 URLs per call

        $response = wp_remote_request(
            "https://api.cloudflare.com/client/v4/zones/{$this->zone_id}/purge_cache",
            [
                'method'  => 'POST',
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode(['files' => array_values($urls)]),
            ]
        );

        if (is_wp_error($response)) {
            error_log('[CF Auto Purge] Error: ' . $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                error_log('[CF Auto Purge] Purged ' . count($urls) . ' URLs');
            } else {
                error_log('[CF Auto Purge] API returned ' . $code . ': ' . wp_remote_retrieve_body($response));
            }
        }
    }

    private function purge_everything() {
        wp_remote_request(
            "https://api.cloudflare.com/client/v4/zones/{$this->zone_id}/purge_cache",
            [
                'method'  => 'POST',
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode(['purge_everything' => true]),
            ]
        );
    }
}

new CF_Auto_Purge();
