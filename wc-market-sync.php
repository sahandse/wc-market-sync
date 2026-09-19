<?php
/**
 * Plugin Name: همگام‌سازی بازار ووکامرس
 * Plugin URI: https://github.com/sahandse/wc-market-sync
 * Description: همگام‌سازی محصولات، قیمت، موجودی و سفارش‌های ووکامرس با باسلام و ترب.
 * Version: 1.3.0
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: wc-market-sync
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

final class WCMS_Plugin {
    const VERSION = '1.3.0';
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
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_post_wcms_test_basalam', [$this, 'test_basalam']);
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
            'torob_feed_secret' => '',
            'basalam_vendor_id' => '',
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
            'torob_feed_secret' => sanitize_key($in['torob_feed_secret'] ?? '') ?: wp_generate_password(24,false,false),
            'basalam_vendor_id' => absint($in['basalam_vendor_id'] ?? 0),
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
            <?php if(!empty($_GET['wcms_notice'])):?><div class="notice notice-info"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['wcms_notice'])))); ?></p></div><?php endif; ?>
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
                        <label>شناسه غرفه باسلام
                            <input type="number" min="0" name="<?php echo self::OPTION; ?>[basalam_vendor_id]" value="<?php echo esc_attr($s['basalam_vendor_id']); ?>">
                        </label>
                        <label>درصد تغییر قیمت باسلام
                            <input type="number" step="0.01" name="<?php echo self::OPTION; ?>[basalam_markup]" value="<?php echo esc_attr($s['basalam_markup']); ?>">
                        </label>
                        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wcms_test_basalam'),'wcms_test_basalam')); ?>">تست اتصال باسلام</a></p>

                        <hr>

                        <label class="wcms-switch"><span>ترب</span><input type="checkbox" name="<?php echo self::OPTION; ?>[enabled_torob]" value="1" <?php checked($s['enabled_torob'],'yes'); ?>></label>
                        <label>کلید/شناسه ترب
                            <input type="password" name="<?php echo self::OPTION; ?>[torob_key]" value="<?php echo esc_attr($s['torob_key']); ?>" autocomplete="off">
                        </label>
                        <label>درصد تغییر قیمت ترب
                            <input type="number" step="0.01" name="<?php echo self::OPTION; ?>[torob_markup]" value="<?php echo esc_attr($s['torob_markup']); ?>">
                        </label>
                        <label>Secret فید ترب
                            <input type="text" name="<?php echo self::OPTION; ?>[torob_feed_secret]" value="<?php echo esc_attr($s['torob_feed_secret']); ?>">
                        </label>
                        <p><strong>آدرس فید ترب:</strong><br><code><?php echo esc_html(rest_url('wcms/v1/torob/products?key=' . ($s['torob_feed_secret'] ?: 'SAVE-FIRST'))); ?></code></p>
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
                        <p>فید زنده محصولات ترب از WooCommerce فعال است و حذف/تغییر محصول در خروجی همان لحظه منعکس می‌شود. باسلام با Bearer Token رسمی تست و شناسایی غرفه می‌شود؛ نوشتن محصول در باسلام فقط پس از داشتن دسترسی OAuth مناسب انجام می‌شود.</p>
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
    }    public function register_rest_routes() {
        register_rest_route('wcms/v1','/torob/products',[
            'methods'=>['GET','POST'],
            'callback'=>[$this,'torob_products_endpoint'],
            'permission_callback'=>'__return_true'
        ]);
    }

    private function torob_authorized(WP_REST_Request $request) {
        $s=$this->settings();
        $secret=(string)$s['torob_feed_secret'];
        if(!$secret) return false;
        $provided=(string)($request->get_param('key') ?: $request->get_header('X-WCMS-Key'));
        return $provided && hash_equals($secret,$provided);
    }

    private function product_to_torob($product) {
        $s=$this->settings();
        $price=(float)$product->get_price();
        $old=(float)$product->get_regular_price();
        $markup=(float)$s['torob_markup'];
        if($markup) { $price *= (1+$markup/100); if($old) $old *= (1+$markup/100); }
        if('rial'===$s['currency_mode']) { $price*=10; if($old)$old*=10; }

        $images=[];
        if($product->get_image_id()) $images[]=wp_get_attachment_image_url($product->get_image_id(),'full');
        foreach($product->get_gallery_image_ids() as $id){ $u=wp_get_attachment_image_url($id,'full'); if($u)$images[]=$u; }

        $cats=wp_get_post_terms($product->get_id(),'product_cat',['fields'=>'names']);
        $stock=$product->managing_stock() ? (int)$product->get_stock_quantity() : ($product->is_in_stock()?1:0);

        return [
            'page_unique'=>(string)$product->get_id(),
            'page_url'=>get_permalink($product->get_id()),
            'title'=>$product->get_name(),
            'price'=>$price>0?(int)round($price):null,
            'old_price'=>$old>$price?(int)round($old):null,
            'availability'=>$product->is_in_stock(),
            'stock'=>$stock,
            'image_urls'=>array_values(array_filter($images)),
            'category'=>is_array($cats)&&$cats?implode(' > ',$cats):'',
            'date_added'=>get_post_time('c',true,$product->get_id()),
            'date_updated'=>get_post_modified_time('c',true,$product->get_id()),
            'product_group_id'=>$product->is_type('variation')?(string)$product->get_parent_id():null,
            'sku'=>$product->get_sku(),
        ];
    }

    public function torob_products_endpoint(WP_REST_Request $request) {
        if(!$this->torob_authorized($request)) return new WP_REST_Response(['error'=>'unauthorized'],401);

        $page=max(1,(int)($request->get_param('page')?:1));
        $per_page=min(100,max(1,(int)($request->get_param('page_size')?:50)));
        $unique=$request->get_param('page_unique');
        $url=$request->get_param('page_url');

        $args=['status'=>['publish'],'limit'=>$per_page,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC'];
        if($unique) $args['include']=[absint($unique)];
        $result=wc_get_products($args);

        $products=[];
        foreach((array)$result->products as $product){
            if($url && untrailingslashit(get_permalink($product->get_id()))!==untrailingslashit(esc_url_raw($url))) continue;
            $products[]=$this->product_to_torob($product);
            if('yes'===$this->settings()['sync_variations'] && $product->is_type('variable')){
                foreach($product->get_children() as $vid){
                    $v=wc_get_product($vid); if($v) $products[]=$this->product_to_torob($v);
                }
            }
        }

        return [
            'count'=>(int)$result->total,
            'page'=>$page,
            'page_size'=>$per_page,
            'has_more'=>$page < (int)$result->max_num_pages,
            'products'=>$products,
        ];
    }

    private function basalam_request($path,$method='GET',$body=null) {
        $token=$this->settings()['basalam_token'];
        if(!$token) return new WP_Error('wcms_basalam_auth','توکن باسلام وارد نشده است.');
        $args=[
            'method'=>$method,'timeout'=>20,
            'headers'=>['Accept'=>'application/json','Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json']
        ];
        if(null!==$body) $args['body']=wp_json_encode($body);
        $res=wp_remote_request('https://core.basalam.com'.$path,$args);
        if(is_wp_error($res)) return $res;
        $code=(int)wp_remote_retrieve_response_code($res);
        $json=json_decode(wp_remote_retrieve_body($res),true);
        if($code<200||$code>=300) return new WP_Error('wcms_basalam_http','خطای باسلام HTTP '.$code);
        return is_array($json)?$json:[];
    }

    public function test_basalam() {
        if(!current_user_can('manage_woocommerce')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('wcms_test_basalam');
        $data=$this->basalam_request('/v3/users/me');
        if(is_wp_error($data)) $msg=$data->get_error_message();
        else {
            $vendor_id=(int)($data['vendor']['id']??0);
            if($vendor_id){
                $opt=(array)get_option(self::OPTION,[]);
                $opt['basalam_vendor_id']=$vendor_id;
                update_option(self::OPTION,$opt,false);
            }
            $msg='اتصال باسلام موفق است'.($vendor_id?' — غرفه #'.$vendor_id:'');
        }
        wp_safe_redirect(add_query_arg(['page'=>'wc-market-sync','wcms_notice'=>rawurlencode($msg)],admin_url('admin.php'))); exit;
    }


}

new WCMS_Plugin();
