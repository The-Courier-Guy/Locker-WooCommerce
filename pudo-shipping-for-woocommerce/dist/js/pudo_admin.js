(function ($) {
	$(
		function () {
			// Handle change in TCG Locker selection (Admin)
			$( '#woocommerce_pickup_dropoff_pudo_source' ).change(
				function (e) {
					manageFieldDisplay( $( this ).val() )
				}
			)

			// Hide/Show address fields based on shipping type (Admin)
			function manageFieldDisplay(val) {
				if (val == 'street') {

					$( '#woocommerce_pickup_dropoff_pudo_locker_name' ).prop( 'hidden', true )
					$( 'label[for="woocommerce_pickup_dropoff_pudo_locker_name"]' ).prop( 'hidden', true )

					$( '#woocommerce_pickup_dropoff_shop_addresses' ).prop( 'hidden', false )
					$( 'label[for="woocommerce_pickup_dropoff_shop_addresses"]' ).prop( 'hidden', false )

					$( '#woocommerce_pickup_dropoff_sender_addressline1' ).prop( 'hidden', false )
					$( 'label[for="woocommerce_pickup_dropoff_sender_addressline1"]' ).prop( 'hidden', false )

					$( '#woocommerce_pickup_dropoff_sender_addressline2' ).prop( 'hidden', false )
					$( 'label[for="woocommerce_pickup_dropoff_sender_addressline2"]' ).prop( 'hidden', false )

					$( '#woocommerce_pickup_dropoff_sender_addressline3' ).prop( 'hidden', false )
					$( 'label[for="woocommerce_pickup_dropoff_sender_addressline3"]' ).prop( 'hidden', false )

					$( '#woocommerce_pickup_dropoff_sender_city' ).prop( 'hidden', false )
					$( 'label[for="woocommerce_pickup_dropoff_sender_city"]' ).prop( 'hidden', false )

					$( '#woocommerce_pickup_dropoff_sender_suburb' ).prop( 'hidden', false )
					$( 'label[for="woocommerce_pickup_dropoff_sender_suburb"]' ).prop( 'hidden', false )

					$( '#woocommerce_pickup_dropoff_sender_postal_code' ).prop( 'hidden', false )
					$( 'label[for="woocommerce_pickup_dropoff_sender_postal_code"]' ).prop( 'hidden', false )
					$( '#woocommerce_pickup_dropoff_pudo_source' ).closest( 'tr' ).find( '.description' ).hide()

					let table = $( 'table.form-table' )[2]
					if (table != null) {
						table.hidden = false
					}
				} else {

					$( '#woocommerce_pickup_dropoff_pudo_locker_name' ).prop( 'hidden', false )
					$( 'label[for="woocommerce_pickup_dropoff_pudo_locker_name"]' ).prop( 'hidden', false )

					$( '#woocommerce_pickup_dropoff_shop_addresses' ).prop( 'hidden', true )
					$( 'label[for="woocommerce_pickup_dropoff_shop_addresses"]' ).prop( 'hidden', true )
					$( '#woocommerce_pickup_dropoff_shop_addresses' ).prop( 'hidden', true )

					$( '#woocommerce_pickup_dropoff_sender_addressline1' ).prop( 'hidden', true )
					$( 'label[for="woocommerce_pickup_dropoff_sender_addressline1"]' ).prop( 'hidden', true )

					$( '#woocommerce_pickup_dropoff_sender_addressline2' ).prop( 'hidden', true )
					$( 'label[for="woocommerce_pickup_dropoff_sender_addressline2"]' ).prop( 'hidden', true )

					$( '#woocommerce_pickup_dropoff_sender_addressline3' ).prop( 'hidden', true )
					$( 'label[for="woocommerce_pickup_dropoff_sender_addressline3"]' ).prop( 'hidden', true )

					$( '#woocommerce_pickup_dropoff_sender_city' ).prop( 'hidden', true )
					$( 'label[for="woocommerce_pickup_dropoff_sender_city"]' ).prop( 'hidden', true )

					$( '#woocommerce_pickup_dropoff_sender_suburb' ).prop( 'hidden', true )
					$( 'label[for="woocommerce_pickup_dropoff_sender_suburb"]' ).prop( 'hidden', true )

					$( '#woocommerce_pickup_dropoff_sender_postal_code' ).prop( 'hidden', true )
					$( 'label[for="woocommerce_pickup_dropoff_sender_postal_code"]' ).prop( 'hidden', true )
					$( '#woocommerce_pickup_dropoff_pudo_source' ).closest( 'tr' ).find( '.description' ).show()

					let table = $( 'table.form-table' )[2]
					if (table != null) {
						table.hidden = true
					}
				}
			}

			applyOverrideSettings()

			function applyOverrideSettings() {
				var overrideSelects = $( '.pudo-override-per-service' )
				var overrideInputs  = $( '.pudo-override-per-service-input' )
				overrideSelects.on(
					'change',
					function () {
						var selectedOptionValue = $( this ).children( 'option:selected' ).val()
						if (selectedOptionValue !== '') {
								$( this ).nextAll( 'span' ).hide()
								$( this ).nextAll( 'span.pudo-override-per-service-span-' + selectedOptionValue ).show()
						}
					}
				)
				overrideInputs.on(
					'blur',
					function () {
						var overrideSelect = $( this ).parent( 'span' ).prevAll( 'select.pudo-override-per-service' )
						var overrideValues = {}
						overrideSelect.nextAll( 'span' ).each(
							function () {
								var input                = $( this ).children( 'input' )
								var serviceId            = input.data( 'service-id' )
								var overrideSelectOption = overrideSelect.find( 'option[value="' + serviceId + '"]' )
								var serviceLabel         = overrideSelectOption.data( 'service-label' )
								var overrideValue        = input.val()
								if (overrideValue !== '') {
									var prefix = ' - '
									if (input.hasClass( 'wc_input_price' )) {
											prefix        = ' - R '
											input.val( parseFloat( overrideValue ).toFixed( 2 ) )
											overrideValue = input.val()
									}
									overrideValues[serviceId] = overrideValue
									serviceLabel              = serviceLabel + prefix + overrideValue
								}
								overrideSelectOption.html( serviceLabel )
							}
						)
						if (Object.keys( overrideValues ).length > 0) {
							overrideSelect.nextAll( 'input' ).val( JSON.stringify( overrideValues ) )
						} else {
							overrideSelect.nextAll( 'input' ).val( '' )
						}
					}
				)
			}

			// Initialize select 2 options for woocomm settings
			if ($( '#woocommerce_pickup_dropoff_pudo_locker_name' ) != null && $().select2) {
				$( '#woocommerce_pickup_dropoff_pudo_locker_name' ).select2()
				$( '#woocommerce_pickup_dropoff_pudo_locker_name_2' ).select2()
				$( '#woocommerce_pickup_dropoff_pudo_locker_name_3' ).select2()
			}

			// Hide origin
			$( '#pudo-locker-origin_field' ).attr( 'hidden', true )
			$( '#pudo-locker-destination_field' ).attr( 'hidden', false )
			// Check the type of controls being used
			if (typeof map != 'undefined' && map != null) {
				$( '#pudo-locker-destination' ).prop( 'hidden', true )
			}

			// Hide any ".hide" classes
			$( '.hide' ).prop( 'hidden', true )

			// Define provider
			$( document ).ready(
				function () {
					manageFieldDisplay( $( '#woocommerce_pickup_dropoff_pudo_source' ).val() )
				}
			)

			$( '#mainform' ).submit(
				function (event) {
					if ($( '#woocommerce_pickup_dropoff_sender_contact' ).val() === '') {
						event.preventDefault()
						alert( 'Sender Shop Contact Required' )
						return
					}

					if ($( '#woocommerce_pickup_dropoff_sender_email' ).val() === '') {
						event.preventDefault()
						alert( 'Sender email Required!' )
						return
					}

					if ($( '#woocommerce_pickup_dropoff_sender_phone' ).val() === '') {
						event.preventDefault()
						alert( 'Sender Phone Number Required!' )
						return
					}

				}
			)
		}
	)
})( jQuery )
