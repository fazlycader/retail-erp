<?php

/**
 * "Available at our showrooms": a block, a shortcode and (optionally) an automatic spot on product
 * pages. The page only holds an empty placeholder; the browser fetches the answer by AJAX, so page
 * caching can never show stale stock. Answers are cached 5 minutes in a transient per SKU.
 */
final class Mvrk_Wc_Availability
{
    private const CACHE_SECONDS = 300;

    public static function register(): void
    {
        add_shortcode('mvrk_showroom_availability', [self::class, 'render']);
        add_action('init', function () {
            register_block_type(MVRK_WC_DIR.'blocks/availability', ['render_callback' => fn ($attrs) => self::render((array) $attrs)]);
        });
        add_action('woocommerce_single_product_summary', function () {
            if ((bool) get_option(Mvrk_Wc_Plugin::OPT_AUTO, true)) {
                echo self::render([]); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render()
            }
        }, 35);
        add_action('wp_ajax_mvrk_availability', [self::class, 'ajaxAvailability']);
        add_action('wp_ajax_nopriv_mvrk_availability', [self::class, 'ajaxAvailability']);
        add_action('wp_ajax_mvrk_enquiry', [self::class, 'ajaxEnquiry']);
        add_action('wp_ajax_nopriv_mvrk_enquiry', [self::class, 'ajaxEnquiry']);
    }

    /** @param array<string, mixed> $attrs */
    public static function render(array $attrs = []): string
    {
        $sku = (string) ($attrs['sku'] ?? '');
        if ($sku === '' && function_exists('wc_get_product')) {
            $product = wc_get_product(get_the_ID());
            $sku = $product ? (string) $product->get_sku() : '';
            $leadTime = $product ? (string) $product->get_meta('mvrk_lead_time') : '';
        }

        wp_enqueue_style('mvrk-wc-availability', MVRK_WC_URL.'assets/availability.css', [], MVRK_WC_VERSION);
        wp_enqueue_script('mvrk-wc-availability', MVRK_WC_URL.'assets/availability.js', [], MVRK_WC_VERSION, true);
        wp_localize_script('mvrk-wc-availability', 'mvrkWc', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mvrk_wc'),
            'enquiry' => (bool) get_option(Mvrk_Wc_Plugin::OPT_ENQUIRY, true),
        ]);

        return sprintf(
            '<div class="mvrk-availability" data-sku="%s" aria-live="polite"><p class="mvrk-availability__title">%s</p>%s<div class="mvrk-availability__list"><span class="mvrk-availability__muted">%s</span></div></div>',
            esc_attr($sku),
            esc_html__('Available at our showrooms', 'mvrk-woo-connector'),
            ! empty($leadTime) ? '<p class="mvrk-availability__lead">'.esc_html($leadTime).'</p>' : '',
            esc_html__('Checking…', 'mvrk-woo-connector'),
        );
    }

    public static function ajaxAvailability(): void
    {
        $sku = sanitize_text_field(wp_unslash($_GET['sku'] ?? ''));
        $key = 'mvrk_wc_av_'.md5($sku);
        $cached = get_transient($key);

        if ($cached === false) {
            $result = Mvrk_Wc_Plugin::api()->availability($sku);
            if (! $result['ok'] && $result['status'] !== 404) {
                Mvrk_Wc_Plugin::remember(false, (string) $result['error']);
                wp_send_json_error(['message' => __('Availability is not available right now.', 'mvrk-woo-connector')], 503);
            }
            $cached = $result['ok'] ? $result['data'] : ['locations' => []];
            set_transient($key, $cached, self::CACHE_SECONDS);
        }

        nocache_headers();
        wp_send_json_success($cached);
    }

    public static function ajaxEnquiry(): void
    {
        check_ajax_referer('mvrk_wc', 'nonce');
        if (! get_option(Mvrk_Wc_Plugin::OPT_ENQUIRY, true) || ! empty($_POST['website'])) {
            wp_send_json_error(['message' => 'Not available.'], 400);
        }

        $fields = array_map(fn ($v) => sanitize_text_field(wp_unslash((string) $v)), array_intersect_key($_POST, array_flip(['name', 'phone', 'email', 'sku', 'location', 'preferred_date', 'message'])));
        $result = Mvrk_Wc_Plugin::api()->enquiry($fields, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        $result['ok']
            ? wp_send_json_success(['message' => (string) ($result['data']['message'] ?? __('Thank you. We will call you to confirm.', 'mvrk-woo-connector'))])
            : wp_send_json_error(['message' => (string) $result['error']], $result['status'] ?: 503);
    }
}
