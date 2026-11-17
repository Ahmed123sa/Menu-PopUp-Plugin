// Fixed Debug Utilities for Reservation System
(function ($) {
  "use strict";

  // Ensure the ReservationDebug object exists
  if (typeof window.ReservationDebug === "undefined") {
    window.ReservationDebug = {};
  }

  // Define all methods
  window.ReservationDebug.initialize = function () {
    console.log("🔧 Debug system initializing...");
    if (typeof console !== "undefined") {
      // Store original console methods
      const originalConsoleLog = console.log;
      const originalConsoleError = console.error;
    }
    console.log("✅ Debug system ready");
  };

  window.ReservationDebug.runSystemTests = function () {
    console.log("🧪 Testing Reservation System...");

    // System Info
    console.log("📋 System Info:", {
      wordpress: "Checking...",
      jquery: $.fn.jquery,
      reservationWizardExists: !!document.querySelector("#reservation-wizard"),
      branchesSelect: !!document.querySelector('select[name="branch_id"]'),
      menuPopupReservation: typeof menuPopupReservation !== "undefined",
    });

    // Test API if available
    if (typeof menuPopupReservation !== "undefined") {
      console.log("🔧 Testing API calls...");
      // Test branches API
      $.post(
        menuPopupReservation.ajax_url,
        {
          action: "menu_popup_get_branches",
          nonce: menuPopupReservation.nonce,
        },
        function (response) {
          console.log("✅ API Test Result:", response);
        }
      );
    }

    console.log("🎉 System tests completed!");
  };
})(jQuery);

// Initialize immediately
if (typeof jQuery !== "undefined") {
  window.ReservationDebug.initialize();

  // Make globally available for debugging
  window.debugReservation = window.ReservationDebug;
}
