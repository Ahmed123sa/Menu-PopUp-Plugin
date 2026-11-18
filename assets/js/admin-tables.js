/**
 * Tables page: open tables (bills, waiter calls) and QR codes.
 */
(function ($) {
    'use strict';

    if (typeof mpTables === 'undefined') { return; }

    var T = mpTables.i18n;
    var state = { first: true, seen: {}, calls: {}, sound: true, timer: null, busy: false };

    try { state.sound = localStorage.getItem('mpTablesSound') !== '0'; } catch (e) {}

    // ---------------------------------------------------------------- helpers
    function el(tag, cls, text) {
        var n = $(document.createElement(tag));
        if (cls) { n.addClass(cls); }
        if (text !== undefined && text !== null) { n.text(text); }
        return n;
    }

    function money(v, cur) { return (cur || '') + Number(v || 0).toFixed(2); }

    function ago(ts, now) {
        var m = Math.max(0, Math.floor((now - ts) / 60));
        return m < 1 ? T.now : (m + ' ' + T.ago);
    }

    function post(action, data) {
        return $.ajax({
            url: mpTables.ajax,
            type: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: mpTables.nonce }, data || {})
        });
    }

    function failMsg(res) {
        return (res && res.data && res.data.message) ? res.data.message : T.error;
    }

    // ---------------------------------------------------------------- sound
    var audio;
    function beep() {
        if (!state.sound) { return; }
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            var o = audio.createOscillator(), g = audio.createGain();
            o.type = 'sine'; o.frequency.value = 880;
            o.connect(g); g.connect(audio.destination);
            g.gain.setValueAtTime(0.0001, audio.currentTime);
            g.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + 0.6);
            o.start(); o.stop(audio.currentTime + 0.65);
        } catch (e) {}
    }

    function paintSound() {
        $('#mp-tables-sound').text((state.sound ? '🔔 ' + T.soundOn : '🔕 ' + T.soundOff));
    }

    // ---------------------------------------------------------------- open tables
    function orderLine(o, data) {
        var $li = el('li', 'mp-t-order' + (o.paid ? ' is-paid' : ''));
        var $top = el('div', 'mp-t-order-top');

        if (data.mode === 'inside' && o.settleable) {
            $top.append($('<input type="checkbox" class="mp-t-pick">').val(o.id).prop('checked', true));
        } else {
            $top.append(el('span', 'mp-t-pick-gap'));
        }

        $top.append(el('strong', '', '#' + String(o.number).replace(/^ORD-\d{8}-/, '')));
        $top.append(el('span', 'mp-t-status mp-s-' + o.status, (mpTables.statusLabels && mpTables.statusLabels[o.status]) || o.status));
        $top.append(el('span', 'mp-t-total', money(o.total, data.currency)));
        $li.append($top);

        if (o.items) { $li.append(el('div', 'mp-t-items', o.items)); }
        if (o.notes) { $li.append(el('div', 'mp-t-notes', '📝 ' + o.notes)); }

        if (data.mode === 'inside') {
            var label = o.paid
                ? '✔ ' + T.paid + ' · ' + (o.paid_via === 'card_pos' ? T.paidCard : (o.paid_via === 'cash' ? T.paidCash : o.method_label))
                : T.unpaid + (o.method_label ? ' · ' + o.method_label : '');
            $li.append(el('div', 'mp-t-pay ' + (o.paid ? 'ok' : 'wait'), label));
        }
        return $li;
    }

    function tableCard(t, data, now) {
        var $c = el('div', 'mp-t-card');
        $c.attr('data-table', t.id);

        var $h = el('div', 'mp-t-head');
        $h.append(el('h3', '', t.name));
        if (t.branch) { $h.append(el('span', 'mp-t-branch', t.branch)); }
        $c.append($h);

        if (t.waiter > 0) {
            $c.append(el('div', 'mp-t-call').append(
                el('span', '', '🔔 ' + T.waiterCall + ' · ' + ago(t.waiter, now)),
                $('<button type="button" class="button button-small mp-t-clear" data-type="waiter"></button>').text(T.done)
            ));
        }
        if (t.bill > 0) {
            $c.append(el('div', 'mp-t-call mp-t-call-bill').append(
                el('span', '', '🧾 ' + T.billCall + ' · ' + ago(t.bill, now)),
                $('<button type="button" class="button button-small mp-t-clear" data-type="bill"></button>').text(T.done)
            ));
        }

        if (t.orders.length) {
            var $ul = el('ul', 'mp-t-orders');
            $.each(t.orders, function (_, o) { $ul.append(orderLine(o, data)); });
            $c.append($ul);

            var $f = el('div', 'mp-t-foot');
            if (data.mode === 'inside') {
                $f.append(el('div', 'mp-t-sum').append(
                    el('span', '', T.due), el('strong', '', money(t.due, data.currency))
                ));
                if (t.paid > 0) {
                    $f.append(el('div', 'mp-t-sum mp-t-sum-paid').append(
                        el('span', '', T.paidTotal), el('span', '', money(t.paid, data.currency))
                    ));
                }
                var $b = el('div', 'mp-t-actions');
                $b.append($('<button type="button" class="button button-primary mp-t-settle" data-via="cash"></button>').text(T.paidCash));
                $b.append($('<button type="button" class="button mp-t-settle" data-via="card_pos"></button>').text(T.paidCard));
                $f.append($b);
            } else {
                var total = t.due + t.paid;
                $f.append(el('div', 'mp-t-sum').append(
                    el('span', '', mpTables.i18n.items), el('strong', '', money(total, data.currency))
                ));
                $f.append(el('p', 'mp-t-note', T.outsideNote));
            }
            $f.append($('<button type="button" class="button button-link-delete mp-t-close"></button>').text(T.closeTable));
            $c.append($f);
        } else {
            $c.append(el('p', 'mp-t-empty', T.noOrders));
        }

        return $c;
    }

    function renderOpen(data) {
        var now = data.time || Math.floor(Date.now() / 1000);
        var $grid = $('#mp-open-grid').empty();
        var $free = $('#mp-free-list').empty();
        var freeNames = [];
        var alertNew = false;

        $.each(data.tables, function (_, t) {
            // Anything new since the last refresh makes the sound
            $.each(t.orders, function (_, o) {
                if (!state.seen[o.id]) { state.seen[o.id] = 1; if (!state.first) { alertNew = true; } }
            });
            var prev = state.calls[t.id];
            if (!state.first && (t.waiter > 0 || t.bill > 0) && (!prev || t.waiter > prev.w || t.bill > prev.b)) { alertNew = true; }
            state.calls[t.id] = { w: t.waiter, b: t.bill };

            if (t.orders.length || t.waiter > 0 || t.bill > 0) {
                $grid.append(tableCard(t, data, now));
            } else {
                freeNames.push(t.name);
            }
        });

        if (!data.tables.length) {
            $grid.append(el('p', 'mp-t-empty', T.noTables));
        }
        if (freeNames.length) {
            $free.append(el('h4', '', T.free));
            $.each(freeNames, function (_, n) { $free.append(el('span', 'mp-t-chip', n)); });
        }

        state.first = false;
        if (alertNew) { beep(); }
        $('#mp-tables-updated').text(new Date().toLocaleTimeString());
    }

    function load() {
        if (state.busy) { return; }
        state.busy = true;
        post('mp_tables_data').done(function (res) {
            if (res && res.success) { renderOpen(res.data); }
        }).always(function () { state.busy = false; });
    }

    function bindOpen() {
        $('#mp-open-grid').on('click', '.mp-t-clear', function () {
            var $b = $(this).prop('disabled', true);
            var id = $b.closest('.mp-t-card').data('table');
            post('mp_tables_clear_call', { table_id: id, type: $b.data('type') }).always(load);
        });

        $('#mp-open-grid').on('click', '.mp-t-settle', function () {
            var $card = $(this).closest('.mp-t-card');
            var ids = $card.find('.mp-t-pick:checked').map(function () { return $(this).val(); }).get();
            if (!ids.length) { window.alert(T.pickOrders); return; }
            var $btns = $card.find('button').prop('disabled', true);
            post('mp_tables_settle', { table_id: $card.data('table'), via: $(this).data('via'), order_ids: ids })
                .done(function (res) { if (!res || !res.success) { window.alert(failMsg(res)); } })
                .fail(function () { window.alert(T.error); })
                .always(function () { $btns.prop('disabled', false); load(); });
        });

        $('#mp-open-grid').on('click', '.mp-t-close', function () {
            var $card = $(this).closest('.mp-t-card');
            var id = $card.data('table');
            if (!window.confirm(T.closeAsk)) { return; }
            post('mp_tables_close', { table_id: id }).done(function (res) {
                if (res && res.success) { load(); return; }
                if (res && res.data && res.data.code === 'unpaid') {
                    if (window.confirm(T.closeUnpaid)) {
                        post('mp_tables_close', { table_id: id, force: 1 }).always(load);
                    }
                    return;
                }
                window.alert(failMsg(res));
            }).fail(function () { window.alert(T.error); });
        });

        $('#mp-tables-sound').on('click', function () {
            state.sound = !state.sound;
            try { localStorage.setItem('mpTablesSound', state.sound ? '1' : '0'); } catch (e) {}
            paintSound();
            if (state.sound) { beep(); }
        });
    }

    // ---------------------------------------------------------------- QR codes
    function qrModules(url) {
        var q = window.qrcode(0, 'M');
        q.addData(url);
        q.make();
        return q;
    }

    function qrSvg(url, px) {
        var q = qrModules(url), n = q.getModuleCount(), m = 4, d = '', r, c;
        for (r = 0; r < n; r++) {
            for (c = 0; c < n; c++) {
                if (q.isDark(r, c)) { d += 'M' + (c + m) + ',' + (r + m) + 'h1v1h-1z'; }
            }
        }
        var s = n + m * 2;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + s + ' ' + s + '" width="' + px + '" height="' + px +
            '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function safeName(s) {
        return String(s).replace(/[^\w؀-ۿ\-]+/g, '_').replace(/^_+|_+$/g, '') || 'table';
    }

    function cardHtml(t) {
        var head = mpTables.logo
            ? '<img class="logo" src="' + esc(mpTables.logo) + '" alt="">'
            : '<div class="brand">' + esc(mpTables.siteName) + '</div>';
        return '<div class="card">' + head + '<div class="qr">' + qrSvg(t.url, 280) + '</div><div class="name">' + esc(t.name) + '</div></div>';
    }

    function printCards(list) {
        var w = window.open('', '_blank');
        if (!w) { window.alert(T.popup); return; }
        var css =
            '@page{size:A4;margin:10mm}*{box-sizing:border-box}' +
            'body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#111}' +
            '.sheet{display:grid;grid-template-columns:1fr 1fr;gap:8mm}' +
            '.card{border:1px dashed #999;border-radius:6mm;padding:8mm;text-align:center;page-break-inside:avoid;height:128mm;display:flex;flex-direction:column;align-items:center;justify-content:space-between}' +
            '.logo{max-height:26mm;max-width:70%;object-fit:contain}.brand{font-size:22px;font-weight:700}' +
            '.qr svg{width:72mm;height:72mm}.name{font-size:34px;font-weight:700;line-height:1.1}';
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>QR</title><style>' + css + '</style></head><body><div class="sheet">';
        $.each(list, function (_, t) { html += cardHtml(t); });
        html += '</div></body></html>';
        w.document.open();
        w.document.write(html);
        w.document.close();
        w.focus();
        setTimeout(function () { try { w.print(); } catch (e) {} }, 500);
    }

    function downloadPng(t) {
        var q = qrModules(t.url), n = q.getModuleCount(), cell = 12, m = 4;
        var size = (n + m * 2) * cell, titleH = 110;
        var cv = document.createElement('canvas');
        cv.width = size; cv.height = size + titleH;
        var ctx = cv.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
        ctx.fillStyle = '#000';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (q.isDark(r, c)) { ctx.fillRect((c + m) * cell, (r + m) * cell, cell, cell); }
            }
        }
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.font = 'bold 64px Arial, sans-serif';
        ctx.fillText(t.name, size / 2, size + titleH / 2 - 8);

        cv.toBlob(function (blob) {
            if (!blob) { return; }
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'table-' + safeName(t.name) + '.png';
            document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        }, 'image/png');
    }

    function findTable(id) {
        for (var i = 0; i < mpTables.tables.length; i++) {
            if (String(mpTables.tables[i].id) === String(id)) { return mpTables.tables[i]; }
        }
        return null;
    }

    function renderQr() {
        var $g = $('#mp-qr-grid').empty();
        if (!mpTables.tables.length) {
            $g.append(el('p', 'mp-t-empty', T.noTables));
            return;
        }
        $.each(mpTables.tables, function (_, t) {
            var $c = el('div', 'mp-qr-card').attr('data-table', t.id);
            $c.append($('<div class="mp-qr-img"></div>').html(qrSvg(t.url, 150)));
            $c.append(el('h3', '', t.name));
            if (t.branch) { $c.append(el('div', 'mp-t-branch', t.branch)); }
            var $b = el('div', 'mp-qr-actions');
            $b.append($('<button type="button" class="button button-primary mp-qr-print"></button>').text(T.print));
            $b.append($('<button type="button" class="button mp-qr-png"></button>').text(T.png));
            $b.append($('<button type="button" class="button mp-qr-copy"></button>').text(T.copyLink));
            if (t.edit) { $b.append($('<a class="button" target="_blank"></a>').attr('href', t.edit).text(T.edit)); }
            $b.append($('<button type="button" class="button button-link-delete mp-qr-regen"></button>').text(T.regen));
            $c.append($b);
            $g.append($c);
        });
    }

    function bindQr() {
        $('#mp-qr-grid').on('click', '.mp-qr-print', function () {
            var t = findTable($(this).closest('.mp-qr-card').data('table'));
            if (t) { printCards([t]); }
        });
        $('#mp-qr-grid').on('click', '.mp-qr-png', function () {
            var t = findTable($(this).closest('.mp-qr-card').data('table'));
            if (t) { downloadPng(t); }
        });
        $('#mp-qr-grid').on('click', '.mp-qr-copy', function () {
            var t = findTable($(this).closest('.mp-qr-card').data('table')), $b = $(this);
            if (!t) { return; }
            var done = function () { var old = $b.text(); $b.text(T.copied); setTimeout(function () { $b.text(old); }, 1200); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(t.url).then(done);
            } else {
                var ta = $('<textarea>').val(t.url).appendTo('body').select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                ta.remove();
            }
        });
        $('#mp-qr-grid').on('click', '.mp-qr-regen', function () {
            var $c = $(this).closest('.mp-qr-card'), t = findTable($c.data('table'));
            if (!t || !window.confirm(T.regenAsk)) { return; }
            post('mp_tables_regen', { table_id: t.id }).done(function (res) {
                if (res && res.success) {
                    t.url = res.data.url;
                    $c.find('.mp-qr-img').html(qrSvg(t.url, 150));
                } else { window.alert(failMsg(res)); }
            }).fail(function () { window.alert(T.error); });
        });
        $('#mp-print-all').on('click', function () { printCards(mpTables.tables); });
    }

    // ---------------------------------------------------------------- tabs / start
    function showTab(tab) {
        tab = tab === 'qr' ? 'qr' : 'open';
        $('.mp-tables-tabs .nav-tab').removeClass('nav-tab-active').filter('[data-tab="' + tab + '"]').addClass('nav-tab-active');
        $('.mp-tables-panel').hide().filter('[data-panel="' + tab + '"]').show();
    }

    $(function () {
        $('#mp-print-all').text(T.printAll);
        $('#mp-add-table').text(T.addTable).attr('href', mpTables.addUrl);
        paintSound();

        $('.mp-tables-tabs').on('click', '.nav-tab', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            if (history.replaceState) { history.replaceState(null, '', '#' + tab); }
            showTab(tab);
        });
        showTab((location.hash || '').replace('#', ''));

        bindOpen();
        bindQr();
        renderQr();
        load();
        state.timer = setInterval(function () { if (!document.hidden) { load(); } }, mpTables.refresh * 1000);
    });
})(jQuery);
