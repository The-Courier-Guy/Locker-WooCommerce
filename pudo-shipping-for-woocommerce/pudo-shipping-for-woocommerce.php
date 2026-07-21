<?php

/**
 * Plugin Name: The Courier Guy Locker Shipping for WooCommerce
 * Description: The Courier Guy Locker WP & Woocommerce Shipping functionality.
 * Author: The Courier Guy
 * Author URI: https://www.thecourierguy.co.za/
 * Version: 1.7.0
 * Plugin Slug: wp-plugin-pudo-for-wc
 * Text Domain: pudo-shipping-for-woocommerce
 * WP tested up to: 6.9
 * WC requires at least: 9.0
 * WC tested up to: 10.6
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

use Pudo\WooCommerce\Pudo_Shipping_Method;
use Pudo\WooCommerce\PudoApi;

/**
 *  Copyright: © 2025 The Courier Guy
 */
// Ensure the WP Absolute path is defined
if (!defined('ABSPATH')) {
    exit;
}
// Include Pudo-Core
require_once 'Includes/ls-framework-custom/Core/PudoPluginDependencies.php';
require_once 'Includes/ls-framework-custom/Core/PudoPlugin.php';
require_once 'Includes/ls-framework-custom/Core/PudoPostType.php';

require_once 'vendor/autoload.php';

// Register and activation of plugin
// Dependent on WooCommerce being installed
register_activation_hook(__FILE__, 'pudo_plugin_activated');
add_action('admin_init', 'pudo_plugin_registered');

// Shipping actions

function pudo_plugin_activated()
{
    add_option('pudoMsg', '1', '', true);
}

// Autoload plugin classes (Include classes)
spl_autoload_register(
    function ($class) {
        $parts      = explode('\\', $class);
        $class_path = plugin_dir_path(__FILE__) . 'classes/';
        $file_path  = $class_path . end($parts) . '.php';
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
);

// Initialise the plugin
pudo_wc_pudo_shipping_init();

add_action('wp_ajax_store_locker_code', 'pudo_store_locker_code_in_session', 10, 1);
add_action('wp_ajax_nopriv_store_locker_code', 'pudo_store_locker_code_in_session', 10, 1);

/**
 * Clear cached package rates so WooCommerce re-runs shipping methods.
 *
 * This is required for Blocks locker updates because locker code is not part
 * of WooCommerce's native shipping package hash.
 *
 * @return void
 */
function pudo_clear_shipping_package_cache(): void
{
    if (!WC()->cart || !WC()->session) {
        return;
    }

    $packages = WC()->cart->get_shipping_packages();
    foreach (array_keys($packages) as $package_index) {
        WC()->session->__unset('shipping_for_package_' . $package_index);
    }
}

add_action('woocommerce_blocks_loaded', function () {
    
    woocommerce_store_api_register_update_callback([
        'namespace' => 'pudo-shipping-for-woocommerce',
        'callback'  => function ($data) {
            $logger = wc_get_logger();
            if (in_array(
                    strtolower($data['pudo_method'] ?? ''),
                    ['d2l-pudo', 'l2l-pudo'],
                    true
                ) && empty($data['locker_code'])) {
                WC()->session->set('chosen_shipping_methods', array());

                return;
            }

            $wcSession   = WC()->session;
            $dataSession = $wcSession->get_session_data();
            $logger      = wc_get_logger();
            $logger->info(
                sprintf(
                    'Session data in callback: %s',
                    json_encode($dataSession)
                ),
                array(
                    'source' => 'pudo-for-wc',
                )
            );

            $order_id   = WC()->session->get('store_api_draft_order');
            $lockerCode = sanitize_text_field($data['locker_code']);
            $pudoMethod = sanitize_text_field($data['pudo_method'] ?? '');
            $order      = wc_get_order($order_id);
            // Order is false if for some reason it cannot be found
            if ($order) {
                $order->update_meta_data('pudo_locker_destination', $lockerCode);
                $order->update_meta_data('pudo_method', $pudoMethod);
                $order->save_meta_data();
                $order->save();
            }

            WC()->session->set(
                'pudo_locker_destination',
                $lockerCode
            );
            WC()->session->set(
                'pudo-destination-locker-set-by-blocks',
                $lockerCode
            );

            pudo_clear_shipping_package_cache();
            WC()->cart->calculate_shipping();

            $packages = WC()->shipping->get_packages();
            $chosen   = WC()->session->get('chosen_shipping_methods', array());
            foreach ($packages as $package_index => $package) {
                foreach ($package['rates'] as $rate_id => $rate) {
                    if ((str_starts_with($pudoMethod, 'd2l') || str_starts_with(
                                $pudoMethod,
                                'l2l'
                            )) && (str_starts_with(
                                       $rate_id,
                                       'd2l'
                                   ) || str_starts_with(
                                       $rate_id,
                                       'l2l'
                                   ) || str_contains($rate_id, ':D2L') || str_contains($rate_id, ':L2L'))) {
                        $chosen[$package_index] = $rate_id;
                        break;
                    }
                }
            }

            WC()->session->set('chosen_shipping_methods', $chosen);

            pudo_clear_shipping_package_cache();

            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();
        },
    ]);
});

function pudo_store_locker_code_in_session()
{
    check_ajax_referer('wc_custom_nonce', 'nonce');

    if (!empty($_POST['locker_code']) && !empty($_POST['locker_title'])) {
        $wcsession   = WC()->session;
        $order_id    = $wcsession->get('store_api_draft_order');
        $order       = wc_get_order($order_id);
        $lockerCode  = sanitize_text_field(wp_unslash($_POST['locker_code']));
        $destination = sanitize_text_field(
            $lockerCode . ':' . sanitize_text_field(
                wp_unslash($_POST['locker_title'])
            )
        );
        WC()->session->set(
            'pudo_locker_destination',
            $destination
        );

        $order->update_meta_data('pudo_locker_destination', $destination);
        set_transient('pudo-destination-locker-set-by-blocks', $lockerCode, 60);
        $order->save_meta_data();
        pudo_clear_shipping_package_cache();
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();
        wp_send_json_success(array('message' => __('Locker data saved.', 'pudo-shipping-for-woocommerce')));
    } else {
        wp_send_json_error(array('message' => __('No locker data provided.', 'pudo-shipping-for-woocommerce')));
    }
}

add_action('woocommerce_thankyou', 'pudo_update_locker_code_on_order', 10, 1);

/**
 * Resolve a stored TCG Locker method string to a canonical family key.
 *
 * @param string $method
 *
 * @return string
 */
function pudo_get_method_family($method)
{
    $normalized_method = strtoupper(trim((string)$method));

    if ($normalized_method === '' || $normalized_method === 'NO-PUDO') {
        return '';
    }

    if (str_contains($normalized_method, ':L2L') || $normalized_method === 'L2L-PUDO' || $normalized_method === 'L2L') {
        return 'l2l';
    }

    if (str_contains($normalized_method, ':L2D') || $normalized_method === 'L2D-PUDO' || $normalized_method === 'L2D') {
        return 'l2d';
    }

    if (str_contains($normalized_method, ':D2L') || $normalized_method === 'D2L-PUDO' || $normalized_method === 'D2L') {
        return 'd2l';
    }

    if (
        str_contains($normalized_method, ':D2D') ||
        str_starts_with($normalized_method, 'D2D') ||
        in_array($normalized_method, array('D2D-PUDO', 'ECO', 'OVN', 'LOX'), true)
    ) {
        return 'd2d';
    }

    return '';
}

function pudo_update_locker_code_on_order($order_id)
{
    if (!$order_id) {
        return;
    }

    $order        = wc_get_order($order_id);
    $meta_updates = array();

    $chosen_methods        = (string)WC()->session->get('pudo-shipping-method-block', '');
    $existing_order_method = (string)$order->get_meta('pudo_method', true);
    $method_source         = 'session';

    if ($chosen_methods !== '' && str_contains($chosen_methods, 'l2d-pudo')) {
        $pudoMethod = (string)WC()->session->get('pudo-shipping-method-l2d', '');
    } elseif ($chosen_methods !== '' && str_starts_with($chosen_methods, 'd2d-pudo')) {
        $pudoMethod = 'd2d-pudo';
    } elseif ($chosen_methods !== '' && (in_array($chosen_methods, ['ECO', 'OVN', 'LOX'], true) || str_starts_with(
                $chosen_methods,
                'D2D'
            ))) {
        $pudoMethod = $chosen_methods;
    } elseif ($chosen_methods === '' && $existing_order_method !== '' && $existing_order_method !== 'no-pudo') {
        // On blocks thankyou, chosen method may be unavailable while a valid checkout method already exists on the order.
        $pudoMethod    = $existing_order_method;
        $method_source = 'order_meta_no_chosen_method';
    } else {
        $pudoMethod = (string)WC()->session->get('pudo-shipping-method-dl2l', 'no-pudo'); // d2l & l2l
    }

    // On thankyou, session values can be missing. Keep the already-persisted checkout method.
    if (($pudoMethod === '' || $pudoMethod === 'no-pudo') && $existing_order_method !== '' && $existing_order_method !== 'no-pudo') {
        $pudoMethod    = $existing_order_method;
        $method_source = 'order_meta_fallback';
    }

    $resolved_method_family = pudo_get_method_family($pudoMethod);
    $existing_method_family = pudo_get_method_family($existing_order_method);

    // If session resolves to a different shipping family than what checkout already persisted, trust the order value.
    if (
        $existing_method_family !== '' &&
        $resolved_method_family !== '' &&
        $existing_method_family !== $resolved_method_family
    ) {
        $pudoMethod             = $existing_order_method;
        $method_source          = 'order_meta_fallback_family_mismatch';
        $resolved_method_family = pudo_get_method_family($pudoMethod);
    }

    if (in_array($resolved_method_family, array('l2l', 'l2d'), true)) {
        $lockerOrigin = WC()->session->get('pudo_source_locker', '');

        $order->update_meta_data('pudo_locker_origin', $lockerOrigin);
        $meta_updates['pudo_locker_origin'] = $lockerOrigin;
    }

    if (in_array($resolved_method_family, array('d2d', 'l2d'), true) && $order->meta_exists(
            'pudo_locker_destination'
        )) {
        $order->delete_meta_data('pudo_locker_destination');
        $meta_updates['pudo_locker_destination'] = '[deleted]';
        $order->save_meta_data();
    }

    $order->update_meta_data('pudo_method', $pudoMethod);
    $order->update_meta_data('pudo_ship_to_different_address', '');
    $order->update_meta_data('pudo_status', 'none', true);
    $meta_updates['pudo_method']                    = $pudoMethod;
    $meta_updates['pudo_ship_to_different_address'] = '';
    $meta_updates['pudo_status']                    = 'none';
    $meta_updates['method_source']                  = $method_source;
    $meta_updates['existing_order_method']          = $existing_order_method;
    $meta_updates['resolved_method_family']         = $resolved_method_family;
    $meta_updates['existing_method_family']         = $existing_method_family;
    pudo_log_order_placement_choice($order, $pudoMethod);

    wc_get_logger()->info(
        sprintf(
            'TCG Locker order meta updates on thankyou: order_id=%d | updates=%s',
            (int)$order_id,
            wp_json_encode($meta_updates)
        ),
        array(
            'source'   => 'pudo-for-wc',
            'order_id' => (int)$order_id,
            'updates'  => $meta_updates,
        )
    );

    $order->save_meta_data();

    // Clear locker data from session
    WC()->session->__unset('locker_code');
    WC()->session->__unset('locker_title');
    WC()->session->__unset('pudo-shipping-method');
    WC()->session->__unset('pudo-shipping-rate');
    WC()->session->__unset('pudo_locker_name');
    WC()->session->__unset('pudo-shipping-rate-l2d');
    WC()->session->__unset('pudo-shipping-method-block');
    WC()->session->__unset('pudo-shipping-method-l2d');
    WC()->session->__unset('pudo-shipping-method-dl2l');
    WC()->session->__unset('pudo_source_locker');
}

/**
 * Add a single order note and WooCommerce log entry with the selected checkout option.
 *
 * @param \WC_Order $order
 * @param string $pudo_method
 *
 * @return void
 */
function pudo_log_order_placement_choice($order, $pudo_method)
{
    if (!$order instanceof WC_Order) {
        return;
    }

    if ($order->get_meta('pudo_checkout_selection_logged', true) === 'yes') {
        return;
    }

    $normalized_method = strtoupper((string)$pudo_method);
    $option_label      = pudo_get_checkout_option_label($normalized_method);

    if ($option_label === '') {
        $option_label = pudo_get_checkout_option_label_from_order($order);
    }

    if ($option_label === '') {
        return;
    }

    $destination = (string)$order->get_meta('pudo_locker_destination', true);
    $origin      = (string)$order->get_meta('pudo_locker_origin', true);

    if ($option_label === 'Deliver to a Locker (The Courier Guy Locker)') {
        $summary = sprintf(
            'TCG Locker checkout selection captured on order placement: %s | destination: %s | method: %s',
            $option_label,
            $destination ?: 'not-selected',
            $pudo_method
        );
    } else {
        $shipping_address = trim(
            implode(
                ', ',
                array_filter(
                    array(
                        $order->get_shipping_address_1(),
                        $order->get_shipping_city(),
                        $order->get_shipping_postcode(),
                    )
                )
            )
        );

        $summary = sprintf(
            'TCG Locker checkout selection captured on order placement: %s | ship-to: %s | origin: %s | method: %s',
            $option_label,
            $shipping_address ?: 'customer shipping address',
            $origin ?: 'not-set',
            $pudo_method
        );
    }

    $order->add_order_note($summary);

    $order->update_meta_data('pudo_checkout_selection_logged', 'yes');
}

/**
 * Resolve checkout option label from normalized method id.
 *
 * @param string $normalized_method
 *
 * @return string
 */
function pudo_get_checkout_option_label($normalized_method)
{
    if (str_contains($normalized_method, ':D2L') || str_contains($normalized_method, ':L2L')) {
        return 'Deliver to a Locker (The Courier Guy Locker)';
    }

    if (
        str_contains($normalized_method, ':L2D') ||
        str_contains($normalized_method, ':D2D') ||
        in_array($normalized_method, array('D2D-PUDO', 'ECO', 'OVN', 'LOX'), true)
    ) {
        return 'Deliver to a Door (The Courier Guy Locker)';
    }

    return '';
}

/**
 * Attempt to infer checkout option label from order shipping line items.
 *
 * @param \WC_Order $order
 *
 * @return string
 */
function pudo_get_checkout_option_label_from_order($order)
{
    foreach ($order->get_items('shipping') as $shipping_item) {
        $method_name = strtoupper((string)$shipping_item->get_name());

        if (str_contains($method_name, 'DELIVER TO A LOCKER')) {
            return 'Deliver to a Locker (The Courier Guy Locker)';
        }

        if (str_contains($method_name, 'DELIVER TO A DOOR')) {
            return 'Deliver to a Door (The Courier Guy Locker)';
        }
    }

    return '';
}

add_filter('woocommerce_package_rates', 'pudo_custom_update_shipping_rate_cost', 100, 2);
function pudo_custom_update_shipping_rate_cost($rates, $package)
{
    // Get session data
    $pudoMethod  = WC()->session->get('pudo-shipping-method', '');
    $pudoRate    = WC()->session->get('pudo-shipping-rate', array());
    $pudoL2dRate = WC()->session->get('pudo-shipping-rate-l2d', array());

    if (empty($pudoMethod)) {
        return $rates;
    }

    $security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';
    $nonce    = isset($_POST['woocommerce-process-checkout-nonce']) ? sanitize_text_field(
        wp_unslash($_POST['woocommerce-process-checkout-nonce'])
    ) : '';
    if (!wp_verify_nonce($security, 'update-order-review')) {
        if (!wp_verify_nonce($nonce, 'woocommerce-process_checkout') && !empty($_POST)) {
            return;
        }
    }

    // Get rates stored in the WC session data for this package.
    $package_key          = 0;
    $wc_session_key       = 'shipping_for_package_' . $package_key;
    $stored_rates         = WC()->session->get($wc_session_key);
    $chosenShippingMethod = '';
    if (isset($_POST['shipping_method'][0])) {
        $chosenShippingMethod = sanitize_text_field(wp_unslash($_POST['shipping_method'][0]));
    }

    foreach ($rates as $rate_id => $rate) {
        // Check for L2D
        if (str_contains($rate_id, 'l2d')) {
            $rates[$rate_id]->cost = $pudoL2dRate['cost'] ?? 0;
        } // Check for L2L or D2L
        elseif (str_contains($rate_id, 'l2l') || str_contains($rate_id, 'd2l')) {
            $rates[$rate_id]->cost = $pudoRate['cost'] ?? 0;
        }
    }
    if ($chosenShippingMethod === $pudoMethod) {
        // Update the rates in the WC session data for this package.
        $rates[$chosenShippingMethod] = $stored_rates['rates'][$chosenShippingMethod] ?? $rates[$chosenShippingMethod];
    }

    return $rates;
}

/**
 * initialize shipping plugin function
 *
 * @param \WC_Order $order
 */
function pudo_wc_pudo_shipping_init()
{
    require_once __DIR__ . '/classes/Product.php';

    add_action('woocommerce_shipping_init', 'pudo_initiate_pudo_shipping_method');
    add_action('wp_enqueue_scripts', 'pudo_register_pudo_js_resources');

    // Create TCG Locker orders in backend
    add_filter(
        'manage_edit-shop_order_columns',
        array(
            Pudo_Shipping_Method::class,
            'addCollectionActionAndPrintWaybillToOrderList',
        ),
        20
    );

    // Use the getter function to get order ID
    function pudo_wc_add_order_meta_box_action($actions, $order)
    {
        if (!$order) {
            return $actions;
        }

        $raw_pudo_method = (string)$order->get_meta('pudo_method');
        $pudo_method     = strtolower(trim($raw_pudo_method));
        if (str_starts_with($pudo_method, 'pickup_dropoff')) {
            // Classic checkout keeps the full pickup_dropoff payload.
            $pudo_method = 'pickup_dropoff';
        } elseif (in_array($pudo_method, ['l2l-pudo', 'l2d-pudo', 'd2l-pudo', 'd2d-pudo'], true)) {
            // Blocks checkout stores normalized pudo slugs.
            $pudo_method = $pudo_method;
        } elseif (in_array($pudo_method, ['eco', 'ovn', 'lox'], true) || str_starts_with($pudo_method, 'd2d')) {
            $pudo_method = 'd2d-pudo';
        } else {
            $family = pudo_get_method_family($raw_pudo_method);
            if ($family === 'l2d') {
                $pudo_method = 'l2d-pudo';
            } elseif ($family === 'd2l') {
                $pudo_method = 'd2l-pudo';
            } elseif ($family === 'l2l') {
                $pudo_method = 'l2l-pudo';
            } elseif ($family === 'd2d') {
                $pudo_method = 'd2d-pudo';
            }
        }

        if (!in_array($pudo_method, ['pickup_dropoff', 'l2d-pudo', 'd2d-pudo', 'l2l-pudo', 'd2l-pudo'], true)) {
            return $actions;
        }

        $order_generated = (bool)$order->get_meta('pudo_booking_id');

        if ($order_generated) {
            $actions['wc_custom_order_action_label']  = __(
                'Print The Courier Guy Locker Label',
                'pudo-shipping-for-woocommerce'
            );
            $actions['wc_custom_order_action_waybil'] = __(
                'Print The Courier Guy Locker Waybill',
                'pudo-shipping-for-woocommerce'
            );
        } else {
            $actions['wc_custom_order_action_pudo'] = __(
                'Create The Courier Guy Locker Shipment',
                'pudo-shipping-for-woocommerce'
            );
        }

        return $actions;
    }

    add_action('woocommerce_order_actions', 'pudo_wc_add_order_meta_box_action', 10, 2);

    /**
     * Add an order note when custom action is clicked
     * Add a flag on the order to show it's been run
     *
     * @param \WC_Order $order
     */
    function pudo_wc_print_waybill_from_order($order)
    {
        Pudo_Shipping_Method::printWaybillFromOrder($order);
    }

    /**
     * Add an order note when custom action is clicked
     * Add a flag on the order to show it's been run
     *
     * @param \WC_Order $order
     */
    function pudo_wc_print_label_from_order($order)
    {
        Pudo_Shipping_Method::printLabelFromOrder($order);
    }

    // Add custom order action in "Order Meta box" (Select drop down on right hand side)
    add_action('woocommerce_order_action_wc_custom_order_action_waybil', 'pudo_wc_print_waybill_from_order');
    add_action('woocommerce_order_action_wc_custom_order_action_label', 'pudo_wc_print_label_from_order');

    add_action('admin_notices', 'pudo_custom_display_admin_message');

    function pudo_custom_display_admin_message()
    {
        global $pagenow;

        // Only run on post edit screen.
        if ('post.php' !== $pagenow) {
            return;
        }

        // Verify post parameter exists.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post ID on admin edit screen only.
        if (!isset($_GET['post'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post ID on admin edit screen only.
        $post_id = absint(wp_unslash($_GET['post']));

        if (!$post_id) {
            return;
        }

        // Confirm correct post type.
        if ('shop_order' !== get_post_type($post_id)) {
            return;
        }

        // Get post meta safely.
        $pudo_status = get_post_meta($post_id, 'pudo_status', true);

        if ('Booking confirmed' === $pudo_status) {
            $message     = 'The Courier Guy Locker ' . $pudo_status . ' for Order ' . $post_id;
            $noticeClass = 'success';

            echo '<div class="notice notice-' . esc_attr($noticeClass) . ' is-dismissible">';
            echo '<p>' . esc_html($message) . '</p>';
            echo '</div>';
        } elseif (!empty($pudo_status) && 'none' !== $pudo_status && 'booking confirmed' !== $pudo_status) {
            $message     = $pudo_status;
            $noticeClass = 'error';

            echo '<div class="notice notice-' . esc_attr($noticeClass) . '">';
            echo '<p>' . esc_html($message) . '</p>';
            echo '</div>';
        }
    }

    // Add custom actions to Woocommerce "Order meta box" (Order box on right hand side)
    add_action(
        'woocommerce_order_action_wc_custom_order_action_pudo',
        'pudo_wc_process_order_meta_box_action'
    );

    function pudo_wc_process_order_meta_box_action($order)
    {
        if (!$order) {
            return;
        }

        if (!current_user_can('edit_shop_order', $order->get_id())) {
            return;
        }

        $url = add_query_arg(
            array(
                'page'     => 'pudo-change-locker',
                'order_id' => $order->get_id(),
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    // Add custom column to woocommerce orders grid
    add_action(
        'manage_shop_order_posts_custom_column',
        array(Pudo_Shipping_Method::class, 'collectActionAndPrintWaybillOnOrderlistContent'),
        20,
        2
    );

    add_action('admin_head', array(Pudo_Shipping_Method::class, 'display_pudo_notice'));

    // Add print waybill hook (Only displayed if order already requested against api)
    add_action(
        'woocommerce_order_action_pudo_print_waybill',
        array(
            Pudo_Shipping_Method::class,
            'printWaybillFromList',
        ),
        10,
        1
    );

    // Make function to print pudo waybill available
    add_action('admin_post_print_pudo_waybill', array(Pudo_Shipping_Method::class, 'printWaybillFromList'));
}

/**
 * Return instance of shipping method
 *
 * @param $settings
 *
 * @return Pudo_Shipping_Method
 */
function pudo_initiate_pudo_shipping_method($settings)
{
    return new Pudo_Shipping_Method();
}

/**
 * Ensure the woocommerce plugin is installed and enabled
 *
 * @return bool
 */
function pudo_wc_is_installed()
{
    // Fetch array containing active plugins from table wp_options
    $active_plugins = get_option('active_plugins');
    // Check If woocommerce/woocommerce.php is not found in array
    if (!in_array('woocommerce/woocommerce.php', $active_plugins)) {
        return false;
    } else {
        register_setting(
            'pudo_woocommerce',
            'dismissed-pudo_disclaimer',
            array(
                'sanitize_callback' => 'sanitize_text_field',
            )
        );
        if (get_option('pudoMsg', false)) {
            echo '
            <div class="updated notice notice-the-courier-guy is-dismissible" data-notice="tcg_disclaimer">
            <p><strong>Pudo Shipping</strong></p>
            <p>Parcel sizes are based on your packaging structure. The plugin will compare the cart’s total
                    dimensions against “Flyer”, “Medium” and “Large” parcel sizes to determine the best fit. The
                    resulting calculation will be submitted to The Pudo API using the parcel’s dimensions.
                    <strong>By downloading and using this plugin, you accept that incorrect ‘Parcel Size’ settings</strong></p>
            </div>
            ';
            delete_option('pudoMsg');
        }

        return true;
    }
}

/**
 * Ensure the plugin is registered else return a admin notice
 *
 * @return void
 */
function pudo_plugin_registered()
{
    $active_plugins = get_option('active_plugins');

    if (pudo_wc_is_installed()) {
        $active_plugins[] = plugin_basename(__FILE__);
    } else {
        add_action('admin_notices', 'pudo_addInvalidPluginNotice');
        deactivate_plugins(plugin_basename(__FILE__));
        unset($_GET['activate']);
    }
}

/**
 * Shipping debug alert
 *
 * @return void
 */
function pudo_woocom_shipping_debug_check()
{
    $pudo                       = new Pudo_Shipping_Method();
    $woocommerce_shipping_debug = $pudo->checkShippingDebug();
    if ($woocommerce_shipping_debug == 'yes') {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                esc_html_e(
                    'Please note TCG Locker Cannot run whilst Woocommerce Shipping Debug is enabled',
                    'pudo-shipping-for-woocommerce'
                );
                ?>
            </p>
        </div>
        <?php
    }
}

/**
 * Admin notice for invalid plugin (Add to body)
 *
 * @return void
 */
function pudo_addInvalidPluginNotice()
{
    echo esc_html(pudo_getInvalidPluginNotice());
}

/**
 * Return the actual notice
 *
 * @return string
 */
function pudo_getInvalidPluginNotice()
{
    return <<<'NOTICE'
    <div id="message" class="error">
    <p>WooCommerce is required for this plugin</p>
    </div>
    NOTICE;
}

/**
 * Include JS/CSS Resources for plugin into global scope
 *
 * @return void
 */
function pudo_register_pudo_js_resources()
{
    $plugin_data    = get_plugin_data(__FILE__);
    $plugin_version = $plugin_data['Version'];

    wp_enqueue_style('pudo_css', plugins_url('/dist/css/pudo.css', __FILE__), array(), $plugin_version);
    wp_enqueue_style('pudo-leaflet-css', plugins_url('/dist/css/leaflet.css', __FILE__), array(), $plugin_version);
    wp_enqueue_style(
        'pudo-leaflet-marker-cluster-css-default',
        plugins_url('/dist/css/MarkerCluster.Default.min.css', __FILE__),
        array(),
        $plugin_version
    );
    wp_enqueue_style(
        'pudo-leaflet-marker-cluster-css',
        plugins_url('/dist/css/MarkerCluster.css', __FILE__),
        array(),
        $plugin_version
    );
    wp_enqueue_style(
        'pudo-font-awesome-css',
        plugins_url('/dist/css/fontawesome.min.css', __FILE__),
        array(),
        $plugin_version
    );
    wp_enqueue_style('pudo-geosearch-css', plugins_url('/dist/css/geosearch.css', __FILE__), array(), $plugin_version);
    wp_enqueue_style(
        'pudo-font-datagrid-css',
        plugins_url('/dist/css/jquery.dataTables.min.css', __FILE__),
        array(),
        $plugin_version
    );

    wp_enqueue_script(
        'pudo-poppover-js',
        plugins_url('/dist/js/popper.min.js', __FILE__),
        array(),
        $plugin_version,
        true
    );
    wp_enqueue_script('pudo-leaflet-js', plugins_url('/dist/js/leaflet.js', __FILE__), array(), $plugin_version, true);
    wp_enqueue_script(
        'pudo-leaflet-marker-cluster-js',
        plugins_url('/dist/js/leaflet.markercluster.js', __FILE__),
        array('pudo-leaflet-js'),
        $plugin_version,
        true
    );
    wp_enqueue_script(
        'pudo-bootstrap-bundle-js',
        plugins_url('/dist/js/bootstrap.bundle.min.js', __FILE__),
        array('jquery'),
        $plugin_version,
        true
    );
    wp_enqueue_script(
        'pudo-bootbox-js',
        plugins_url('/dist/js/bootbox.min.js', __FILE__),
        array('jquery', 'pudo-bootstrap-bundle-js'),
        $plugin_version,
        true
    );
    wp_enqueue_script(
        'pudo-geosearch-js',
        plugins_url('/dist/js/geosearch.umd.js', __FILE__),
        array('pudo-leaflet-js'),
        $plugin_version,
        true
    );
    wp_enqueue_script(
        'pudo-geosearch-datatable-js',
        plugins_url('/dist/js/jquery.dataTables.min.js', __FILE__),
        array('jquery'),
        $plugin_version,
        true
    );
    wp_enqueue_script(
        'pudo-jquery-loader',
        plugins_url('/dist/js/loadingoverlay.min.js', __FILE__),
        array('jquery'),
        $plugin_version,
        true
    );

    $pudoJsPath = plugin_dir_path(__FILE__) . 'dist/js/pudo.js';
    wp_deregister_script('pudo_js');
    wp_enqueue_script(
        'pudo_js',
        plugins_url('/dist/js/pudo.js', __FILE__),
        array('jquery', 'pudo-leaflet-js', 'wp-data', 'wc-blocks-data-store'),
        $plugin_version . '.' . filemtime($pudoJsPath),
        true
    );

    $pudo_api = PudoApi::getInstance();
    wp_localize_script(
        'pudo_js',
        'pudo_params',
        array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('wc_custom_nonce'),
            'markersJSON' => $pudo_api->lockers,
        )
    );

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['page'], $_GET['tab']) && $_GET['page'] === 'wc-settings' && $_GET['tab'] === 'shipping') {
        wp_enqueue_script(
            'pudo_admin_js',
            plugins_url('/dist/js/pudo_admin.js', __FILE__),
            array('jquery', 'pudo_js'),
            $plugin_version,
            true
        );
    }
}

/**
 * Register settings
 *
 * @return void
 */
function pudoWooCommerceSettings()
{
    register_setting(
        'pudo_woocommerce',
        'pudo_account_key',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );
    register_setting(
        'pudo_woocommerce',
        'pudo_api_url',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        )
    );
    register_setting(
        'pudo_woocommerce',
        'pudo_account',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );
    register_setting(
        'pudo_woocommerce',
        'pudo_osm_email',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        )
    );
    register_setting(
        'pudo_woocommerce',
        'pudo_use_osm_map',
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'pudo_sanitize_checkbox',
            'default'           => 0,
        )
    );
    // Include select2 JS and CSS
    wp_enqueue_script('pudo-select2', plugins_url('dist/js/select2.min.js', __FILE__), array('jquery'), '4.1.0', false);
    wp_enqueue_style('pudo-select2', plugins_url('dist/css/select2.min.css', __FILE__), array(), '4.1.0');
    $plugin_data    = get_plugin_data(__FILE__);
    $plugin_version = $plugin_data['Version'];
    wp_enqueue_script(
        'pudo_admin_js',
        plugins_url('/dist/js/pudo_admin.js', __FILE__),
        array('jquery'),
        $plugin_version,
        true
    );
}

/**
 * Custom sanitizer for the checkbox
 */
function pudo_sanitize_checkbox($input)
{
    return (isset($input) && $input == '1') ? 1 : 0;
}

function pudo_set_shipping_method()
{
    check_ajax_referer('wc_custom_nonce', 'nonce');

    if (isset($_POST['shipping_method']) && $_POST['shipping_method'] !== 'undefined') {
        // Classic route
        $shipping_method = sanitize_text_field(wp_unslash($_POST['shipping_method']));
        $pudo_method     = sanitize_text_field(wp_unslash($_POST['pudo_method']));
        $lockerCode      = sanitize_text_field($_POST['locker_code']);
        $destination     = sanitize_text_field(
            $lockerCode . ':' . sanitize_text_field(
                wp_unslash($_POST['locker_title'])
            )
        );
        WC()->session->set('pudo-shipping-method-block', $pudo_method);
        WC()->session->set(
            'pudo_locker_destination',
            $destination
        );
        WC()->session->set(
            'pudo_locker_destination',
            $lockerCode
        );
        pudo_clear_shipping_package_cache();
        WC()->cart->calculate_totals();
        wp_send_json_success();
    } elseif (isset($_POST['pudo_method']) && $_POST['pudo_method'] !== '') {
        // Blocks route
        $pudo_method = sanitize_text_field(wp_unslash($_POST['pudo_method']));
        $lockerCode  = sanitize_text_field($_POST['locker_code']);
        $destination = sanitize_text_field(
            $lockerCode . ':' . sanitize_text_field(
                wp_unslash($_POST['locker_title'])
            )
        );
        $logger      = wc_get_logger();
        $wcSession   = WC()->session;
        $data        = $wcSession->get_session_data();
        $order_id  = WC()->session->get('store_api_draft_order');
        $order     = wc_get_order($order_id);
        
        if ($order) {
            $order->update_meta_data('pudo_locker_destination', $lockerCode);
            $order->update_meta_data('pudo_method', $pudo_method);
            WC()->session->set('pudo-shipping-method-block', $pudo_method);
            WC()->session->set(
                'pudo_locker_destination',
                $destination
            );
            WC()->session->set(
                'pudo_locker_destination',
                $lockerCode
            );
            WC()->session->set(
                'pudo-destination-locker-set-by-blocks',
                $lockerCode
            );
            $order->save_meta_data();
            $order->save();

            $order->update_meta_data('pudo_locker_destination', $lockerCode);
            $order->update_meta_data('pudo_method', $pudo_method);
        }
        WC()->session->set('pudo-shipping-method-block', $pudo_method);
        WC()->session->set(
            'pudo_locker_destination',
            $destination
        );
        WC()->session->set(
            'pudo_locker_destination',
            $lockerCode
        );
        WC()->session->set(
            'pudo-destination-locker-set-by-blocks',
            $lockerCode
        );
        pudo_clear_shipping_package_cache();
        if ($order) {
            $order->save_meta_data();
            $order->save();
        }
        WC()->cart->calculate_totals();
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}

add_action('wp_ajax_set_shipping_method', 'pudo_set_shipping_method');
add_action('wp_ajax_nopriv_set_shipping_method', 'pudo_set_shipping_method');

// Register settings in WP
add_action('admin_init', 'pudoWooCommerceSettings');

/**
 * Plugin Action Links : Apply merger of links (Custom pudo settings link on plugin card)
 *
 * @param $links
 *
 * @return mixed
 */
function pudo_wc_add_plugin_settings_link($links)
{
    $url           = admin_url() . '/admin.php?page=pudo-woocommerce-config';
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html('Settings') . '</a>';
    $links[]       = $settings_link;

    return $links;
}

// Add filter for plugin link on plugin card
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pudo_wc_add_plugin_settings_link');

/**
 * Settings Form
 *
 * @return void
 */
function pudo_render_plugin_settings_page()
{
    // adding check to see if in the admin page, and then loading this html

    $apiURL        = (get_option('pudo_api_url')) ? get_option(
        'pudo_api_url'
    ) : '';
    $apiKey        = (get_option('pudo_account_key')) ? get_option(
        'pudo_account_key'
    ) : '';
    $pudoID        = get_option('pudo_account') ?? '';
    $pudoOSMEmail  = get_option('pudo_osm_email') ?? '';
    $pudoUseOSMMap = get_option('pudo_use_osm_map') ?? '';
    // $pudoDisplayShippingLabel = get_option('pudo_display_shipping_zone_match_label') ?? '';

    $template = plugin_dir_path(__FILE__) . 'templates/settings-form.php';
    if (file_exists($template)) {
        include $template;
    }
}

/**
 * Add settings page
 *
 * @return void
 */
function pudo_wc_pudo_add_settings_page()
{
    add_submenu_page(
        'woocommerce',
        'The Courier Guy Locker Account',
        'The Courier Guy Locker Account',
        'manage_options',
        'pudo-woocommerce-config',
        'pudo_render_plugin_settings_page',
        1.0
    );
}

// Add action to WP environment
add_action('admin_menu', 'pudo_wc_pudo_add_settings_page');
// Add checkout values hook
add_action('woocommerce_checkout_update_order_review', 'pudo_save_checkout_values', 9999);

// Action to delete selected pudo shipping method session after checkout
add_action('woocommerce_checkout_order_processed', 'pudo_delete_session_after_checkout');
function pudo_delete_session_after_checkout($order_id)
{
    // Get the WooCommerce session object
    $wc_session = WC()->session;

    $wc_session->set('pudo-shipping-method', null);
}

/**
 * Define function for checkout hook callback
 *
 * @param $posted_data
 *
 * @return void
 */
function pudo_save_checkout_values($posted_data)
{
    parse_str($posted_data, $output);
    WC()->session->set('checkout_data', $output);
    WC()->cart->calculate_totals();
}

// Checkout get value hook
add_filter('woocommerce_checkout_get_value', 'pudo_get_saved_checkout', 9999, 2);
/**
 * Get saved checkout values (WP Session)
 *
 * @param $value
 * @param $index
 *
 * @return int|mixed
 */
function pudo_get_saved_checkout($value, $index)
{
    $data = WC()->session->get('checkout_data');
    if (!$data || empty($data[$index])) {
        return $value;
    }

    return is_bool($data[$index]) ? (int)$data[$index] : $data[$index];
}

// Ship to different address checkbox on "Checkout" page
add_filter('woocommerce_ship_to_different_address_checked', 'pudo_get_saved_ship_to_different');

add_action('woocommerce_calculate_totals', function ($cart) {
    $shippingMethods = $cart->get_shipping_methods();
    foreach ($shippingMethods as $method) {
        if (str_contains($method->get_method_id(), 'pickup_dropoff')) {
            $cartContentTaxes     = $cart->get_cart_contents_taxes();
            $cartContentTax       = 0.00;
            $cartContentTaxesKeys = array_keys($cartContentTaxes);
            foreach ($cartContentTaxes as $key => $value) {
                $cartContentTax += $value;
            }
            $cartContentTaxes    = [];
            $cartContentTaxes[1] = $cartContentTax;
            $shippingTaxes       = $cart->get_shipping_taxes();
            $cartShippingTax     = 0.00;
            foreach ($shippingTaxes as $key => $value) {
                if (in_array($key, $cartContentTaxesKeys)) {
                    $cartShippingTax = 0.00;
                    continue;
                }
                $cartShippingTax += $value;
                unset($shippingTaxes[$key]);
                if (!empty($cartContentTaxesKeys)) {
                    $shippingTaxes[$cartContentTaxesKeys[0]] = $cartShippingTax;
                }
            }
            $cart->set_shipping_taxes($shippingTaxes);
            $totalTaxes = array_sum($shippingTaxes) + array_sum($cartContentTaxes);
            $cart->set_total_tax($totalTaxes);
        }
    }
});

/**
 * Check if ship to different address is check or not
 *
 * @param $checked
 *
 * @return mixed|true
 */
function pudo_get_saved_ship_to_different($checked)
{
    $data = WC()->session->get('checkout_data');
    if (!$data || empty($data['ship_to_different_address'])) {
        return $checked;
    }

    return true;
}

function pudo_generateToken()
{
    // Generate a random token using a secure method
    $token = bin2hex(random_bytes(32));

    // Store the token in the user's session
    $_SESSION['_token'] = $token;

    return $token;
}

function pudo_get_admin_order_url($order_id)
{
    // Ensure the order exists
    $order = wc_get_order($order_id);
    if (!$order) {
        return false; // Return false if order doesn't exist
    }

    return admin_url("post.php?post={$order_id}&action=edit");
}

/**
 * Generate iframe form
 *
 * @return void
 */
/**
 * AJAX handler for fetching Pudo shipping rates.
 */
function pudoGetRates(): void
{
    // 1. NONCE VERIFICATION
    // We use 'nonce' as the key to match your JS data object.
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'wc_custom_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed'), 403);
    }

    // 2. INPUT VALIDATION & SANITIZATION
    if (!isset($_POST['pudoPost']) || !is_array($_POST['pudoPost'])) {
        wp_send_json_error(array('message' => 'Invalid or missing data'), 400);
    }

    // Unslash the whole array first to remove WP magic quotes
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- We will sanitize individual keys below.
    $rawPudoPost = wp_unslash($_POST['pudoPost']);

    // Sanitize individual keys for security and data integrity
    $pudoPost = array(
        'method'            => sanitize_text_field($rawPudoPost['method'] ?? ''),
        'collectionAddress' => sanitize_text_field($rawPudoPost['collectionAddress'] ?? ''),
        'deliveryAddress'   => sanitize_text_field($rawPudoPost['deliveryAddress'] ?? ''),
        'orderID'           => absint($rawPudoPost['orderID'] ?? 0),
    );

    // 3. CORE LOGIC
    $order = wc_get_order($pudoPost['orderID']);
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
    }

    $shippingData = new PudoShippingData($pudoPost['method'], true);
    $data         = array(
        'pudo-source-locker'      => $pudoPost['collectionAddress'],
        'pudo-destination-locker' => $pudoPost['deliveryAddress'],
    );

    $instanceId = getInstanceId();

    $pudoShippingMethod = new Pudo_Shipping_Method($instanceId);
    $settings           = $pudoShippingMethod->instance_settings;
    $pudoApi            = PudoApi::getInstance();
    $orderArray         = Pudo_WCOrderUtility::convertOrderToArray($order);

    $apiRequestBuilder = $shippingData->initializeAPIRequestBuilder(
        $orderArray,
        $settings,
        $data,
        $pudoPost['method']
    );

    $ratesResponse = $pudoApi->getRates(json_encode($apiRequestBuilder->buildRatesRequest()));
    $body          = json_decode($ratesResponse['body'], true);
    // This is a temporary fix to remove D2D rates from the response until the API is updated to handle them correctly.
    foreach ($body['rates'] as $key => $pudoRate) {
        if (str_starts_with($pudoRate['service_level']['code'], 'D2D')) {
            unset($body['rates'][$key]);
        }
    }
    
    // Apply label overrides only; price overrides must not apply during shipment creation
    // so that the original rate from the API is displayed.
    $labelOverrides = [];
    if (!empty($settings['label_override_per_service'])) {
        $labelOverrides = json_decode($settings['label_override_per_service'], true);
    }
    foreach ($body['rates'] as $key => $pudoRate) {
        $serviceLevelCode = $pudoRate['service_level']['code'];
        $serviceLevelCode = str_replace(' ', '', $serviceLevelCode ?? '');
        if (isset($labelOverrides[$serviceLevelCode])) {
            $body['rates'][$key]['service_level']['name'] = $labelOverrides[$serviceLevelCode];
        }
    }

    // Now check for free shipping override
    $freeShippingOverride = ($settings['free_shipping'] ?? 'no') === 'yes';
    $freeShippingLimit    = (float)($settings['amount_for_free_shipping'] ?? 0.0);
    $orderTotal           = (float)$order->get_total();
    $shippingTotal        = (float)$order->get_shipping_total();
    $itemTotal            = $orderTotal - $shippingTotal;
    if ($freeShippingOverride && $itemTotal >= $freeShippingLimit) {
        foreach ($body['rates'] as $key => $pudoRate) {
            $body['rates'][$key]['rate'] = 0.0;
        }
    }


    // 4. RESPONSE
    // We send back the body directly; wp_send_json_success handles JSON encoding
    wp_send_json_success(array('rates' => json_encode($body)));
}

add_action('wp_ajax_pudo_get_rates', 'pudoGetRates');
add_action('wp_ajax_nopriv_pudo_get_rates', 'pudoGetRates');

/**
 * AJAX handler for creating a Pudo shipment/booking.
 */
function pudoCreateShipment(): void
{
    // 1. NONCE VERIFICATION
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'wc_custom_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed'), 403);
    }

    // 2. INPUT VALIDATION & PARSING
    if (!isset($_POST['pudoPostData'])) {
        wp_send_json_error(array('message' => 'Missing required data'));
    }

    // Unslash the serialized string before parsing
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- We will sanitize individual values after parsing.
    $rawPostData = $_POST['pudoPostData'];
    $formData    = array();
    parse_str($rawPostData, $formData);

    // Sanitize parsed values
    $orderID          = absint($formData['orderID'] ?? 0);
    $method           = strtoupper(sanitize_text_field($formData['pudo-method'] ?? ''));
    $lockerSize       = sanitize_text_field($formData['lockerSize'] ?? $formData['pudo-locker-size'] ?? '');
    $serviceLevelCode = sanitize_text_field($formData['serviceLevelCode'] ?? '');
    if (empty($serviceLevelCode) && $method === 'D2D') {
        $serviceLevelCode = sanitize_text_field($formData['serviceLevelCodeD2D'] ?? '');
    }

    // 3. CORE LOGIC
    $order = wc_get_order($orderID);
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
    }

    // We need to get the instance id for TCG Lockers, not necessarily the same as the order shipping
    $instanceId = getInstanceId() ?? 0;
    if (empty($instanceId)) {
        $methodFull = $order->get_meta('pudo_method', true);
        preg_match('/.+:(.+):(\d):/', $methodFull, $matches);
        if (isset($matches[1]) && empty($serviceLevelCode)) {
            $serviceLevelCode = $matches[1];
        }
        if (isset($matches[2])) {
            $instanceId = $matches[2];
        }
    }
    if (empty($instanceId)) {
        $orderShippingData = $order->get_meta('_order_shipping_data', true);
        if (is_string($orderShippingData) && str_starts_with($orderShippingData, '"pickup_dropoff')) {
            $shippingDataArray = explode(':', $orderShippingData);
            if (count($shippingDataArray) === 5) {
                $instanceId       = $shippingDataArray[2];
                $serviceLevelCode = $shippingDataArray[1];
            }
        }
    }

    $orderData    = Pudo_WCOrderUtility::convertOrderToArray($order);
    $shippingData = new PudoShippingData($method, true);

    $pudoShippingMethod = new Pudo_Shipping_Method($instanceId);
    $settings           = $pudoShippingMethod->instance_settings;
    $pudoApi            = PudoApi::getInstance();

    // Attach box info to the form data array for the builder
    $formData['fittedBox'] = $pudoApi->getBoxInfo($lockerSize);

    $apiRequestBuilder = $shippingData->initializeAPIRequestBuilder($orderData, $settings, $formData, $method);
    $requestBody       = json_encode($apiRequestBuilder->buildBookingRequest($serviceLevelCode));
    $bookingResponse   = $pudoApi->bookingRequest($requestBody);
    $bookingData       = json_decode($bookingResponse['body'], true);

    // 4. ORDER UPDATES & RESPONSE
    if (!isset($bookingData['id'])) {
        $order->add_order_note('The Courier Guy Locker: Could not book - API error.');
        $order->update_meta_data('pudo_status', 'Could not confirm');
        $order->save_meta_data();

        wp_send_json_error(array('result' => $bookingData));
    } else {
        $order->add_order_note('The Courier Guy Locker: Booking confirmed');
        $order->update_meta_data('pudo_booking_id', $bookingData['id']);
        $order->update_meta_data('pudo_custom_tracking_reference', $bookingData['custom_tracking_reference']);
        $order->update_meta_data('pudo_status', 'Booking confirmed');
        $order->save_meta_data();

        wp_send_json_success(array('result' => $bookingData));
    }
}

add_action('wp_ajax_pudo_submit_shipment', 'pudoCreateShipment');
add_action('wp_ajax_nopriv_pudo_submit_shipment', 'pudoCreateShipment');

/** Create endpoint function override to receive locker update info
 *
 * @return void
 */
function pudo_custom_rewrite_endpoint()
{
    add_rewrite_endpoint('change-locker-origin', EP_PERMALINK);
}

// Initialize the route function in wp scope
add_action('init', 'pudo_custom_rewrite_endpoint');

/**
 * Declares support for HPOS.
 *
 * @return void
 */
function pudo_woocommerce_pudo_declare_hpos_compatibility()
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
}

add_action('before_woocommerce_init', 'pudo_woocommerce_pudo_declare_hpos_compatibility');

/**
 * Normalize stored order method into one of: l2l, l2d, d2l, d2d.
 * Supports both classic (pickup_dropoff:...:...:L2D) and blocks (l2d-pudo, d2d-pudo, ECO/OVN/LOX).
 *
 * @param string $raw_pudo_method
 *
 * @return string
 */
function pudo_normalize_order_method_for_change_locker($raw_pudo_method)
{
    $normalized = strtoupper((string)$raw_pudo_method);

    if ($normalized === '') {
        return '';
    }

    if (str_contains($normalized, ':L2L') || $normalized === 'L2L-PUDO' || $normalized === 'L2L') {
        return 'l2l';
    }

    if (str_contains($normalized, ':L2D') || $normalized === 'L2D-PUDO' || $normalized === 'L2D') {
        return 'l2d';
    }

    if (str_contains($normalized, ':D2L') || $normalized === 'D2L-PUDO' || $normalized === 'D2L') {
        return 'd2l';
    }

    if (
        str_contains($normalized, ':D2D') ||
        str_starts_with($normalized, 'D2D') ||
        in_array($normalized, array('D2D-PUDO', 'ECO', 'OVN', 'LOX'), true)
    ) {
        return 'd2d';
    }

    return '';
}

// Add hidden submenu page
$pudo_change_locker_hook = null;

add_action(
    'admin_menu',
    function () use (&$pudo_change_locker_hook) {
        $pudo_change_locker_hook = add_submenu_page(
            'pudo', // hidden page
            'Change Locker',
            'Change Locker',
            'edit_shop_orders',
            'pudo-change-locker',
            'pudo_render_change_locker_page'
        );
    }
);

// Enqueue CSS/JS only for the hidden page.
add_action(
    'admin_enqueue_scripts',
    function ($hook_suffix) use (&$pudo_change_locker_hook) {
        // Only load on our hidden page
        if ($hook_suffix !== $pudo_change_locker_hook) {
            return;
        }

        $plugin_data    = get_plugin_data(__FILE__);
        $plugin_version = $plugin_data['Version'];

        // CSS
        wp_enqueue_style('pudo-bootstrap', plugins_url('dist/css/bootstrap.min.css', __FILE__), array(), '4.6.2');
        wp_enqueue_style(
            'pudo-fontawesome',
            plugins_url('dist/css/fontawesome.min.css', __FILE__),
            array(),
            '6.2.1'
        );

        // add order-locker.css
        wp_enqueue_style(
            'pudo-order-locker-css',
            plugins_url('dist/css/order-locker.css', __FILE__),
            array(),
            $plugin_version
        );

        // JS
        wp_enqueue_script('jquery');
        $changeLockerJsPath = plugin_dir_path(__FILE__) . 'dist/js/admin-change-locker.js';
        wp_enqueue_script(
            'pudo-admin-change-locker-js',
            plugins_url('dist/js/admin-change-locker.js', __FILE__),
            array('jquery'),
            $plugin_version . '.' . filemtime($changeLockerJsPath),
            false
        );

        // Localize JS here — can’t do it inside page callback.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['order_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order_id = absint($_GET['order_id']);

            $order                 = wc_get_order($order_id);
            $rawPudoMethod         = (string)$order->get_meta('pudo_method');
            $pudoMethodArray       = explode(':', $rawPudoMethod);
            $orderServiceLevelCode = $pudoMethodArray[1] ?? '';
            $orderMethod           = pudo_normalize_order_method_for_change_locker($rawPudoMethod);

            $pudoApi = PudoApi::getInstance();
            $lockers = $pudoApi->getAllLockers();

            wp_localize_script(
                'pudo-admin-change-locker-js',
                'pudoData',
                array(
                    'lockers'               => $lockers,
                    'orderID'               => $order_id,
                    'orderMethod'           => $orderMethod,
                    'orderServiceLevelCode' => $orderServiceLevelCode,
                    'redirectBackUrl'       => admin_url("post.php?post={$order_id}&action=edit"),
                    'ajaxUrl'               => admin_url('admin-ajax.php'),
                    'nonce'                 => wp_create_nonce('wc_custom_nonce'),
                )
            );
        }
    }
);

// Render the Change Locker page.
function pudo_render_change_locker_page()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (!isset($_GET['order_id'])) {
        wp_die(esc_html__('Missing order ID.', 'pudo-shipping-for-woocommerce'));
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $order_id = absint($_GET['order_id']);

    if (!current_user_can('edit_shop_order', $order_id)) {
        wp_die(esc_html__('Unauthorized access.', 'pudo-shipping-for-woocommerce'));
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        wp_die(esc_html__('Invalid order.', 'pudo-shipping-for-woocommerce'));
    }

    $redirectBackUrl = admin_url("post.php?post={$order_id}&action=edit");

    // Locker data
    $pudoLockerOrigin = explode(':', (string)$order->get_meta('pudo_locker_origin'));
    $lockerOriginCode = $pudoLockerOrigin[0] ?? '';

    $pudoLockerDestination = explode(':', (string)$order->get_meta('pudo_locker_destination'));
    $lockerDestinationCode = $pudoLockerDestination[0] ?? '';

    $rawPudoMethod         = (string)$order->get_meta('pudo_method');
    $pudoMethodArray       = explode(':', $rawPudoMethod);
    $orderServiceLevelCode = $pudoMethodArray[1] ?? '';
    $pudoMethod            = pudo_normalize_order_method_for_change_locker($rawPudoMethod);

    if ($orderServiceLevelCode === '') {
        $pudoMethodArray       = explode(':', (string)$order->get_meta('_order_shipping_data'));
        $orderServiceLevelCode = $pudoMethodArray[1] ?? '';
        $rawPudoMethod         = $orderServiceLevelCode;
    }

    $pudoApi = PudoApi::getInstance();
    $lockers = $pudoApi->getAllLockers();

    // Load template.
    $template_path = plugin_dir_path(__FILE__) . 'templates/change-locker-form.php';

    if (file_exists($template_path)) {
        $data = array(
            'order_id'              => $order_id,
            'order'                 => $order,
            'lockers'               => $lockers,
            'lockerOriginCode'      => $lockerOriginCode,
            'lockerDestinationCode' => $lockerDestinationCode,
            'orderServiceLevelCode' => $orderServiceLevelCode,
            'pudoMethod'            => $pudoMethod,
            'rawPudoMethod'         => $rawPudoMethod,
            'redirectBackUrl'       => $redirectBackUrl,
        );

        extract($data, EXTR_SKIP);
        require $template_path;
        exit;
    }

    wp_die(esc_html__('Template not found.', 'pudo-shipping-for-woocommerce'));
}

function getInstanceId()
{
    $instanceId = 0;
    $instances  = [];

    foreach (WC_Shipping_Zones::get_zones() as $zone) {
        foreach ($zone['shipping_methods'] as $method) {
            if ($method->id === 'pickup_dropoff') {
                $instances[] = [
                    'zone_id'     => $zone['id'],
                    'zone_name'   => $zone['zone_name'],
                    'instance_id' => $method->instance_id,
                    'enabled'     => $method->enabled,
                    'title'       => $method->title,
                ];
            }
        }
    }
    $zone = new WC_Shipping_Zone(0);

    foreach ($zone->get_shipping_methods() as $method) {
        if ($method->id === 'pickup_dropoff') {
            $instances[] = [
                'zone_id'     => 0,
                'zone_name'   => 'Rest of the world',
                'instance_id' => $method->instance_id,
                'enabled'     => $method->enabled,
                'title'       => $method->title,
            ];
        }
    }

    if (!empty($instances)) {
        $instanceId = $instances[0]['instance_id'];
    }

    return $instanceId;
}
