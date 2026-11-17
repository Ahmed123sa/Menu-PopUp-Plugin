/**
 * Delivery Zone Geo-Checker — Cart Integration
 *
 * Hooks into the EXISTING cart confirmation flow.
 * When a customer selects "Delivery" in the order confirmation modal:
 *
 *   1. Asks for GPS location via browser Geolocation API
 *   2. Checks lat/lng against each zone's geo data (client-side first,
 *      then AJAX to the PHP checker as authoritative source)
 *   3. Depending on the result:
 *      – IN ZONE  : auto-selects the matching zone in the dropdown,
 *                   shows a green "✔ Delivery available" badge
 *      – OUT ZONE : shows a red warning, disables the Delivery radio,
 *                   switches back to Pickup automatically
 *
 * No zone data in DB (zones have no geo) → silently does nothing,
 * leaving the existing manual dropdown fully in control.
 *
 * Depends on: jQuery, menuPopupAjax (wp_localize_script)
 *
 * @package MenuPopup
 */
/* global jQuery, menuPopupAjax */
(function ($) {
    'use strict';

    // ──────────────────────────────────────────────────────────────────────
    // Config
    // ──────────────────────────────────────────────────────────────────────
    const cfg = {
        geoEnabled    : menuPopupAjax.geoCheckEnabled === '1',
        nonce         : menuPopupAjax.geoZoneNonce || '',
        ajaxUrl       : menuPopupAjax.ajaxurl,
        branchId      : parseInt(menuPopupAjax.activeBranchId || 0, 10),
        zones         : menuPopupAjax.deliveryZones || [],          // legacy zones (with optional .geo)
        currency      : menuPopupAjax.currencySymbol || '',
    };

    // ──────────────────────────────────────────────────────────────────────
    // Detect whether ANY zone in the legacy list has geo data attached
    // ──────────────────────────────────────────────────────────────────────
    function zonesHaveGeoData() {
        return cfg.zones.some(function (z) { return z.geo && z.geo.zone_type; });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Client-side geo check (fast, runs before AJAX)
    // Returns the first matched zone object or null
    // ──────────────────────────────────────────────────────────────────────
    function clientSideCheck(lat, lng) {
        for (let i = 0; i < cfg.zones.length; i++) {
            const z = cfg.zones[i];
            if (!z.geo || !z.geo.zone_type) continue;

            if (z.geo.zone_type === 'radius') {
                const dist = haversine(lat, lng,
                    parseFloat(z.geo.center_lat),
                    parseFloat(z.geo.center_lng));
                if (dist <= parseFloat(z.geo.radius_km)) return z;

            } else if (z.geo.zone_type === 'polygon') {
                if (pointInPolygon(lat, lng, z.geo.polygon_coords || [])) return z;
            }
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Haversine distance (km)
    // ──────────────────────────────────────────────────────────────────────
    function haversine(lat1, lng1, lat2, lng2) {
        const R    = 6371;
        const dLat = toRad(lat2 - lat1);
        const dLng = toRad(lng2 - lng1);
        const a    = Math.sin(dLat / 2) ** 2
                   + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2))
                   * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }
    function toRad(deg) { return deg * Math.PI / 180; }

    // ──────────────────────────────────────────────────────────────────────
    // Ray-casting point-in-polygon
    // vertices: [{lat,lng}, …]
    // ──────────────────────────────────────────────────────────────────────
    function pointInPolygon(lat, lng, vertices) {
        if (!Array.isArray(vertices) || vertices.length < 3) return false;
        let inside = false;
        let j = vertices.length - 1;
        for (let i = 0; i < vertices.length; i++) {
            const xi = parseFloat(vertices[i].lng),  yi = parseFloat(vertices[i].lat);
            const xj = parseFloat(vertices[j].lng),  yj = parseFloat(vertices[j].lat);
            const intersect = ((yi > lat) !== (yj > lat))
                && (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
            j = i;
        }
        return inside;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Server-side geo check via AJAX (authoritative)
    // ──────────────────────────────────────────────────────────────────────
    function serverSideCheck(lat, lng) {
        return new Promise(function (resolve, reject) {
            $.post(cfg.ajaxUrl, {
                action    : 'mp_check_delivery_zone',
                nonce     : cfg.nonce,
                branch_id : cfg.branchId,
                lat       : lat,
                lng       : lng,
            })
            .done(function (res) {
                if (res.success) resolve(res.data);
                else reject(res.data || {});
            })
            .fail(function (jqXHR, textStatus, errorThrown) { 
                reject({ message: 'Network error', status: textStatus, error: errorThrown, response: jqXHR.responseText }); 
            });
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // UI helpers — work inside #order-confirmation-modal
    // ──────────────────────────────────────────────────────────────────────

    /** Ensure the geo-status banner exists and return it */
    function getOrCreateBanner() {
        let $b = $('#mp-geo-zone-banner');
        if (!$b.length) {
            $b = $('<div id="mp-geo-zone-banner" style="' +
                'display:none;padding:10px 14px;border-radius:6px;margin:10px 0;' +
                'font-size:14px;font-weight:600;text-align:center;transition:all .3s;">' +
                '</div>');
            // Insert just before the zone dropdown wrapper
            const $zoneField = $('#order-confirmation-modal .delivery-zones-field');
            if ($zoneField.length) {
                $b.insertBefore($zoneField);
            } else {
                $('#order-confirmation-modal .order-type-selection').after($b);
            }
        }
        return $b;
    }

    function showBanner(type, msg) {
        const $b = getOrCreateBanner();
        const styles = {
            locating  : 'background:#f0f4ff;color:#3b55d4;border:1px solid #c7d2f8;',
            'in-zone' : 'background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;',
            'out-zone': 'background:#fff1f2;color:#9f1239;border:1px solid #fda4af;',
            error     : 'background:#fefce8;color:#78350f;border:1px solid #fde68a;',
        };
        $b.attr('style',
            'display:block;padding:10px 14px;border-radius:6px;margin:10px 0;' +
            'font-size:14px;font-weight:600;text-align:center;transition:all .3s;' +
            (styles[type] || styles.error)
        ).text(msg);
    }

    function hideBanner() {
        $('#mp-geo-zone-banner').hide();
    }

    /** Auto-select a zone in the existing dropdown by its id */
    function selectZoneInDropdown(zoneId) {
        const $sel = $('#confirmation_delivery_zone');
        if ($sel.length && zoneId) {
            $sel.val(String(zoneId)).trigger('change');
        }
    }

    /** Lock/unlock the Delivery radio button */
    function setDeliveryRadioLocked(locked) {
        const $radio = $('input[name="confirmation_order_type"][value="delivery"]');
        $radio.prop('disabled', locked);
        if (locked) {
            // Snap back to pickup
            $('input[name="confirmation_order_type"][value="pickup"]')
                .prop('checked', true).trigger('change');
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Core flow — triggered when user selects "Delivery"
    // ──────────────────────────────────────────────────────────────────────
    function runGeoCheck() {
        // Skip if no zones have geo data — old behaviour takes over
        if (!zonesHaveGeoData()) {
            return;
        }

        showBanner('locating', '📍 Detecting your location to check delivery availability…');

        if (!navigator.geolocation) {
            showBanner('error', '⚠ Geolocation not supported. Please select your zone manually.');
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function (pos) {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;

                // Fast client-side pre-check
                const clientMatch = clientSideCheck(lat, lng);

                if (clientMatch) {
                    // Optimistic: pre-select the zone immediately
                    selectZoneInDropdown(clientMatch.id);
                    showBanner('in-zone',
                        '✔ Delivery available — zone "' + (clientMatch.name || clientMatch.zone_name || '') + '" auto-selected.');
                }

                // Server authoritative check (runs even after client match to confirm)
                if (cfg.nonce) {
                    serverSideCheck(lat, lng)
                        .then(function (result) {
                            if (result.in_zone) {
                                const z = result.zone;
                                selectZoneInDropdown(z ? z.id : (clientMatch ? clientMatch.id : ''));
                                const zoneName = z ? (z.zone_name || z.name || '') : '';
                                const fee  = z ? cfg.currency + parseFloat(z.delivery_fee || 0).toFixed(2) : '';
                                showBanner('in-zone',
                                    '✔ Delivery available' +
                                    (zoneName ? ' — zone "' + zoneName + '"' : '') +
                                    (fee ? ' · Fee: ' + fee : ''));
                                setDeliveryRadioLocked(false);
                            } else {
                                // Out of zone — block delivery
                                showBanner('out-zone',
                                    '✘ Delivery is not available in your area. Please choose Pickup.');
                                setDeliveryRadioLocked(true);
                            }
                        })
                        .catch(function (err) {
                            console.error('Menu Popup Geo Check Server Error Details:', err);
                            console.log('cfg object:', cfg);
                            
                            // Server check failed — keep client result if any, else show warning
                            if (!clientMatch) {
                                showBanner('error', '⚠ Could not verify delivery zone. Select manually.');
                            }
                        });
                } else if (!clientMatch) {
                    showBanner('out-zone', '✘ Your location is outside all delivery zones.');
                    setDeliveryRadioLocked(true);
                }
            },
            function (err) {
                // Geolocation denied / unavailable
                hideBanner();
                // Silent degradation — manual dropdown still fully works
                if (err.code === 1) {
                    showBanner('error',
                        '⚠ Location access denied. Please select your delivery zone manually.');
                }
            },
            { timeout: 8000, maximumAge: 120000 }
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Hook into the existing confirmation modal
    // Uses event delegation on body so it catches dynamically added elements
    // ──────────────────────────────────────────────────────────────────────
    $(function () {
        if (!cfg.geoEnabled) return;
        if (menuPopupAjax.deliveryEnabled !== '1') return;

        $(document).on('change', 'input[name="confirmation_order_type"]', function () {
            const type = $(this).val();
            if (type === 'delivery') {
                runGeoCheck();
            } else {
                hideBanner();
                setDeliveryRadioLocked(false);   // re-enable in case it was locked
            }
        });
    });

}(jQuery));
