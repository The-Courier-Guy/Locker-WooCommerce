(async function ($) {
  window.selectedLocker = ''

  jQuery('#pudo-locker-origin_field').hide()
  let isBlocks = false
  let pudoMethod = ''
  let modalShown = false
  let pudoModal
  let selectedRadio = null
  modalShown = false
  this.pudoShowModal = function () {
    if (!this.modalShown && pudoModal) {
      pudoModal.style.display = 'block'
      map.invalidateSize()
      modalShown = true
    }
  }

  // Re-open modal from the checkout summary "Change" button.
  this.pudoReopenModal = function () {
    modalShown = false
    pudoShowModal()
  }

  this.updateShippingMethod = async function (shippingMethod, code = null, title = null, pudoMethod = null) {
    const response = await fetch(pudo_params.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: new URLSearchParams({
        action: 'set_shipping_method',
        shipping_method: shippingMethod,
        locker_code: code,
        locker_title: title,
        pudo_method: pudoMethod,
        nonce: pudo_params.nonce
      })
    })

    if (!response.ok) {
      throw new Error('Network response was not ok')
    }

    const data = await response.json()

    if (data.success) {
      $(document.body).trigger('update_checkout')
    } else {
      throw new Error('Failed to update shipping method')
    }
  }

  function renderDisplayedLocker(message = '') {
    let checkedRadio = $('input[name^="radio-control-"]:checked')
      .filter(function () {
        return this.id.toLowerCase().includes('pudo');
      });
    console.log('Checked radio button:', checkedRadio);
    let radioVal = checkedRadio.val();
    console.log('Checked radio button value:', radioVal);

    let disablePlaceOrder = false;

    if (radioVal && (radioVal.includes('l2l') || radioVal.includes('d2l'))) {
      let labelClass = checkedRadio[0].name + '-' + radioVal.replace('-pudo', '') + '-pudo__label';

      let label = document.getElementById(labelClass);
      let labelContent = label.innerHTML;

      if (labelContent.includes(' - ')) {
        labelContent = labelContent.split(' - ')[0];
      }

      let appendedText = ` - <strong> ${window.selectedLocker} </strong>`;

      if (!window.selectedLocker) {
        appendedText = ' - <strong style="color: red;"> No locker selected </strong>';
        disablePlaceOrder = true;
      }
      if (message) {
        appendedText = ` - <strong style="color: red;"> ${message} </strong>`;
        disablePlaceOrder = true;
      }

      label.innerHTML = labelContent + appendedText;
    }

    document.querySelector('.wc-block-components-checkout-place-order-button').disabled = disablePlaceOrder;
  }

  this.setDestinationLocker = async function (code, title, pudoMethod) {
    if (isBlocks) {
      // Disable entire page interactions
      $('body').css('pointer-events', 'none')
      renderDisplayedLocker('Calculating shipping, please wait...')
      pudoModal.style.display = 'none'

      const pm = $('input[name^="radio-control-"]:checked').val()?.toLowerCase()
      if (pm !== pudoMethod) {
        pudoMethod = pm
      }

      window.selectedLocker = code + ': ' + title
      const shippingMethodId = $('input[name="shipping_method[0]"]').attr('id')
      await updateShippingMethod(shippingMethodId, code, title, pudoMethod)

      // define the data to be sent to the server
      const data = {
        locker_code: code,
        locker_title: title,
        pudo_method: pudoMethod
      };

      console.log('Updating cart with data:', data);

      const result = await wp.data.dispatch('wc/store/cart').applyExtensionCartUpdate({
        namespace: 'pudo-shipping-for-woocommerce',
        data: data
      });
      
      console.log('Cart update result:', result);

      renderDisplayedLocker()
      // Re-enable interactions
      $('body').css('pointer-events', 'auto')

      pudoModal.style.display = 'none'

    } else {
      $('#pudo-locker-destination-name').val(title)
      $('#pudo-locker-origin-name').val($('#pudo-locker-origin option:selected').text())

      const shippingMethodId = $('input[name="shipping_method[0]"]').attr('id')
      let pudoMethod
      if (shippingMethodId.includes('l2l')) {
        pudoMethod = 'l2l-pudo'
      } else if (shippingMethodId.includes('d2l')) {
        pudoMethod = 'd2l-pudo'
      } else if (shippingMethodId.includes('l2d')) {
        pudoMethod = 'l2d-pudo'
      } else if (shippingMethodId.includes('d2d')) {
        pudoMethod = 'd2d-pudo'
      }

      await updateShippingMethod(shippingMethodId, code, title, pudoMethod)
      pudoModal.style.display = 'none'
      // Re-enable interactions
      $('body').css('pointer-events', 'auto')

      pudoModal.style.display = 'none'
    }
  }, $(
    function () {
      // Change button in classic checkout order review table.
      $(document.body).on(
        'click',
        '#pudo-change-option,[data-pudo-change="1"]',
        function (e) {
          e.preventDefault()
          pudoReopenModal()
        }
      )

      // (Checkout)
      // Fire update checkout on change
      $('form.checkout').on(
        'change',
        'input[name="shipping_method[0]"]',
        function () {
          this.modalShown = false
          $('body').trigger('update_checkout')
        }
      )

      // Add markers to the map
      async function addMarker(title, code, address, lat, lng, markers) {
        // Define marker object
        if (!lat || !lng) {
          return
        }
        if (lat == '.,') {
          return
        }
        if (lat == '.') {
          return
        }

        // Define custom icon properties
        let customIcon = {
          iconUrl: window.location.origin + '/wp-content/plugins/pudo-shipping-for-woocommerce/dist/icon_pudo.png',
          iconSize: [30, 50]
        }
        // Create icon object
        let myIcon = L.icon(customIcon)

        // Icon options
        let iconOptions = {
          icon: myIcon
        }
        var p = L.marker([lat, lng], iconOptions)

        // Set marker properties
        p.title = title
        p.alt = title
        // Define popupContent with onClick functions to manage Pickup/Delivery location
        popupContent =
          '<button type=\'button\' class=\'btn btn-primary\' onclick=\'setDestinationLocker("' + code + '","' + title + '","' + pudoMethod + '");\'>' +
          '<span class=\'pnomobile\'>Select </span>' + title + ' <span class=\'pnomobile\'>as the Delivery Locker</span>' +
          '</button>'

        // Bind the generated popupContent to the marker
        p.bindPopup(popupContent)
        // Finally add the marker to the map
        // Set the name as a tooltip and Open it up so user can see what location is what marker
        p.bindTooltip(title, {}).openTooltip()
        markers.addLayer(p)
        await addRowTable(code, [lat, lng], title, address)
      }

      // Add rows to table
      function addRowTable(code, coords, title, address) {
        var tr = document.createElement('tr')

        var td = document.createElement('td')
        td.style.width = '500px'
        td.textContent = title

        var tdHidden = document.createElement('td')
        tdHidden.textContent = address

        tr.appendChild(td)
        tr.appendChild(tdHidden)
        tr.onclick = async function () {
          var tableRows = document.getElementById('t_points').getElementsByTagName('tr')
          for (var i = 0; i < tableRows.length; i++) {
            var row = tableRows[i]
            var cells = row.getElementsByTagName('td')
            for (var j = 0; j < cells.length; j++) {
              var cell = cells[j]
              if (cell !== td) {
                var button = cell.querySelector('button')
                if (button) {
                  var cellTitle = button.getAttribute('data-title')
                  cell.innerHTML = cellTitle
                  cell.style.padding = '8px 10px'
                }
              }
            }
          }

          var button = td.querySelector('button')
          if (button) {
            // Button is already present, so remove it and revert the title
            var buttonTitle = button.getAttribute('data-title')
            td.innerHTML = buttonTitle
            td.style.padding = '8px 12px !important'
          } else {
            // Button is not present, add it and perform the flyTo action
            map.flyTo(coords, 15, {duration: 2})
            td.innerHTML = '<button style=\'font-size:16px;text-align:left;width:100%;padding: 8px 10px;\' onclick=\'setDestinationLocker("' + code + '","' + title + '","' + pudoMethod + '");\' data-title=\'' + title + '\'>Select ' + title + '</button>'
            td.style.overflow = 'hidden'
            td.style.padding = '0px'
          }
        }
        document.getElementById('t_points').appendChild(tr)
      }

      // Hide any ".hide" classes
      $('.hide').prop('hidden', true)

      let pictureUrl = window.location.origin + '/wp-content/plugins/pudo-shipping-for-woocommerce/dist/icon_pudo.png'

      $(document).on(
        'click',
        '#shipping-option input',
        function () {
          const val = $(this).val()
          const lowerVal = val.toLowerCase()

          if (lowerVal.includes('d2l') || lowerVal.includes('l2l')) {
            isBlocks = true
            pudoMethod = lowerVal
            if ((window.selectedLocker || '') === '') {
              modalShown = false
              pudoShowModal()
            }
          }
        }
      )

      $(document).on(
        'click',
        'input[name^="radio-control-"]',
        async function () {
          const val = $(this).val()
          const lowerVal = val.toLowerCase()
          let is2LMethod = false;
           const $shippingContainer = $(this).closest(
             '.wc-block-checkout__shipping-option, ' +
             '.wp-block-woocommerce-checkout-shipping-methods-block, ' +
             '[data-block-name="woocommerce/checkout-shipping-methods-block"]'
           );
           if (!$shippingContainer.length) return;

          if (lowerVal.includes('d2l') || lowerVal.includes('l2l')) {
            is2LMethod = true;
            isBlocks = true
            pudoMethod = lowerVal
            selectedRadio = this
            if ((window.selectedLocker || '') === '') {
              modalShown = false
              pudoShowModal()
            }
          } else {
            selectedRadio = null
            window.selectedLocker = ''
            renderDisplayedLocker()
          }
          console.log('Selected shipping method on radio click:', lowerVal)
          let is2DMethod = false;
          if (['ovn', 'eco', 'lox'].includes(lowerVal)) {
            is2DMethod = true;
          } else if (lowerVal.startsWith('d2d') || lowerVal.startsWith('l2d')) {
            is2DMethod = true;
          }
          if (is2DMethod || !is2LMethod) {
            const result = await wp.data.dispatch('wc/store/cart').applyExtensionCartUpdate({
              namespace: 'pudo-shipping-for-woocommerce',
              data: {
                locker_code: '',
                locker_title: '',
                pudo_method: lowerVal
              }
            });

            console.log('Cart update result:', result);
          }
        }
      )

      // Add the pudoModal to the body of the app
      $('body').append(
        '<div id="pudoModal" class="pudo-modal">' +
        '<div class="pudo-modal-content">' +
        '<span class="pudo-close">&times;</span>' +
        '<div id="pudo-wrapper">' +
        '<div id="pudo-first">' +
        '<div id="pudo-header-container">' +
        '<h4 class="pudo-h4" id="pudo-modalTitle"><img src="' + pictureUrl + '" class="pudo-marker-title"> The Courier Guy Locker</h4>' +
        '</div>' +
        '<p class="pnodesktop pudo-modal-desc"> Select a locker for delivery</p>' +
        '<div id="pudo-table-container" style="display:inline-block">' +
        '<table class="table table-bordered" style="display:inline-block" id="table">' +
        '<thead>' +
        '<tr>' +
        '<th>The Courier Guy Locker</th>' +
        '<th>Area</th>' +
        '</tr>' +
        '</thead>' +
        '<tbody id="t_points"></tbody>' +
        '</table>' +
        '</div>' +
        '</div>' +
        '<div id="pudo-map-container">' +
        '<div id="pudo-map" style="display:inline-block width: 100%;" class=""></div>' +
        '</div>' +
        '</div>' +
        '</div>'
      )

      // Define map object
      var mapElement = document.getElementById('pudo-map')
      var currencyLabel = document.getElementById('pricing_options-description')
      // Check type of control
      if (currencyLabel === null && mapElement !== null) {
        map = L.map('pudo-map').setView([-27.9150338, 24.8737837], 6)
        // Fetch tile layer (OSM)
        L.tileLayer('//{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map)
      }
      // Define provider
      $(document).ready(
        function () {
          // Add select2 to pudo selector
          const $lockerOrigin = $('#pudo-locker-origin')
          const $lockerDestination = $('#pudo-locker-destination')
          // Check what type of control is used to select locker
          // If the map is not used, initialize select2 on the element
          if ($lockerDestination[0]) {
            $lockerDestination.select2()
          }

          if (map != null) {

            // Get the modal
            pudoModal = document.getElementById('pudoModal')

            // Get the button that opens the modal
            // Get the <span> element that closes the modal
            var span = document.getElementsByClassName('pudo-close')[0]

            // When the user clicks on <span> (x), close the modal
            span.onclick = function () {
              pudoModal.style.display = 'none'
              renderDisplayedLocker()
            }

          } else {
            $lockerOrigin.select2()
            // Hide/Show address  fields based on selection of shipping type
          }

          if (map != null) {
            var markers = L.markerClusterGroup({chunkedLoading: true})
            var points = pudo_params.markersJSON

            // Add the markers to the map and table on left of map
            for (let key in points) {
              if (points.hasOwnProperty(key)) {
                let entry = points[key]
                addMarker(entry.name, entry.code, entry.address, entry.latitude, entry.longitude, markers)
              }
            }
            map.addLayer(markers)

            // Assign on change function to each of the markers
            $('#range').change(
              function (e) {
                var radius = parseInt($(this).val())
                markers.forEach(
                  function (e) {
                    e.setRadius(radius)
                    e.addTo(map)

                  }
                )
              }
            )
            // Initialize data tables
            $('#table').DataTable(
              {
                responsive: false,
                'bInfo': false,
                'bLengthChange': false,
                pagingType: 'full',
                pageLength: 6,
                language: {
                  search: '_INPUT_',
                  searchPlaceholder: 'Search'
                },
                columnDefs: [
                  {'visible': false, 'targets': 1}
                ]
              }
            )
            // Hide pagination, table length and info on map modal
            $('#table').find('.dataTables_paginate, .dataTables_length, .dataTables_info').hide()

            // Hide the tool tips on map
            map.eachLayer(
              function (layer) {
                if (layer.options.pane === 'tooltipPane') {
                  layer.removeFrom(map)
                }
              }
            )

            // Move the search input to the header container
            $('#table_filter').appendTo('#pudo-header-container')

          }
        }
      )
    }
  )

  // check if the page is the checkout page and trigger the modal if a l2l/d2l shipping method is selected
  jQuery(document).ready(
    async function ($) {
      const selectedShippingOption = $('input[name^="radio-control-"]:checked').val()?.toLowerCase()
      console.log('Selected shipping option on page load:', selectedShippingOption)
      if (['ovn', 'eco', 'lox'].includes(selectedShippingOption) || selectedShippingOption?.startsWith('d2d')) {
        await wp.data.dispatch('wc/store/cart').applyExtensionCartUpdate({
          namespace: 'pudo-shipping-for-woocommerce',
          data: {
            locker_code: '',
            locker_title: '',
            pudo_method: selectedShippingOption
          }
        });
      }
      if (document.body.classList.contains('woocommerce-checkout')) {
        var retryCount = 0
        var maxRetries = 3
        var retryDelay = 300

        function checkAndTriggerPudoModal() {
          var radios = jQuery('input[type="radio"][id^="radio-control-"]')
          let checkedRadio = $('.wc-block-checkout__shipping-option input[name^="radio-control-"]:checked')

          if (radios.length) {
            if (checkedRadio.length) {
              var checkedValue = (checkedRadio.val() || '').toLowerCase()
              if (checkedValue.includes('l2l') || checkedValue.includes('d2l')) {
                isBlocks = true
                pudoMethod = checkedValue
                modalShown = false
                pudoShowModal()
              }
            } else {
              modalShown = false
              pudoShowModal()
            }
          } else {
            retryCount++
            if (retryCount <= maxRetries) {
              setTimeout(checkAndTriggerPudoModal, retryDelay)
            }
          }
        }

        pudoModal = document.getElementById('pudoModal')
        checkAndTriggerPudoModal()
      }
    }
  )

})(jQuery)
