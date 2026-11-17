// Social Sharing and PWA Features for Reservation System
(function ($) {
  "use strict";

  class SocialAndPWAFeatures {
    constructor(reservationData) {
      this.reservationData = reservationData;
      this.init();
    }

    init() {
      this.initSocialFeatures();
      this.showPWAInstallPrompt();
    }

    // Social sharing functions
    formatDate(dateString) {
      if (!dateString) return "TBD";
      const date = new Date(dateString);
      return date.toLocaleDateString("en-US", {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
      });
    }

    getShareText() {
      const dateStr = this.formatDate(this.reservationData.date);
      return `I've made a reservation at the restaurant! 🎉 ${dateStr} at ${this.reservationData.time} for ${this.reservationData.party_size} people. Table: ${this.reservationData.table_name}. ${window.location.href}`;
    }

    shareWhatsApp() {
      const shareText = this.getShareText();
      const whatsappUrl = `https://wa.me/?text=${encodeURIComponent(
        shareText
      )}`;
      window.open(whatsappUrl, "_blank");
    }

    shareFacebook() {
      const url = encodeURIComponent(window.location.href);
      const facebookUrl = `https://www.facebook.com/sharer/sharer.php?u=${url}`;
      window.open(facebookUrl, "_blank");
    }

    copyToClipboard() {
      const shareText = this.getShareText();
      if (navigator.share) {
        // Use Web Share API if available
        navigator.share({
          title: "Restaurant Reservation",
          text: shareText,
          url: window.location.href,
        });
      } else {
        // Fallback to clipboard
        navigator.clipboard
          .writeText(shareText)
          .then(() => {
            alert("Reservation details copied to clipboard! 📋");
          })
          .catch(() => {
            alert("Please copy this message manually:\n\n" + shareText);
          });
      }
    }

    initSocialFeatures() {
      const self = this;

      // Event delegation for social buttons
      $(document).on("click", ".share-btn.whatsapp", function (e) {
        e.preventDefault();
        self.shareWhatsApp();
      });

      $(document).on("click", ".share-btn.facebook", function (e) {
        e.preventDefault();
        self.shareFacebook();
      });

      $(document).on("click", ".share-btn.copy-link", function (e) {
        e.preventDefault();
        self.copyToClipboard();
      });
    }

    // PWA Installation features
    showPWAInstallPrompt() {
      const installSection = document.getElementById("pwa-install-section");
      if (!installSection) return;

      // Check if PWA features are supported
      if ("BeforeInstallPromptEvent" in window) {
        let deferredPrompt;

        const showInstallBanner = (e) => {
          e.preventDefault();
          deferredPrompt = e;

          // Show install banner
          installSection.innerHTML = `
                        <div class="pwa-install-banner">
                            <div class="pwa-icon">📱</div>
                            <div class="pwa-content">
                                <h4>Get the App for Better Experience!</h4>
                                <p>Install our app for offline access and instant reservations</p>
                            </div>
                            <button class="btn btn-sm btn-success pwa-install-btn">Install App</button>
                        </div>
                    `;

          // Handle install button click
          const installBtn = installSection.querySelector(".pwa-install-btn");
          installBtn.addEventListener("click", () => {
            installSection.innerHTML = "";

            if (deferredPrompt) {
              // Show the install prompt
              deferredPrompt.prompt();

              // Wait for the user to respond to the prompt
              deferredPrompt.userChoice.then((choiceResult) => {
                console.log(
                  choiceResult.outcome === "accepted"
                    ? "PWA install accepted"
                    : "PWA install dismissed"
                );
                deferredPrompt = null;
              });
            }
          });
        };

        // Listen for the beforeinstallprompt event
        window.addEventListener("beforeinstallprompt", showInstallBanner);

        // Check if app is already installed
        window.addEventListener("appinstalled", () => {
          console.log("PWA was installed");
          installSection.innerHTML =
            '<div class="pwa-installed"><p><small>🎉 App installed successfully!</small></p></div>';
        });
      } else if (window.navigator.standalone === true) {
        // iOS standalone mode
        installSection.innerHTML = `
                    <div class="pwa-ios-installed">
                        <div class="pwa-icon">📱</div>
                        <p><small>You can now add this to your home screen for quick access!</small></p>
                    </div>
                `;
      } else {
        // PWA not supported - show subtle message
        installSection.innerHTML = `
                    <div class="pwa-not-supported">
                        <p><small>💡 For the best experience, visit us on mobile and save to your home screen.</small></p>
                    </div>
                `;
      }
    }
  }

  // Make available globally for the reservation wizard
  window.SocialAndPWAFeatures = SocialAndPWAFeatures;
})(jQuery);
