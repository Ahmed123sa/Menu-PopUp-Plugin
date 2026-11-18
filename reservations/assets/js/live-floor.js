(function ($) {
  'use strict';

  var cfg = window.mpLiveFloor;
  if (!cfg || !document.getElementById('mpl-root')) { return; }

  var state = { data: cfg.snapshot, filter: 'all', zone: 'all', open: 0, timer: null, busy: false };
  var L = cfg.i18n;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return esc(state.data.currency) + (Math.round(n * 100) / 100).toFixed(2); }
  function byId(id) {
    var t = state.data.tables;
    for (var i = 0; i < t.length; i++) { if (t[i].id === id) { return t[i]; } }
    return null;
  }

  var STATUS_LABEL = { free: L.available, reserved: L.reserved, occupied: L.occupied };
  var FLAG_LABEL = {
    waiter: '🛎 ' + L.waiter, bill: '🧾 ' + L.bill, late: '⏰ ', overstay: '⌛ ' + L.overstay, unconfirmed: '❓ ' + L.unconfirmed
  };

  function summary() {
    var s = state.data.summary;
    $('#mpl-sum-free').text(s.free);
    $('#mpl-sum-reserved').text(s.reserved);
    $('#mpl-sum-occupied').text(s.occupied);
    $('#mpl-sum-attention').text(s.attention);
    $('#mpl-sum-bookings').text(s.bookings);
    $('#mpl-sum-guests').text(s.guests);
    $('#mpl-chip-all').text(state.data.tables.length);
    $('#mpl-chip-free').text(s.free);
    $('#mpl-chip-reserved').text(s.reserved);
    $('#mpl-chip-occupied').text(s.occupied);
    $('#mpl-chip-attention').text(s.attention);
  }

  function cardLine(t) {
    var r;
    if (t.current) {
      r = t.current;
      return '<div class="mpl-line"><strong>' + esc(r.customer) + '</strong> · ' + r.party + ' ' + esc(L.guests) +
        '<span class="mpl-sub">' + esc(L.since) + ' ' + esc(r.time) + ' · ' + esc(L.until) + ' ' + esc(r.end) + '</span></div>';
    }
    if (t.orders && t.orders.count) {
      return '<div class="mpl-line"><strong>' + t.orders.count + ' ' + esc(L.openOrders) + '</strong>' +
        '<span class="mpl-sub">' + esc(L.due) + ' ' + money(t.orders.due) + '</span></div>';
    }
    return '';
  }
  function cardNext(t) {
    var r = t.next;
    if (!r) { return '<div class="mpl-next mpl-muted">' + esc(t.status === 'free' ? L.freeAllDay : '') + '</div>'; }
    var late = r.late > 0 ? ' <em class="mpl-late">' + esc(L.late) + ' ' + r.late + ' ' + esc(L.min) + '</em>' : '';
    return '<div class="mpl-next">' + esc(L.next) + ' <strong>' + esc(r.time) + '</strong> · ' + esc(r.customer) +
      ' (' + r.party + ')' + late + '</div>';
  }

  function render() {
    var d = state.data, html = '', zones = {}, order = [];
    d.tables.forEach(function (t) {
      if (state.zone !== 'all' && t.zone !== state.zone) { return; }
      if (state.filter === 'attention') {
        if (!t.flags.length) { return; }
      } else if (state.filter !== 'all' && t.status !== state.filter) { return; }
      if (!zones[t.zone]) { zones[t.zone] = []; order.push(t.zone); }
      zones[t.zone].push(t);
    });

    order.forEach(function (z) {
      html += '<section class="mpl-zone"><h3>' + esc(z.charAt(0).toUpperCase() + z.slice(1)) +
        ' <span>' + zones[z].length + '</span></h3><div class="mpl-grid">';
      zones[z].forEach(function (t) {
        var flags = '';
        t.flags.forEach(function (f) {
          var txt = FLAG_LABEL[f] || f;
          if (f === 'late') { txt += t.next ? t.next.late + ' ' + L.min : ''; }
          flags += '<span class="mpl-flag mpl-flag-' + esc(f) + '">' + esc(txt) + '</span>';
        });
        html += '<button type="button" class="mpl-card mpl-' + esc(t.status) + (state.open === t.id ? ' is-open' : '') +
          '" data-id="' + t.id + '">' +
          '<div class="mpl-card-top"><span class="mpl-name">' + esc(t.name) + '</span>' +
          '<span class="mpl-pill">' + esc(STATUS_LABEL[t.status] || t.status) + '</span></div>' +
          '<div class="mpl-seats">👥 ' + t.capacity + ' ' + esc(L.seats) + (t.branch ? ' · ' + esc(t.branch) : '') + '</div>' +
          cardLine(t) + cardNext(t) +
          (flags ? '<div class="mpl-flags">' + flags + '</div>' : '') +
          '</button>';
      });
      html += '</div></section>';
    });

    if (!html) { html = '<p class="mpl-empty">' + esc(L.none) + '</p>'; }
    $('#mpl-board').html(html);
    summary();
    if (state.open) { panel(byId(state.open)); }
  }

  function actionsFor(r) {
    var a = [];
    if (r.status === 'pending') { a.push(['confirmed', L.confirm, 'primary']); }
    if (r.status === 'pending' || r.status === 'confirmed') {
      a.push(['seated', L.seat, 'primary']);
      a.push(['no_show', L.noShow, '']);
      a.push(['cancelled', L.cancel, 'danger']);
    }
    if (r.status === 'seated') { a.push(['completed', L.complete, 'primary']); }
    return a;
  }

  function panel(t) {
    if (!t) { closePanel(); return; }
    var h = '<div class="mpl-panel-head"><div><h2>' + esc(t.name) + '</h2><span class="mpl-pill mpl-' + esc(t.status) + '">' +
      esc(STATUS_LABEL[t.status]) + '</span> <span class="mpl-muted">' + t.capacity + ' ' + esc(L.seats) + '</span></div>' +
      '<button type="button" class="mpl-close" aria-label="' + esc(L.close) + '">×</button></div>';

    if (t.orders && t.orders.count) {
      h += '<div class="mpl-box"><strong>' + t.orders.count + ' ' + esc(L.openOrders) + '</strong> · ' + esc(L.due) + ' ' +
        money(t.orders.due) + (cfg.tablesUrl ? ' · <a href="' + esc(cfg.tablesUrl) + '">' + esc(L.openTables) + '</a>' : '') + '</div>';
    }
    if (t.flags.indexOf('waiter') > -1 || t.flags.indexOf('bill') > -1) {
      h += '<div class="mpl-box mpl-box-warn">' + (t.flags.indexOf('waiter') > -1 ? FLAG_LABEL.waiter + ' ' : '') +
        (t.flags.indexOf('bill') > -1 ? FLAG_LABEL.bill : '') + '</div>';
    }

    h += '<h4>' + esc(L.today) + ' (' + t.today.length + ')</h4>';
    if (!t.today.length) { h += '<p class="mpl-muted">' + esc(L.noRes) + '</p>'; }
    t.today.forEach(function (r) {
      h += '<div class="mpl-res mpl-res-' + esc(r.status) + '"><div class="mpl-res-top"><strong>' + esc(r.time) + '–' + esc(r.end) +
        '</strong><span class="mpl-badge">' + esc(L.st[r.status] || r.status) + '</span></div>' +
        '<div>' + esc(r.customer) + ' · ' + r.party + ' ' + esc(L.guests) +
        (r.phone ? ' · <a href="tel:' + esc(r.phone) + '">' + esc(r.phone) + '</a>' : '') + '</div>';
      if (r.notes) { h += '<div class="mpl-muted">' + esc(r.notes) + '</div>'; }
      var acts = actionsFor(r);
      if (acts.length) {
        h += '<div class="mpl-actions">';
        acts.forEach(function (a) {
          h += '<button type="button" class="button mpl-act ' + (a[2] ? 'mpl-' + a[2] : '') + '" data-res="' + r.id +
            '" data-status="' + a[0] + '">' + esc(a[1]) + '</button>';
        });
        h += '</div>';
      }
      h += '</div>';
    });

    $('#mpl-panel').html(h).addClass('is-open');
    $('#mpl-shade').addClass('is-open');
  }

  function closePanel() {
    state.open = 0;
    $('#mpl-panel').removeClass('is-open').empty();
    $('#mpl-shade').removeClass('is-open');
    $('.mpl-card.is-open').removeClass('is-open');
  }

  function stamp() {
    var d = new Date();
    $('#mpl-updated').text(L.updated + ' ' + d.toTimeString().substring(0, 8));
  }

  function refresh(manual) {
    if (state.busy) { return; }
    state.busy = true;
    if (manual) { $('#mpl-refresh').prop('disabled', true); }
    $.post(cfg.ajaxUrl, { action: 'menu_popup_live_snapshot', nonce: cfg.nonce })
      .done(function (res) {
        if (res && res.success) {
          state.data = res.data;
          render();
          stamp();
          $('#mpl-live').removeClass('is-off');
        } else { $('#mpl-live').addClass('is-off'); }
      })
      .fail(function () { $('#mpl-live').addClass('is-off'); })
      .always(function () { state.busy = false; $('#mpl-refresh').prop('disabled', false); });
  }

  function schedule() {
    clearInterval(state.timer);
    state.timer = setInterval(function () { if (!document.hidden) { refresh(false); } }, cfg.interval * 1000);
  }

  $(document)
    .on('click', '.mpl-card', function () {
      state.open = parseInt($(this).data('id'), 10);
      render();
    })
    .on('click', '.mpl-close, #mpl-shade', closePanel)
    .on('keydown', function (e) { if (e.key === 'Escape') { closePanel(); } })
    .on('click', '#mpl-refresh', function () { refresh(true); })
    .on('click', '.mpl-chip', function () {
      state.filter = $(this).data('filter');
      $('.mpl-chip').removeClass('is-on');
      $(this).addClass('is-on');
      render();
    })
    .on('change', '#mpl-zone', function () { state.zone = $(this).val(); render(); })
    .on('click', '.mpl-act', function () {
      var $b = $(this), status = $b.data('status');
      if ((status === 'cancelled' || status === 'no_show') && !window.confirm(L.sure)) { return; }
      $b.prop('disabled', true);
      $.post(cfg.ajaxUrl, {
        action: 'menu_popup_update_reservation_status',
        nonce: cfg.nonce,
        reservation_id: $b.data('res'),
        status: status
      }).done(function (res) {
        if (res && res.success) { state.busy = false; refresh(false); }
        else { window.alert((res && res.data) || L.failed); $b.prop('disabled', false); }
      }).fail(function () { window.alert(L.failed); $b.prop('disabled', false); });
    });

  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(false); } });

  render();
  stamp();
  schedule();
})(jQuery);
