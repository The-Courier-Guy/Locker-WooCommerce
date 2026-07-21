<?php

namespace Pudo\WooCommerce;

use Pudo\Common\Service\PudoShippingService;
use StdClass;

/**
 *  Copyright: © 2025 The Courier Guy
 */
class Pudo_Shipping_Settings {


	const ADDRESS_LINE_1 = 'Address Line 1';
	const ADDRESS_LINE_2 = 'Address Line 2';
	const ADDRESS_LINE_3 = 'Address Line 3';

	/**
	 * Generate the fields for settings for the plugin
	 */
	public static function overrideFormFieldsVariable( array $lockers = array() ) {
		$fields['title'] = array(
			'title'   => __( 'Shipping Method Title', 'pudo-shipping-for-woocommerce' ),
			'type'    => 'text',
			'label'   => __( 'Shipping Method Title', 'pudo-shipping-for-woocommerce' ),
			'default' => 'The Courier Guy Locker',
		);

		$fields['tax_status'] = array(
			'title'       => __( 'Tax status', 'pudo-shipping-for-woocommerce' ),
			'type'        => 'select',
			'options'     => array(
				'taxable' => 'Taxable',
				'none'    => 'None',
			),
			'description' => __( 'VAT applies or not', 'pudo-shipping-for-woocommerce' ),
			'default'     => __( 'taxable', 'pudo-shipping-for-woocommerce' ),
		);

		// Pudo source
		$fields['pudo_source'] = array(
			'title'       => __( 'Select locker or address as source', 'pudo-shipping-for-woocommerce' ),
			'type'        => 'select',
			'options'     => array(
				'locker' => 'Locker',
				'street' => 'Street Address',
			),
			'label'       => __( 'Select locker or address as source', 'pudo-shipping-for-woocommerce' ),
			'description' => __(
				'If you do not see all the lockers below please save and submit a valid API key on The Courier Guy Locker Account page.',
				'pudo-shipping-for-woocommerce'
			),
			'desc_tip'    => false,
		);

		$fields['pudo_locker_name'] = array(
			'title'   => __( 'The Courier Guy Locker: Locker Source 1', 'pudo-shipping-for-woocommerce' ),
			'type'    => 'select',
			'options' => $lockers,
			'label'   => __( 'The Courier Guy Locker: Locker Source', 'pudo-shipping-for-woocommerce' ),
		);

		$fields['pudo_locker_name_2'] = array(
			'title'   => __( 'The Courier Guy Locker: Locker Source 2', 'pudo-shipping-for-woocommerce' ),
			'type'    => 'select',
			'options' => $lockers,
			'label'   => __( 'The Courier Guy Locker: Locker Source', 'pudo-shipping-for-woocommerce' ),
		);

		$fields['pudo_locker_name_3'] = array(
			'title'   => __( 'The Courier Guy Locker: Locker Source 3', 'pudo-shipping-for-woocommerce' ),
			'type'    => 'select',
			'options' => $lockers,
			'label'   => __( 'The Courier Guy Locker: Locker Source', 'pudo-shipping-for-woocommerce' ),
		);

		$fields['other_settings'] = array(
			'title' => __( 'Other Settings', 'pudo-shipping-for-woocommerce' ),
			'type'  => 'title',
			'label' => __( 'Other Settings', 'pudo-shipping-for-woocommerce' ),
		);

		$fields['usemonolog']               = array(
			'title'       => __( 'Enable WooCommerce Logging', 'pudo-shipping-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => __(
				'Check this to enable WooCommerce logging for this plugin. Remember to empty out logs when done.',
				'pudo-shipping-for-woocommerce'
			),
			'default'     => __( 'no', 'pudo-shipping-for-woocommerce' ),
		);
		$fields['free_shipping']            = array(
			'title'       => __( 'Enable free shipping ', 'pudo-shipping-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => __(
				'This will enable free shipping over a specified amount',
				'pudo-shipping-for-woocommerce'
			),
			'default'     => 'no',
		);
		$fields['amount_for_free_shipping'] = array(
			'title'             => __( 'Amount for free Shipping', 'pudo-shipping-for-woocommerce' ),
			'type'              => 'number',
			'description'       => __(
				'Enter the amount for free shipping when enabled',
				'pudo-shipping-for-woocommerce'
			),
			'default'           => '1000',
			'custom_attributes' => array(
				'min' => '0',
			),
		);

		$fields['label_override_per_service'] = array(
			'title'       => __( 'Label Override Per Service', 'pudo-shipping-for-woocommerce' ),
			'type'        => 'pudo_override_per_service',
			'description' => __(
				'These labels will override The Courier Guy Locker labels per service.',
				'pudo-shipping-for-woocommerce'
			) . '<br />' . __(
				'Select a service to add or remove label override.',
				'pudo-shipping-for-woocommerce'
			),
			'options'     => PudoShippingService::getRateOptions(),
			'default'     => '',
			'class'       => 'pudo-override-per-service',
		);

		$fields['price_rate_override_per_service'] = array(
			'title'       => __( 'Price Rate Override Per Service', 'pudo-shipping-for-woocommerce' ),
			'type'        => 'pudo_override_per_service',
			'description' => __(
				'These prices will override The Courier Guy Locker rates per service.',
				'pudo-shipping-for-woocommerce'
			) . '<br />' . __(
				'Select a service to add or remove price rate override.',
				'pudo-shipping-for-woocommerce'
			),
			'options'     => PudoShippingService::getRateOptions(),
			'default'     => '',
			'class'       => 'pudo-override-per-service',
		);

		// Sender contact details
		$fields['sender_contact'] = array(
			'title'    => __( 'Name of Shop Contact', 'pudo-shipping-for-woocommerce' ),
			'type'     => 'text',
			'label'    => __( 'Name of Sender', 'pudo-shipping-for-woocommerce' ),
			'required' => true,
		);
		$fields['sender_email']   = array(
			'title'    => __( 'Email of Sender', 'pudo-shipping-for-woocommerce' ),
			'type'     => 'text',
			'label'    => __( 'Sender Email', 'pudo-shipping-for-woocommerce' ),
			'required' => true,
		);
		$fields['sender_phone']   = array(
			'title'    => __( 'Phone of Sender', 'pudo-shipping-for-woocommerce' ),
			'type'     => 'text',
			'label'    => __( 'Sender Phone', 'pudo-shipping-for-woocommerce' ),
			'required' => true,
		);

		// Shop Addresses
		$fields['shop_addresses'] = array(
			'title' => __( 'Shop Addresses (If Street Address selected as Source)', 'pudo-shipping-for-woocommerce' ),
			'type'  => 'title',
			'label' => __( 'Shop Addresses', 'pudo-shipping-for-woocommerce' ),
		);

		$fields['sender_addressline1'] = array(
			'title' => __( 'Address Line 1', 'pudo-shipping-for-woocommerce' ),
			'type'  => 'text',
			'label' => __( 'Address Line 1', 'pudo-shipping-for-woocommerce' ),
		);
		$fields['sender_addressline2'] = array(
			'title' => __( 'Address Line 2', 'pudo-shipping-for-woocommerce' ),
			'type'  => 'text',
			'label' => __( 'Address Line 2', 'pudo-shipping-for-woocommerce' ),
		);
		$fields['sender_addressline3'] = array(
			'title' => __( 'Address Line 3', 'pudo-shipping-for-woocommerce' ),
			'type'  => 'text',
			'label' => __( 'Address Line 3', 'pudo-shipping-for-woocommerce' ),
		);
		$fields['sender_city']         = array(
			'title' => __( 'City', 'pudo-shipping-for-woocommerce' ),
			'type'  => 'text',
			'label' => __( 'City', 'pudo-shipping-for-woocommerce' ),
		);
		$fields['sender_suburb']       = array(
			'title' => __( 'Suburb', 'pudo-shipping-for-woocommerce' ),
			'type'  => 'text',
			'label' => __( 'Suburb', 'pudo-shipping-for-woocommerce' ),
		);
		$fields['sender_postal_code']  = array(
			'title' => __( 'Postal Code', 'pudo-shipping-for-woocommerce' ),
			'type'  => 'text',
			'label' => __( 'Postal Code', 'pudo-shipping-for-woocommerce' ),
		);

		return $fields;
	}
}
