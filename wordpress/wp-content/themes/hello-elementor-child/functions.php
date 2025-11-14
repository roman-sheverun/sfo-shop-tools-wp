<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if ( !function_exists( 'chld_thm_cfg_locale_css' ) ):
    function chld_thm_cfg_locale_css( $uri ){
        if ( empty( $uri ) && is_rtl() && file_exists( get_template_directory() . '/rtl.css' ) )
            $uri = get_template_directory_uri() . '/rtl.css';
        return $uri;
    }
endif;
add_filter( 'locale_stylesheet_uri', 'chld_thm_cfg_locale_css' );
         
if ( !function_exists( 'child_theme_configurator_css' ) ):
    function child_theme_configurator_css() {
        wp_enqueue_style( 'chld_thm_cfg_child', trailingslashit( get_stylesheet_directory_uri() ) . 'style.css', array( 'hello-elementor','hello-elementor','hello-elementor-theme-style','hello-elementor-header-footer' ) );
    }
endif;
add_action( 'wp_enqueue_scripts', 'child_theme_configurator_css', 9223372036854775807 );



// END ENQUEUE PARENT ACTION


add_shortcode('prd_tabs', 'prd_tabs');
function prd_tabs() {
    global $product;

    $tabs = apply_filters('woocommerce_product_tabs', array());

    $output = '';

    if (!empty($tabs)) {
        $output .= '<div class="product-tabs">';

        foreach ($tabs as $key => $tab) {
            $output .= '<div class="tab-title" data-tab="' . esc_attr($key) . '">' . esc_html($tab['title']) . '<span class="toggle-icon">+</span></div>';

            ob_start(); 
            call_user_func($tab['callback']); 
            $content = ob_get_clean(); 

            $output .= '<div class="tab-content" style="display: none;">' . wp_kses_post($content) . '</div>';
        }

        $output .= '</div>';
    }

    return $output;
}

add_filter( 'woocommerce_product_tabs', 'woo_remove_product_tabs', 98 );

function woo_remove_product_tabs( $tabs ) {
    unset( $tabs['reviews'] );
	unset( $tabs['additional_information'] );
    return $tabs;
}

add_filter( 'woocommerce_product_tabs', 'woo_extra_tab' );

function woo_extra_tab( $tabs ) {

    $tabs['benefit_tab'] = array(
        'title'     => __( 'Shipping & Returns', 'woocommerce' ),
        'priority'  => 110,
        'callback'  => 'woo_shippig_tab_content'

    );
    return $tabs;
}

function woo_shippig_tab_content() {

    echo '<b>Lorem Ipsum</b> is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged. It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum passages, and more recently with desktop publishing software like Aldus PageMaker including versions of Lorem Ipsum.';
}


function custom_currency_switcher() {
    if ( function_exists( 'wc_get_currency_switcher_markup' ) ) {
        $instance = [
            'symbol' => false,
            'flag'   => true,
        ];
        $args = [];
        return wc_get_currency_switcher_markup( $instance, $args );
    }
    return '';
}
//add_shortcode( 'currency_switcher', 'custom_currency_switcher' );


// Register a menu location
function register_custom_menu_location() {
    register_nav_menus( array(
        'primary' => __( 'Primary', 'fry-oil' ),
    ) );
}
add_action( 'after_setup_theme', 'register_custom_menu_location' );

// function replace_menu_item_with_currency_switcher( $item_output, $item, $depth, $args ) {
//     if ( $item->title === 'Currency' && function_exists( 'wc_get_currency_switcher_markup' ) ) {
//         $instance = [
//             'symbol' => true,
//             'flag'   => true,
//         ];
//         $currency_switcher = wc_get_currency_switcher_markup( $instance, [] );

//         // Replace the menu item's output
//         return '<li class="menu-item currency-switcher multi-curr">' . $currency_switcher . '</li>';
//     }

//     return $item_output;
// }
// add_filter( 'walker_nav_menu_start_el', 'replace_menu_item_with_currency_switcher', 10, 4 );

// function replace_menu_item_with_custom_currency_switcher($item_output, $item, $depth, $args) {
//     if ($item->title === 'Currency' && class_exists('WOOMULTI_CURRENCY_F_Data')) {
//         $settings = WOOMULTI_CURRENCY_F_Data::get_ins();
//         $currencies = $settings->get_list_currencies();
//         $current_currency = $settings->get_current_currency();

//         $html = '<li class="menu-item currency-switcher multi-curr">';
//         $html .= '<div class="custom-currency-switcher">';
//         $html .= '<select onchange="window.location.href=this.value;">';

//         foreach ($currencies as $currency => $data) {
//             $url = add_query_arg('wmc-currency', $currency, home_url('/shop-tools/'));
//             $selected = $currency === $current_currency ? 'selected' : '';
//             $html .= sprintf(
//                 '<option value="%s" %s>%s (%s)</option>',
//                 esc_url($url),
//                 $selected,
//                 esc_html($data['name']),
//                 esc_html($currency)
//             );
//         }

//         $html .= '</select>';
//         $html .= '</div>';
//         $html .= '</li>';

//         return $html;
//     }

//     return $item_output;
// }
// add_filter('walker_nav_menu_start_el', 'replace_menu_item_with_custom_currency_switcher', 10, 4);

function replace_menu_item_with_custom_currency_switcher($item_output, $item, $depth, $args) {
    if ($item->title === 'Currency' && class_exists('WOOMULTI_CURRENCY_F_Data')) {
        $settings = WOOMULTI_CURRENCY_F_Data::get_ins();
        $currencies = $settings->get_list_currencies();
        $current_currency = $settings->get_current_currency();

        // Получаем текущий URL
        $current_url = home_url($_SERVER['REQUEST_URI']);
        // Проверяем, содержит ли URL /shop-tools/
        $is_shop_tools_page = strpos($current_url, '/shop-tools/') !== false;

        $html = '<li class="menu-item currency-switcher multi-curr">';
        $html .= '<div class="custom-currency-switcher">';
        $html .= '<select onchange="window.location.href=this.value;">';

        foreach ($currencies as $currency => $data) {
            // Если текущая страница содержит /shop-tools/, используем текущий URL
            if ($is_shop_tools_page) {
                $url = add_query_arg('wmc-currency', $currency, $current_url);
            } else {
                // Иначе используем стандартный URL /shop-tools/
                $url = add_query_arg('wmc-currency', $currency, home_url('/shop-tools/'));
            }
            
            $selected = $currency === $current_currency ? 'selected' : '';
            $html .= sprintf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_url($url),
                $selected,
                esc_html($data['name']),
                esc_html($currency)
            );
        }

        $html .= '</select>';
        $html .= '</div>';
        $html .= '</li>';

        return $html;
    }

    return $item_output;
}
add_filter('walker_nav_menu_start_el', 'replace_menu_item_with_custom_currency_switcher', 10, 4);

// 1. Додаємо чекбокс у вкладку Inventory
add_action('woocommerce_product_options_stock', 'add_custom_checkbox_inventory_tab');
function add_custom_checkbox_inventory_tab() {
    woocommerce_wp_checkbox( array(
        'id'            => '_hide_add_to_cart_button',
        'label'         => __('Hide Add to Cart Button', 'woocommerce'),
        'description'   => __('If checked, the Add to Cart button will be hidden on the product page and catalog.', 'woocommerce'),
    ));
}

// 2. Зберігаємо значення чекбокса
add_action('woocommerce_process_product_meta', 'save_custom_checkbox_inventory_tab');
function save_custom_checkbox_inventory_tab($post_id) {
    $checkbox = isset($_POST['_hide_add_to_cart_button']) ? 'yes' : 'no';
    update_post_meta($post_id, '_hide_add_to_cart_button', $checkbox);
}

// 3. Ховаємо кнопку і форму на сторінці товару (як раніше)
add_action('woocommerce_before_add_to_cart_form', 'conditionally_hide_add_to_cart_button');
function conditionally_hide_add_to_cart_button() {
    global $product;
    if (!$product) return;

    $hide = get_post_meta($product->get_id(), '_hide_add_to_cart_button', true);

    if ($hide === 'yes') {
        echo '<style>
            form.cart,
            .single_add_to_cart_button,
            .e-add-to-cart--show-quantity-yes {
                display: none !important;
            }
        </style>';
    }
}

// 4. Генеруємо JS, що приховує кнопки в каталозі для товарів з увімкненим чекбоксом
add_action('wp_footer', 'hide_add_to_cart_buttons_catalog_js');
function hide_add_to_cart_buttons_catalog_js() {
    if ( ! (is_shop() || is_product_category() || is_product_tag()) ) {
        return; // Виконуємо лише на сторінках каталогу
    }

    global $wp_query;

    if (empty($wp_query->posts)) {
        return;
    }

    $product_ids_to_hide = [];

    foreach ($wp_query->posts as $post) {
        $hide = get_post_meta($post->ID, '_hide_add_to_cart_button', true);
        if ($hide === 'yes') {
            $product_ids_to_hide[] = $post->ID;
        }
    }

    if (empty($product_ids_to_hide)) {
        return;
    }

    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var productIdsToHide = <?php echo json_encode($product_ids_to_hide); ?>;

        productIdsToHide.forEach(function(productId) {
            // Знаходимо кнопки з data-product_id === productId
            var buttons = document.querySelectorAll('.ajax_add_to_cart[data-product_id="' + productId + '"]');

            buttons.forEach(function(btn) {
                // Ховаємо кнопку і, якщо треба, її контейнер
                btn.style.display = 'none';

                // Якщо потрібно, ховаємо батьківський блок (наприклад .uael-woo-products-button-align)
                if(btn.parentElement && btn.parentElement.classList.contains('uael-woo-products-button-align')) {
                    btn.parentElement.style.display = 'none';
                }
            });
        });
    });
    </script>
    <?php
}


/**
 * Сымитировать фронтенд-хук checkout при переводе любого заказа в processing
 */
add_action( 'woocommerce_order_status_processing', 'simulate_checkout_hooks_for_manual_orders', 10, 1 );
function simulate_checkout_hooks_for_manual_orders( $order_id ) {

    $order = wc_get_order( $order_id );
    $posted_data = [];
    do_action( 'woocommerce_checkout_order_processed', $order_id, $posted_data, $order );
    do_action( 'woocommerce_payment_complete', $order_id );
    do_action( 'woocommerce_checkout_update_order_meta', $order_id, $posted_data );
}

////////////////////////////////////
add_action('wp_footer', function () {
    if (is_admin()) return; // не выводим в админке
    ?>
    <!-- Popup markup -->
    <div id="menu-popup" class="mp-overlay" aria-hidden="true">
      <div class="mp-dialog" role="dialog" aria-modal="true" aria-labelledby="mp-title">
        <button type="button" class="mp-close" aria-label="<?php esc_attr_e('Close', 'textdomain'); ?>">×</button>
        <div id="mp-title"><?php esc_html_e('Coming Soon ...', 'textdomain'); ?></div>
      </div>
    </div>

    <script>
    (function(){
      var overlay = document.getElementById('menu-popup');
      if (!overlay) return;
      var closeBtn = overlay.querySelector('.mp-close');
      var titleEl  = overlay.querySelector('#mp-title');

      function openPopup(text){
        if (text) titleEl.textContent = text;
        overlay.classList.add('is-open');
        document.documentElement.classList.add('mp-no-scroll');
      }
      function closePopup(){
        overlay.classList.remove('is-open');
        document.documentElement.classList.remove('mp-no-scroll');
      }

      // Клик по пунктам меню с классом menuPopup (li или a)
      document.addEventListener('click', function(e){
        var trigger = e.target.closest('li.menuPopup, a.menuPopup');
        if (!trigger) return;

        // Блокируем переход по ссылке, если он есть
        var link = trigger.matches('a') ? trigger : trigger.querySelector('a');
        if (link) e.preventDefault();

        // Можно задать свой текст через data-popup-text на li/a (если есть)
        var msg = (trigger.dataset && trigger.dataset.popupText) || titleEl.textContent || 'Coming Soon ...';
        openPopup(msg);
      });

      // Закрытие
      closeBtn.addEventListener('click', closePopup);
      overlay.addEventListener('click', function(e){ if (e.target === overlay) closePopup(); });
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closePopup(); });
    })();
    </script>
    <?php
});

//////////////////////// отмена авторизации ордера
add_filter( 'user_has_cap', function( $allcaps, $caps, $args ) {
    if ( isset( $caps[0], $_GET['key'] ) && $caps[0] === 'pay_for_order' ) {
        $order_id = isset( $args[2] ) ? (int) $args[2] : 0;
        if ( $order_id && wc_get_order( $order_id ) ) {
            $allcaps['pay_for_order'] = true;
        }
    }
    return $allcaps;
}, 9999, 3 );


add_filter( 'woocommerce_order_email_verification_required', '__return_false', 9999 );