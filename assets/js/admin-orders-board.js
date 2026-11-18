/**
 * Order Dashboard – live board, payments to check, history.
 * Depends on jQuery and mpBoard (wp_localize_script).
 */
/* global jQuery, mpBoard */
(function ($) {
    'use strict';

    var C = window.mpBoard || {};
    var T = C.i18n || {};
    var view = 'live';
    var timer = null;
    var historyPage = 1;
    var seen = null;            // ids already shown (new + receipts), for the alert
    var openId = 0;             // order open in the drawer
    var soundOn = true;
    var audioCtx = null;
    var baseTitle = document.title;

    try { soundOn = localStorage.getItem('mpBoardSound') !== '0'; } catch (e) {}

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------
    function typeIcon(o) { return o.type === 'delivery' ? '🚚' : (o.type === 'dine_in' ? '🍽️' : '🛍️'); }
    function typeText(o) {
        if (o.type === 'delivery') { return T.delivery; }
        if (o.type === 'dine_in') { return T.dineIn + (o.table ? ' · ' + T.table + ' ' + o.table : ''); }
        return T.pickup;
    }
    function money(v, cur) { return (cur || C.currency || '') + Number(v || 0).toFixed(2); }
    function el(tag, cls, text) {
        var $e = $('<' + tag + '></' + tag + '>');
        if (cls) { $e.addClass(cls); }
        if (text !== undefined && text !== null) { $e.text(text); }
        return $e;
    }
    function ago(ts) {
        var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (s < 60) { return s + 's'; }
        if (s < 3600) { return Math.floor(s / 60) + ' min'; }
        if (s < 86400) { return Math.floor(s / 3600) + ' h'; }
        return Math.floor(s / 86400) + ' d';
    }
    function clock(sec) {
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }
    function statusLabel(s) { return (C.statusLabels && C.statusLabels[s]) || s; }
    function paymentLabel(s) { return (C.paymentLabels && C.paymentLabels[s]) || s || ''; }
    function filters() {
        return {
            search: $('#mp-f-search').val() || '',
            branch: $('#mp-f-branch').val() || 0,
            type: $('#mp-f-type').val() || '',
            from: $('#mp-f-from').val() || '',
            to: $('#mp-f-to').val() || '',
            status: $('#mp-f-status').val() || ''
        };
    }
    function api(action, data) {
        return $.post(C.ajax, $.extend({ action: action, nonce: C.nonce }, data || {}));
    }
    function fail(res) {
        window.alert((res && res.data && res.data.message) || T.error);
    }
    function fill(text, o) {
        return String(text || '').replace(/\{(\w+)\}/g, function (m, k) { return o[k] !== undefined ? o[k] : ''; });
    }

    // ------------------------------------------------------------------
    // sound + title alert
    // ------------------------------------------------------------------
    function beep() {
        if (!soundOn) { return; }
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.25].forEach(function (d) {
                var o = audioCtx.createOscillator(), g = audioCtx.createGain();
                o.type = 'sine'; o.frequency.value = 880;
                g.gain.setValueAtTime(0.0001, audioCtx.currentTime + d);
                g.gain.exponentialRampToValueAtTime(0.3, audioCtx.currentTime + d + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + d + 0.2);
                o.connect(g); g.connect(audioCtx.destination);
                o.start(audioCtx.currentTime + d); o.stop(audioCtx.currentTime + d + 0.22);
            });
        } catch (e) {}
    }
    function soundButton() { $('#mp-sound').text(soundOn ? '🔔 ' + T.soundOn : '🔕 ' + T.soundOff); }

    // ids that should ring: new orders and receipts to check
    function watch(ids) {
        if (seen === null) { seen = {}; ids.forEach(function (i) { seen[i] = 1; }); return; }
        var fresh = ids.filter(function (i) { return !seen[i]; });
        ids.forEach(function (i) { seen[i] = 1; });
        if (fresh.length) { beep(); }
    }

    // ------------------------------------------------------------------
    // stats
    // ------------------------------------------------------------------
    function renderStats(st) {
        if (!st) { return; }
        $('[data-stat="today_orders"]').text(st.today_orders);
        $('[data-stat="today_sales"]').text(money(st.today_sales));
        $('[data-stat="live"]').text(st.live);
        $('[data-stat="to_check"]').text(st.to_check);
        $('[data-count="live"]').text(st.live || '');
        $('[data-count="to_check"]').text(st.to_check || '');
        $('.mp-stat-warn').toggleClass('is-on', st.to_check > 0);
        document.title = (st.to_check > 0 ? '(' + st.to_check + ') ' : '') + baseTitle;
    }

    // ------------------------------------------------------------------
    // cards
    // ------------------------------------------------------------------
    function card(o, opts) {
        opts = opts || {};
        var $c = el('div', 'mp-card mp-st-' + o.status).attr('data-id', o.id);
        var $h = el('div', 'mp-card-head');
        $h.append(el('strong', 'mp-num', '#' + o.number));
        $h.append(el('span', 'mp-type mp-type-' + o.type, typeIcon(o) + ' ' + typeText(o)));
        $h.append(el('span', 'mp-ago', ago(o.time)));
        $c.append($h);

        var who = o.customer || '—';
        $c.append(el('div', 'mp-who', who + (o.phone ? ' · ' + o.phone : '')));
        if (o.branch || o.zone) {
            $c.append(el('div', 'mp-meta', [o.branch, o.zone].filter(Boolean).join(' · ')));
        }
        $c.append(el('div', 'mp-items', o.items_short));

        var $f = el('div', 'mp-card-foot');
        $f.append(el('strong', 'mp-total', money(o.total, o.currency)));
        var pay = o.payment_label + (o.payment_status ? ' · ' + paymentLabel(o.payment_status) : '');
        $f.append(el('span', 'mp-pay mp-pay-' + (o.payment_status || 'none'), pay));
        $c.append($f);

        if (o.needs_review) { $c.append(el('div', 'mp-warn', '⚠️')); }
        if (o.duplicates && o.duplicates.length) {
            $c.append(el('div', 'mp-warn', T.duplicate + ' ' + o.duplicates.join(', ')));
        }
        if (opts.payment && o.reference) {
            $c.append(el('div', 'mp-meta', T.reference + ': ' + o.reference));
        }
        if (o.status === 'awaiting_payment' && o.seconds_left > 0) {
            $c.append(el('div', 'mp-meta mp-left', clock(o.seconds_left) + ' ' + T.timeLeft).attr('data-left', o.seconds_left));
        }

        var $a = el('div', 'mp-actions');
        if (opts.next && T.next[o.status]) {
            $a.append(el('button', 'button button-primary mp-next', T.next[o.status]).attr('type', 'button'));
        }
        if (opts.payment && o.status === 'awaiting_verification') {
            $a.append(el('button', 'button button-primary mp-pay-ok', T.confirmPay).attr('type', 'button'));
        }
        $a.append(el('button', 'button mp-open', T.view).attr('type', 'button'));
        $c.append($a);
        return $c;
    }

    function empty() { return el('p', 'mp-empty', T.empty); }

    function renderLive(orders) {
        var cols = [
            { key: 'pending', title: T['new'] },
            { key: 'confirmed', title: T.confirmed },
            { key: 'preparing', title: T.preparing },
            { key: 'ready', title: T.ready }
        ];
        var $wrap = $('#mp-live').empty();
        cols.forEach(function (col) {
            var list = orders.filter(function (o) { return o.status === col.key; });
            // oldest first inside a column: that is the order to work in
            list.sort(function (a, b) { return a.time - b.time; });
            var $col = el('div', 'mp-col mp-col-' + col.key);
            $col.append(el('h3', '', col.title).append(el('span', 'mp-count', list.length || '')));
            if (!list.length) { $col.append(empty()); }
            list.forEach(function (o) { $col.append(card(o, { next: true })); });
            $wrap.append($col);
        });
        watch(orders.filter(function (o) { return o.status === 'pending'; }).map(function (o) { return 'o' + o.id; }));
    }

    function renderPayments(data) {
        var $c = $('#mp-to-check').empty();
        (data.to_check || []).forEach(function (o) { $c.append(card(o, { payment: true })); });
        if (!(data.to_check || []).length) { $c.append(empty()); }

        var $w = $('#mp-waiting').empty();
        (data.waiting || []).forEach(function (o) { $w.append(card(o, { payment: true })); });
        if (!(data.waiting || []).length) { $w.append(empty()); }
    }

    function historyRow(o) {
        var $tr = el('tr').attr('data-id', o.id);
        $tr.append(el('td').append(el('strong', '', '#' + o.number)));
        $tr.append(el('td', '', o.date));
        $tr.append(el('td').append(el('div', '', o.customer || '—')).append(el('small', '', o.phone)));
        $tr.append(el('td', '', typeText(o) + (o.branch ? ' · ' + o.branch : '')));
        $tr.append(el('td', '', money(o.total, o.currency)));
        $tr.append(el('td', '', o.payment_label + (o.payment_status ? ' · ' + paymentLabel(o.payment_status) : '')));
        $tr.append(el('td').append(el('span', 'mp-badge mp-st-' + o.status, statusLabel(o.status))));
        $tr.append(el('td').append(el('button', 'button button-small mp-open', T.view).attr('type', 'button')));
        return $tr;
    }

    // ------------------------------------------------------------------
    // loading
    // ------------------------------------------------------------------
    function load(append) {
        var data = $.extend({ view: view }, filters());
        if (view === 'history') { data.page = append ? historyPage + 1 : 1; }

        return api('mp_board_list', data).done(function (res) {
            if (!res || !res.success) { return; }
            var d = res.data;
            renderStats(d.stats);
            if (view === 'live') { renderLive(d.orders || []); }
            if (view === 'payments') { renderPayments(d); }
            if (view === 'history') {
                historyPage = d.page;
                var $b = $('#mp-history-body');
                if (!append) { $b.empty(); }
                (d.orders || []).forEach(function (o) { $b.append(historyRow(o)); });
                if (!append && !(d.orders || []).length) {
                    $b.append(el('tr').append(el('td', 'mp-empty', T.empty).attr('colspan', 8)));
                }
                $('#mp-more').text(T.loadMore).toggle(d.page < d.pages);
            }
            // receipts ring on every view
            if (d.stats) { checkReceipts(); }
        });
    }

    // a small extra call so a new receipt rings even while on the live view
    var lastToCheck = null;
    function checkReceipts() {
        var n = parseInt($('[data-stat="to_check"]').text(), 10) || 0;
        if (lastToCheck !== null && n > lastToCheck) { beep(); }
        lastToCheck = n;
    }

    function schedule() {
        clearInterval(timer);
        timer = setInterval(function () {
            if (document.hidden) { return; }
            if (view === 'history') { api('mp_board_list', $.extend({ view: 'live' }, filters())).done(function (r) { if (r && r.success) { renderStats(r.data.stats); checkReceipts(); } }); return; }
            load(false);
        }, (C.refresh || 30) * 1000);
    }

    function show(v) {
        if (['live', 'payments', 'history'].indexOf(v) === -1) { v = 'live'; }
        view = v;
        $('.mp-board-tabs .nav-tab').removeClass('nav-tab-active').filter('[data-view="' + v + '"]').addClass('nav-tab-active');
        $('.mp-view').hide().filter('[data-view="' + v + '"]').show();
        $('.mp-history-only').toggle(v === 'history');
        load(false);
    }

    // countdowns on waiting cards
    setInterval(function () {
        $('.mp-left[data-left]').each(function () {
            var s = parseInt($(this).attr('data-left'), 10) - 1;
            if (s < 0) { s = 0; }
            $(this).attr('data-left', s).text(clock(s) + ' ' + T.timeLeft);
        });
    }, 1000);

    // ------------------------------------------------------------------
    // drawer (order details)
    // ------------------------------------------------------------------
    function waLink(o, template, reason) {
        if (!o.wa_phone) { return ''; }
        var text = fill(template, { name: o.customer || '', number: o.number, url: o.status_url, reason: reason || '' });
        return 'https://wa.me/' + o.wa_phone + '?text=' + encodeURIComponent(text);
    }

    function section(title) {
        return el('div', 'mp-sec').append(el('h3', '', title));
    }

    function drawer(o) {
        openId = o.id;
        var $b = $('#mp-drawer-body').empty();

        var $top = el('div', 'mp-d-top');
        $top.append(el('h2', '', '#' + o.number));
        $top.append(el('span', 'mp-badge mp-st-' + o.status, statusLabel(o.status)));
        $top.append(el('div', 'mp-meta', o.date + ' · ' + typeText(o) + (o.branch ? ' · ' + o.branch : '') + (o.source ? ' · ' + o.source : '')));
        $b.append($top);

        // payment block first when it needs a decision
        var $p = section(T.payment);
        $p.append(el('div', '', o.payment_label + (o.payment_status ? ' · ' + paymentLabel(o.payment_status) : '') + (o.paid_via ? ' (' + (o.paid_via === 'card_pos' ? T.markPaidCard : T.markPaidCash) + ')' : '')));
        if (o.is_transfer) {
            if (o.reference) { $p.append(el('div', '', T.reference + ': ').append(el('strong', '', o.reference))); }
            if (o.sender) { $p.append(el('div', '', T.sender + ': ' + o.sender)); }
            if (o.duplicates && o.duplicates.length) { $p.append(el('div', 'mp-warn', T.duplicate + ' ' + o.duplicates.join(', '))); }
            if (o.reject_reason) { $p.append(el('div', 'mp-meta', '✘ ' + o.reject_reason)); }
            if (o.status === 'awaiting_payment' && o.seconds_left > 0) {
                $p.append(el('div', 'mp-meta mp-left', clock(o.seconds_left) + ' ' + T.timeLeft).attr('data-left', o.seconds_left));
            }
            if (o.proof_url) {
                if (o.proof_is_pdf) {
                    $p.append(el('a', 'button', T.openPdf).attr({ href: o.proof_url, target: '_blank', rel: 'noopener' }));
                } else {
                    $p.append(el('a', 'mp-proof').attr({ href: o.proof_url, target: '_blank', rel: 'noopener' })
                        .append($('<img>').attr({ src: o.proof_url, alt: T.receipt })));
                }
            } else if (o.status === 'awaiting_verification') {
                $p.append(el('div', 'mp-meta', T.noReceipt));
            }
            if (o.status === 'awaiting_verification' || o.status === 'awaiting_payment') {
                var $pa = el('div', 'mp-actions');
                $pa.append(el('button', 'button button-primary mp-pay-ok', T.confirmPay).attr('type', 'button'));
                if (o.status === 'awaiting_verification') {
                    $pa.append(el('button', 'button mp-pay-no', T.rejectPay).attr('type', 'button'));
                }
                $p.append($pa);
            }
        } else if (o.payment_status !== 'paid' && ['cancelled', 'expired'].indexOf(o.status) === -1) {
            var $mp = el('div', 'mp-actions');
            $mp.append(el('button', 'button mp-paid', T.markPaidCash).attr({ type: 'button', 'data-via': 'cash' }));
            $mp.append(el('button', 'button mp-paid', T.markPaidCard).attr({ type: 'button', 'data-via': 'card_pos' }));
            $p.append($mp);
        }
        $b.append($p);

        // customer
        var $cu = section(T.customer);
        $cu.append(el('div', '', o.customer || '—'));
        if (o.phone) { $cu.append(el('div').append(el('a', '', o.phone).attr('href', 'tel:' + o.phone))); }
        if (o.email) { $cu.append(el('div', '', o.email)); }
        if (o.type === 'delivery') {
            if (o.zone) { $cu.append(el('div', '', o.zone)); }
            if (o.address) { $cu.append(el('div', '', o.address)); }
            if (o.details) { $cu.append(el('div', '', o.details)); }
            var extra = [];
            if (o.distance) { extra.push(o.distance + ' km'); }
            if (o.eta) { extra.push('~' + o.eta + ' min'); }
            if (extra.length) { $cu.append(el('div', 'mp-meta', extra.join(' · '))); }
            if (o.map_url) { $cu.append(el('a', '', T.map).attr({ href: o.map_url, target: '_blank', rel: 'noopener' })); }
        }
        var $links = el('div', 'mp-actions');
        if (o.wa_phone) {
            $links.append(el('a', 'button', T.whatsapp).attr({ href: waLink(o, T.waGeneric), target: '_blank', rel: 'noopener' }));
        }
        $links.append(el('a', 'button', T.statusPage).attr({ href: o.status_url, target: '_blank', rel: 'noopener' }));
        $cu.append($links);
        $b.append($cu);

        // items
        var $it = section(T.items);
        var $ul = el('ul', 'mp-lines');
        (o.items || []).forEach(function (l) {
            $ul.append(el('li').append(el('span', '', l.quantity + '× ' + l.name + (l.size ? ' (' + l.size + ')' : ''))).append(el('span', '', money(l.line_total, o.currency))));
        });
        $it.append($ul);
        var line = function (label, v, cls) { return el('div', 'mp-sum ' + (cls || '')).append(el('span', '', label)).append(el('span', '', v)); };
        $it.append(line(T.subtotal, money(o.subtotal, o.currency)));
        if (o.tax > 0) { $it.append(line(T.tax, money(o.tax, o.currency))); }
        if (o.type === 'delivery') { $it.append(line(T.deliveryFee, money(o.delivery_fee, o.currency))); }
        $it.append(line(T.total, money(o.total, o.currency), 'mp-sum-total'));
        if (o.notes) { $it.append(el('div', 'mp-note', T.notes + ': ' + o.notes)); }
        $b.append($it);

        // status
        var $st = section(T.changeStatus);
        var $sel = el('select', 'mp-status-select');
        ['pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled'].forEach(function (s) {
            $sel.append(el('option', '', statusLabel(s)).attr('value', s).prop('selected', s === o.status));
        });
        if (['pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled'].indexOf(o.status) === -1) {
            $sel.prepend(el('option', '', statusLabel(o.status)).attr('value', '').prop('selected', true));
        }
        $st.append($sel);
        $st.append(el('button', 'button button-link-delete mp-delete', T['delete']).attr('type', 'button'));
        $b.append($st);

        // history
        if (o.history && o.history.length) {
            var $h = section(T.history);
            var $hl = el('ul', 'mp-hist');
            o.history.forEach(function (h) {
                $hl.append(el('li', '', h.date + ' · ' + (statusLabel(h.status) || paymentLabel(h.status)) + (h.by ? ' · ' + h.by : '') + (h.note ? ' — ' + h.note : '')));
            });
            $h.append($hl);
            $b.append($h);
        }

        $('#mp-drawer').addClass('is-open').attr('aria-hidden', 'false');
    }

    function openOrder(id) {
        api('mp_board_order', { order_id: id }).done(function (res) {
            if (res && res.success) { drawer(res.data); } else { fail(res); }
        }).fail(function () { fail(); });
    }

    function closeDrawer() {
        openId = 0;
        $('#mp-drawer').removeClass('is-open').attr('aria-hidden', 'true');
    }

    function afterChange(res) {
        if (!res || !res.success) { fail(res); return; }
        if (openId && res.data && res.data.id === openId) { drawer(res.data); }
        load(false);
    }

    // ------------------------------------------------------------------
    // events
    // ------------------------------------------------------------------
    $(function () {
        soundButton();

        $('.mp-board-tabs').on('click', '.nav-tab', function (e) {
            e.preventDefault();
            var v = $(this).data('view');
            if (history.replaceState) { history.replaceState(null, '', '#' + v); }
            show(v);
        });

        var t;
        $('#mp-f-search').on('input', function () { clearTimeout(t); t = setTimeout(function () { load(false); }, 400); });
        $('#mp-f-branch, #mp-f-type, #mp-f-from, #mp-f-to, #mp-f-status').on('change', function () { load(false); });
        $('#mp-refresh').on('click', function () { load(false); });
        $('#mp-more').on('click', function () { load(true); });

        $('#mp-sound').on('click', function () {
            soundOn = !soundOn;
            try { localStorage.setItem('mpBoardSound', soundOn ? '1' : '0'); } catch (e) {}
            soundButton();
            if (soundOn) { beep(); }
        });
        // browsers allow audio only after a click on the page
        $(document).one('click', function () {
            try { audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
        });

        $('#mp-board').on('click', '.mp-open', function () {
            openOrder($(this).closest('[data-id]').data('id'));
        });
        $('#mp-board').on('click', '.mp-card', function (e) {
            if ($(e.target).closest('button, a').length) { return; }
            openOrder($(this).data('id'));
        });

        $('#mp-board').on('click', '.mp-next', function () {
            var $c = $(this).closest('[data-id]');
            var flow = { pending: 'confirmed', confirmed: 'preparing', preparing: 'ready', ready: 'delivered' };
            var cur = ($c.attr('class').match(/mp-st-(\w+)/) || [])[1];
            if (!flow[cur]) { return; }
            $(this).prop('disabled', true);
            api('mp_board_set_status', { order_id: $c.data('id'), status: flow[cur] }).done(afterChange).fail(function () { fail(); });
        });

        $('#mp-board').on('click', '.mp-pay-ok', function () {
            var $card = $(this).closest('.mp-card');
            var id = $card.length ? $card.data('id') : openId;
            $(this).prop('disabled', true);
            api('mp_board_payment', { order_id: id, decision: 'confirm' }).done(function (res) {
                afterChange(res);
                if (res && res.success && res.data.wa_phone && openId) {
                    // offer to tell the customer
                    var link = waLink(res.data, T.waConfirm);
                    if (link) {
                        $('#mp-drawer-body .mp-sec').first().append(
                            el('a', 'button button-primary', T.whatsapp).attr({ href: link, target: '_blank', rel: 'noopener' })
                        );
                    }
                }
            }).fail(function () { fail(); });
        });

        $('#mp-board').on('click', '.mp-pay-no', function () {
            var reason = window.prompt(T.rejectReason, T.defaultReason);
            if (reason === null) { return; }
            api('mp_board_payment', { order_id: openId, decision: 'reject', reason: reason }).done(function (res) {
                afterChange(res);
                if (res && res.success && res.data.wa_phone) {
                    var link = waLink(res.data, T.waReject, reason);
                    $('#mp-drawer-body .mp-sec').first().append(
                        el('a', 'button button-primary', T.whatsapp).attr({ href: link, target: '_blank', rel: 'noopener' })
                    );
                }
            }).fail(function () { fail(); });
        });

        $('#mp-board').on('click', '.mp-paid', function () {
            api('mp_board_payment', { order_id: openId, decision: 'mark_paid', via: $(this).data('via') }).done(afterChange).fail(function () { fail(); });
        });

        $('#mp-board').on('change', '.mp-status-select', function () {
            var s = $(this).val();
            if (!s) { return; }
            if (s === 'cancelled' && !window.confirm(T.confirmCancel)) { return; }
            api('mp_board_set_status', { order_id: openId, status: s }).done(afterChange).fail(function () { fail(); });
        });

        $('#mp-board').on('click', '.mp-delete', function () {
            if (!window.confirm(T.confirmDelete)) { return; }
            api('mp_board_delete', { order_id: openId }).done(function (res) {
                if (!res || !res.success) { fail(res); return; }
                closeDrawer();
                load(false);
            });
        });

        $('#mp-drawer').on('click', '.mp-drawer-bg, .mp-drawer-close', closeDrawer);
        $(document).on('keydown', function (e) { if (e.key === 'Escape') { closeDrawer(); } });

        $('#mp-export').on('click', function () {
            var f = filters();
            $.post(C.ajax, {
                action: 'export_orders_data',
                nonce: C.exportNonce,
                filters: { status: f.status || 'all', search: f.search, date_from: f.from, date_to: f.to }
            }).done(function (res) {
                if (!res || !res.success) { fail(res); return; }
                var blob = new Blob(['﻿' + res.data.csv_data], { type: 'text/csv;charset=utf-8' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'orders-' + new Date().toISOString().slice(0, 10) + '.csv';
                document.body.appendChild(a); a.click(); a.remove();
            });
        });

        show((location.hash || '').replace('#', '') || 'live');
        schedule();
    });
}(jQuery));
