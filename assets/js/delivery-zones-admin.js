/**
 * Delivery Zones – Admin JS
 *
 * Handles:
 *  1. Zone CRUD (create / update / delete) via WP AJAX
 *  2. Google Maps Drawing Manager integration for radius + polygon drawing
 *  3. Populates hidden form fields from map shapes
 *
 * Depends on:  jQuery, mpDeliveryZones (wp_localize_script)
 * Optional:    Google Maps JS API with libraries=drawing,geometry
 *
 * @package MenuPopup
 */

/* global jQuery, google, mpDeliveryZones */
(function ($) {
    'use strict';

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------
    let map            = null;
    let drawingManager = null;
    let currentShape   = null;   // the Google Maps shape object currently drawn
    let editingZoneId  = 0;

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    $(function () {
        bindTypeToggle();
        bindFormSubmit();
        bindEditButtons();
        bindDeleteButtons();
        bindCancelEdit();

        if (mpDeliveryZones.hasMapsApi && typeof google !== 'undefined') {
            initMap();
        }
    });

    // -----------------------------------------------------------------------
    // Zone-type radio toggle
    // -----------------------------------------------------------------------
    function bindTypeToggle() {
        $('input[name="mp-zone-type"]').on('change', function () {
            const type = $(this).val();
            $('#mp-radius-fields').toggle(type === 'radius');
            $('#mp-polygon-fields').toggle(type === 'polygon');

            if (map && drawingManager) {
                const mode = type === 'radius'
                    ? google.maps.drawing.OverlayType.CIRCLE
                    : google.maps.drawing.OverlayType.POLYGON;
                drawingManager.setDrawingMode(mode);
                updateMapHint(type);
            }
        });
    }

    // -----------------------------------------------------------------------
    // Google Maps initialisation
    // -----------------------------------------------------------------------
    function initMap() {
        map = new google.maps.Map(document.getElementById('mp-zones-map'), {
            center: { lat: 24.7136, lng: 46.6753 },  // Riyadh default – can be customised
            zoom  : 11,
        });

        drawingManager = new google.maps.drawing.DrawingManager({
            drawingMode: google.maps.drawing.OverlayType.CIRCLE,
            drawingControl: true,
            drawingControlOptions: {
                position: google.maps.ControlPosition.TOP_CENTER,
                drawingModes: [
                    google.maps.drawing.OverlayType.CIRCLE,
                    google.maps.drawing.OverlayType.POLYGON,
                ],
            },
            circleOptions : { fillColor: '#3b82f6', fillOpacity: 0.25, strokeWeight: 2, strokeColor: '#1d4ed8', editable: true },
            polygonOptions: { fillColor: '#10b981', fillOpacity: 0.25, strokeWeight: 2, strokeColor: '#065f46', editable: true },
        });

        drawingManager.setMap(map);

        // ── Circle drawn ──────────────────────────────────────────────────
        google.maps.event.addListener(drawingManager, 'circlecomplete', function (circle) {
            clearCurrentShape();
            currentShape = circle;
            drawingManager.setDrawingMode(null);

            const centre = circle.getCenter();
            const radiusM = circle.getRadius();

            $('#mp-center-lat').val(centre.lat().toFixed(7));
            $('#mp-center-lng').val(centre.lng().toFixed(7));
            $('#mp-radius-km').val((radiusM / 1000).toFixed(3));

            // Re-sync on drag / resize
            circle.addListener('center_changed', function () {
                const c = circle.getCenter();
                $('#mp-center-lat').val(c.lat().toFixed(7));
                $('#mp-center-lng').val(c.lng().toFixed(7));
            });
            circle.addListener('radius_changed', function () {
                $('#mp-radius-km').val((circle.getRadius() / 1000).toFixed(3));
            });
        });

        // ── Polygon drawn ─────────────────────────────────────────────────
        google.maps.event.addListener(drawingManager, 'polygoncomplete', function (polygon) {
            clearCurrentShape();
            currentShape = polygon;
            drawingManager.setDrawingMode(null);

            syncPolygonToField(polygon);

            // Re-sync when vertices are moved
            polygon.getPath().addListener('set_at',    function () { syncPolygonToField(polygon); });
            polygon.getPath().addListener('insert_at', function () { syncPolygonToField(polygon); });
            polygon.getPath().addListener('remove_at', function () { syncPolygonToField(polygon); });
        });

        updateMapHint('radius');
    }

    function syncPolygonToField(polygon) {
        const path   = polygon.getPath();
        const coords = [];
        path.forEach(function (latLng) {
            coords.push({ lat: +latLng.lat().toFixed(7), lng: +latLng.lng().toFixed(7) });
        });
        $('#mp-polygon-coords').val(JSON.stringify(coords));
    }

    function clearCurrentShape() {
        if (currentShape) {
            currentShape.setMap(null);
            currentShape = null;
        }
        $('#mp-polygon-coords').val('');
        $('#mp-center-lat, #mp-center-lng, #mp-radius-km').val('');
    }

    function updateMapHint(type) {
        const msg = type === 'radius'
            ? mpDeliveryZones.i18n.drawRadius
            : mpDelivityZones.i18n.drawPolygon;
        $('#mp-map-hint').text(msg);
    }

    // -----------------------------------------------------------------------
    // Edit zone
    // -----------------------------------------------------------------------
    function bindEditButtons() {
        $(document).on('click', '.mp-edit-zone', function () {
            const zone = $(this).data('zone');
            populateForm(zone);
        });
    }

    function populateForm(zone) {
        editingZoneId = zone.id;

        $('#mp-zone-id').val(zone.id);
        $('#mp-zone-form-title').text('Edit Zone: ' + zone.zone_name);
        $('#mp-zone-name').val(zone.zone_name);
        $('#mp-zone-name-ar').val(zone.zone_name_ar || '');
        $('input[name="mp-zone-type"][value="' + zone.zone_type + '"]').prop('checked', true).trigger('change');
        $('#mp-delivery-fee').val(zone.delivery_fee);
        $('#mp-minimum-order').val(zone.minimum_order);
        $('#mp-eta').val(zone.estimated_delivery_minutes);
        $('#mp-is-active').prop('checked', zone.is_active == 1);

        if (zone.zone_type === 'radius') {
            $('#mp-center-lat').val(zone.center_lat);
            $('#mp-center-lng').val(zone.center_lng);
            $('#mp-radius-km').val(zone.radius_km);

            if (map) {
                clearCurrentShape();
                const centre = { lat: parseFloat(zone.center_lat), lng: parseFloat(zone.center_lng) };
                currentShape = new google.maps.Circle({
                    map,
                    center     : centre,
                    radius     : parseFloat(zone.radius_km) * 1000,
                    fillColor  : '#3b82f6',
                    fillOpacity: 0.25,
                    strokeWeight: 2,
                    strokeColor : '#1d4ed8',
                    editable   : true,
                });
                map.setCenter(centre);
                map.setZoom(12);
            }
        } else {
            const coords = Array.isArray(zone.polygon_coords)
                ? zone.polygon_coords
                : JSON.parse(zone.polygon_coords || '[]');
            $('#mp-polygon-coords').val(JSON.stringify(coords));

            if (map && coords.length) {
                clearCurrentShape();
                const path = coords.map(function (p) { return { lat: +p.lat, lng: +p.lng }; });
                currentShape = new google.maps.Polygon({
                    map,
                    paths      : path,
                    fillColor  : '#10b981',
                    fillOpacity: 0.25,
                    strokeWeight: 2,
                    strokeColor : '#065f46',
                    editable   : true,
                });
                const bounds = new google.maps.LatLngBounds();
                path.forEach(function (p) { bounds.extend(p); });
                map.fitBounds(bounds);
            }
        }

        $('#mp-cancel-edit').show();
        $('html, body').animate({ scrollTop: $('#mp-zone-form').offset().top - 40 }, 400);
    }

    // -----------------------------------------------------------------------
    // Cancel edit
    // -----------------------------------------------------------------------
    function bindCancelEdit() {
        $('#mp-cancel-edit').on('click', function () {
            resetForm();
        });
    }

    function resetForm() {
        editingZoneId = 0;
        $('#mp-zone-id').val(0);
        $('#mp-zone-form-title').text('Add New Zone');
        $('#mp-zone-form')[0].reset();
        $('#mp-radius-fields').show();
        $('#mp-polygon-fields').hide();
        $('#mp-cancel-edit').hide();
        $('#mp-zone-message').hide();
        clearCurrentShape();
    }

    // -----------------------------------------------------------------------
    // Save (create / update)
    // -----------------------------------------------------------------------
    function bindFormSubmit() {
        $('#mp-zone-form').on('submit', function (e) {
            e.preventDefault();

            const type = $('input[name="mp-zone-type"]:checked').val();
            const $btn  = $('#mp-save-zone-btn');
            const $spin = $btn.siblings('.spinner');
            const $msg  = $('#mp-zone-message');

            $btn.prop('disabled', true);
            $spin.addClass('is-active');
            $msg.hide();

            const data = {
                action    : 'mp_save_delivery_zone',
                nonce     : mpDeliveryZones.nonce,
                zone_id   : $('#mp-zone-id').val(),
                branch_id : $('#mp-zone-branch-id').val(),
                zone_name    : $('#mp-zone-name').val(),
                zone_name_ar : $('#mp-zone-name-ar').val(),
                zone_type : type,
                delivery_fee  : $('#mp-delivery-fee').val(),
                minimum_order : $('#mp-minimum-order').val(),
                estimated_delivery_minutes: $('#mp-eta').val(),
                is_active : $('#mp-is-active').is(':checked') ? 1 : 0,
            };

            if (type === 'radius') {
                data.center_lat = $('#mp-center-lat').val();
                data.center_lng = $('#mp-center-lng').val();
                data.radius_km  = $('#mp-radius-km').val();
            } else {
                data.polygon_coords = $('#mp-polygon-coords').val();
            }

            $.post(mpDeliveryZones.ajaxurl, data)
                .done(function (res) {
                    if (res.success) {
                        $msg.removeClass('notice-error').addClass('notice notice-success')
                            .text(mpDeliveryZones.i18n.savedOk).show();
                        // Reload the zones list section
                        setTimeout(function () { location.reload(); }, 1200);
                    } else {
                        $msg.removeClass('notice-success').addClass('notice notice-error')
                            .text(res.data.message || 'Error saving zone.').show();
                    }
                })
                .fail(function () {
                    $msg.addClass('notice notice-error').text('Server error. Please try again.').show();
                })
                .always(function () {
                    $btn.prop('disabled', false);
                    $spin.removeClass('is-active');
                });
        });
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------
    function bindDeleteButtons() {
        $(document).on('click', '.mp-delete-zone', function () {
            if (! confirm(mpDeliveryZones.i18n.confirmDelete)) return;

            const $row   = $(this).closest('.mp-zone-row');
            const zoneId = $(this).data('id');

            $.post(mpDeliveryZones.ajaxurl, {
                action  : 'mp_delete_delivery_zone',
                nonce   : mpDeliveryZones.nonce,
                zone_id : zoneId,
            }).done(function (res) {
                if (res.success) {
                    $row.fadeOut(300, function () { $(this).remove(); });
                } else {
                    alert(res.data.message || 'Delete failed.');
                }
            });
        });
    }

}(jQuery));
