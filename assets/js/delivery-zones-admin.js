/**
 * Delivery Zones – Admin JS
 *
 *  1. Overview map with every zone and branch pin
 *  2. Circle / polygon drawing that fills the hidden shape fields
 *  3. Zone create / update / delete / pause over WP AJAX
 *  4. Location tester that runs the same rules as a customer order
 *
 * Depends on: jQuery, mpDeliveryZones (wp_localize_script)
 * Optional:   Google Maps JS API (geometry library)
 */

/* global jQuery, google, mpDeliveryZones */
(function ($) {
    'use strict';

    const cfg = window.mpDeliveryZones || {};
    const MANUAL = cfg.mode === 'manual';

    let map = null;
    let drawMode = 'idle';          // idle | circle | polygon
    let geocoder = null;
    let searchMarker = null;
    let draft = null;               // in-progress drawing state
    let currentShape = null;      // the shape being drawn or edited
    let editingZoneId = 0;
    const overviewShapes = {};    // zoneId -> google shape

    const COLORS = {
        mine:   { fill: '#2563eb', stroke: '#1d4ed8' },
        other:  { fill: '#9ca3af', stroke: '#6b7280' },
        off:    { fill: '#f59e0b', stroke: '#b45309' },
        draw:   { fill: '#10b981', stroke: '#047857' }
    };

    window.gm_authFailure = function () {
        $('#mp-map-hint').text(cfg.i18n.authFailed);
    };

    $(function () {
        bindTypeToggle();
        bindPricingToggle();
        bindFormSubmit();
        bindEditButtons();
        bindDeleteButtons();
        bindPauseButtons();
        bindCancelEdit();
        bindTester();

        if (MANUAL) { applyManualLayout(); }

        if (!MANUAL && cfg.hasMapsApi && typeof google !== 'undefined' && google.maps) {
            initMap();
        } else {
            $('#mp-map-hint').text('');
        }
    });

    // ------------------------------------------------------------------
    // Form toggles
    // ------------------------------------------------------------------
    function zoneType() { return MANUAL ? 'manual' : $('input[name="mp-zone-type"]:checked').val(); }

    // Manual zones: name, fee, minimum order, ETA. No shape, no map-only options.
    function applyManualLayout() {
        $('input[name="mp-zone-type"]').closest('tr').hide();
        $('input[name="mp-pricing-type"]').closest('tr').hide();
        $('input[name="mp-pricing-type"][value="flat"]').prop('checked', true);
        $('#mp-distance-fields').hide();
        $('#mp-flat-fields').show();
        $('#mp-free-over, #mp-priority, #mp-schedule-from').closest('tr').hide();
    }

    function bindTypeToggle() {
        $('input[name="mp-zone-type"]').on('change', function () {
            clearCurrentShape();
            if (map) { startDrawing(); }
            updateShapeStatus();
        });
    }

    function bindPricingToggle() {
        $('input[name="mp-pricing-type"]').on('change', applyPricingVisibility);
        applyPricingVisibility();
    }

    function applyPricingVisibility() {
        if (MANUAL) { return; }
        const distance = $('input[name="mp-pricing-type"]:checked').val() === 'distance';
        $('#mp-flat-fields').toggle(!distance);
        $('#mp-distance-fields').toggle(distance);
    }

    function updateShapeStatus() {
        const type = zoneType();
        let text;
        if (type === 'radius' && $('#mp-center-lat').val() !== '') {
            text = '✔ ' + parseFloat($('#mp-radius-km').val() || 0).toFixed(2) + ' km';
        } else if (type === 'polygon' && $('#mp-polygon-coords').val()) {
            let n = 0;
            try { n = JSON.parse($('#mp-polygon-coords').val()).length; } catch (e) {}
            text = '✔ ' + n + ' points';
        } else {
            text = type === 'radius' ? cfg.i18n.drawRadius : cfg.i18n.drawPolygon;
        }
        $('#mp-shape-status').text(text);
        $('#mp-map-hint').text(text);
    }

    // ------------------------------------------------------------------
    // Map
    // ------------------------------------------------------------------
    function initMap() {
        const el = document.getElementById('mp-zones-map');
        if (!el) { return; }

        map = new google.maps.Map(el, {
            center: cfg.center,
            zoom: 11,
            streetViewControl: false,
            mapTypeControl: false
        });

        geocoder = new google.maps.Geocoder();
        map.setOptions({ disableDoubleClickZoom: true, gestureHandling: 'greedy' });

        map.addListener('click', onMapClick);
        map.addListener('mousemove', onMapMove);
        map.addListener('dblclick', function () { if (drawMode === 'polygon') { finishPolygon(); } });

        bindSearch();
        $('#mp-draw-finish').on('click', function () { if (drawMode === 'polygon') { finishPolygon(); } });
        $('#mp-draw-clear').on('click', function () { clearCurrentShape(); startDrawing(); });

        drawOverview();
        startDrawing();
        updateShapeStatus();
    }

    // ------------------------------------------------------------------
    // Drawing (own implementation, no Google drawing library)
    // ------------------------------------------------------------------
    function startDrawing() {
        resetDraft();
        if (currentShape) { setMode('idle'); return; }
        setMode(zoneType() === 'radius' ? 'circle' : 'polygon');
    }

    function setMode(mode) {
        drawMode = mode;
        if (map) { map.setOptions({ draggableCursor: mode === 'idle' ? null : 'crosshair' }); }
        $('#mp-draw-finish').toggle(mode === 'polygon');
    }

    function resetDraft() {
        if (draft) {
            if (draft.poly) { draft.poly.setMap(null); }
            if (draft.circle) { draft.circle.setMap(null); }
            (draft.markers || []).forEach(function (m) { m.setMap(null); });
        }
        draft = null;
    }

    function onMapClick(e) {
        if (drawMode === 'idle') {
            $('#mp-test-lat').val(e.latLng.lat().toFixed(6));
            $('#mp-test-lng').val(e.latLng.lng().toFixed(6));
            return;
        }

        if (drawMode === 'circle') {
            if (!draft) {
                // first click: centre
                draft = {
                    centre: e.latLng,
                    circle: new google.maps.Circle(Object.assign(shapeStyle(COLORS.draw, false), {
                        map: map, center: e.latLng, radius: 300, clickable: false
                    })),
                    markers: [new google.maps.Marker({ map: map, position: e.latLng, clickable: false })]
                };
                $('#mp-map-hint').text(cfg.i18n.circleSecond);
            } else {
                // second click: fix the radius
                const radius = Math.max(100, google.maps.geometry.spherical.computeDistanceBetween(draft.centre, e.latLng));
                const circle = new google.maps.Circle(Object.assign(shapeStyle(COLORS.draw, true), {
                    map: map, center: draft.centre, radius: radius
                }));
                resetDraft();
                currentShape = circle;
                setMode('idle');
                syncCircle(circle);
                circle.addListener('center_changed', function () { syncCircle(circle); });
                circle.addListener('radius_changed', function () { syncCircle(circle); });
            }
            return;
        }

        if (drawMode === 'polygon') {
            if (!draft) {
                draft = { points: [], markers: [], poly: new google.maps.Polyline({
                    map: map, path: [], strokeColor: COLORS.draw.stroke, strokeWeight: 2, clickable: false
                }) };
            }
            draft.points.push(e.latLng);
            draft.poly.setPath(draft.points);
            const first = draft.points.length === 1;
            const marker = new google.maps.Marker({
                map: map, position: e.latLng, clickable: first,
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: first ? 7 : 4, fillColor: first ? '#047857' : '#10b981', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 }
            });
            if (first) { marker.addListener('click', function () { finishPolygon(); }); }
            draft.markers.push(marker);
            $('#mp-map-hint').text(draft.points.length + ' ' + cfg.i18n.pointsAdded);
        }
    }

    function onMapMove(e) {
        if (drawMode === 'circle' && draft && draft.circle) {
            draft.circle.setRadius(Math.max(100, google.maps.geometry.spherical.computeDistanceBetween(draft.centre, e.latLng)));
        }
    }

    function finishPolygon() {
        if (!draft || !draft.points || draft.points.length < 3) {
            $('#mp-map-hint').text(cfg.i18n.needThree);
            return;
        }
        const polygon = new google.maps.Polygon(Object.assign(shapeStyle(COLORS.draw, true), {
            map: map, paths: draft.points.slice()
        }));
        resetDraft();
        currentShape = polygon;
        setMode('idle');
        syncPolygon(polygon);
        ['set_at', 'insert_at', 'remove_at'].forEach(function (ev) {
            polygon.getPath().addListener(ev, function () { syncPolygon(polygon); });
        });
    }

    // ------------------------------------------------------------------
    // Address / area search
    // ------------------------------------------------------------------
    function bindSearch() {
        const $input = $('#mp-map-search');
        $('#mp-map-search-btn').on('click', runSearch);
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
        });
    }

    function runSearch() {
        const q = $.trim($('#mp-map-search').val());
        if (!q || !geocoder) { return; }

        const coords = q.match(/^(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)$/);
        if (coords) {
            focusOn({ lat: parseFloat(coords[1]), lng: parseFloat(coords[2]) }, null);
            return;
        }

        $('#mp-search-msg').text('…');
        geocoder.geocode({ address: q }, function (results, status) {
            if (status === 'OK' && results && results[0]) {
                $('#mp-search-msg').text(results[0].formatted_address);
                focusOn(results[0].geometry.location, results[0].geometry.viewport);
            } else if (status === 'REQUEST_DENIED') {
                $('#mp-search-msg').text(cfg.i18n.geocodeDenied);
            } else if (status === 'ZERO_RESULTS') {
                $('#mp-search-msg').text(cfg.i18n.noResults);
            } else {
                $('#mp-search-msg').text(status);
            }
        });
    }

    function focusOn(position, viewport) {
        if (viewport) { map.fitBounds(viewport); } else { map.setCenter(position); map.setZoom(16); }
        if (searchMarker) { searchMarker.setMap(null); }
        searchMarker = new google.maps.Marker({ map: map, position: position, clickable: false });
    }

    function shapeStyle(color, editable) {
        return {
            fillColor: color.fill, fillOpacity: 0.22,
            strokeColor: color.stroke, strokeWeight: 2,
            editable: !!editable, clickable: !!editable
        };
    }

    function drawOverview() {
        const bounds = new google.maps.LatLngBounds();
        let any = false;

        (cfg.zones || []).forEach(function (z) {
            let color = z.branch_id === cfg.activeBranch ? COLORS.mine : COLORS.other;
            if (!z.is_active || z.is_paused) { color = COLORS.off; }

            let shape = null;
            if (z.type === 'radius' && z.center_lat !== null && z.radius_km > 0) {
                shape = new google.maps.Circle(Object.assign(shapeStyle(color, false), {
                    map: map,
                    center: { lat: z.center_lat, lng: z.center_lng },
                    radius: z.radius_km * 1000
                }));
                bounds.union(shape.getBounds());
                any = true;
            } else if (z.type === 'polygon' && z.polygon_coords && z.polygon_coords.length > 2) {
                const path = z.polygon_coords.map(function (p) { return { lat: +p.lat, lng: +p.lng }; });
                shape = new google.maps.Polygon(Object.assign(shapeStyle(color, false), { map: map, paths: path }));
                path.forEach(function (p) { bounds.extend(p); });
                any = true;
            }
            if (shape) { overviewShapes[z.id] = shape; }
        });

        (cfg.branches || []).forEach(function (b) {
            new google.maps.Marker({ map: map, position: { lat: b.lat, lng: b.lng }, title: b.name, label: '📍' });
            bounds.extend({ lat: b.lat, lng: b.lng });
            any = true;
        });

        if (any) { map.fitBounds(bounds); }
    }

    function syncCircle(circle) {
        const c = circle.getCenter();
        $('#mp-center-lat').val(c.lat().toFixed(7));
        $('#mp-center-lng').val(c.lng().toFixed(7));
        $('#mp-radius-km').val((circle.getRadius() / 1000).toFixed(4));
        updateShapeStatus();
    }

    function syncPolygon(polygon) {
        const coords = [];
        polygon.getPath().forEach(function (p) {
            coords.push({ lat: +p.lat().toFixed(7), lng: +p.lng().toFixed(7) });
        });
        $('#mp-polygon-coords').val(JSON.stringify(coords));
        updateShapeStatus();
    }

    function clearCurrentShape() {
        resetDraft();
        if (currentShape) {
            currentShape.setMap(null);
            currentShape = null;
        }
        $('#mp-polygon-coords, #mp-center-lat, #mp-center-lng, #mp-radius-km').val('');
        updateShapeStatus();
    }

    // ------------------------------------------------------------------
    // Edit
    // ------------------------------------------------------------------
    function bindEditButtons() {
        $(document).on('click', '.mp-edit-zone', function () {
            populateForm($(this).data('zone'));
        });
    }

    function populateForm(zone) {
        editingZoneId = parseInt(zone.id, 10);

        $('#mp-zone-id').val(zone.id);
        $('#mp-zone-form-title').text('Edit Zone: ' + zone.zone_name);
        $('#mp-zone-name').val(zone.zone_name);
        $('#mp-zone-name-ar').val(zone.zone_name_ar || '');
        if (!MANUAL) { $('input[name="mp-zone-type"][value="' + zone.zone_type + '"]').prop('checked', true); }
        $('input[name="mp-pricing-type"][value="' + (zone.pricing_type || 'flat') + '"]').prop('checked', true);
        applyPricingVisibility();

        $('#mp-delivery-fee').val(zone.delivery_fee);
        $('#mp-base-fee').val(zone.base_fee);
        $('#mp-per-km-fee').val(zone.per_km_fee);
        $('#mp-max-fee').val(zone.max_fee);
        $('#mp-free-over').val(zone.free_delivery_over);
        $('#mp-minimum-order').val(zone.minimum_order);
        $('#mp-eta').val(zone.estimated_delivery_minutes);
        $('#mp-priority').val(zone.priority || 0);
        $('#mp-schedule-from').val(zone.schedule && zone.schedule.from ? zone.schedule.from : '');
        $('#mp-schedule-to').val(zone.schedule && zone.schedule.to ? zone.schedule.to : '');
        $('#mp-is-active').prop('checked', parseInt(zone.is_active, 10) === 1);
        $('#mp-is-paused').prop('checked', parseInt(zone.is_paused, 10) === 1);

        // Replace the overview shape with an editable one
        if (overviewShapes[editingZoneId]) { overviewShapes[editingZoneId].setMap(null); }

        if (map) {
            clearCurrentShape();
            setMode('idle');

            if (zone.zone_type === 'radius') {
                const centre = { lat: parseFloat(zone.center_lat), lng: parseFloat(zone.center_lng) };
                currentShape = new google.maps.Circle(Object.assign(shapeStyle(COLORS.draw, true), {
                    map: map, center: centre, radius: parseFloat(zone.radius_km) * 1000
                }));
                syncCircle(currentShape);
                currentShape.addListener('center_changed', function () { syncCircle(currentShape); });
                currentShape.addListener('radius_changed', function () { syncCircle(currentShape); });
                map.fitBounds(currentShape.getBounds());
            } else {
                const coords = Array.isArray(zone.polygon_coords) ? zone.polygon_coords : [];
                const path = coords.map(function (p) { return { lat: +p.lat, lng: +p.lng }; });
                currentShape = new google.maps.Polygon(Object.assign(shapeStyle(COLORS.draw, true), { map: map, paths: path }));
                syncPolygon(currentShape);
                const poly = currentShape;
                ['set_at', 'insert_at', 'remove_at'].forEach(function (ev) {
                    poly.getPath().addListener(ev, function () { syncPolygon(poly); });
                });
                const bounds = new google.maps.LatLngBounds();
                path.forEach(function (p) { bounds.extend(p); });
                map.fitBounds(bounds);
            }
        } else if (zone.zone_type === 'radius') {
            $('#mp-center-lat').val(zone.center_lat);
            $('#mp-center-lng').val(zone.center_lng);
            $('#mp-radius-km').val(zone.radius_km);
        } else {
            $('#mp-polygon-coords').val(JSON.stringify(zone.polygon_coords || []));
        }

        updateShapeStatus();
        $('#mp-cancel-edit').show();
        $('html, body').animate({ scrollTop: $('#mp-zones-map').length ? $('#mp-zones-map').offset().top - 40 : 0 }, 300);
    }

    function bindCancelEdit() {
        $('#mp-cancel-edit').on('click', function () { location.reload(); });
    }

    // ------------------------------------------------------------------
    // Save
    // ------------------------------------------------------------------
    function bindFormSubmit() {
        $('#mp-zone-form').on('submit', function (e) {
            e.preventDefault();

            const type = zoneType();
            const $btn = $('#mp-save-zone-btn');
            const $spin = $btn.siblings('.spinner');
            const $msg = $('#mp-zone-message');
            const $warn = $('#mp-zone-warnings');

            const hasShape = type === 'manual' ? true : type === 'radius'
                ? $('#mp-center-lat').val() !== '' && parseFloat($('#mp-radius-km').val()) > 0
                : !!$('#mp-polygon-coords').val();
            if (!hasShape) {
                $msg.attr('class', 'notice notice-error').text(cfg.i18n.noShape).show();
                return;
            }

            $btn.prop('disabled', true);
            $spin.addClass('is-active');
            $msg.hide();
            $warn.empty();

            const data = {
                action: 'mp_save_delivery_zone',
                nonce: cfg.nonce,
                zone_id: $('#mp-zone-id').val(),
                branch_id: $('#mp-zone-branch-id').val(),
                zone_name: $('#mp-zone-name').val(),
                zone_name_ar: $('#mp-zone-name-ar').val(),
                zone_type: type,
                pricing_type: $('input[name="mp-pricing-type"]:checked').val(),
                delivery_fee: $('#mp-delivery-fee').val(),
                base_fee: $('#mp-base-fee').val(),
                per_km_fee: $('#mp-per-km-fee').val(),
                max_fee: $('#mp-max-fee').val(),
                free_delivery_over: $('#mp-free-over').val(),
                minimum_order: $('#mp-minimum-order').val(),
                estimated_delivery_minutes: $('#mp-eta').val(),
                priority: $('#mp-priority').val(),
                schedule_from: $('#mp-schedule-from').val(),
                schedule_to: $('#mp-schedule-to').val(),
                is_active: $('#mp-is-active').is(':checked') ? 1 : 0,
                is_paused: $('#mp-is-paused').is(':checked') ? 1 : 0
            };

            if (type === 'manual') {
                // fixed fee only, no shape
            } else if (type === 'radius') {
                data.center_lat = $('#mp-center-lat').val();
                data.center_lng = $('#mp-center-lng').val();
                data.radius_km = $('#mp-radius-km').val();
            } else {
                data.polygon_coords = $('#mp-polygon-coords').val();
            }

            $.post(cfg.ajaxurl, data)
                .done(function (res) {
                    if (res.success) {
                        $msg.attr('class', 'notice notice-success').text(cfg.i18n.savedOk).show();
                        const warnings = res.data.warnings || [];
                        if (warnings.length) {
                            warnings.forEach(function (w) {
                                $warn.append($('<div class="notice notice-warning inline"></div>').append($('<p></p>').text(w)));
                            });
                            $warn.append($('<p></p>').append(
                                $('<button type="button" class="button">OK</button>').on('click', function () { location.reload(); })
                            ));
                        } else {
                            setTimeout(function () { location.reload(); }, 900);
                        }
                    } else {
                        $msg.attr('class', 'notice notice-error').text((res.data && res.data.message) || 'Error saving zone.').show();
                    }
                })
                .fail(function () {
                    $msg.attr('class', 'notice notice-error').text('Server error. Please try again.').show();
                })
                .always(function () {
                    $btn.prop('disabled', false);
                    $spin.removeClass('is-active');
                });
        });
    }

    // ------------------------------------------------------------------
    // Delete / pause
    // ------------------------------------------------------------------
    function bindDeleteButtons() {
        $(document).on('click', '.mp-delete-zone', function () {
            if (!window.confirm(cfg.i18n.confirmDelete)) { return; }

            const $row = $(this).closest('.mp-zone-row');
            $.post(cfg.ajaxurl, {
                action: 'mp_delete_delivery_zone',
                nonce: cfg.nonce,
                zone_id: $(this).data('id')
            }).done(function (res) {
                if (res.success) {
                    $row.fadeOut(300, function () { $(this).remove(); });
                } else {
                    window.alert((res.data && res.data.message) || 'Delete failed.');
                }
            });
        });
    }

    function bindPauseButtons() {
        $(document).on('click', '.mp-pause-zone', function () {
            const $btn = $(this);
            const pause = String($btn.data('paused')) !== '1';

            $btn.prop('disabled', true);
            $.post(cfg.ajaxurl, {
                action: 'mp_toggle_zone_pause',
                nonce: cfg.nonce,
                zone_id: $btn.data('id'),
                paused: pause ? 1 : 0
            }).done(function (res) {
                if (res.success) {
                    location.reload();
                } else {
                    $btn.prop('disabled', false);
                    window.alert((res.data && res.data.message) || 'Update failed.');
                }
            }).fail(function () { $btn.prop('disabled', false); });
        });
    }

    // ------------------------------------------------------------------
    // Tester
    // ------------------------------------------------------------------
    function bindTester() {
        $('#mp-test-btn').on('click', function () {
            const $out = $('#mp-test-result');
            $out.hide();

            $.post(cfg.ajaxurl, {
                action: 'mp_test_delivery_resolve',
                nonce: cfg.nonce,
                lat: $('#mp-test-lat').val(),
                lng: $('#mp-test-lng').val(),
                order_value: $('#mp-test-value').val()
            }).done(function (res) {
                if (!res.success) {
                    $out.attr('class', 'no').text((res.data && res.data.message) || 'Error').show();
                    return;
                }
                const r = res.data;
                if (r.ok) {
                    const lines = [
                        '✔ ' + cfg.i18n.testOk,
                        'Zone: ' + r.zone_name + (r.zone_name_ar ? ' / ' + r.zone_name_ar : ''),
                        'Branch: ' + (r.branch_name || '-'),
                        'Fee: ' + r.fee.toFixed(2) + (r.free_delivery_applied ? ' (free delivery applied)' : ''),
                        'Distance: ' + r.distance_km + ' km',
                        'Minimum order: ' + r.min_order.toFixed(2),
                        'ETA: ' + r.eta + ' min'
                    ];
                    $out.attr('class', 'ok').empty().show();
                    lines.forEach(function (l) { $out.append($('<div></div>').text(l)); });
                } else {
                    $out.attr('class', 'no').empty().show();
                    $out.append($('<div></div>').text('✘ ' + r.message + ' (' + r.reason + ')'));
                    (r.nearest_branches || []).forEach(function (b) {
                        $out.append($('<div></div>').text('Nearest branch: ' + b.name + ' · ' + b.distance_km + ' km'));
                    });
                }
            }).fail(function () {
                $out.attr('class', 'no').text('Server error').show();
            });
        });
    }

}(jQuery));
