// Debug Utilities for Reservation System
(function ($) {
  "use strict";

  window.ReservationDebug = {
    initialize: function () {
      if (typeof console !== "undefined") {
        // Override console.log to send to server when WP_DEBUG is on
        const originalConsoleLog = console.log;
        console.log = function (...args) {
          originalConsoleLog.apply(console, args);

          // Send to server if we're in debug mode and have AJAX
          if (
            typeof menuPopupReservation !== "undefined" &&
            menuPopupReservation.debug_enabled
          ) {
            const message = args
              .map((arg) =>
                typeof arg === "object" ? JSON.stringify(arg) : String(arg)
              )
              .join(" ");

            $.post(menuPopupReservation.ajax_url, {
              action: "menu_popup_debug_log_js",
              message: message,
              level: "INFO",
              nonce: menuPopupReservation.nonce,
            }).fail(function () {
              // Silently fail, don't break the page
            });
          }
        };

        console.error = function (...args) {
          originalConsoleLog.apply(console, args);
          const message =
            "[ERROR] " +
            args
              .map((arg) =>
                typeof arg === "object" ? JSON.stringify(arg) : String(arg)
              )
              .join(" ");

          if (typeof menuPopupReservation !== "undefined") {
            $.post(menuPopupReservation.ajax_url, {
              action: "menu_popup_debug_log_js",
              message: message,
              level: "ERROR",
              nonce: menuPopupReservation.nonce,
            });
          }
        };
      }
    },

    // Utility functions for testing
    testAPI: function (endpointName, params = {}) {
      console.log(`🧪 Testing API: ${endpointName}`, params);

      return $.ajax({
        url: menuPopupReservation.ajax_url,
        type: "POST",
        data: {
          action: endpointName,
          nonce: menuPopupReservation.nonce,
          ...params,
        },
        success: function (response) {
          console.log(`✅ API ${endpointName} Success:`, response);
        },
        error: function (xhr, status, error) {
          console.error(`❌ API ${endpointName} Error:`, {
            status: xhr.status,
            statusText: xhr.statusText,
            response: xhr.responseText,
            error: error,
          });
        },
      });
    },

    // Run comprehensive system tests
    runSystemTests: function () {
      console.log("🧪 Running Reservation System Tests...");

      // Test 1: System Info
      console.log("📋 System Info:", {
        wordpress: typeof wp !== "undefined" ? wp.version : "N/A",
        jquery: $.fn.jquery,
        navigator: {
          userAgent: navigator.userAgent,
          onLine: navigator.onLine,
          serviceWorker: !!navigator.serviceWorker,
          clipboard: !!navigator.clipboard,
          share: !!navigator.share,
        },
        menuPopupReservation:
          typeof menuPopupReservation !== "undefined"
            ? {
                ajax_url: !!menuPopupReservation.ajax_url,
                nonce: !!menuPopupReservation.nonce,
                debug_enabled: menuPopupReservation.debug_enabled || false,
              }
            : "NOT FOUND",
      });

      // Test 2: API Connectivity
      this.testAPI("menu_popup_get_branches");

      // Test 3: DOM Elements
      const wizard = document.querySelector("#reservation-wizard");
      console.log("🖥️ DOM Check:", {
        wizardExists: !!wizard,
        steps: wizard ? wizard.querySelectorAll(".wizard-step").length : 0,
        branchSelect: !!wizard?.querySelector('select[name="branch_id"]'),
        nextButtons: wizard ? wizard.querySelectorAll(".next-step").length : 0,
        prevButtons: wizard ? wizard.querySelectorAll(".prev-step").length : 0,
      });

      console.log("✅ Tests completed. Check console for details.");
    },

    // Show performance metrics
    logPerformance: function (label, startTime) {
      const duration = performance.now() - startTime;
      console.log(`⏱️ Performance: ${label} took ${duration.toFixed(2)}ms`);
    },

    // Monitor Ajax calls
    monitorAjax: function () {
      const originalAjax = $.ajax;
      $.ajax = function (settings) {
        const startTime = performance.now();

        console.log("📡 AJAX Request Started:", {
          url: settings.url,
          action: settings.data?.action,
          method: settings.type || "GET",
        });

        return originalAjax(settings)
          .done(function (data) {
            ReservationDebug.logPerformance("AJAX Complete", startTime);
            console.log("📨 AJAX Response:", data);
          })
          .fail(function (xhr, status, error) {
            console.error("📡 AJAX Error:", {
              url: settings.url,
              status: xhr.status,
              statusText: xhr.statusText,
              response: xhr.responseText,
              error: error,
            });
          });
      };
    },
  };

  // Initialize on document ready
  $(document).ready(function () {
    ReservationDebug.initialize();

    // Add global debug function if debug is enabled
    if (
      typeof menuPopupReservation !== "undefined" &&
      menuPopupReservation.debug_enabled
    ) {
      window.debugReservation = ReservationDebug;
      console.log(
        '🐛 Reservation Debug Enabled - Type "debugReservation.runSystemTests()" to run tests'
      );
    }
  });
})(jQuery);
