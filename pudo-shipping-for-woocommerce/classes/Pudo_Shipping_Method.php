<?php

namespace Pudo\WooCommerce;

defined('ABSPATH') || exit;

use PudoShippingData;
use WC_Order;
use Pudo\Common\Service\PudoShippingService;
use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use WC_Blocks_Utils;

use Pudo_WCOrderUtility;

use WC_Tax;

use function WC;

/**
 *  Copyright: © 2025 The Courier Guy
 */
class Pudo_Shipping_Method extends \WC_Shipping_Method
{


    const TITLE = 'The Courier Guy Locker';

    public $id;
    public $supports;
    public $title = null;
    public array $prohibitedProducts = array();
    public $method_description;
    public $instance_form_fields;
    public $enabled;
    public static ?\WC_Logger $wc_logger = null;
    public $method_title;

    private static ?bool $checkout_fields_added = null;
    private static bool $selected_option_hook_added = false;
    private static array $parameters;
    private bool $pudoAvailable;
    private array $lockers;
    private string $optionsParams;
    private string $class_name;
    private array $pudoShippingTypes = array('d2l-pudo', 'l2l-pudo', 'l2d-pudo', 'd2d-pudo', 'pickup_dropoff');
    public const ORDER_SHIPPING_DATA = '_order_shipping_data';

    private static array $printedShippingOptions = array();
    private array $defaultCheckoutRates = array();
    private string $defaultCheckoutKey = 'default_checkout_rates';

    /**
     * @var \Pudo\WooCommerce\PudoApi
     */
    private PudoApi $pudoApi;
    private PudoShippingService $pudoShippingService;
    private int $fitIndex = 0;

    /**
     * @param int $instance_id
     */
    public function __construct($instance_id = 0)
    {
        parent::__construct($instance_id);

        // 1. Properly Unslash and Sanitize the Nonce
        $nonce = isset($_POST['pudo_nonce']) ? sanitize_text_field(wp_unslash($_POST['pudo_nonce'])) : '';

        // 2. Properly Unslash the Raw Post Data
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_post_data = isset($_POST['post_data']) ? wp_unslash($_POST['post_data']) : '';

        if ($nonce && wp_verify_nonce($nonce, 'pudo_action')) {
            // 3. Parse the string into an array
            parse_str($raw_post_data, $post_data_array);

            if (is_array($post_data_array)) {
                // 4. Sanitize every key and value in the parsed array
                $sanitized_post_data = array();
                foreach ($post_data_array as $key => $value) {
                    $sanitized_key = sanitize_key($key);
                    if (is_array($value)) {
                        $sanitized_post_data[$sanitized_key] = array_map('sanitize_text_field', $value);
                    } else {
                        $sanitized_post_data[$sanitized_key] = sanitize_text_field($value);
                    }
                }
                $this->optionsParams = wp_json_encode($sanitized_post_data);
            }
        } else {
            $this->optionsParams = wp_json_encode(array());
        }

        $this->id            = 'pickup_dropoff';
        $this->class_name    = 'Pudo\WooCommerce\Pudo_Shipping_Method';
        $this->title         = self::TITLE;
        $this->enabled       = 'yes';
        $this->pudoAvailable = false;

        if (!empty($instance_id)) {
            $this->title = __('The Courier Guy Locker', 'pudo-shipping-for-woocommerce');
        }
        $this->supports = array(
            'settings',
            'shipping-zones',
            'instance-settings',
        );

        $this->method_title       = __('The Courier Guy Locker', 'pudo-shipping-for-woocommerce');
        $this->method_description = __(
            'The Courier Guy Locker is a smart locker system designed to allow South Africans to send and receive parcels around the country.',
            'pudo-shipping-for-woocommerce'
        );

        $this->pudoApi = PudoApi::getInstance();
        $this->lockers = $this->getPudoLockerOptions();

        $this->pudoShippingService = new PudoShippingService();

        if (empty($this->lockers)) {
            $this->lockers = array(
                array('CG54' => 'Sasol Rivonia Uplifted'),
            );
        }

        $fields                     = Pudo_Shipping_Settings::overrideFormFieldsVariable($this->lockers);
        $this->instance_form_fields = $fields;
        $this->settings             = $fields;
        if (false === get_transient($this->defaultCheckoutKey)) {
            $this->defaultCheckoutRates = array();
        } else {
            $this->defaultCheckoutRates = get_transient($this->defaultCheckoutKey);
        }

        add_action('woocommerce_update_options_shipping_methods', array($this, 'process_admin_options'));

        add_action('woocommerce_shipping_methods', array($this, 'add_pudo_shipping_method'));
        $this->enabled = 'yes';
        $this->init();

        add_action('woocommerce_checkout_process', array($this, 'updateCartOnCheckout'), 10);

        self::$parameters = $this->instance_settings;

        if (self::$wc_logger === null) {
            self::$wc_logger = wc_get_logger();
        }

        if (self::$checkout_fields_added === null) {
            // Add custom html to checkout page
            add_filter('woocommerce_checkout_fields', array($this, 'add_pudo_checkout_fields'));
            add_filter('woocommerce_cart_shipping_packages', array($this, 'add_pudo_address_fields'));
            self::$checkout_fields_added = true;
        }

        add_action('woocommerce_checkout_update_order_meta', array($this, 'updateShippingPropertiesOnOrder'), 10, 2);
        add_action('woocommerce_before_checkout_form', array($this, 'addProhibitedWarnings'), 10);
        add_action('woocommerce_checkout_update_order_review', array($this, 'addProhibitedWarnings'), 5);
        add_filter('woocommerce_package_rates', array($this, 'pudo_rates_filter'), 10, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'updateOrderOnCheckout'], 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'updateOrderAfterCreationBlocks'], 10, 1);
        add_filter('woocommerce_shipping_method_add_rate', [$this, 'add_rate_filter'], 10, 3);
        /**
         * This adds the selected TCG Locker option on the check out and provides a button to change the selection
         * if required. The if statement ensures that the hook is only added once to prevent duplicated renderings of
         * the selected option.
         * */
        if (!self::$selected_option_hook_added) {
            add_action(
                'woocommerce_review_order_after_shipping',
                array($this, 'renderSelectedPudoOptionOnCheckout'),
                20
            );
            self::$selected_option_hook_added = true;
        }

        delete_transient('tcg_lockers_rates_debounce');
    }

    public function updateCartOnCheckout($cart)
    {
        $wcSession               = WC()->session;
        $previousShippingMethods = $wcSession->get('previous_shipping_methods', array());
        $chosenShippingMethods   = WC()->session->get('chosen_shipping_methods', array());
        if (is_array($chosenShippingMethods) && array_key_exists('undefined', $chosenShippingMethods)) {
            unset($chosenShippingMethods['undefined']);
            WC()->session->set('chosen_shipping_methods', $previousShippingMethods[0]);
        }
    }

    public function updateOrderOnCheckout($orderId, $postedData, $order)
    {
        $nonce = isset($_POST['woocommerce-process-checkout-nonce']) ? sanitize_text_field(
            wp_unslash($_POST['woocommerce-process-checkout-nonce'])
        ) : '';
        if (!wp_verify_nonce($nonce, 'woocommerce-process_checkout')) {
            return;
        }
        $wcSession               = WC()->session;
        $pudoshippingRate        = WC()->session->get('pudoShippingRate', null);
        $previousShippingMethods = $wcSession->get('previous_shipping_methods', array());
        $chosenShippingMethods   = WC()->session->get('chosen_shipping_methods', array());
        $selectedShippingMethod  = '';
        if (isset($_POST['shipping_method'][0])) {
            $selectedShippingMethod = sanitize_text_field(wp_unslash($_POST['shipping_method'][0]));
        }
        if (is_array($chosenShippingMethods) && array_key_exists('undefined', $chosenShippingMethods)) {
            unset($chosenShippingMethods['undefined']);
            WC()->session->set('chosen_shipping_methods', $previousShippingMethods[0]);
        }
        $lockersMethods = ['pickup_dropoff', 'l2l-pudo', 'l2d-pudo', 'd2l-pudo', 'd2d-pudo'];
        if (in_array($selectedShippingMethod, $lockersMethods) || str_contains($selectedShippingMethod, 'd2d-pudo')) {
            foreach ($order->get_items('shipping') as $item_id => $shipping_item) {
                $order->remove_item($item_id);
            }
            $item = new \WC_Order_Item_Shipping();
            $item->set_method_title(
                $pudoshippingRate ? $pudoshippingRate->get_label() : 'The Courier Guy Locker'
            );
            $item->set_method_id('pickup_dropoff');
            $item->set_total($pudoshippingRate ? $pudoshippingRate->get_cost() : 0);
            $item->set_taxes([
                'total' => $pudoshippingRate->get_taxes() ?? 0,
            ]);
            $order->add_item($item);
            $order->calculate_taxes();
            $order->calculate_totals();
            $order->save();
        }
    }

    public function updateOrderAfterCreationBlocks($order)
    {
        $wc_session        = WC()->session;
        $destinationLocker = $wc_session->get('pudo_locker_destination', '');
        if (empty($destinationLocker) || $destinationLocker === 'none') {
            $destinationLocker = $wc_session->get('pudo-destination-locker-set-by-blocks', '');
        }
        $order->update_meta_data('pudo_locker_destination', $destinationLocker);
        $order->save_meta_data();
        $order->save();
    }

    public function add_rate_filter($rate, $args)
    {
        if (str_contains($rate->id, 'pickup_dropoff')) {
            WC()->session->set('pudoShippingRate', $rate);
        }

        return $rate;
    }

    /**
     * Echos out a shipping option only if it has not been echoed before
     *
     * @param string $html
     *
     * @return void
     * */
    private static function printShippingOptionToPage($html)
    {
        $hash = md5($html);

        if (array_key_exists($hash, self::$printedShippingOptions)) {
            return;
        }

        echo wp_kses($html, self::get_allowed_shipping_html());

        self::$printedShippingOptions[$hash] = $html;
    }

    /**
     * Helper to define exactly which tags we allow in the shipping rows.
     * This is more secure than wp_kses_post() as it restricts to specific checkout elements.
     */
    private static function get_allowed_shipping_html()
    {
        return array(
            'tr'     => array(),
            'th'     => array(),
            'td'     => array(),
            'strong' => array(),
            'label'  => array(
                'for' => array(),
            ),
            'input'  => array(
                'type'     => array(),
                'value'    => array(),
                'id'       => array(),
                'name'     => array(),
                'class'    => array(),
                'checked'  => array(),
                'disabled' => array(),
            ),
        );
    }

    /*
	 * Echos radios for shipping selection
	 *
	 * returns void
	 */
    function addPudoShippingRateOptions()
    {
        // 1. Debounce logic - ensure we don't spam the transient
        $helloTime = get_transient('tcg_lockers_rates_debounce');
        if ($helloTime && (time() - $helloTime) < 2) {
            return;
        }
        set_transient('tcg_lockers_rates_debounce', time(), 30);

        // 2. Safely decode and verify options
        $opts = json_decode($this->optionsParams, true);
        if (empty($opts)) {
            return;
        }

        $sp                       = $opts['shipping_method'][0] ?? '';
        $free_ship                = self::$parameters['free_shipping'] ?? 'no';
        $amount_for_free_shipping = self::$parameters['amount_for_free_shipping'] ?? 0;
        $subtotal                 = $this->resolveCartSubtotal(array());

        $pudo_source   = self::$parameters['pudo_source'] ?? 'locker';
        $sourceLiteral = ($pudo_source === 'street') ? 'D' : 'L';
        $radio_value   = ($pudo_source === 'street') ? 'd2l-pudo' : 'l2l-pudo';

        // --- Start Building HTML ---
        $html = '<tr><th></th><td>';
        $html .= sprintf(
            '<input type="radio" value="%1$s" id="2l-pudo" name="shipping_method[0]" class="update_totals_on_change" %2$s>',
            esc_attr($radio_value),
            checked(in_array($sp, array('d2l-pudo', 'l2l-pudo')), true, false)
        );

        $label_text = 'Deliver to a Locker (The Courier Guy Locker)';
        if (($free_ship === 'yes' && $subtotal >= $amount_for_free_shipping)) {
            $html .= sprintf('<label><strong>%s</strong></label>', esc_html($label_text));
        } elseif (isset($opts["{$sourceLiteral}2LPrice"])) {
            $l2lPrice = (float)$opts["{$sourceLiteral}2LPrice"];
            if ($l2lPrice > 0) {
                $l2lPrice = number_format($l2lPrice, 2);

                $html .= sprintf(
                    '<label>%s : <strong>R%s</strong></label>',
                    esc_html($label_text),
                    esc_html($l2lPrice)
                );
            } else {
                $html .= sprintf('<label><strong>%s</strong></label>', esc_html($label_text));
            }
        } else {
            $html .= sprintf('<label>%s</label>', esc_html($label_text));
        }
        $html .= '</td></tr>';

        self::printShippingOptionToPage($html);

        // 3. Conditional Door Delivery Logic
        if ($pudo_source !== 'street') {
            $html = '<tr><th></th><td>';
            $html .= sprintf(
                '<input type="radio" value="l2d-pudo" id="2d-pudo" name="shipping_method[0]" class="update_totals_on_change" %s>',
                checked($sp, 'l2d-pudo', false)
            );

            if (isset($opts["{$sourceLiteral}2DPrice"])) {
                $html .= sprintf(
                    '<label>Deliver to a Door (The Courier Guy Locker) : <strong>R%s</strong></label>',
                    esc_html($opts["{$sourceLiteral}2DPrice"])
                );
            } else {
                $html .= '<label>Deliver to a Door (The Courier Guy Locker)</label>';
            }
            $html .= '</td></tr>';
            self::printShippingOptionToPage($html);
        } elseif (isset($opts['d2drates']) && is_array($opts['d2drates'])) {
            // 4. Secure Loop for D2D Rates
            foreach ($opts['d2drates'] as $ddrate) {
                $val  = "d2d-pudo,{$ddrate['id']},{$ddrate['cost']}";
                $html = '<tr><th></th><td>';
                $html .= sprintf(
                    '<input type="radio" value="%1$s" id="%1$s" name="shipping_method[0]" class="update_totals_on_change" %2$s>',
                    esc_attr($val),
                    checked($sp, $val, false)
                );
                $html .= sprintf(
                    '<label>Deliver to a Door (%1$s) : <strong>R%2$s</strong></label>',
                    esc_html($ddrate['label']),
                    esc_html($ddrate['cost'])
                );
                $html .= '</td></tr>';
                self::printShippingOptionToPage($html);
            }
        }

        // 5. "Other Shipping" option
        if (in_array($sp, $this->pudoShippingTypes) || $sp === '') {
            $html = '<tr><th></th><td>';
            $html .= sprintf(
                '<input type="radio" value="no-pudo" id="no-pudo" name="shipping_method[0]" class="update_totals_on_change" %s>',
                checked($sp, 'no-pudo', false)
            );
            $html .= '<label>Other Shipping</label></td></tr>';
            self::printShippingOptionToPage($html);
        }

        // 6. Secure Script Injection
        if (!empty($opts['showModal']) && $opts['showModal'] === true) {
            // Use wp_add_inline_script if possible, otherwise escape the JS call
            echo '<script type="text/javascript">if(typeof pudoShowModal === "function") { pudoShowModal(); }</script>';
        }
    }

    /**
     * @return string (yes|no)
     */
    public function checkShippingDebug()
    {
        $woocommerce_shipping_debug = \WC_Admin_Settings::get_option('woocommerce_shipping_debug_mode', 'no');

        return $woocommerce_shipping_debug;
    }

    /**
     * If one of TCG Locker methods selected on checkout filter out all non-pudo methods
     *
     * @param array $rates
     *
     * @return array
     */
    public function pudo_rates_filter(array $rates): array
    {
        $rateNames = array_keys($rates);
        $hasPudo   = false;
        foreach ($rateNames as $rateName) {
            if (str_contains($rateName, 'pickup_dropoff')) {
                $hasPudo = true;
                break;
            }
        }

        if ($hasPudo) {
            $postData       = array();
            $selectedMethod = '';
            // Nonce verification and sanitization
            $nonce = isset($_POST['pudo_nonce']) ? sanitize_text_field(wp_unslash($_POST['pudo_nonce'])) : '';
            if ($nonce && wp_verify_nonce($nonce, 'pudo_action') && !empty($_POST['post_data'])) {
                // $_POST['post_data'] will be sanitized and unslashed in the same way as in the constructor, to ensure consistency
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $raw_post_data = wp_unslash($_POST['post_data']);
                parse_str($raw_post_data, $postData);
                // Sanitize all values
                foreach ($postData as $key => $value) {
                    $sanitized_key = sanitize_key($key);
                    if (is_array($value)) {
                        $postData[$sanitized_key] = array_map('sanitize_text_field', $value);
                    } else {
                        $postData[$sanitized_key] = sanitize_text_field($value);
                    }
                }
                $selectedMethod = $postData['shipping_method'][0] ?? '';
                // Validate l2l-pudo (same origin and destination)
                if ($selectedMethod === 'l2l-pudo' && $postData['pudo-locker-origin'] === $postData['pudo-locker-destination']) {
                    $rates = array_filter(
                        $rates,
                        function ($rate) {
                            return $rate->id !== 'pickup_dropoff:L2L:2:L2L';
                        }
                    );
                }
            }

            // Filter out non TCG Locker rates only if a TCG Locker method is selected (exclude 'no-pudo')
            if ($hasPudo || in_array($selectedMethod, array('l2l-pudo', 'd2l-pudo', 'l2d-pudo', 'd2d-pudo'))) {
                $rates = array_filter(
                    $rates,
                    function ($rate) {
                        return str_contains($rate->id, 'pickup_dropoff');
                    }
                );
            }

            // Sort rates by cost
            uasort(
                $rates,
                function ($a, $b) {
                    $a = (float)$a->cost;
                    $b = (float)$b->cost;

                    return $a == $b ? 0 : $a - $b;
                }
            );
        }

        return $rates;
    }

    /**
     * @param $orderId
     * @param $data
     *
     * @return void
     */
    public function updateShippingPropertiesOnOrder($orderId, $data)
    {
        // Verify WooCommerce checkout nonce
        if (!isset($_POST['woocommerce-process-checkout-nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['woocommerce-process-checkout-nonce'])),
                'woocommerce-process_checkout'
            )
        ) {
            return;
        }

        $sp                     = (string)WC()->session->get('pudo-shipping-method', 'no-pudo');
        $chosen_methods         = (string)WC()->session->get('pudo-shipping-method-block', '');
        $posted_shipping_method = '';

        if (isset($_POST['shipping_method'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw_shipping_method = wp_unslash($_POST['shipping_method']);
            if (is_array($raw_shipping_method)) {
                $posted_shipping_method = sanitize_text_field((string)($raw_shipping_method[0] ?? ''));
            } else {
                $posted_shipping_method = sanitize_text_field((string)$raw_shipping_method);
            }
        }

        if ($chosen_methods === '' && $posted_shipping_method !== '') {
            $chosen_methods = $posted_shipping_method;
        }

        // In Blocks checkout, the generic session key can be stale. Resolve from the selected block method first.
        if ($chosen_methods !== '' && str_starts_with($chosen_methods, $this->id . ':')) {
            $sp = $chosen_methods;
        } elseif ($chosen_methods !== '' && str_contains($chosen_methods, 'l2d-pudo')) {
            $l2d_method = (string)WC()->session->get('pudo-shipping-method-l2d', '');
            if ($l2d_method !== '') {
                $sp = $l2d_method;
            }
        } elseif ($chosen_methods !== '' && (str_contains($chosen_methods, 'd2l-pudo') || str_contains(
                    $chosen_methods,
                    'l2l-pudo'
                ))) {
            $dl2l_method = (string)WC()->session->get('pudo-shipping-method-dl2l', '');
            if ($dl2l_method !== '') {
                $sp = $dl2l_method;
            }
        }

        // Sanitize and Unslash all relevant POST inputs
        $originLockerName = isset($_POST['pudo-locker-origin-name'])
            ? sanitize_text_field(wp_unslash($_POST['pudo-locker-origin-name']))
            : '';

        $destinationLockerName = isset($_POST['pudo-locker-destination-name'])
            ? sanitize_text_field(wp_unslash($_POST['pudo-locker-destination-name']))
            : '';

        $originLockerCode = isset($_POST['pudo-locker-origin'])
            ? sanitize_text_field(wp_unslash($_POST['pudo-locker-origin']))
            : '';

        $destinationLockerCode = isset($_POST['pudo-locker-destination'])
            ? sanitize_text_field(wp_unslash($_POST['pudo-locker-destination']))
            : '';

        if (empty($destinationLockerCode) || $destinationLockerCode === 'none') {
            $destinationLockerCode = WC()->session->get('pudo_locker_destination', '');
        }

        $order   = wc_get_order($orderId);
        $usePudo = false;

        if (WC()->session) {
            $lockerOrigin = $originLockerCode . ':' . $originLockerName;
            WC()->session->set('pudo_source_locker', $lockerOrigin);

            $lockerDestination = $destinationLockerCode ? ($destinationLockerCode . ':' . $destinationLockerName) : '';

            if (str_starts_with($sp, $this->id)) {
                $usePudo = $this->checkPudoShipment();
            }
        }
        if ($usePudo) {
            $metaUpdates = array(
                self::ORDER_SHIPPING_DATA        => json_encode($sp),
                'pudo_method'                    => $sp,
                'pudo_status'                    => 'none',
                'pudo_ship_to_different_address' => $data['ship_to_different_address'] ?? '',
            );

            $_SESSION['pudo-shipping-method'] = '';
            $order->update_meta_data(self::ORDER_SHIPPING_DATA, json_encode($sp));
            $order->update_meta_data('pudo_method', $sp);
            $order->add_meta_data('pudo_status', 'none', true);
            $order->update_meta_data('pudo_ship_to_different_address', $data['ship_to_different_address']);
            $spArr = explode(':', $sp);
            // Determine type and mansage order fields
            if (isset($spArr[3]) && $spArr[3] == 'D2L') {
                $order->update_meta_data('pudo_locker_destination', $lockerDestination);
                $metaUpdates['pudo_locker_destination'] = $lockerDestination;
            } elseif (isset($spArr[3]) && $spArr[3] == 'L2D') {
                $order->update_meta_data('pudo_locker_origin', $lockerOrigin);
                $order->update_meta_data('pudo_locker_destination', 'none');
                $metaUpdates['pudo_locker_origin']      = $lockerOrigin;
                $metaUpdates['pudo_locker_destination'] = 'none';
            } elseif (isset($spArr[3]) && $spArr[3] == 'L2L') {
                $order->update_meta_data('pudo_locker_origin', $lockerOrigin);
                $order->update_meta_data('pudo_locker_destination', $lockerDestination);
                $metaUpdates['pudo_locker_origin']      = $lockerOrigin;
                $metaUpdates['pudo_locker_destination'] = $lockerDestination;
            } elseif (isset($spArr[3]) && $spArr[3] == 'D2D') {
                $order->update_meta_data('pudo_locker_origin', 'none');
                $order->update_meta_data('pudo_locker_destination', 'none');
                $metaUpdates['pudo_locker_origin']      = 'none';
                $metaUpdates['pudo_locker_destination'] = 'none';
            }

            wc_get_logger()->info(
                sprintf(
                    'TCG Locker order meta updates on checkout_update_order_meta: order_id=%d | updates=%s',
                    (int)$orderId,
                    wp_json_encode($metaUpdates)
                ),
                array(
                    'source'   => 'pudo-for-wc',
                    'order_id' => (int)$orderId,
                    'updates'  => $metaUpdates,
                )
            );
        }
        $order->save_meta_data();
    }

    /**
     * @param string $method
     *
     * @return false|string
     */
    private static function isPudoMethod(string $method)
    {
        return str_contains($method, 'pickup_dropoff');
    }

    /**
     * Resolve subtotal reliably for checkout refreshes where cart_subtotal may be missing.
     */
    private function resolveCartSubtotal(array $package): float
    {
        if (isset($package['cart_subtotal'])) {
            return (float)$package['cart_subtotal'];
        }

        if (!WC()->cart) {
            return 0.0;
        }

        $subtotal = (float)WC()->cart->subtotal;
        if ($subtotal > 0) {
            return $subtotal;
        }

        return (float)WC()->cart->get_subtotal();
    }

    public function add_pudo_address_fields(array $fields): array
    {
        if (
            !isset($_POST['security']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['security'])),
                'update-order-review'
            )
        ) {
            return $fields;
        }

        $postData = array();

        if (!empty($_POST['post_data'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            parse_str(wp_unslash($_POST['post_data']), $postData);

            $fields[0]['destination']['pudo-select'] =
                isset($postData['shipping_method'][0])
                    ? sanitize_text_field($postData['shipping_method'][0])
                    : 'deliver_to_locker';

            $fields[0]['destination']['pudo-locker-origin'] =
                isset($postData['pudo-locker-origin'])
                    ? sanitize_text_field($postData['pudo-locker-origin'])
                    : '';

            $fields[0]['destination']['pudo-locker-destination'] =
                isset($postData['pudo-locker-destination'])
                    ? sanitize_text_field($postData['pudo-locker-destination'])
                    : '';
        } else {
            $fields[0]['destination']['pudo-select'] =
                isset($_POST['shipping_method'][0])
                    ? sanitize_text_field(wp_unslash($_POST['shipping_method'][0]))
                    : 'deliver_to_locker';

            $fields[0]['destination']['pudo-locker-origin'] =
                isset($_POST['pudo-locker-origin'])
                    ? sanitize_text_field(wp_unslash($_POST['pudo-locker-origin']))
                    : '';

            $fields[0]['destination']['pudo-locker-destination'] =
                isset($_POST['pudo-locker-destination'])
                    ? sanitize_text_field(wp_unslash($_POST['pudo-locker-destination']))
                    : '';
        }

        return $fields;
    }

    /**
     * @return void
     */
    public function init()
    {
        // Load the settings API
        $this->init_form_fields();
        $this->init_settings();
        $this->init_instance_settings();

        // Save admin settings
        add_action('woocommerce_update_options_shipping_methods', array($this, 'process_admin_options'));

        add_filter('woocommerce_no_shipping_available_html', array($this, 'custom_no_shipping_message'), 1);
    }

    public function custom_no_shipping_message($message)
    {
        return $this->pudoAvailable ? 'Choose The Courier Guy Locker option' : $message;
    }

    /**
     * @return \WC_Logger|null
     */
    public static function get_wc_logger(): ?\WC_Logger
    {
        return self::$wc_logger;
    }

    /**
     * Add this method to the WC Shopping Methods
     *
     * @param array $methods
     *
     * @return mixed
     */
    public function add_pudo_shipping_method(array $methods): array
    {
        $methods[$this->id] = 'Pudo\WooCommerce\Pudo_Shipping_Method';

        return $methods;
    }

    /**
     * @param array $package
     *
     * @throws \Exception
     */
    public function calculate_shipping($package = array())
    {
        if (
            isset($_POST['security']) &&
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['security'])),
                'update-order-review'
            )
        ) {
            return;
        }

        // Check if any of the products are prohibited
        $this->prohibitedProducts = $this->isPudoProductProhibited($package);
        if (!empty($this->prohibitedProducts)) {
            return;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'store_locker_code' && isset($_POST['locker_code'])) {
            $lockerCode = sanitize_text_field(wp_unslash($_POST['locker_code']));
            WC()->session->set(
                'pudo-destination-locker-set-by-blocks',
                $lockerCode
            );
            set_transient('pudo-destination-locker-set-by-blocks', $lockerCode, 60);
        }

        $packageHash       = md5(json_encode($package));
        $wcSession         = WC()->session;
        $destinationLocker = $wcSession->get('pudo_locker_destination', '');
        if (empty($destinationLocker) || $destinationLocker === 'none') {
            $destinationLocker = $wcSession->get('pudo-destination-locker-set-by-blocks', '');
        }

        $freeShippingFinal = false;
        // Free shipping product settings
        $product_free_shipping = false;
        if (isset($package['contents'])) {
            foreach ($package['contents'] as $product) {
                $pfs = get_post_meta($product['product_id'], 'product_free_shipping_pudo', true);
                if ($pfs === 'on' || $pfs === 'yes') {
                    $product_free_shipping = true;
                }
            }
        }

        // Free shipping global settings
        $free_ship                = $this->get_instance_option('free_shipping');
        $amount_for_free_shipping = $this->get_instance_option('amount_for_free_shipping');
        $subtotal                 = $this->resolveCartSubtotal($package);

        if ((($free_ship === 'yes' || $free_ship === 'on') && $subtotal >= $amount_for_free_shipping)
            || $product_free_shipping) {
            $freeShippingFinal = true;
        }
        $transientKey        = 'pudo_shipping_rates_' . $packageHash . '_' . $destinationLocker;
        $transientFreeKey    = "free_shipping_{$packageHash}_{$destinationLocker}";
        $rates               = get_transient($transientKey);
        $freeShippingChanged = get_transient($transientFreeKey) !== ($freeShippingFinal ? 'free' : 'not_free');
        
        // log rates and free shipping status for debugging
        wc_get_logger()->info(
            sprintf(
                'Pudo Shipping Rates Debug: packageHash=%s, destinationLocker=%s, rates=%s, freeShippingChanged=%s, freeShippingFinal=%s',
                $packageHash,
                $destinationLocker,
                wp_json_encode($rates),
                $freeShippingChanged ? 'true' : 'false',
                $freeShippingFinal ? 'true' : 'false'
            ),
            array('source' => 'pudo-for-wc')
        );
        
        if ($rates && !$freeShippingChanged) {
            $this->rates = $rates;

            if (!has_action('woocommerce_review_order_after_shipping', array($this, 'addPudoShippingRateOptions'))) {
                add_action(
                    'woocommerce_review_order_after_shipping',
                    array($this, 'addPudoShippingRateOptions'),
                    10,
                    2
                );
            }
            set_transient($transientFreeKey, $freeShippingFinal ? 'free' : 'not_free', 15);

            return;
        }

        // Gather environmental variables
        $settings         = $this->instance_settings;
        self::$parameters = $settings;
        $postData         = array();

        // Parse postdata
        if (!empty($_POST['post_data'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            parse_str(wp_unslash($_POST['post_data']), $postData);
        }

        // Gather shipping method value
        $sp = isset($postData['shipping_method'][0])
            ? sanitize_text_field($postData['shipping_method'][0])
            : '';

        // Begin validation

        // If shipping is local_pickup, set value to no-pudo
        if (str_contains($sp, 'local_pickup')) {
            $postData['pudo-select'] = 'no-pudo';
        }

        // Get box size for cart
        list($boxes, $sortedBoxClass) = $this->getPudoBoxOptions();

        $apiPayload        = new Pudo_Api_Payload();
        $settings['boxes'] = $boxes;
        if (isset($package['contents'])) {
            $parcels = $apiPayload->getContentsPayload($settings, $package['contents']);
        } else {
            $parcels = array();
        }

        if (!empty($parcels)) {
            $this->fitIndex = $parcels[0]['fitIndex'];
        }

        if (count($parcels) !== 1) {
            $this->pudoAvailable = false;

            return;
        }

        $this->pudoAvailable = true;

        wc_get_logger()->log('info', $sp);

        // If there is post data available
        if (!empty($_POST['post_data'])) {
            $shippingDetails = array(
                'name'           => isset($postData['billing_first_name']) ? sanitize_text_field(
                    $postData['billing_first_name']
                ) : '',
                'email'          => isset($postData['billing_email']) ? sanitize_email(
                    $postData['billing_email']
                ) : '',
                'mobile_number'  => isset($postData['billing_phone']) ? sanitize_text_field(
                    $postData['billing_phone']
                ) : '',
                'street_address' => isset($_POST['s_address']) ? sanitize_text_field(
                    wp_unslash($_POST['s_address'])
                ) : (isset($postData['shipping_address_1']) ? sanitize_text_field(
                    $postData['shipping_address_1']
                ) : ''),
                'city'           => isset($_POST['s_city']) ? sanitize_text_field(
                    wp_unslash($_POST['s_city'])
                ) : (isset($postData['shipping_city']) ? sanitize_text_field($postData['shipping_city']) : ''),
                'code'           => isset($_POST['s_postcode']) ? sanitize_text_field(
                    wp_unslash($_POST['s_postcode'])
                ) : (isset($postData['shipping_postcode']) ? sanitize_text_field(
                    $postData['shipping_postcode']
                ) : ''),
                'zone'           => isset($_POST['s_state']) ? sanitize_text_field(
                    wp_unslash($_POST['s_state'])
                ) : (isset($postData['shipping_state']) ? sanitize_text_field($postData['shipping_state']) : ''),
                'country'        => isset($_POST['s_country']) ? sanitize_text_field(
                    wp_unslash($_POST['s_country'])
                ) : (isset($postData['shipping_country']) ? sanitize_text_field(
                    $postData['shipping_country']
                ) : ''),
            );

            $destinationLocker = isset($postData['pudo-locker-destination']) ? sanitize_text_field(
                $postData['pudo-locker-destination']
            ) : '';
            if (empty($destinationLocker) || ($destinationLocker ?? '') === 'none') {
                $destinationLocker = WC()->session->get('pudo-destination-locker-set-by-blocks', '');
            }
            if (empty($destinationLocker)) {
                $destinationLocker = WC()->session->get('pudo-destination-locker', '');
            }
            if (empty($destinationLocker)) {
                $destinationLocker = WC()->session->get('pudo_locker_destination', '');
            }
            $lockerData = array(
                'pudo-source-locker'      => $postData['pudo-locker-origin'],
                'pudo-destination-locker' => $destinationLocker,
            );

            if (empty($lockerData['pudo-destination-locker']) || ($lockerData['pudo-destination-locker'] ?? '') === 'none') {
                $lockerData['pudo-destination-locker'] = $this->pudoApi->getDefaultLockerCode();
            }

            // Get the fit index (Index of type of delivery package)
            $fittedBox = $sortedBoxClass[$parcels[0]['fitIndex']];

            if (empty($lockerData['pudo-destination-locker'])) {
                $lockerData['pudo-destination-locker'] =
                    WC()->session->get('pudo_destination_locker', '');
            }

            $rate = $this->buildPudoRate(
                $sp,
                $fittedBox,
                $settings,
                $shippingDetails,
                $lockerData,
                $parcels,
                $freeShippingFinal
            );
            // Add shipping options
            if (!has_action('woocommerce_review_order_after_shipping', 'addPudoShippingRateOptions')) {
                add_action(
                    'woocommerce_review_order_after_shipping',
                    array($this, 'addPudoShippingRateOptions'),
                    10,
                    2
                );
            }

            if ($sp === 'l2d-pudo' || $sp === 'l2l-pudo') {
                WC()->session->set('chosen-shipping-method', $rate['id']);
                WC()->session->set('chosen-shipping-rate', $rate);
            } elseif (str_contains($sp, 'pickup_dropoff')) {
                WC()->session->set('chosen-shipping-method', null);
                WC()->session->set('chosen-shipping-rate', null);
            }

            if (strpos($sp ?? '', 'd2d-pudo') === 0) {
                $d2drate = explode(',', $sp);
                $rateId  = "pickup_dropoff:$d2drate[1]:2:D2D:" . $fittedBox['name'];
                $rate    = array(
                    'id'       => $rateId,
                    'label'    => "The Courier Guy Locker D2D - $d2drate[1]",
                    'cost'     => $d2drate[2],
                    'calc_tax' => 'per_order',
                    'taxes'    => array(
                        1 => 0,
                    ),
                );
                $sp      = 'd2d-pudo';

                // Define shipping  ID
                WC()->session->set('pudo-shipping-method', $rateId);
            }
            // Identify if shipping price and shipping method is to be applied
            if (in_array($sp, $this->pudoShippingTypes)) {
                $this->add_rate($rate);
            } elseif (str_contains($sp, 'pickup_dropoff')) {
                $this->add_rate($rate);
            }
        } else {
            $this->getD2DRates($settings, $freeShippingFinal, $parcels);
        }

        if (WC_Blocks_Utils::has_block_in_page(
                wc_get_page_id('checkout'),
                'woocommerce/checkout'
            ) && self::$parameters['pudo_source'] != 'street') {
            if (empty($destinationLocker) || $destinationLocker === 'none') {
                if (isset($_POST['action']) && $_POST['action'] === 'store_locker_code') {
                    $destinationLocker = sanitize_text_field(wp_unslash($_POST['locker_code'] ?? ''));
                } elseif (WC()->session->get('pudo-destination-locker-set-by-blocks', '') !== '') {
                    $destinationLocker = WC()->session->get('pudo-destination-locker-set-by-blocks', '');
                } else {
                    $destinationLocker = '';
                }
            }
            // If the source is not street, we are in L2L or L2D
            $lockerData = array(
                'pudo-source-locker'      => $settings['pudo_locker_name'] ?? '',
                'pudo-destination-locker' => $postData['pudo-locker-destination'] ?? $destinationLocker ?? '',
            );

            $lockerName = $this->lockers[$settings['pudo_locker_name']] ?? '';
            WC()->session->set('pudo_source_locker', $settings['pudo_locker_name'] . ':' . $lockerName);

            $this->getL2lBlocks(
                $sortedBoxClass[$parcels[0]['fitIndex']],
                $parcels,
                $lockerData,
                $settings,
                $freeShippingFinal
            );

            // Checkout page is using blocks
            $lockerData = array(
                'pudo-source-locker' => $settings['pudo_locker_name'] ?? '',
            );
            $this->getL2dBlocks(
                $sortedBoxClass[$parcels[0]['fitIndex']],
                $parcels,
                $lockerData,
                $settings,
                $freeShippingFinal
            );
        }

        if (WC_Blocks_Utils::has_block_in_page(
                wc_get_page_id('checkout'),
                'woocommerce/checkout'
            ) && self::$parameters['pudo_source'] === 'street') {
            if (empty($lockerData['pudo-destination-locker'])) {
                $lockerData['pudo-destination-locker'] =
                    WC()->session->get('pudo-destination-locker-set-by-blocks', '');
            }
            $this->getD2lBlocks(
                $sortedBoxClass[$parcels[0]['fitIndex']],
                $parcels,
                $lockerData,
                $settings,
                $freeShippingFinal
            );
        }

        // log the calculated rates and free shipping status for debugging purposes
        wc_get_logger()->info(
            sprintf(
                'Calculated Pudo shipping rates: %s | Free shipping status: %s',
                wp_json_encode($this->rates),
                $freeShippingFinal ? 'free' : 'not_free'
            ),
            array(
                'source' => 'pudo-for-wc',
            )
        );

        set_transient($transientKey, $this->rates, 60 * 5);
        set_transient($transientFreeKey, $freeShippingFinal ? 'free' : 'not_free', 15);

        add_action('woocommerce_review_order_after_shipping', array($this, 'addPudoShippingRateOptions'), 10, 2);
    }

    // get d2d

    /**
     * @return void
     */
    public function getD2DRates($settings, $freeShipping, $parcels = null)
    {
        if (
            isset($_POST['security']) &&
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['security'])),
                'update-order-review'
            )
        ) {
            return;
        }

        if (self::$parameters['pudo_source'] != 'street') {
            return;
        }

        if (isset($_POST['post_data'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            parse_str(wp_unslash($_POST['post_data']), $postData);
        }

        $data = array(
            'pudo-source-locker'      => isset($postData['pudo-locker-origin'])
                ? sanitize_text_field($postData['pudo-locker-origin'])
                : '',
            'pudo-destination-locker' => isset($postData['pudo-locker-destination'])
                ? sanitize_text_field($postData['pudo-locker-destination'])
                : '',
            'parcels'                 => $parcels,
        );

        $customer = WC()->customer;

        $order = $this->getCustomerShippingDetails($customer);

        // Do the same for L2L, L2D, D2L always use $lockerRates['rates'][0];

        // Gather d2d pudo rates
        $shippingData      = new PudoShippingData('D2D', false);
        $apiRequestBuilder = $shippingData->initializeAPIRequestBuilder($order, $settings, $data, 'D2D');
        $d2lLockerRates    = $this->pudoApi->getRates(json_encode($apiRequestBuilder->buildRatesRequest()));

        $lockerRates = array();
        if (is_string($d2lLockerRates['body'])) {
            $lockerRates = json_decode($d2lLockerRates['body'], true);
        }

        // Apply rate overrides if they exist
        $pudoShippingService = new PudoShippingService();
        foreach ($lockerRates['rates'] as $key => $rate) {
            $serviceLevelCode = $rate['service_level']['code'];
            $rateLabel        = $rate['service_level']['name'];
            $ratePrice        = $rate['rate'];
            $pudoShippingService->applyRateOverrides($settings, $rateLabel, $ratePrice, $serviceLevelCode);
            $lockerRates['rates'][$key]['rate']                  = $ratePrice;
            $lockerRates['rates'][$key]['service_level']['name'] = $rateLabel;
        }

        if (empty($lockerRates['rates'])) {
            $chosenShippingMethod = WC()->session->get('chosen-shipping-method', 'no-pudo');
            $chosenShippingRate   = WC()->session->get('chosen-shipping-rate', null);
            if ($chosenShippingRate !== null && $this->isPudoMethod($chosenShippingMethod)) {
                $this->add_rate($chosenShippingRate);
            }
        }
        // Filter out the D2D rates as the API is broken when creating shipments with these rates
        foreach ($lockerRates['rates'] as $key => $rate) {
            if (str_contains($rate['service_level']['code'], 'D2D')) {
                unset($lockerRates['rates'][$key]);
            }
        }

        foreach ($lockerRates['rates'] as $rateData) {
            $serviceLevel = $rateData['service_level'];

            $rate = array(
                'id'       => $serviceLevel['code'],
                'label'    => 'The Courier Guy Locker ' . $serviceLevel['name'] . ($freeShipping ? ' - FREE' : ''),
                'cost'     => $freeShipping ? 0.00 : $rateData['rate'],
                'calc_tax' => 'per_order',
            );

            $this->add_rate($rate);
        }
    }


    /**
     *
     * @return bool
     */
    public function checkPudoShipment(): bool
    {
        if (!isset($_POST['woocommerce-process-checkout-nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['woocommerce-process-checkout-nonce'])),
                'woocommerce-process_checkout'
            )
        ) {
            return false;
        }

        if (isset($_POST['shipping_method'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $shippingMethod = wp_unslash($_POST['shipping_method']);

            // If it's an array, get the first element, otherwise use the string directly
            $method = is_array($shippingMethod) ? $shippingMethod[0] : $shippingMethod;
            if (str_starts_with($method, 'l2d-pudo') || str_starts_with($method, 'd2d-pudo')) {
                return true;
            }

            // Check if 'pudo' is in the method
            return in_array(
                $method,
                array_merge($this->pudoShippingTypes, ['pickup_dropoff'])
            );
        }

        return false;
    }

    /**
     * This method is called to build the UI for custom shipping setting of type 'pudo_override_per_service'.
     * This method must be overridden as it is called by the parent class WC_Settings_API.
     *
     * @param $key
     * @param $data
     *
     * @return string
     * @uses WC_Settings_API::get_custom_attribute_html()
     * @uses WC_Shipping_Method::get_option()
     * @uses WC_Settings_API::get_field_key()
     * @uses WC_Settings_API::get_tooltip_html()
     * @uses WC_Settings_API::get_description_html()
     */
    public function generate_pudo_override_per_service_html($key, $data)
    {
        $field_key      = $this->get_field_key($key);
        $defaults       = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
            'options'           => array(),
        );
        $data           = wp_parse_args($data, $defaults);
        $overrideValue  = $this->get_option($key);
        $overrideValues = json_decode($overrideValue, true);
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="
				<?php
                echo esc_attr($field_key);
                ?>
				_select">
                    <?php
                    echo wp_kses_post($data['title']);
                    ?>
                    <?php
                    echo wp_kses_post($this->get_tooltip_html($data)); // WPCS: XSS ok.
                    ?>
                </label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text">
                        <span><?php
                            echo wp_kses_post($data['title']); ?></span></legend>
                    <select class="select <?php
                    echo esc_attr($data['class']); ?>"
                            style="<?php
                            echo esc_attr($data['css']); ?>"
                        <?php
                        disabled($data['disabled'], true);
                        ?>
                        <?php
                        echo wp_kses_post($this->get_custom_attribute_html($data)); // WPCS: XSS ok.
                        ?>
                    >
                        <option value="">Select a Service</option>
                        <?php
                        $prefix = ' - ';
                        if ($field_key == 'woocommerce_pudo_price_rate_override_per_service') {
                            $prefix = ' - R ';
                        }
                        ?>
                        <?php
                        foreach ((array)$data['options'] as $option_key => $option_value) :
                            $value = esc_attr($option_value);
                            $value .= (!empty($overrideValues[$option_key])) ? esc_attr(
                                $prefix . $overrideValues[$option_key]
                            ) : '';
                            ?>
                            <option value="<?php
                            echo esc_attr($option_key); ?>"
                                    data-service-label="<?php
                                    echo esc_attr($option_value); ?>">
                                <?php
                                echo esc_attr($value); ?>
                            </option>
                        <?php
                        endforeach;
                        ?>
                    </select>
                    <?php
                    foreach ((array)$data['options'] as $option_key => $option_value) :
                        $class = esc_attr($data['class']) . '-span-'
                                 . esc_attr($option_key);
                        ?>
                        <span style="display:none;" class="<?php
                        echo esc_attr($class); ?>">
							<?php
                            $class = '';
                            $style = '';
                            if ($field_key == 'woocommerce_pudo_price_rate_override_per_service') {
                                $class = 'wc_input_price ';
                                $style = ' style="width: 90px !important;" ';
                                ?>
                                <span style="position:relative; top:8px; padding:0 0 0 10px;">R </span>
                                <?php
                            }
                            $class = "$class input-text regular-input " . esc_attr($data['class']) . '-input';
                            $class = trim($class);
                            ?>
							<input data-service-id="<?php
                            echo esc_attr($option_key); ?>"
                                   class="<?php
                                   echo esc_attr($class); ?>"
                                   type="text"
									<?php
                                    echo esc_attr($style); ?>
									value="<?php
                                    echo isset($overrideValues[$option_key]) ? esc_attr(
                                        $overrideValues[$option_key]
                                    ) : ''; ?>"/>
						</span>
                    <?php
                    endforeach;
                    ?>
                    <?php
                    echo wp_kses_post($this->get_description_html($data)); // WPCS: XSS ok.
                    ?>
                    <input type="hidden" name="<?php
                    echo esc_attr($field_key); ?>"
                           value="<?php
                           echo esc_attr($overrideValue); ?>"/>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * @param string $selectedMethod
     * @param $sortedBoxClass
     * @param $settings
     * @param $shippingDetails
     * @param $lockerData
     * @param $parcels
     * @param bool $freeShipping
     *
     * @return array
     */
    private function buildPudoRate(
        string $selectedMethod,
        $fittedBox,
        $settings,
        $shippingDetails,
        $lockerData,
        $parcels,
        $freeShipping = false
    ): array {
        $postData = array();

        if (
            isset($_POST['security']) &&
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['security'])),
                'update-order-review'
            )
        ) {
            return array();
        }

        if (!empty($_POST['post_data'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            parse_str(wp_unslash($_POST['post_data']), $postData);
        }
        // Define tax rates dependent on woocommerce on config
        $taxes_enabled = get_option('woocommerce_calc_taxes') ?? 'true';
        $taxRate       = 0.0;
        if ($taxes_enabled && $settings['tax_status'] === 'taxable') {
            $taxRates = WC_Tax::get_rates();
            foreach ($taxRates as $tax) {
                if (isset($tax['shipping']) && $tax['shipping'] == 'yes') {
                    $taxRate = $tax['rate'];
                    break;
                }
            }
        }

        // Get the fit index (Index of type of delivery package)
        $lockerData['fittedBox'] = $fittedBox;

        $total    = 0.00;
        $rateCode = '';

        if ($settings['pudo_source'] === 'street') {
            unset($this->pudoShippingTypes[1]);
            unset($this->pudoShippingTypes[2]);
        } else {
            unset($this->pudoShippingTypes[0]);
            unset($this->pudoShippingTypes[3]);
        }

        foreach ($this->pudoShippingTypes as $pudoShippingType) {
            $iteratedMethod = strtoupper(substr($pudoShippingType, 0, 3));

            // pudo-destination-locker
            $pudoDestinationLocker = $lockerData['pudo-destination-locker'] ?? '';
            if (isset($this->pudoApi->lockers[$pudoDestinationLocker]) &&
                isset($this->pudoApi->lockers[$pudoDestinationLocker]['type']) &&
                isset($this->pudoApi->lockers[$pudoDestinationLocker]['type']['name'])
            ) {
                $pudoDestinationLockerTypeName = $this->pudoApi->lockers[$pudoDestinationLocker]['type']['name'];
            } else {
                $pudoDestinationLockerTypeName = '';
            }

            // pudo-destination-locker
            $pudoSourceLocker = $lockerData['pudo-source-locker'] ?? '';
            if (isset($this->pudoApi->lockers[$pudoSourceLocker]) &&
                isset($this->pudoApi->lockers[$pudoSourceLocker]['type']) &&
                isset($this->pudoApi->lockers[$pudoSourceLocker]['type']['name'])
            ) {
                $pudoSourceLockerTypeName = $this->pudoApi->lockers[$pudoSourceLocker]['type']['name'];
            } else {
                $pudoSourceLockerTypeName = '';
            }

            if ($iteratedMethod === 'D2L') {
                $sourceLetter = 'D';
                switch ($pudoDestinationLockerTypeName) {
                    case 'Kiosk':
                        $destinationLetter = 'K';
                        break;
                    case 'Locker':
                        $destinationLetter = 'L';
                        break;
                    case 'Pickup Point':
                        $destinationLetter = 'P';
                        break;
                    default:
                        $destinationLetter = '';
                }
            }

            if ($iteratedMethod === 'L2D') {
                switch ($pudoSourceLockerTypeName) {
                    case 'Kiosk':
                        $sourceLetter = 'K';
                        break;
                    case 'Locker':
                        $sourceLetter = 'L';
                        break;
                    case 'Pickup Point':
                        $sourceLetter = 'P';
                        break;
                    default:
                        $sourceLetter = '';
                }
                $destinationLetter = 'D';
            }

            if ($iteratedMethod === 'D2D') {
                $sourceLetter      = 'D';
                $destinationLetter = 'D';
            }

            if ($iteratedMethod === 'L2L') {
                switch ($pudoSourceLockerTypeName) {
                    case 'Kiosk':
                        $sourceLetter = 'K';
                        break;
                    case 'Locker':
                        $sourceLetter = 'L';
                        break;
                    case 'Pickup Point':
                        $sourceLetter = 'P';
                        break;
                    default:
                        $sourceLetter = '';
                }
                switch ($pudoDestinationLockerTypeName) {
                    case 'Kiosk':
                        $destinationLetter = 'K';
                        break;
                    case 'Locker':
                        $destinationLetter = 'L';
                        break;
                    case 'Pickup Point':
                        $destinationLetter = 'P';
                        break;
                    default:
                        $destinationLetter = '';
                }
            }

            $iteratedRateCode = $sourceLetter . '2' . $destinationLetter . explode(
                    '-',
                    $fittedBox['name']
                )[1] . ' - ECO';

            $shippingData      = new PudoShippingData($iteratedMethod, false);
            $apiRequestBuilder = $shippingData->initializeAPIRequestBuilder(
                $shippingDetails,
                $settings,
                $lockerData,
                $iteratedMethod
            );
            $checkoutRates     = $this->pudoApi->getRates(json_encode($apiRequestBuilder->buildRatesRequest()));

            $checkoutRates = json_decode($checkoutRates['body'], true);

            if (isset($checkoutRates['rates'])) {
                foreach ($checkoutRates['rates'] as $rateData) {
                    $serviceLevel = $rateData['service_level'];
                    if ($serviceLevel['code'] === $iteratedRateCode) {
                        $price          = $rateData['rate'];
                        $exclusivePrice = $rateData['rate_excluding_vat'] ?? $price;
                        $vatCharge      = (float)$price - $exclusivePrice;
                        if ($iteratedMethod === 'L2L') {
                            $optionsParams             = json_decode($this->optionsParams);
                            $optionsParams['L2LPrice'] = $price;
                            $this->optionsParams       = json_encode($optionsParams);
                        }
                        $iteratedPrice = $freeShipping ? 0.00 : $price;

                        $postData[$iteratedMethod . 'Price'] = $iteratedPrice;

                        $selectedMethodParts = explode(':', $selectedMethod);
                        $method              = '';
                        if (($selectedMethodParts[0] ?? '') === 'pickup_dropoff' && count($selectedMethodParts) >= 2) {
                            $method = $selectedMethodParts[1];
                        }

                        if ($selectedMethod === $pudoShippingType || $iteratedRateCode === $method) {
                            $selectedMethod = $iteratedMethod;
                            $total          = $iteratedPrice;
                            $rateCode       = $iteratedRateCode;
                        }
                        break;
                    }
                }
            } elseif (isset($defaultCheckoutRates['rates'])) {
                foreach ($defaultCheckoutRates['rates'] as $rateData) {
                    $serviceLevel = $rateData['service_level'];

                    if ($serviceLevel['code'] === $iteratedRateCode) {
                        $price         = $rateData['rate'];
                        $iteratedPrice = $freeShipping ? 0.00 : $price;

                        $postData[$iteratedMethod . 'Price'] = $iteratedPrice;

                        if ($selectedMethod === $pudoShippingType) {
                            $selectedMethod = $iteratedMethod;
                            $total          = $iteratedPrice;
                            $rateCode       = $iteratedRateCode;
                        }
                        break;
                    }
                }
            } elseif ($selectedMethod === $pudoShippingType) {
                $selectedMethod = $iteratedMethod;
            }
        }

        $rateLabel = "The Courier Guy Locker  $rateCode";

        $serviceLevelCode = str_replace(' ', '', $rateCode ?? '');

        $this->processShippingRates($rateLabel, $total, $serviceLevelCode);

        // Work out shipping price from (possibly overridden) total
        if ($taxes_enabled == 'yes') {
            // calculate tax
            $tax      = $total * $taxRate / (100.0 + $taxRate);
            $tax      = (float)(((int)(100 * $tax)) / 100);
            $shipping = $total - $tax;
        } else {
            $shipping = $total;
        }

        // Define shipping  ID
        $shippingMethodId = "$this->id:$rateCode:$this->instance_id:$selectedMethod";
        // Define rate
        $rate = array(
            'id'       => $shippingMethodId,
            'label'    => $rateLabel . ($freeShipping ? ' - FREE' : ''),
            'cost'     => $freeShipping ? 0 : $shipping,
            'calc_tax' => 'per_order',
        );
        // More tax checks -_-
        if ($taxes_enabled == 'yes') {
            $tax           = round($total * $taxRate / (100.0 + $taxRate), 2);
            $rate['taxes'] = $freeShipping ? 0 : array(1 => $tax);
            $rate['cost']  = $freeShipping ? 0 : $total - $tax;
        } else {
            $rate['taxes'] = array(1 => 0);
        }

        $postData['d2drates'] = $this->addD2DRates($settings, $shippingDetails, $lockerData, $freeShipping);

        // If pudo locker has been selected, set a modal controller (Will show the map)
        $checkoutShippingMethod = $postData['shipping_method'][0] ?? '';
        $postData['L2LPrice']   = 0.0;
        if (($selectedMethod === 'L2L' || $selectedMethod === 'D2L') && !str_contains(
                $checkoutShippingMethod,
                'pickup_dropoff'
            )) {
            $postData['showModal'] = true;
            $postData['L2LPrice']  = $total;
        } else {
            $postData['L2LPrice']  = 0;
            $postData['showModal'] = false;
        }

        // Set the rate, set global variable to keep passed post variable in context
        $postData['rate']            = $rate['cost'];
        $orderLockerSize             = $fittedBox;
        $postData['orderLockerSize'] = $orderLockerSize;
        $this->optionsParams         = json_encode($postData);

        WC()->session->set('pudo-shipping-method', $shippingMethodId);
        if (str_contains($shippingMethodId, 'L2D')) {
            WC()->session->set('pudo-shipping-rate-l2d', $rate);
            WC()->session->set('pudo-shipping-method-l2d', $shippingMethodId);
        }

        if (str_contains($shippingMethodId, '2L')) {
            WC()->session->set('pudo-shipping-rate', $rate);
            WC()->session->set('pudo-shipping-method-dl2l', $shippingMethodId);
        }
        $block_chosen_methods = WC()->session->get('chosen_shipping_methods');

        if (is_array($block_chosen_methods)) {
            $block_chosen_methods = end($block_chosen_methods);
        }

        WC()->session->set('pudo-shipping-method-block', $block_chosen_methods ?? 'no-pudo');

        return $rate;
    }

    /**
     * @param $settings
     * @param $shippingDetails
     * @param $lockerData
     * @param $freeShipping
     *
     * @return array
     */
    public function addD2DRates($settings, $shippingDetails, $lockerData, $freeShipping): array
    {
        $shippingData      = new PudoShippingData('D2D', false);
        $apiRequestBuilder = $shippingData->initializeAPIRequestBuilder(
            $shippingDetails,
            $settings,
            $lockerData,
            'D2D'
        );
        $d2lLockerRates    = $this->pudoApi->getRates(json_encode($apiRequestBuilder->buildRatesRequest()));

        $lockerRates = json_decode($d2lLockerRates['body'], true);
        $rates       = array();
        if (isset($lockerRates['rates'])) {
            foreach ($lockerRates['rates'] as $rateData) {
                $serviceLevel = $rateData['service_level'];
                if (str_starts_with($serviceLevel['code'], 'D2D')) {
                    continue;
                }
                $rateLabel = 'The Courier Guy Locker ' . $serviceLevel['name'];
                $ratePrice = $rateData['rate'];

                $this->processShippingRates($rateLabel, $ratePrice, $serviceLevel['code']);

                $rate = array(
                    'id'       => $serviceLevel['code'],
                    'label'    => $rateLabel . ($freeShipping ? ' - FREE' : ''),
                    'cost'     => $freeShipping ? 0 : $ratePrice,
                    'calc_tax' => 'per_order',
                );

                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /**
     * @param $rateLabel
     * @param $ratePrice
     * @param $serviceLevelCode
     *
     * @return void
     */
    public function processShippingRates(&$rateLabel, &$ratePrice, $serviceLevelCode): void
    {
        $options = array(
            'label_overrides' => json_decode($this->get_instance_option('label_override_per_service'), true),
            'price_overrides' => json_decode($this->get_instance_option('price_rate_override_per_service'), true),
        );

        $this->pudoShippingService->applyRateOverrides($options, $rateLabel, $ratePrice, $serviceLevelCode);
    }

    /**
     * @param $fields
     *
     * @return array
     */
    public function add_pudo_checkout_fields($fields)
    {
        $methods = \WC_Shipping_Zones::get_zones();
        foreach ($methods as $method) {
            foreach ($method['shipping_methods'] as $key => $item) {
                if (is_a($item, $this->class_name)) {
                    $this->instance_id = $key;
                }
            }
        }

        $this->init_instance_settings();

        $this->pudoApi = PudoApi::getInstance();
        $lockerz       = $this->lockers;

        $useSourceLocker = $this->instance_settings['pudo_source'] === 'locker';
        $sourceLocker    = $this->instance_settings['pudo_locker_name'];

        $sourceLockers = array_filter(
            $lockerz,
            function ($key) use ($sourceLocker) {
                return $key === $sourceLocker;
            },
            ARRAY_FILTER_USE_KEY
        );

        $destinationLockers = array_filter(
            $lockerz,
            function ($key) use ($sourceLocker) {
                return $key !== $sourceLocker;
            },
            ARRAY_FILTER_USE_KEY
        );

        $destinationLockers = array('none' => 'None') + $destinationLockers;

        $fields['billing']['pudo-locker-origin']      = array(
            'label'    => 'Origin Locker',
            'type'     => 'select',
            'options'  => $sourceLockers,
            'priority' => 5,
            'class'    => array('address-field'),
        );
        $fields['billing']['pudo-locker-origin-name'] = array(
            'label'    => 'Origin Locker Name',
            'type'     => 'text',
            'priority' => 5,
            'class'    => 'hide',
        );

        $fields['billing']['pudo-locker-destination'] = array(
            'label'    => 'Destination Locker',
            'type'     => 'text',
            'priority' => 5,
            'class'    => 'hide',
            'required' => false,
        );

        $fields['billing']['pudo-locker-destination-name'] = array(
            'label'    => 'Destination Locker Name',
            'type'     => 'text',
            'priority' => 5,
            'class'    => 'hide',
            'required' => false,
        );

        if (get_option('pudo_use_osm_map') !== 'true') {
            $fields['billing']['pudo-locker-destination']['type']    = 'select';
            $fields['billing']['pudo-locker-destination']['options'] = $destinationLockers;
        }

        return $fields;
    }

    /**
     * @return array
     */
    protected function getPudoLockerOptions(): array
    {
        $lockersx = array();
        foreach ($this->pudoApi->lockers as $locker) {
            $lockersx[$locker['code']] = $locker['name'];
        }

        return $lockersx;
    }

    /**
     * @return array
     */
    protected function getPudoBoxOptions(): array
    {
        $boxes = array();
        if (isset($this->instance_settings['pudo_locker_name'])) {
            $locker = $this->pudoApi->lockers[$this->instance_settings['pudo_locker_name']] ?? null;
            if (!$locker) {
                throw new \Exception('Configured source locker does not exist.');
            }
            $sortedBoxClass = $locker['lstTypesBoxes'];

            usort(
                $sortedBoxClass,
                function ($a, $b) {
                    $a1 = array($a['width'], $a['height'], $a['length']);
                    rsort($a1);
                    $b1 = array($b['width'], $b['height'], $b['length']);
                    rsort($b1);
                    if ($r0 = ($a1[0] <=> $b1[0])) {
                        return $r0;
                    }
                    if ($r1 = ($a1[1] <=> $b1[1])) {
                        return $r1;
                    }

                    return $a1[2] <=> $b1[2];
                }
            );
            foreach ($sortedBoxClass as $box) {
                $b = array($box['width'] * 10, $box['height'] * 10, $box['length'] * 10);
                // Sort each box largest to smallest dimension
                rsort($b);
                $b['volume']    = $b[0] * $b[1] * $b[2];
                $b['name']      = $box['name'];
                $b['type']      = $box['type'];
                $b['maxWeight'] = (float)$box['weight'];
                $boxes[]        = $b;
            }
        }

        return array($boxes, $sortedBoxClass);
    }

    /**
     * Add Pudo Shipping column in admin orders
     *
     * @param $columns
     *
     * @return array
     */
    public static function addCollectionActionAndPrintWaybillToOrderList($columns)
    {
        $reordered_columns = array();
        foreach ($columns as $key => $column) {
            $reordered_columns[$key] = $column;
            if ($key == 'order_status') {
                $reordered_columns['pickup_dropoff_order'] = __(
                    'The Courier Guy Locker Shipping',
                    'pudo-shipping-for-woocommerce'
                );
            }
        }

        return $reordered_columns;
    }

    /**
     * @param WC_Order $order
     *
     * @return bool
     */
    private static function orderHasPudoMethod(WC_Order $order)
    {
        $shippingMethods[] = json_decode($order->get_meta(self::ORDER_SHIPPING_DATA));
        $hasPudo           = false;
        foreach ($shippingMethods as $method) {
            if (self::isPudoMethod($method)) {
                $hasPudo = true;
            }
        }

        return $hasPudo;
    }

    /**
     * @param $column
     * @param $orderId
     *
     * @return void
     */
    public static function collectActionAndPrintWaybillOnOrderlistContent($column, $orderId)
    {
        if ($column === 'pickup_dropoff_order') {
            $order           = wc_get_order($orderId);
            $shippingMethods = get_post_meta($orderId)['pudo_method'] ?? '';

            $hasOrder      = $order->get_meta('pudo_wayBillNumber', true) !== '';
            $hasPudoMethod = false;

            if ($shippingMethods) {
                foreach ($shippingMethods ?? array() as $key => $shippingMethod) {
                    if ((self::isPudoMethod($shippingMethod))) {
                        $hasPudoMethod = true;
                        $waybill       = $order->get_meta("pickup_dropoff_waybill_filename_$key", true);
                        if ($waybill !== '') {
                            $hasOrder = true;
                        }
                    }
                }
                if ($hasPudoMethod) {
                    if ($hasOrder) {
                        ?>
                        <a href='/wp-admin/admin-post.php?action=print_pudo_waybill&order_id=
						<?php
                        echo esc_attr($orderId);
                        ?>
						' class='print-pudo-waybill_order-list' title='Print Waybill'>
                            <?php
                            echo esc_html(wc_help_tip('Print The Courier Guy Locker Waybill'));
                            ?>
                        </a>
                        <?php
                    }
                }
            }
        }
    }

    public static function display_pudo_notice()
    {
        global $pagenow;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- This is a GET request for displaying a notice, no nonce needed.
        $post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- This is a GET request for displaying a notice, no nonce needed.
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;

        if ($pagenow === 'edit.php' && $post_type === 'shop_order' && $order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                return;
            }

            $pudo_status = $order->get_meta('pudo_status');

            if ($pudo_status === 'Booking confirmed') {
                $message     = 'The Courier Guy Locker ' . $pudo_status . ' for Order ' . $order_id;
                $noticeClass = 'success';
            } else {
                $message     = $pudo_status;
                $noticeClass = 'error';
            }
            ?>
            <script>
              jQuery(document).ready(function ($) {
                $('#wpbody-content').prepend(
                  '<div class="notice notice-<?php echo esc_attr($noticeClass); ?>"><p>
                    <?php
                    echo esc_html(
                        $message
                    );
                    ?>
                  < /p></div > '
                )
              })
            </script>
            <?php
        }
    }

    public static function getOriginLockerName($index)
    {
        $instanceSettings = (new Pudo_Shipping_Method())->instance_settings;

        return $instanceSettings['pudo_locker_name_' . $index];
    }

    /**
     * Triggered by icon click in orders list
     *
     * @return void
     */
    public static function printWaybillFromList()
    {
        $orderId = isset($_REQUEST['order_id']) ? absint(wp_unslash($_REQUEST['order_id'])) : 0;
        self::printWaybillFromOrder(new WC_Order($orderId));
    }

    /**
     * Triggered by action in order detail or from above
     *
     * @param WC_Order $order
     *
     * @return void
     */
    public static function printWaybillFromOrder(WC_Order $order): void
    {
        $pudoApi    = PudoApi::getInstance();
        $booking_id = $order->get_meta('pudo_booking_id');

        if (!$booking_id) {
            return;
        }

        $waybillResponse = $pudoApi->getWaybill((int)$booking_id);

        // Check for API errors or empty body
        if (is_wp_error($waybillResponse) ||
            (isset($waybillResponse['response']['code']) && $waybillResponse['response']['code'] !== 200) ||
            empty($waybillResponse['body'])) {
            $order->add_order_note('The Courier Guy Locker: Could not fetch the waybill');

            return;
        }

        $pdfData = $waybillResponse['body'];

        // 1. Sanitize the filename components
        $order_id      = $order->get_id();
        $receiverEmail = sanitize_email($order->get_billing_email());
        $tracking_ref  = sanitize_file_name($order->get_meta('pudo_custom_tracking_reference'));

        // Construct a safe filename
        $filename = sprintf('waybill-%s-%s.pdf', $order_id, $tracking_ref);

        // 2. Clear any previous output buffers to prevent PDF corruption
        if (ob_get_level()) {
            ob_end_clean();
        }

        // 3. Set Headers securely
        // Set headers securely, escaping filename
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        header('Content-Length: ' . strlen($pdfData));
        header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Expires: 0');

        // 4. Output and stop execution immediately
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $pdfData;
        exit;
    }

    /**
     * Triggered by action in order detail or from above
     *
     * @param WC_Order $order
     *
     * @return void
     */
    public static function printLabelFromOrder(WC_Order $order): void
    {
        $pudoApi = PudoApi::getInstance();

        $labelResponse = $pudoApi->getLabel((int)$order->get_meta('pudo_booking_id'));

        if ($labelResponse['response']['code'] !== 200) {
            $order->add_order_note('The Courier Guy Locker: Could not fetch the label');

            return;
        }

        $receiverEmail   = sanitize_email($order->get_billing_email());
        $pudoLabelNumber = sanitize_file_name($order->get_meta('pudo_custom_tracking_reference'));

        $pdfData  = $labelResponse['body'];
        $filename = $order->get_id() . '-' . $receiverEmail . '-' . $pudoLabelNumber . '.pdf';

        header('Content-type: application/pdf');
        header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
        header('Content-Transfer-Encoding: binary');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        header('Content-Length: ' . strlen($pdfData));
        header('Accept-Ranges: bytes');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $pdfData;
        exit();
    }

    /**
     * @param int $productId
     *
     * @return bool
     */
    private function isPudoProhibited(int $productId): bool
    {
        return get_post_meta($productId, 'product_prohibit_pudo', true) === 'on';
    }

    /**
     * @param array $package
     *
     * @return array
     */
    private function isPudoProductProhibited(array $package): array
    {
        if (!isset($package['contents'])) {
            return array();
        }
        $prohibitedProducts = array();

        foreach ($package['contents'] as $item) {
            if ($this->isPudoProhibited($item['product_id'])) {
                $prohibitedProducts[] = $item;
            }
        }

        return $prohibitedProducts;
    }

    /**
     * @return void
     */
    public function addProhibitedWarnings()
    {
        if (empty($this->prohibitedProducts)) {
            return;
        }
        $errorMessage = 'Products ';
        foreach ($this->prohibitedProducts as $product) {
            $errorMessage .= "{$product['data']->get_name()},";
        }
        $errorMessage = rtrim($errorMessage, ',') . ' are prohibited from using The Courier Guy Locker checkout';
        $errors       = new \WP_Error();
        $errors->add('validation', $errorMessage);
        wc_print_notice($errorMessage);
    }

    /** Returns lockers
     *
     * @param int $instanceId
     *
     * @return array
     */
    public static function getPudoLockers(int $instanceId)
    {
        $pudoApi = PudoApi::getInstance();

        return $pudoApi->lockers;
    }

    /**
     * @param \WC_Customer|null $customer
     *
     * @return array
     */
    protected function getCustomerShippingDetails(?\WC_Customer $customer): array
    {
        $order = array(
            'name'           => $customer->get_shipping_first_name(),
            'email'          => $customer->get_billing_phone(),
            'mobile_number'  => $customer->get_billing_email(),
            'street_address' => $customer->get_shipping_address_1(),
            'city'           => $customer->get_shipping_city(),
            'code'           => $customer->get_shipping_postcode(),
            'zone'           => $customer->get_shipping_postcode(),
            'country'        => $customer->get_shipping_country(),
        );

        return $order;
    }

    /**
     * @param $sortedBoxClass
     * @param array $parcels
     * @param array $lockerData
     * @param array $settings
     * @param bool $freeShippingFinal
     *
     * @return void
     */
    protected function getD2lBlocks(
        $sortedBoxClass,
        array $parcels,
        array $lockerData,
        array $settings,
        bool $freeShippingFinal
    ): void {
        // Get the fit index (Index of type of delivery package)
        $fittedBox = $sortedBoxClass;
        $customer  = WC()->customer;
        $sp        = 'd2l-pudo';

        $order = $this->getCustomerShippingDetails($customer);

        $rateD2l = $this->buildPudoRate(
            $sp,
            $fittedBox,
            $settings,
            $order,
            $lockerData,
            $parcels,
            $freeShippingFinal
        );
        // Checkout page is using blocks
        $cost = $rateD2l['cost'] ?? 0;

        $rate = array(
            'id'       => 'd2l-pudo',
            'label'    => 'Deliver to a Locker (The Courier Guy Locker)',
            'calc_tax' => 'per_order',
            'cost'     => $cost,
        );

        $this->add_rate($rate);
    }

    /**
     * @param $sortedBoxClass
     * @param array $parcels
     * @param array $lockerData
     * @param array $settings
     * @param bool $freeShippingFinal
     *
     * @return void
     */
    protected function getL2lBlocks(
        $sortedBoxClass,
        array $parcels,
        array $lockerData,
        array $settings,
        bool $freeShippingFinal
    ): void {
        // Get the fit index (Index of type of delivery package)
        $fittedBox = $sortedBoxClass;
        $customer  = WC()->customer;
        $sp        = 'l2l-pudo';

        $order = $this->getCustomerShippingDetails($customer);

        if (empty($lockerData['pudo-destination-locker'])) {
            $wcSession = WC()->session;
            if (!empty($wcSession)) {
                $lockerData['pudo-destination-locker'] = $wcSession->get('pudo_locker_destination', '');
            }
        }

        $rateL2l = $this->buildPudoRate(
            $sp,
            $fittedBox,
            $settings,
            $order,
            $lockerData,
            $parcels,
            $freeShippingFinal
        );

        // Checkout page is using blocks
        $cost      = $rateL2l['cost'] ?? 0;
        $rateLabel = $rateL2l['label'] !== 'The Courier Guy Locker  ' ? $rateL2l['label'] :
            'Locker to Locker (The Courier Guy Locker)';

        $rate = array(
            'id'       => 'l2l-pudo',
            'label'    => $rateLabel,
            'calc_tax' => 'per_order',
            'cost'     => $cost,
        );

        $logger = wc_get_logger();

        $this->add_rate($rate);
    }

    /**
     * @param $sortedBoxClass
     * @param array $parcels
     * @param array $lockerData
     * @param array $settings
     * @param bool $freeShippingFinal
     *
     * @return void
     */
    protected function getL2dBlocks(
        $sortedBoxClass,
        array $parcels,
        array $lockerData,
        array $settings,
        bool $freeShippingFinal
    ): void {
        // Get the fit index (Index of type of delivery package)
        $fittedBox = $sortedBoxClass;
        $customer  = WC()->customer;
        $sp        = 'l2d-pudo';

        $order   = $this->getCustomerShippingDetails($customer);
        $rateL2d = $this->buildPudoRate(
            $sp,
            $fittedBox,
            $settings,
            $order,
            $lockerData,
            $parcels,
            $freeShippingFinal
        );
        // Checkout page is using blocks
        $cost = $rateL2d['cost'] ?? 0;

        $rate = array(
            'id'       => $sp,
            'label'    => 'Locker to Door (The Courier Guy Locker)',
            'calc_tax' => 'per_order',
            'cost'     => $cost,
        );

        $this->add_rate($rate);
    }

    /**
     * Render a small summary row showing the currently selected locker option
     * and a button to reopen the locker selection modal.
     *
     * Classic checkout only (Blocks uses JS-based rendering).
     *
     * @return void
     */
    public function renderSelectedPudoOptionOnCheckout(): void
    {
        // Only render on checkout page.
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        // Verify nonce for security
        $nonce          = isset($_POST['woocommerce-process-checkout-nonce']) ? sanitize_text_field(
            wp_unslash($_POST['woocommerce-process-checkout-nonce'])
        ) : '';
        $nonce_verified = $nonce && wp_verify_nonce($nonce, 'woocommerce-process_checkout');

        $selected_method = '';
        if ($nonce_verified && !empty($_POST['post_data'])) {
            $post_data_sanitized = sanitize_text_field(wp_unslash($_POST['post_data']));
            parse_str($post_data_sanitized, $postData);
            $selected_method = isset($postData['shipping_method'][0]) ? sanitize_text_field(
                $postData['shipping_method'][0]
            ) : '';
        } elseif ($nonce_verified && !empty($_POST['shipping_method'][0])) {
            $selected_method = sanitize_text_field(wp_unslash($_POST['shipping_method'][0]));
        } else {
            $chosen          = WC()->session ? WC()->session->get('chosen_shipping_methods', array()) : array();
            $selected_method = $chosen[0] ?? '';
        }

        // Only show for TCG Locker shipping types.
        if (empty($selected_method) || !in_array($selected_method, $this->pudoShippingTypes, true)) {
            return;
        }

        $destination_name = '';
        $destination_code = '';
        if ($nonce_verified && !empty($_POST['post_data'])) {
            $post_data_sanitized = sanitize_text_field(wp_unslash($_POST['post_data']));
            parse_str($post_data_sanitized, $postData);
            $destination_code = isset($postData['pudo-locker-destination']) ? sanitize_text_field(
                $postData['pudo-locker-destination']
            ) : '';
            $destination_name = isset($postData['pudo-locker-destination-name']) ? sanitize_text_field(
                $postData['pudo-locker-destination-name']
            ) : '';
        } elseif ($nonce_verified && !empty($_POST['pudo-locker-destination'])) {
            $destination_code = sanitize_text_field(wp_unslash($_POST['pudo-locker-destination']));
            $destination_name = isset($_POST['pudo-locker-destination-name']) ? sanitize_text_field(
                wp_unslash($_POST['pudo-locker-destination-name'])
            ) : '';
        }

        $selected_label = '';

        $allowedShippingMethods = array('d2l-pudo', 'l2l-pudo');

        if (in_array($selected_method, $allowedShippingMethods, true)) {
            $selected_label = sprintf('%s: %s', $destination_code ?: '--', $destination_name ?: 'Unknown');
        }

        if ($selected_label === '--: Unknown') {
            $selected_label = 'Please select a valid locker location';
        }

        // If we only have code, try map lookup.
        if (empty($selected_label) && !empty($destination_code) && isset($this->lockers[$destination_code])) {
            $selected_label = $this->lockers[$destination_code];
        }

        if (empty($selected_label)) {
            return;
        }

        $selected_label = trim(wp_strip_all_tags((string)$selected_label));
        include plugin_dir_path(__DIR__) . 'templates/checkout-selected-option.php';
    }
}
