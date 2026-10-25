<?php
/** Settings → MVRK Connector: app address, token, test connection, last status; admin notice when unreachable. */
final class Mvrk_Wc_Settings
{
    public static function register(): void
    {
        add_action('admin_menu', function () {
            add_options_page('MVRK Connector', 'MVRK Connector', 'manage_woocommerce', 'mvrk-woo-connector', [self::class, 'page']);
        });
        add_action('admin_init', [self::class, 'settings']);
        add_action('admin_post_mvrk_wc_test', [self::class, 'test']);
        add_action('admin_notices', [self::class, 'notice']);
    }

    public static function settings(): void
    {
        register_setting('mvrk_wc', Mvrk_Wc_Plugin::OPT_URL, ['type' => 'string', 'sanitize_callback' => fn ($v) => esc_url_raw(rtrim(trim((string) $v), '/'), ['https'])]);
        register_setting('mvrk_wc', Mvrk_Wc_Plugin::OPT_TOKEN, ['type' => 'string', 'sanitize_callback' => fn ($v) => trim((string) $v) !== '' ? sanitize_text_field($v) : (string) get_option(Mvrk_Wc_Plugin::OPT_TOKEN, '')]);
        register_setting('mvrk_wc', Mvrk_Wc_Plugin::OPT_AUTO, ['type' => 'boolean', 'default' => true, 'sanitize_callback' => fn ($v) => (bool) $v]);
        register_setting('mvrk_wc', Mvrk_Wc_Plugin::OPT_ENQUIRY, ['type' => 'boolean', 'default' => true, 'sanitize_callback' => fn ($v) => (bool) $v]);
    }

    public static function page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $status = get_option(Mvrk_Wc_Plugin::OPT_STATUS);
        $token = (string) get_option(Mvrk_Wc_Plugin::OPT_TOKEN, '');
        ?>
        <div class="wrap">
            <h1>MVRK Connector</h1>
            <p>Products, prices, stock and orders are synced by the ERP. This plugin only adds <strong>live showroom availability</strong> on product pages (block “Available at our showrooms” or shortcode <code>[mvrk_showroom_availability]</code>) and the optional <strong>Reserve to view in showroom</strong> form.</p>
            <form method="post" action="options.php">
                <?php settings_fields('mvrk_wc'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="mvrk_url">App address</label></th>
                        <td><input id="mvrk_url" class="regular-text" type="url" name="<?php echo esc_attr(Mvrk_Wc_Plugin::OPT_URL); ?>" value="<?php echo esc_attr((string) get_option(Mvrk_Wc_Plugin::OPT_URL, '')); ?>" placeholder="https://erp.example.lk" required>
                        <p class="description">The ERP’s https:// address.</p></td></tr>
                    <tr><th scope="row"><label for="mvrk_token">Connection token</label></th>
                        <td><input id="mvrk_token" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr(Mvrk_Wc_Plugin::OPT_TOKEN); ?>" value="" placeholder="<?php echo $token ? '•••• saved (leave blank to keep)' : 'Paste the token'; ?>">
                        <p class="description">Made on the ERP server with <code>php artisan woo:storefront-token</code>. It can only read availability and send showroom requests.</p></td></tr>
                    <tr><th scope="row">Product pages</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(Mvrk_Wc_Plugin::OPT_AUTO); ?>" value="1" <?php checked((bool) get_option(Mvrk_Wc_Plugin::OPT_AUTO, true)); ?>> Show availability under the Add to cart button automatically</label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr(Mvrk_Wc_Plugin::OPT_ENQUIRY); ?>" value="1" <?php checked((bool) get_option(Mvrk_Wc_Plugin::OPT_ENQUIRY, true)); ?>> Offer “Reserve to view in showroom”</label></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mvrk_wc_test">
                <?php wp_nonce_field('mvrk_wc_test'); ?>
                <?php submit_button('Test connection', 'secondary'); ?>
            </form>
            <?php if (is_array($status)) { ?>
                <p><strong>Last check:</strong> <?php echo $status['ok'] ? '✅ ' : '⚠️ ';
                echo esc_html($status['message']); ?> (<?php echo esc_html(human_time_diff((int) $status['at'])); ?> ago)</p>
            <?php } ?>
            <?php if (MVRK_WC_BRAND['credit']) { ?>
                <p class="description">MVRK Woo Connector <?php echo esc_html(MVRK_WC_VERSION); ?> by <?php echo esc_html(MVRK_WC_BRAND['name']); ?> · <?php echo esc_html(MVRK_WC_BRAND['website']); ?></p>
            <?php } ?>
        </div>
        <?php
    }

    public static function test(): void
    {
        if (! current_user_can('manage_woocommerce') || ! check_admin_referer('mvrk_wc_test')) {
            wp_die('Not allowed.');
        }
        $result = Mvrk_Wc_Plugin::api()->ping();
        Mvrk_Wc_Plugin::remember($result['ok'], $result['ok'] ? 'Connected to '.($result['data']['app'] ?? 'the app').'.' : (string) $result['error']);
        wp_safe_redirect(admin_url('options-general.php?page=mvrk-woo-connector'));
        exit;
    }

    /** Admins see a notice while the last call to the app failed (checked at most every 15 minutes). */
    public static function notice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $status = get_option(Mvrk_Wc_Plugin::OPT_STATUS);
        if (! is_array($status) || time() - (int) $status['at'] > 900) {
            $result = Mvrk_Wc_Plugin::api()->ping();
            Mvrk_Wc_Plugin::remember($result['ok'], $result['ok'] ? 'Connected.' : (string) $result['error']);
            $status = get_option(Mvrk_Wc_Plugin::OPT_STATUS);
        }
        if (is_array($status) && ! $status['ok']) {
            printf('<div class="notice notice-warning"><p><strong>MVRK Connector:</strong> %s <a href="%s">Settings</a></p></div>', esc_html($status['message']), esc_url(admin_url('options-general.php?page=mvrk-woo-connector')));
        }
    }
}
