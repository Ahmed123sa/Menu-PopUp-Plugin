jQuery(document).ready(function ($) {
    $('#menu-popup-reservation-form').on('submit', function (e) {
        e.preventDefault();
        var form = $(this);
        var message = form.find('.form-message');
        var submitBtn = form.find('.submit-btn');

        submitBtn.prop('disabled', true).text('Booking...');
        message.html('').removeClass('error success');

        $.ajax({
            url: menuPopupReservation.ajax_url,
            type: 'POST',
            data: form.serialize() + '&action=menu_popup_submit_reservation&nonce=' + menuPopupReservation.nonce,
            success: function (response) {
                if (response.success) {
                    message.addClass('success').html(response.data);
                    form[0].reset();
                } else {
                    message.addClass('error').html(response.data);
                }
            },
            error: function () {
                message.addClass('error').html('An error occurred. Please try again.');
            },
            complete: function () {
                submitBtn.prop('disabled', false).text('Book Table');
            }
        });
    });
});
