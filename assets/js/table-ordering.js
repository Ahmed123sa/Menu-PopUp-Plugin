/**
 * Customer side of "order from the table".
 *
 * The QR link stores the table in a cookie. Here the cookie is checked with the
 * server and, if it is valid, the menu switches to table mode: payment choices
 * for the table, no delivery, and buttons to call the waiter or ask for the bill.
 * Nothing is read from the page HTML, so page caching can never mix up tables.
 */
(function ($) {
    'use strict';

    if (typeof mpTableCfg === 'undefined' || typeof menuPopupAjax === 'undefined') { return; }

    var T = mpTableCfg.i18n;

    function readCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()\[\]\\\/+^]/g, '\\$&') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    function dropCookie() {
        document.cookie = mpTableCfg.cookie + '=; Max-Age=0; path=/';
    }

    var ref = readCookie(mpTableCfg.cookie);
    if (!ref) { return; }

    window.mpTablePending = true;

    function say($bar, text) {
        var $m = $bar.find('.mp-table-msg').text(text).show();
        clearTimeout($m.data('t'));
        $m.data('t', setTimeout(function () { $m.fadeOut(200); }, 4000));
    }

    function cooldown($btn, seconds) {
        var label = $btn.data('label');
        var left = seconds;
        $btn.prop('disabled', true);
        var tick = setInterval(function () {
            left--;
            if (left <= 0) {
                clearInterval(tick);
                $btn.prop('disabled', false).text(label);
            } else {
                $btn.text(label + ' (' + left + ')');
            }
        }, 1000);
    }

    function buildBar(ctx) {
        var $bar = $('<div class="mp-table-bar" role="region"></div>');
        $bar.append($('<span class="mp-table-name"></span>').text('🍽️ ' + T.table + ' ' + ctx.name));

        var $a = $('<span class="mp-table-actions"></span>');
        $.each([['waiter', T.callWaiter, T.waiterSent], ['bill', T.askBill, T.billSent]], function (_, d) {
            var $b = $('<button type="button" class="mp-table-btn"></button>').text(d[1]).data('label', d[1]);
            $b.on('click', function () {
                if ($b.prop('disabled')) { return; }
                $b.prop('disabled', true);
                $.ajax({
                    url: mpTableCfg.ajax, type: 'POST', dataType: 'json',
                    data: { action: 'mp_table_call', ref: ref, type: d[0] }
                }).done(function (res) {
                    if (res && res.success) {
                        say($bar, d[2]);
                        cooldown($b, ctx.cooldown);
                    } else {
                        say($bar, (res && res.data && res.data.message) || T.error);
                        cooldown($b, 15);
                    }
                }).fail(function () {
                    say($bar, T.error);
                    $b.prop('disabled', false);
                });
            });
            $a.append($b);
        });
        $bar.append($a).append('<div class="mp-table-msg" style="display:none"></div>');
        return $bar;
    }

    $.ajax({
        url: mpTableCfg.ajax, type: 'POST', dataType: 'json',
        data: { action: 'mp_table_context', ref: ref }
    }).done(function (res) {
        window.mpTablePending = false;

        if (!res || !res.success) {
            dropCookie();
            return;
        }

        var ctx = res.data;
        menuPopupAjax.table = { id: ctx.id, sig: ctx.sig, name: ctx.name, branch_id: ctx.branch_id, mode: ctx.mode };
        menuPopupAjax.paymentMethods = ctx.methods || [];
        // Fields are rendered by the main script; the checks relax at a table
        menuPopupAjax.customerNameRequired = '1';
        menuPopupAjax.customerPhoneRequired = '1';
        menuPopupAjax.deliveryEnabled = '0';
        menuPopupAjax.geoCheckEnabled = '0';

        $('body').addClass('mp-at-table').prepend(buildBar(ctx));
        $(document).trigger('mp:table-ready', [menuPopupAjax.table]);
    }).fail(function () {
        // Network problem: keep the cookie, ordering stays blocked until the page is reloaded
        window.mpTablePending = false;
        window.mpTableFailed = true;
    });
})(jQuery);
