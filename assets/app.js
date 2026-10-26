jQuery(function ($) {
  function updateCharge() {
    const option = $('#service_id option:selected');
    const price = parseFloat(option.data('price') || 0);
    const quantity = parseInt($('#quantity').val() || 0, 10);
    const charge = (price * quantity) / 1000;
    $('#charge').text('R$ ' + charge.toFixed(2));
  }

  $('#service_id, #quantity').on('change input', updateCharge);
  updateCharge();

  $('#copy-pix').on('click', function () {
    navigator.clipboard.writeText($('#pix-code').val()).then(function () {
      $('#copy-pix').text('Copied!');
    });
  });
});
