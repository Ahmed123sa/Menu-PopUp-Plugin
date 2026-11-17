/**
 * Delivery by map – customer side
 *
 * When a customer picks "Delivery" in the order confirmation window, the
 * delivery zone list is replaced by a Google map. The customer pins the spot
 * (search, "use my location", or drag the pin) and the server answers with the
 * branch that serves it, the fee, the minimum order and the estimated time.
 *
 * The server repeats the same check when the order is saved, so nothing the
 * browser shows here can change what the order costs.
 *
 * Exposes window.mpDelivery for menu-popup-frontend.js.
 * Depends on: jQuery, menuPopupAjax (wp_localize_script)
 */

/* global jQuery, menuPopupAjax, google */
(function ($) {
    'use strict';

    const cfg = {
        ajaxUrl:     menuPopupAjax.ajaxurl,
        nonce:       menuPopupAjax.geoZoneNonce || '',
        branchId:    parseInt(menuPopupAjax.activeBranchId || 0, 10),
        currency:    menuPopupAjax.currencySymbol || '',
        apiKey:      menuPopupAjax.mapsApiKey || '',
        center:      menuPopupAjax.mapCenter || { lat: 30.0444, lng: 31.2357 },
        taxRate:     parseFloat(menuPopupAjax.taxRate || 0),
        enabled:     menuPopupAjax.geoCheckEnabled === '1' &&
                     menuPopupAjax.deliveryEnabled === '1' &&
                     menuPopupAjax.zonesConfigured === '1'
    };

    const STORE_KEY = 'mpDeliveryPin';

    const state = {
        map: null,
        marker: null,
        geocoder: null,
        lat: null,
        lng: null,
        quote: null,
        loading: false,
        failed: false,
        mapsPromise: null,
        timer: null,
        reqId: 0,
        addressAuto: true
    };

    // ------------------------------------------------------------------
    // Public API for the order flow
    // ------------------------------------------------------------------
    window.mpDelivery = {
        isMapMode: function () { return cfg.enabled; },

        quote: function () { return state.quote; },

        hasPin: function () { return state.lat !== null; },

        /** Fields the order request needs in map mode */
        extras: function () {
            if (!cfg.enabled || state.lat === null) { return {}; }
            return {
                delivery_lat: state.lat,
                delivery_lng: state.lng,
                address_details: $('#mp-address-details').val() || ''
            };
        },

        /** Ask the server again, e.g. when the cart value changed */
        refresh: function (orderValue) { return requestQuote(orderValue); }
    };

    if (!cfg.enabled) { return; }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    function money(v) { return cfg.currency + Number(v || 0).toFixed(2); }

    function cartValue() {
        const mp = window.menuPopup;
        if (!mp || !mp.cart) { return 0; }
        const sub = Object.values(mp.cart).reduce(function (s, i) { return s + i.price * i.quantity; }, 0);
        return sub + sub * (cfg.taxRate / 100);
    }

    function selectedType() {
        return $('input[name="confirmation_order_type"]:checked').val() || 'pickup';
    }

    function deliveryRadio() {
        return $('input[name="confirmation_order_type"][value="delivery"]');
    }

    function switchToPickup() {
        $('input[name="confirmation_order_type"][value="pickup"]').prop('checked', true).trigger('change');
    }

    function notify() {
        $(document).trigger('mp:delivery-quote');
    }

    // ------------------------------------------------------------------
    // Google Maps loader
    // ------------------------------------------------------------------
    function loadMaps() {
        if (state.mapsPromise) { return state.mapsPromise; }

        state.mapsPromise = new Promise(function (resolve, reject) {
            if (window.google && google.maps && google.maps.Map) {
                resolve();
                return;
            }

            const timer = setTimeout(function () { reject(new Error('timeout')); }, 12000);

            window.__mpMapsReady = function () {
                clearTimeout(timer);
                resolve();
            };
            window.gm_authFailure = function () {
                clearTimeout(timer);
                reject(new Error('auth'));
            };

            const s = document.createElement('script');
            s.async = true;
            s.defer = true;
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(cfg.apiKey) +
                    '&libraries=places&callback=__mpMapsReady';
            s.onerror = function () { clearTimeout(timer); reject(new Error('network')); };
            document.head.appendChild(s);
        });

        return state.mapsPromise;
    }

    // ------------------------------------------------------------------
    // UI
    // ------------------------------------------------------------------
    function field() { return $('#order-confirmation-modal .delivery-zones-field'); }

    function buildUI() {
        const $field = field();
        if (!$field.length || $field.data('mp-map-ui')) { return $field.length > 0; }

        // The manual zone list is replaced by the map
        $field.find('select, p.description').hide();
        $field.find('select').prop('required', false);
        $field.find('label').first().html('Delivery Location <span class="required">*</span>');

        const $box = $(
            '<div id="mp-delivery-box">' +
              '<div class="mp-dm-tools">' +
                '<input type="text" id="mp-map-search" dir="auto" placeholder="Search your address…" autocomplete="off">' +
                '<button type="button" id="mp-map-search-btn" aria-label="Search">🔍</button>' +
                '<button type="button" id="mp-use-my-location">📍 Use my location</button>' +
              '</div>' +
              '<div id="mp-delivery-map" aria-label="Delivery map"></div>' +
              '<div id="mp-delivery-summary" role="status"></div>' +
              '<input type="text" id="mp-address-details" dir="auto" placeholder="Floor, apartment, landmark (optional)">' +
            '</div>'
        );
        $field.append($box);
        $field.data('mp-map-ui', true);

        injectStyles();
        bindUI();
        return true;
    }

    function injectStyles() {
        if ($('#mp-delivery-style').length) { return; }
        $('head').append(
            '<style id="mp-delivery-style">' +
            '#mp-delivery-box{margin-top:6px}' +
            '#mp-delivery-box .mp-dm-tools{display:flex;gap:6px;margin-bottom:8px}' +
            '#mp-map-search{flex:1;min-width:0;padding:9px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px}' +
            '#mp-map-search-btn,#mp-use-my-location{border:1px solid #d1d5db;background:#fff;border-radius:8px;padding:0 10px;cursor:pointer;font-size:13px;white-space:nowrap}' +
            '#mp-delivery-map{width:100%;height:260px;border-radius:10px;border:1px solid #d1d5db;background:#f3f4f6}' +
            '#mp-delivery-summary{margin-top:8px;padding:9px 12px;border-radius:8px;font-size:14px;line-height:1.5;display:none}' +
            '#mp-delivery-summary.ok{display:block;background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7}' +
            '#mp-delivery-summary.no{display:block;background:#fff1f2;color:#9f1239;border:1px solid #fda4af}' +
            '#mp-delivery-summary.wait{display:block;background:#f0f4ff;color:#3b55d4;border:1px solid #c7d2f8}' +
            '#mp-delivery-summary .mp-ds-note{display:block;margin-top:4px;font-size:13px;color:#92400e}' +
            '#mp-delivery-summary .mp-ds-branch{display:block;margin-top:4px;font-size:13px}' +
            '#mp-delivery-summary button{margin-top:6px;padding:6px 12px;border-radius:8px;border:1px solid #9f1239;background:#fff;color:#9f1239;cursor:pointer}' +
            '#mp-address-details{width:100%;margin-top:8px;padding:9px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box}' +
            '</style>'
        );
    }

    function bindUI() {
        $(document).on('click', '#mp-use-my-location', useMyLocation);

        $(document).on('click', '#mp-map-search-btn', searchAddress);
        $(document).on('keydown', '#mp-map-search', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchAddress();
            }
        });

        // Typing in the address box means the customer wrote it themselves
        $(document).on('input', '#confirmation_customer_address', function () {
            state.addressAuto = false;
        });

        $(document).on('click', '#mp-pickup-instead', function () {
            switchToPickup();
        });
    }

    // ------------------------------------------------------------------
    // Map
    // ------------------------------------------------------------------
    function showSummary(kind, html) {
        const $s = $('#mp-delivery-summary');
        $s.attr('class', kind).html(html);
    }

    function startDelivery() {
        if (!cfg.apiKey) {
            failMaps('Delivery is not available right now. Please choose pickup.');
            return;
        }
        if (state.failed) {
            failMaps('The map could not be loaded. Please choose pickup or order by WhatsApp.');
            return;
        }
        if (!buildUI()) { return; }

        showSummary('wait', 'Loading the map…');

        loadMaps().then(function () {
            // The window is still sliding open, give it a moment to get its size
            setTimeout(initMap, 350);
        }).catch(function (err) {
            state.failed = true;
            failMaps(err && err.message === 'auth'
                ? 'The map is not available right now. Please choose pickup.'
                : 'The map could not be loaded. Check your connection, or choose pickup.');
        });
    }

    function failMaps(message) {
        if (field().length && field().data('mp-map-ui')) {
            showSummary('no', message + ' <button type="button" id="mp-pickup-instead">Pickup instead</button>');
        } else {
            window.alert(message);
        }
        deliveryRadio().prop('disabled', true);
        switchToPickup();
        // Let the customer try again later in the same visit
        setTimeout(function () { deliveryRadio().prop('disabled', false); state.mapsPromise = null; }, 60000);
    }

    function initMap() {
        const el = document.getElementById('mp-delivery-map');
        if (!el) { return; }

        if (state.map) {
            google.maps.event.trigger(state.map, 'resize');
            if (state.lat !== null) { state.map.setCenter({ lat: state.lat, lng: state.lng }); }
            else { showSummary('wait', 'Search your address or tap the map to place the pin.'); }
            return;
        }

        const saved = readSaved();
        const start = saved || cfg.center;

        state.map = new google.maps.Map(el, {
            center: { lat: parseFloat(start.lat), lng: parseFloat(start.lng) },
            zoom: saved ? 16 : 12,
            streetViewControl: false,
            mapTypeControl: false,
            fullscreenControl: false,
            gestureHandling: 'greedy'
        });
        state.geocoder = new google.maps.Geocoder();

        state.marker = new google.maps.Marker({
            map: state.map,
            draggable: true,
            visible: false
        });

        state.map.addListener('click', function (e) { setPin(e.latLng.lat(), e.latLng.lng(), true); });
        state.marker.addListener('dragend', function () {
            const p = state.marker.getPosition();
            setPin(p.lat(), p.lng(), true);
        });

        // Suggestions dropdown when the Places library allows it for this key
        try {
            if (google.maps.places && google.maps.places.Autocomplete) {
                const ac = new google.maps.places.Autocomplete(document.getElementById('mp-map-search'), {
                    componentRestrictions: { country: 'eg' },
                    fields: ['geometry', 'formatted_address']
                });
                ac.addListener('place_changed', function () {
                    const place = ac.getPlace();
                    if (place && place.geometry && place.geometry.location) {
                        state.map.setZoom(17);
                        setPin(place.geometry.location.lat(), place.geometry.location.lng(), false, place.formatted_address);
                    }
                });
            }
        } catch (e) { /* search by Enter still works */ }

        if (saved) {
            setPin(parseFloat(saved.lat), parseFloat(saved.lng), true);
        } else {
            showSummary('wait', 'Search your address or tap the map to place the pin.');
        }
    }

    function readSaved() {
        try {
            const raw = JSON.parse(window.localStorage.getItem(STORE_KEY) || 'null');
            if (raw && isFinite(raw.lat) && isFinite(raw.lng)) { return raw; }
        } catch (e) { /* ignore */ }
        return null;
    }

    function saveLast() {
        try {
            window.localStorage.setItem(STORE_KEY, JSON.stringify({ lat: state.lat, lng: state.lng }));
        } catch (e) { /* private mode */ }
    }

    /**
     * Put the pin somewhere and ask the server what delivering there means.
     *
     * @param {number}  lat
     * @param {number}  lng
     * @param {boolean} reverse   Look up the street address for the pin
     * @param {string=} address   Address text when it is already known
     */
    function setPin(lat, lng, reverse, address) {
        state.lat = lat;
        state.lng = lng;
        state.quote = null;

        state.marker.setPosition({ lat: lat, lng: lng });
        state.marker.setVisible(true);
        if (!state.map.getBounds() || !state.map.getBounds().contains({ lat: lat, lng: lng })) {
            state.map.panTo({ lat: lat, lng: lng });
        }

        showSummary('wait', 'Checking delivery to this location…');
        notify();

        clearTimeout(state.timer);
        state.timer = setTimeout(function () {
            requestQuote();
            if (reverse) { reverseGeocode(lat, lng); }
        }, 350);

        if (address) { fillAddress(address); }
    }

    function fillAddress(text) {
        const $a = $('#confirmation_customer_address');
        if ($a.length && (state.addressAuto || !$a.val().trim())) {
            $a.val(text);
            state.addressAuto = true;
        }
    }

    function reverseGeocode(lat, lng) {
        if (!state.geocoder) { return; }
        state.geocoder.geocode({ location: { lat: lat, lng: lng } }, function (results, status) {
            if (status === 'OK' && results && results[0]) {
                fillAddress(results[0].formatted_address);
            }
        });
    }

    function searchAddress() {
        const q = $.trim($('#mp-map-search').val());
        if (!q || !state.geocoder) { return; }

        showSummary('wait', 'Searching…');

        const done = function (results, status, retry) {
            if (status === 'OK' && results && results[0]) {
                const loc = results[0].geometry.location;
                state.map.setZoom(17);
                setPin(loc.lat(), loc.lng(), false, results[0].formatted_address);
                return;
            }
            if (status === 'ZERO_RESULTS' && retry) {
                // second try without the country filter
                state.geocoder.geocode({ address: q }, function (r, s) { done(r, s, false); });
                return;
            }
            if (window.console && console.warn) { console.warn('[menu-popup] geocoder status:', status); }
            if (status === 'REQUEST_DENIED' || status === 'OVER_QUERY_LIMIT') {
                showSummary('no', 'Address search is temporarily unavailable. Please tap the map to place the pin.');
            } else if (status === 'ZERO_RESULTS') {
                showSummary('no', 'We could not find that address. Try another spelling or tap the map.');
            } else {
                showSummary('no', 'Search failed (' + status + '). Please tap the map to place the pin.');
            }
        };

        state.geocoder.geocode({
            address: q,
            region: 'EG',
            componentRestrictions: { country: 'EG' }
        }, function (results, status) { done(results, status, true); });
    }

    function useMyLocation() {
        if (!state.map) { return; }
        if (!navigator.geolocation) {
            showSummary('no', 'Your browser cannot share your location. Search your address or tap the map.');
            return;
        }

        showSummary('wait', 'Finding your location…');
        navigator.geolocation.getCurrentPosition(function (pos) {
            state.map.setZoom(17);
            setPin(pos.coords.latitude, pos.coords.longitude, true);
        }, function (err) {
            showSummary('no', err && err.code === 1
                ? 'Location access was denied. Search your address or tap the map instead.'
                : 'We could not get your location. Search your address or tap the map.');
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
    }

    // ------------------------------------------------------------------
    // Server quote
    // ------------------------------------------------------------------
    function requestQuote(orderValue) {
        if (state.lat === null) { return Promise.resolve(null); }

        const id = ++state.reqId;
        const value = typeof orderValue === 'number' ? orderValue : cartValue();

        return new Promise(function (resolve) {
            $.post(cfg.ajaxUrl, {
                action: 'mp_resolve_delivery',
                nonce: cfg.nonce,
                lat: state.lat,
                lng: state.lng,
                order_value: value,
                branch_id: cfg.branchId
            }).done(function (res) {
                if (id !== state.reqId) { resolve(null); return; }   // a newer pin replaced this one

                if (!res || !res.success) {
                    state.quote = null;
                    showSummary('no', (res && res.data && res.data.message) || 'Could not check delivery. Please try again.');
                } else {
                    state.quote = res.data;
                    renderQuote(res.data, value);
                    if (res.data.ok) { saveLast(); }
                }
                notify();
                resolve(state.quote);
            }).fail(function () {
                if (id !== state.reqId) { resolve(null); return; }
                state.quote = null;
                showSummary('no', 'Could not check delivery. Please check your connection and tap the map again.');
                notify();
                resolve(null);
            });
        });
    }

    function renderQuote(q, value) {
        if (!q.ok) {
            let html = $('<div>').text(q.message).html();
            if (q.nearest_branches && q.nearest_branches.length) {
                html += '<span class="mp-ds-branch">Nearest branch: ' +
                    $('<div>').text(q.nearest_branches[0].name + ' (' + q.nearest_branches[0].distance_km + ' km)').html() + '</span>';
            }
            html += '<br><button type="button" id="mp-pickup-instead">Pickup from the nearest branch</button>';
            showSummary('no', html);
            return;
        }

        const esc = function (t) { return $('<div>').text(t).html(); };
        const fee = q.fee > 0 ? 'Delivery fee ' + money(q.fee) : (q.free_delivery_applied ? 'Free delivery' : 'No delivery fee');
        let html = '✔ Delivering' + (q.branch_name ? ' from <strong>' + esc(q.branch_name) + '</strong>' : '') +
                   '<br>' + fee + (q.eta ? ' · about ' + q.eta + ' min' : '');

        if (q.min_order > 0) {
            html += ' · Minimum order ' + money(q.min_order);
            if (value < q.min_order) {
                html += '<span class="mp-ds-note">Your order is ' + money(q.min_order - value) + ' short of the minimum for this area.</span>';
            }
        }
        if (!q.free_delivery_applied && q.free_delivery_over > 0 && value < q.free_delivery_over) {
            html += '<span class="mp-ds-note" style="color:#065f46">Add ' + money(q.free_delivery_over - value) + ' more for free delivery.</span>';
        }
        if (cfg.branchId > 0 && q.branch_id > 0 && q.branch_id !== cfg.branchId) {
            html += '<span class="mp-ds-note">This address is served by a different branch than the menu you opened. ' +
                    'Your cart will be checked against that branch.</span>';
        }

        showSummary('ok', html);
    }

    // ------------------------------------------------------------------
    // Wire into the confirmation window
    // ------------------------------------------------------------------
    $(function () {
        $(document).on('change', 'input[name="confirmation_order_type"]', function () {
            if ($(this).val() === 'delivery') {
                startDelivery();
            }
        });
    });

}(jQuery));
