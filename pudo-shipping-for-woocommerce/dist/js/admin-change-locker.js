jQuery(document).ready(async function ($) {

  console.log('Admin Change Locker script loaded');

  // -----------------------------
  // Variables from localized PHP
  // -----------------------------
  const lockers = pudoData.lockers || {};
  const orderID = pudoData.orderID || 0;
  const orderMethod = pudoData.orderMethod || '';
  const orderServiceLevelCode = pudoData.orderServiceLevelCode || '';
  const redirectBackUrl = pudoData.redirectBackUrl || '';

  const submitShipmentForm = $('#submit_shipment_form');
  const radioMethods = $('input[name="pudo-method"]');
  const pudoSourceDiv = $('div.pudo-source-locker');
  const pudoDestinationDiv = $('div.pudo-destination-locker');
  const pudoLockerTypeDiv = $('div.pudo-locker-size');
  const pudoD2DPricingDiv = $('div.pudo-d2d-pricing');
  const pudoSubmitDiv = $('div.pudo-submit');
  const spinner = $('#spinner');
  const selectionContainer = $('#selectionContainer');

  const pudoSubmitBtn = $('#pudo-submit-btn');
  const pudoSourceLockerSelect = $('select[name="pudo-source-locker"]');
  const pudoDestinationLockerSelect = $('select[name="pudo-destination-locker"]');
  const pudoLockerTypeSelect = $('select[name="pudo-locker-size"]');
  const serviceLevelCodeInput = $('#service-level-code');

  let method = orderMethod || radioMethods.filter(':checked').val() || 'l2l';
  let pudoSourceLocker = pudoSourceLockerSelect.val();
  let pudoDestinationLocker = pudoDestinationLockerSelect.val();
  let rates;
  let locker = lockers[pudoSourceLocker];

  // -----------------------------
  // Initialize Select2
  // -----------------------------
  if ($.fn.select2) {
    pudoSourceLockerSelect.select2();
    pudoDestinationLockerSelect.select2();
  }

  // Enable radio buttons
  radioMethods.prop('disabled', false);
  radioMethods.filter(`[value="${method}"]`).prop('checked', true);

  // -----------------------------
  // Listeners
  // -----------------------------
  radioMethods.on('change', async function () {
    method = $(this).val();
    await loadMethod(method, pudoSourceLocker, pudoDestinationLocker);
  });

  pudoSourceLockerSelect.on('change', async function () {
    pudoSourceLocker = $(this).val();
    if (method === 'l2l' || method === 'l2d') {
      await loadMethod(method, pudoSourceLocker, pudoDestinationLocker);
    }
  });

  pudoDestinationLockerSelect.on('change', async function () {
    pudoDestinationLocker = $(this).val();
    if (method === 'l2l' || method === 'd2l') {
      await loadMethod(method, pudoSourceLocker, pudoDestinationLocker);
    }
  });

  pudoLockerTypeSelect.on('change', function () {
    setServiceLevelCode();
  });

  submitShipmentForm.on('submit', async function (e) {
    e.preventDefault();

    spinner.show();
    pudoSubmitBtn.prop('disabled', true);

    try {
      const ajaxSuccess = await new Promise((resolve, reject) => {
        $.ajax({
          url: pudoData.ajaxUrl,
          type: 'POST',

          data: {
            action: 'pudo_submit_shipment',
            pudoPostData: $(this).serialize(),
            nonce: pudoData.nonce
          },
          success: function (response) {
            if (response.success) {
              alert('Booking confirmed!');
              resolve(true);
            } else {
              alert('Unsuccessful booking: ' + (response.data?.message || 'Unknown error'));
              pudoSubmitBtn.prop('disabled', false);
              resolve(false);
            }
          },
          error: function (xhr, status, error) {
            console.error('AJAX error:', error);
            alert('An error occurred while processing the request.');
            reject(false);
          }
        });
      });

      spinner.hide();
      selectionContainer.show();

      if (ajaxSuccess) {
        window.location.href = redirectBackUrl;
      }

    } catch (error) {
      console.error('Form submission error:', error);
      spinner.hide();
    }
  });

  // -----------------------------
  // Helper Functions
  // -----------------------------

  async function loadMethod(method, sourceLocker, destinationLocker) {
    // Hide all optional sections
    pudoSourceDiv.addClass('pudo-hidden');
    pudoDestinationDiv.addClass('pudo-hidden');
    pudoLockerTypeDiv.addClass('pudo-hidden');
    pudoD2DPricingDiv.addClass('pudo-hidden');
    pudoSubmitDiv.addClass('pudo-hidden');

    switch (method) {
      case 'l2l':
        pudoSourceDiv.removeClass('pudo-hidden');
        pudoDestinationDiv.removeClass('pudo-hidden');
        locker = lockers[sourceLocker?.trim()];
        if (await getRates('L2L', sourceLocker, destinationLocker)) {
          populateLockerTypes(locker);
          pudoSubmitDiv.removeClass('pudo-hidden');
        }
        break;
      case 'l2d':
        pudoSourceDiv.removeClass('pudo-hidden');
        locker = lockers[sourceLocker?.trim()];
        if (await getRates('L2D', sourceLocker, null)) {
          populateLockerTypes(locker);
          pudoSubmitDiv.removeClass('pudo-hidden');
        }
        break;
      case 'd2l':
        pudoDestinationDiv.removeClass('pudo-hidden');
        locker = lockers[destinationLocker?.trim()];
        if (await getRates('D2L', null, destinationLocker)) {
          populateLockerTypes(locker);
          pudoSubmitDiv.removeClass('pudo-hidden');
        }
        break;
      case 'd2d':
        if (await getRates('D2D', null, null)) {
          populateD2DRates();
          pudoD2DPricingDiv.removeClass('pudo-hidden');
          pudoSubmitDiv.removeClass('pudo-hidden');
        }
        break;
    }
    setServiceLevelCode();
  }

  function setServiceLevelCode() {
    const selectedOption = pudoLockerTypeSelect.find('option:selected');
    const serviceLevelCode = selectedOption.data('servicelevelcode') || '';
    serviceLevelCodeInput.val(serviceLevelCode);
  }

  async function getRates(method, collectionAddress, deliveryAddress) {
    const data = {
      collectionAddress: collectionAddress,
      deliveryAddress: deliveryAddress,
      method: method,
      orderID: orderID,
      lockerSize: pudoLockerTypeSelect.val()
    };

    try {
      selectionContainer.hide();
      $('#form-content-pudo').append('<i class="fas fa-spinner fa-spin"></i>');

      return await new Promise((resolve, reject) => {
        $.ajax({
          url: pudoData.ajaxUrl,
          type: 'POST',
          data: {action: 'pudo_get_rates', pudoPost: data, nonce: pudoData.nonce},
          success: function (rateResponse) {
            console.log('Rates response:', rateResponse);

            if (!rateResponse.success) {
              alert(rateResponse.data?.message || 'Failed to get rates');
              reject('Failed to get rates');
            } else {
              rates = JSON.parse(rateResponse.data.rates);
              resolve(true);
            }
          },
          error: function (xhr, status, error) {
            reject(error);
          }
        });
      });
    } catch (err) {
      console.error(err);
      return false;
    } finally {
      selectionContainer.show();
      $('#form-content-pudo i.fa-spinner').remove();
    }
  }

  function populateD2DRates() {
    const d2dSelect = $('#d2d-select');
    d2dSelect.empty();
    const rawPudoMethod = $('input[name="raw_pudo_method"]').val();
    console.log('Raw method for D2D:', rawPudoMethod);
    if (!rates || !rates.rates) return;

    rates.rates.forEach(rate => {
      const serviceLevel = rate.service_level;
      // The current create shipment API does not support D2D service levels, so we skip them in the dropdown.
      if (serviceLevel.code.startsWith('D2D')) {
        return;
      }
      const option = new Option(
        `${serviceLevel.name} - ${rate.rate}`,
        serviceLevel.code, false,
        serviceLevel.code.toLowerCase() === rawPudoMethod.toLowerCase()
      );
      d2dSelect.append(option);
    });
  }

  function populateLockerTypes() {
    if (!rates || !rates.rates) return;

    pudoLockerTypeSelect.empty();
    rates.rates.forEach(rate => {
      const price = rate.rate;
      const serviceLevelCode = rate.service_level.code;
      const boxType = rate.service_level.box_type;
      const name = rate.service_level.box_type_name;

      const option = `<option value="${boxType}" data-price="${price}" data-servicelevelcode="${serviceLevelCode}" ${serviceLevelCode === orderServiceLevelCode ? 'selected' : ''}>${name}: R${price}</option>`;
      pudoLockerTypeSelect.append(option);
    });
    pudoLockerTypeDiv.removeClass('pudo-hidden');
  }

  // Initialize default method view
  await loadMethod(method, pudoSourceLocker, pudoDestinationLocker);

});