<?php
/**
 * Plugin Name: همگام‌سازی بازار ووکامرس
 * Plugin URI: https://github.com/sahandse/wc-market-sync
 * Description: همگام‌سازی محصولات، قیمت، موجودی و سفارش‌های ووکامرس با باسلام و ترب.
 * Version: 1.2.1
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: wc-market-sync
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

final class WCMS_Plugin {
    const VERSION = '1.2.1';
    const OPTION  = 'wcms_settings';

    public function __construct() {
        add_action('before_woocommerce_init', [$this, 'declare_hpos']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function declare_hpos() {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    public function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('save_post_product', [$this, 'queue_product_sync'], 20, 3);
        add_action('woocommerce_product_set_stock', [$this, 'queue_stock_sync']);
        add_action('before_delete_post', [$this, 'queue_delete_sync']);
    }

    public function woocommerce_notice() {
        echo '<div class="notice notice-error"><p>افزونه همگام‌سازی بازار برای اجرا به WooCommerce نیاز دارد.</p></div>';
    }

    public function defaults() {
        return [
            'enabled_basalam' => 'yes',
            'enabled_torob' => 'yes',
            'basalam_markup' => 0,
            'torob_markup' => 0,
            'currency_mode' => 'toman',
            'sync_stock' => 'yes',
            'sync_price' => 'yes',
            'sync_orders' => 'yes',
            'sync_images' => 'yes',
            'sync_variations' => 'yes',
            'accent' => '#111827',
            'basalam_token' => '',
            'torob_key' => '',
        ];
    }

    public function settings() {
        return wp_parse_args((array)get_option(self::OPTION, []), $this->defaults());
    }

    public function register_settings() {
        register_setting('wcms_group', self::OPTION, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($in) {
        $d = $this->defaults();
        return [
            'enabled_basalam' => !empty($in['enabled_basalam']) ? 'yes' : 'no',
            'enabled_torob' => !empty($in['enabled_torob']) ? 'yes' : 'no',
            'basalam_markup' => max(-99, min(1000, (float)($in['basalam_markup'] ?? 0))),
            'torob_markup' => max(-99, min(1000, (float)($in['torob_markup'] ?? 0))),
            'currency_mode' => in_array($in['currency_mode'] ?? '', ['rial','toman'], true) ? $in['currency_mode'] : $d['currency_mode'],
            'sync_stock' => !empty($in['sync_stock']) ? 'yes' : 'no',
            'sync_price' => !empty($in['sync_price']) ? 'yes' : 'no',
            'sync_orders' => !empty($in['sync_orders']) ? 'yes' : 'no',
            'sync_images' => !empty($in['sync_images']) ? 'yes' : 'no',
            'sync_variations' => !empty($in['sync_variations']) ? 'yes' : 'no',
            'accent' => sanitize_hex_color($in['accent'] ?? '') ?: $d['accent'],
            'basalam_token' => sanitize_text_field($in['basalam_token'] ?? ''),
            'torob_key' => sanitize_text_field($in['torob_key'] ?? ''),
        ];
    }

    public function admin_menu() {
        if (function_exists('s_store_register_submenu')) {
            s_store_register_submenu('wc-market-sync', 'همگام‌سازی بازار', [$this, 'settings_page'], 'manage_woocommerce', 'همگام‌سازی بازار');
            return;
        }
        add_submenu_page(
            'woocommerce',
            'همگام‌سازی بازار',
            'همگام‌سازی بازار',
            'manage_woocommerce',
            'wc-market-sync',
            [$this, 'settings_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'wc-market-sync')) return;
        wp_enqueue_style('wcms-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = $this->settings();
        ?>
        <div class="wrap wcms-admin">
            <div class="wcms-hero">
                <div>
                    <h1>همگام‌سازی بازار ووکامرس</h1>
                    <p>مدیریت همگام‌سازی محصولات، قیمت، موجودی و سفارش‌ها با باسلام و ترب.</p>
                </div>
                <span>v<?php echo esc_html(self::VERSION); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('wcms_group'); ?>
                <div class="wcms-grid">
                    <section class="wcms-card">
                        <h2>بازارها</h2>
                        <label class="wcms-switch"><span>باسلام</span><input type="checkbox" name="<?php echo self::OPTION; ?>[enabled_basalam]" value="1" <?php checked($s['enabled_basalam'],'yes'); ?>></label>
                        <label>توکن باسلام
                            <input type="password" name="<?php echo self::OPTION; ?>[basalam_token]" value="<?php echo esc_attr($s['basalam_token']); ?>" autocomplete="off">
                        </label>
                        <label>درصد تغییر قیمت باسلام
                            <input type="number" step="0.01" name="<?php echo self::OPTION; ?>[basalam_markup]" value="<?php echo esc_attr($s['basalam_markup']); ?>">
                        </label>

                        <hr>

                        <label class="wcms-switch"><span>ترب</span><input type="checkbox" name="<?php echo self::OPTION; ?>[enabled_torob]" value="1" <?php checked($s['enabled_torob'],'yes'); ?>></label>
                        <label>کلید/شناسه ترب
                            <input type="password" name="<?php echo self::OPTION; ?>[torob_key]" value="<?php echo esc_attr($s['torob_key']); ?>" autocomplete="off">
                        </label>
                        <label>درصد تغییر قیمت ترب
                            <input type="number" step="0.01" name="<?php echo self::OPTION; ?>[torob_markup]" value="<?php echo esc_attr($s['torob_markup']); ?>">
                        </label>
                    </section>

                    <section class="wcms-card">
                        <h2>همگام‌سازی</h2>
                        <label class="wcms-switch"><span>موجودی</span><input type="checkbox" name="<?php echo self::OPTION; ?>[sync_stock]" value="1" <?php checked($s['sync_stock'],'yes'); ?>></label>
                        <label class="wcms-switch"><span>قیمت</span><input type="checkbox" name="<?php echo self::OPTION; ?>[sync_price]" value="1" <?php checked($s['sync_price'],'yes'); ?>></label>
                        <label class="wcms-switch"><span>سفارش‌های باسلام</span><input type="checkbox" name="<?php echo self::OPTION; ?>[sync_orders]" value="1" <?php checked($s['sync_orders'],'yes'); ?>></label>
                        <label class="wcms-switch"><span>تصاویر</span><input type="checkbox" name="<?php echo self::OPTION; ?>[sync_images]" value="1" <?php checked($s['sync_images'],'yes'); ?>></label>
                        <label class="wcms-switch"><span>محصولات متغیر/رنگ/سایز</span><input type="checkbox" name="<?php echo self::OPTION; ?>[sync_variations]" value="1" <?php checked($s['sync_variations'],'yes'); ?>></label>
                    </section>

                    <section class="wcms-card">
                        <h2>واحد قیمت</h2>
                        <label>تبدیل قیمت
                            <select name="<?php echo self::OPTION; ?>[currency_mode]">
                                <option value="toman" <?php selected($s['currency_mode'],'toman'); ?>>تومان</option>
                                <option value="rial" <?php selected($s['currency_mode'],'rial'); ?>>ریال</option>
                            </select>
                        </label>
                    </section>

                    <section class="wcms-card">
                        <h2>ظاهر</h2>
                        <label>رنگ اصلی
                            <input type="color" name="<?php echo self::OPTION; ?>[accent]" value="<?php echo esc_attr($s['accent']); ?>">
                        </label>
                    </section>

                    <section class="wcms-card wcms-wide">
                        <h2>منطق همگام‌سازی</h2>
                        <p>ساختار صف همگام‌سازی برای تغییر محصول، موجودی و حذف آماده است. اتصال نهایی به API رسمی باسلام/ترب و نگاشت دسته‌بندی گرافیکی در نسخه‌های بعدی همین Repo تکمیل می‌شود.</p>
                    </section>
                </div>

                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
        </div>
        <?php
    }

    public function queue_product_sync($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || 'product' !== $post->post_type) return;
        update_post_meta($post_id, '_wcms_sync_pending', current_time('mysql'));
    }

    public function queue_stock_sync($product) {
        if (!$product || !is_a($product, 'WC_Product')) return;
        update_post_meta($product->get_id(), '_wcms_stock_sync_pending', current_time('mysql'));
    }

    public function queue_delete_sync($post_id) {
        if ('product' !== get_post_type($post_id)) return;
        update_option('wcms_last_deleted_product', [
            'product_id' => (int)$post_id,
            'time' => current_time('mysql'),
        ], false);
    }
}

new WCMS_Plugin();
