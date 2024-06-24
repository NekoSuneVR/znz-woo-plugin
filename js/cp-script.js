// Prevent conflict with other libraries
jQuery.noConflict();

(function( $ ) {
    $(function() {

        ////////////////////////////////
        // Countdown timer for transaction verification
        ////////////////////////////////
        var znzCount = parseInt($('input[name="znz_order_remaining_time"]').val());
        var znzCounter = setInterval(timer, 1000);

        function formatTime(seconds) {
            var h = Math.floor(seconds / 3600),
                m = Math.floor(seconds / 60) % 60,
                s = seconds % 60;
            if (h < 10) h = "0" + h;
            if (m < 10) m = "0" + m;
            if (s < 10) s = "0" + s;
            return m + ":" + s;
        }

        function timer() {
            znzCount--;
            if (znzCount < 0) {
              return clearInterval(znzCounter);
            }
            $('.znz-counter').html(formatTime(znzCount));
        }


        ////////////////////////////////
        // Copy button action
        ////////////////////////////////
        $(document).on('click', '.znz-copy-btn', function(e){
            var btn = $(this);
            var input = btn.closest('.znz-input-box').find('input');

            input.select();
            document.execCommand("Copy");

            btn.addClass('znz-copied');
            setTimeout( function(){
                btn.removeClass('znz-copied');
            }, 1000);
        });


        ////////////////////////////////
        // Countdown timer for transaction verification
        ////////////////////////////////
        var znz_interval;
        verifyTransaction();

        function verifyTransaction(){
            clearTimeout(znz_interval);
            znz_interval = null;
            var baseurl = window.location.origin;

            $.ajax({
                url: '/wp-admin/admin-ajax.php',
                type: "POST",
                data: {
                    action: "znz_verify_payment",
                    // Add any other necessary data here
                },
                beforeSend: function(){

                },
                success: function(response) {
                    console.log(response);
                    var order_message = $('.znz-payment-msg');
                    var order_info_holder = $('.znz-payment-info-holder');
                    var order_status = $('.znz-payment-info-status');
                    var counter = $('.znz-counter');

                    // Update status message
                    order_status.html(response.message);

                    // Continue with payment verification requests
                    if (response.status == "waiting" || response.status == "detected" || response.status == "failed" || response.status == "expired") {
                        if(response.status == "expired") {
                            order_message.html('The payment time for order has expired! Do not make any payments as they will be invalid! If you have already made a payment within the allowed time, please wait.')

                            setTimeout(() => location.reload(), 2000)
                        }

                        if(response.status == "detected") {
                            clearInterval(znzCounter);
                            counter.html('00:00');
                        }

                        znz_interval = setTimeout(function(){
                          verifyTransaction();
                        }, 10000);
                        return false;
                    }
                    if(response.status == "confirmed") {
                        order_info_holder.addClass('znz-' + response.status);

                        clearInterval(znzCounter);
                        counter.html('00:00');

                        setTimeout( function(){
                            location.reload()
                        }, 2000);

                        return false;
                    }
                }
            });
        }
    });
})(jQuery);
