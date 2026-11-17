jQuery(document).ready(function ($) {
  // Status Update Logic (List View) - Modern AJAX approach
  $(document).on("click", ".update-status", function (e) {
    e.preventDefault();
    var button = $(this);
    var row = button.closest("tr");
    var id = button.data("id");
    var status = button.data("status");

    button.prop("disabled", true).text("Updating...");

    $.ajax({
      url: menuPopupReservation.ajax_url,
      type: "POST",
      data: {
        action: "menu_popup_update_reservation_status",
        nonce: menuPopupReservation.nonce,
        id: id,
        status: status,
      },
      success: function (response) {
        if (response.success) {
          // Update status visually without reload
          var statusCell = row.find("td").eq(3).find("span");
          statusCell.removeClass(
            "status-pending status-confirmed status-cancelled"
          );
          statusCell.addClass("status-" + status);
          statusCell.text(status.charAt(0).toUpperCase() + status.slice(1));

          // Update button to next logical status
          if (status === "confirmed") {
            button.data("status", "completed");
            button.text("Complete");
          } else if (status === "completed") {
            button.data("status", "cancelled");
            button.text("Cancel");
          }
        } else {
          alert("Failed to update status. Please try again.");
        }
        button.prop("disabled", false).text("Updated");
      },
      error: function () {
        alert("Error updating status. Please check connection.");
        button
          .prop("disabled", false)
          .text(
            button.data("status").charAt(0).toUpperCase() +
              button.data("status").slice(1)
          );
      },
    });
  });

  // Live Map Logic with enhanced status checking
  if ($("#floor-plan").length) {
    let liveUpdateInterval;

    function updateMapStatus() {
      $.ajax({
        url: menuPopupReservation.ajax_url,
        data: {
          action: "menu_popup_get_live_status",
          nonce: menuPopupReservation.nonce,
        },
        success: function (response) {
          if (response.success) {
            $.each(response.data, function (tableId, status) {
              var tableNode = $("#table-" + tableId);
              tableNode.removeClass(
                "status-free status-occupied status-reserved"
              );
              tableNode.addClass("status-" + status);

              // Add visual indicators
              updateTableIndicator(tableNode, status);
            });
          }
        },
        error: function () {
          showMapError("Unable to fetch live status");
        },
      });
    }

    function updateTableIndicator(tableNode, status) {
      // Remove existing indicators
      tableNode.find(".status-badge").remove();

      var indicator = "";
      if (status === "occupied") {
        indicator = "🟢";
      } else if (status === "reserved") {
        indicator = "🟡";
      } else {
        indicator = "⚪";
      }

      if (indicator) {
        tableNode.append('<div class="status-badge">' + indicator + "</div>");
      }
    }

    function showMapError(message) {
      if (!$(".map-error").length) {
        $("#floor-plan").prepend(
          '<div class="map-error" style="color: #e53e3e; padding: 10px; background: #fed7d7; border-radius: 4px; margin-bottom: 10px;">' +
            message +
            "</div>"
        );
      }
    }

    // Initial load
    updateMapStatus();

    // Manual refresh
    $("#refresh-map").click(function () {
      $(this).prop("disabled", true).text("Refreshing...");
      updateMapStatus();
      setTimeout(function () {
        $("#refresh-map").prop("disabled", false).text("Refresh Live Status");
      }, 1000);
    });

    // Auto refresh for live view (not arrangement mode)
    function startLiveUpdates() {
      stopLiveUpdates();
      liveUpdateInterval = setInterval(function () {
        if (!$("#arrangement-mode").hasClass("active")) {
          updateMapStatus();
        }
      }, 30000);
    }

    function stopLiveUpdates() {
      if (liveUpdateInterval) {
        clearInterval(liveUpdateInterval);
      }
    }

    // Start live updates by default
    startLiveUpdates();

    // Enhanced table interaction
    $(document).on("click", ".table-node", function (e) {
      if ($("#arrangement-mode").hasClass("active")) return; // Skip in arrangement mode

      const tableId = $(this).data("id");
      const tableName = $(this).find(".table-name").text();

      // Show reservation details for this table (if any)
      showTableReservations(tableId, tableName);
    });

    function showTableReservations(tableId, tableName) {
      // AJAX call to get reservations for this table today
      $.ajax({
        url: menuPopupReservation.ajax_url,
        data: {
          action: "menu_popup_get_table_reservations",
          nonce: menuPopupReservation.nonce,
          table_id: tableId,
          date: new Date().toISOString().split("T")[0],
        },
        success: function (response) {
          if (response.success && response.data.length > 0) {
            let reservationList =
              "<h4>Reservations for " + tableName + "</h4><ul>";
            response.data.forEach(function (reservation) {
              reservationList +=
                "<li>" +
                reservation.time +
                " - " +
                reservation.customer_name +
                " (" +
                reservation.party_size +
                " people)</li>";
            });
            reservationList += "</ul>";

            // Show in a modal or alert
            if (typeof Swal !== "undefined") {
              Swal.fire({
                title: tableName,
                html: reservationList,
                icon: "info",
              });
            } else {
              alert(
                "Reservations for " +
                  tableName +
                  ":\n" +
                  response.data
                    .map((r) => r.time + " - " + r.customer_name)
                    .join("\n")
              );
            }
          } else {
            alert("No reservations for " + tableName + " today.");
          }
        },
      });
    }
  }
});
