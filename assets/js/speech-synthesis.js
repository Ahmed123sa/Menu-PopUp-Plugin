/**
 * Menu Popup Speech Synthesis Module
 * Handles text-to-speech pronunciation on hover
 */

(function ($) {
  "use strict";

  class SpeechSynthesis {
    constructor(options = {}) {
      this.options = $.extend(
        {
          enabled: true,
          itemSelector: ".menu-item",
          nameSelector: ".menu-item-name",
          container: ".menu-popup-container",
          voiceEnabled: true,
          debug: true,
        },
        options
      );

      this.synth = null;
      this.voices = [];
      this.currentVoice = null;
      this.currentUtterance = null;
      this.hoverTimeouts = {};

      this.init();
    }

    init() {
      if (this.options.debug)
        console.log("[SpeechSynthesis] Initializing speech synthesis module");

      if (!this.checkBrowserSupport()) {
        if (this.options.debug)
          console.log(
            "[SpeechSynthesis] Browser does not support speech synthesis"
          );
        return;
      }

      this.synth = window.speechSynthesis;
      this.loadVoices();
      this.bindEvents();

      if (this.options.debug)
        console.log("[SpeechSynthesis] Speech synthesis module initialized");
    }

    checkBrowserSupport() {
      return !!window.speechSynthesis;
    }

    loadVoices() {
      // Get available voices
      let voices = this.synth.getVoices();

      if (voices.length === 0) {
        // Voices might not be loaded immediately, wait for them
        this.synth.onvoiceschanged = () => {
          voices = this.synth.getVoices();
          this.setupVoices(voices);
        };
      } else {
        this.setupVoices(voices);
      }
    }

    setupVoices(voices) {
      this.voices = voices;

      if (this.options.debug) {
        console.log("[SpeechSynthesis] Available voices:", voices);
      }

      // Find preferred voices
      this.setupPreferredVoice();

      if (this.options.debug) {
        console.log("[SpeechSynthesis] Selected voice:", this.currentVoice);
      }
    }

    setupPreferredVoice() {
      if (!this.voices.length) return;

      const currentLang = this.getCurrentLanguage();

      // Check if user has disabled speech synthesis
      if (this.getUserPreference() === false) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Speech synthesis disabled by user");
        return;
      }

      // Check for manually selected voices from settings
      const selectedArabicVoice = this.getSelectedVoice("arabic");
      const selectedEnglishVoice = this.getSelectedVoice("english");

      if (currentLang === "ar" && selectedArabicVoice) {
        // Use manually selected Arabic voice
        this.currentVoice = selectedArabicVoice;
        if (this.options.debug)
          console.log(
            "[SpeechSynthesis] Using selected Arabic voice:",
            selectedArabicVoice.name
          );
      } else if (currentLang !== "ar" && selectedEnglishVoice) {
        // Use manually selected English voice
        this.currentVoice = selectedEnglishVoice;
        if (this.options.debug)
          console.log(
            "[SpeechSynthesis] Using selected English voice:",
            selectedEnglishVoice.name
          );
      } else {
        // Fall back to automatic selection
        this.setupAutoVoice(currentLang);
      }
    }

    getSelectedVoice(language) {
      // Get selected voice from WordPress settings (passed via AJAX)
      const settingName =
        language === "arabic" ? "arabicVoice" : "englishVoice";
      const selectedVoiceName =
        typeof menuPopupAjax !== "undefined" && menuPopupAjax[settingName]
          ? menuPopupAjax[settingName]
          : "";

      if (!selectedVoiceName) return null;

      // Find the voice by name
      return (
        this.voices.find((voice) => voice.name === selectedVoiceName) || null
      );
    }

    setupAutoVoice(currentLang) {
      // Original automatic voice selection logic
      const englishVoices = this.voices.filter((voice) =>
        voice.lang.startsWith("en")
      );

      const arabicVoices = this.voices.filter(
        (voice) => voice.lang.startsWith("ar") || voice.lang.includes("Arabic")
      );

      if (currentLang === "ar" && arabicVoices.length > 0) {
        // Prefer Arabic voices for Arabic language
        this.currentVoice =
          arabicVoices.find(
            (voice) =>
              voice.name.toLowerCase().includes("female") ||
              voice.name.toLowerCase().includes("male")
          ) || arabicVoices[0];
      } else {
        // Default to English voices or first available
        this.currentVoice =
          englishVoices.find(
            (voice) =>
              voice.name.toLowerCase().includes("female") || voice.default
          ) ||
          englishVoices[0] ||
          this.voices[0];
      }

      if (this.options.debug)
        console.log(
          "[SpeechSynthesis] Using auto-selected voice:",
          this.currentVoice?.name
        );
    }

    getCurrentLanguage() {
      // Detect current language from page (same logic as voice search)
      const htmlLang = $("html").attr("lang");
      let currentLang = "en";

      if (htmlLang && htmlLang.startsWith("ar")) {
        currentLang = "ar";
      }

      // Check for Arabic content indicators
      if ($(".menu-rtl").length > 0) {
        currentLang = "ar";
      }

      // Check cookie
      const langCookie = document.cookie.match(/menu_popup_lang=([^;]*)/);
      if (langCookie && langCookie[1] === "ar") {
        currentLang = "ar";
      }

      return currentLang;
    }

    getUserPreference() {
      // Get user preference from localStorage (null = use admin setting, true/false = user override)
      const preference = localStorage.getItem(
        "menuPopupSpeechSynthesisEnabled"
      );
      if (preference === null) return null; // Use admin setting
      return preference === "true";
    }

    setUserPreference(enabled) {
      localStorage.setItem(
        "menuPopupSpeechSynthesisEnabled",
        enabled.toString()
      );
      if (this.options.debug) {
        console.log("[SpeechSynthesis] User set preference:", enabled);
      }
    }

    bindEvents() {
      // Hover events for pronunciation - icons and menu items
      $(document).on("mouseenter", this.options.itemSelector, (e) => {
        const item = $(e.currentTarget);
        this.schedulePronunciation(item);
      });

      $(document).on("mouseleave", this.options.itemSelector, (e) => {
        const item = $(e.currentTarget);
        this.cancelPronunciation(item);
      });

      // Hover events for category filter buttons pronunciation
      $(document).on("mouseenter", ".category-btn", (e) => {
        const categoryBtn = $(e.currentTarget);
        this.scheduleCategoryPronunciation(categoryBtn);
      });

      $(document).on("mouseleave", ".category-btn", (e) => {
        const categoryBtn = $(e.currentTarget);
        this.cancelCategoryPronunciation(categoryBtn);
      });

      // Handle touch events for mobile
      $(document).on("touchstart", this.options.itemSelector, (e) => {
        const item = $(e.currentTarget);
        if ($(window).width() < 768) {
          // Only trigger on mobile if enabled
          if (this.options.enableMobileHover) {
            this.schedulePronunciation(item, true);
          }
        }
      });

      $(document).on("touchend", this.options.itemSelector, (e) => {
        const item = $(e.currentTarget);
        if ($(window).width() < 768) {
          this.cancelPronunciation(item);
        }
      });

      // Language change detection
      $(document).on("click", ".language-btn", () => {
        setTimeout(() => {
          this.setupPreferredVoice();
        }, 100);
      });

      // Stop current speech when window loses focus
      $(window).on("blur", () => {
        this.stopSpeaking();
      });
    }

    schedulePronunciation(item, immediate = false) {
      const itemId = item.attr("id") || item.data("id") || "unknown";
      const delay = immediate ? 200 : 800; // Shorter delay for touch

      // Cancel any existing timeout for this item
      if (this.hoverTimeouts[itemId]) {
        clearTimeout(this.hoverTimeouts[itemId]);
      }

      this.hoverTimeouts[itemId] = setTimeout(() => {
        this.pronounceItemName(item);
        delete this.hoverTimeouts[itemId];
      }, delay);
    }

    cancelPronunciation(item) {
      const itemId = item.attr("id") || item.data("id") || "unknown";

      if (this.hoverTimeouts[itemId]) {
        clearTimeout(this.hoverTimeouts[itemId]);
        delete this.hoverTimeouts[itemId];
      }

      // Stop speaking if utterance is ongoing
      this.stopSpeaking();
    }

    scheduleCategoryPronunciation(categoryBtn, immediate = false) {
      const categoryId = categoryBtn.data("category");
      const timeoutId = `category-${categoryId}`;
      const delay = immediate ? 200 : 500; // Shorter delay for categories

      // Cancel any existing timeout for this category
      if (this.hoverTimeouts[timeoutId]) {
        clearTimeout(this.hoverTimeouts[timeoutId]);
      }

      this.hoverTimeouts[timeoutId] = setTimeout(() => {
        this.pronounceCategoryName(categoryBtn);
        delete this.hoverTimeouts[timeoutId];
      }, delay);
    }

    cancelCategoryPronunciation(categoryBtn) {
      const categoryId = categoryBtn.data("category");
      const timeoutId = `category-${categoryId}`;

      if (this.hoverTimeouts[timeoutId]) {
        clearTimeout(this.hoverTimeouts[timeoutId]);
        delete this.hoverTimeouts[timeoutId];
      }

      // Stop speaking if utterance is ongoing
      this.stopSpeaking();
    }

    pronounceCategoryName(categoryBtn) {
      // Check user preference first
      if (this.getUserPreference() === false) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Speech synthesis disabled by user");
        return;
      }

      // Don't speak if already speaking something else
      if (this.synth.speaking) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Already speaking, skipping category");
        return;
      }

      // Check if speech synthesis is allowed
      if (!this.synth) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Speech synthesis not available");
        return;
      }

      // Get the category name from button text
      const categoryText = categoryBtn.text().trim();
      const categorySlug = categoryBtn.data("category");

      // Skip "All" categories for pronunciation
      if (categorySlug === "all" || !categoryText) {
        return;
      }

      // Clean up the category name (remove extra whitespace, etc.)
      const cleanCategoryName = categoryText.replace(/\s+/g, " ").trim();

      if (this.options.debug)
        console.log(
          "[SpeechSynthesis] Pronouncing category:",
          cleanCategoryName
        );

      try {
        // Create utterance
        const utterance = new SpeechSynthesisUtterance(cleanCategoryName);

        // Configure utterance
        if (this.currentVoice) {
          utterance.voice = this.currentVoice;
        }

        utterance.rate = this.getSpeechRate();
        utterance.pitch = this.getSpeechPitch();
        utterance.volume = 0.8; // Slightly louder for categories

        // Set language based on content
        if (this.getCurrentLanguage() === "ar") {
          utterance.lang = "ar-SA"; // Saudi Arabic
          utterance.rate = 0.8; // Slower for Arabic
        } else {
          utterance.lang = "en-US"; // American English
        }

        // Event handlers
        utterance.onstart = () => {
          if (this.options.debug)
            console.log(
              "[SpeechSynthesis] Started speaking category:",
              cleanCategoryName
            );
          this.showCategoryPronunciationIndicator(categoryBtn, true);
        };

        utterance.onend = () => {
          if (this.options.debug)
            console.log(
              "[SpeechSynthesis] Finished speaking category:",
              cleanCategoryName
            );
          this.showCategoryPronunciationIndicator(categoryBtn, false);
          this.currentUtterance = null;
        };

        utterance.onerror = (event) => {
          console.log(
            "[SpeechSynthesis] Category speech synthesis error:",
            event.error
          );
          this.handleSynthError(event);
          this.showCategoryPronunciationIndicator(categoryBtn, false);
          this.currentUtterance = null;
        };

        // Store reference to current utterance
        this.currentUtterance = utterance;

        // Speak (with error handling)
        this.synth.speak(utterance);
      } catch (error) {
        console.error(
          "[SpeechSynthesis] Error creating category utterance:",
          error
        );
        this.showCategoryPronunciationIndicator(categoryBtn, false);
      }
    }

    showCategoryPronunciationIndicator(categoryBtn, show) {
      // Add visual indicator that category pronunciation is happening
      if (show) {
        categoryBtn.addClass("category-pronouncing");
        // Create a subtle visual indicator
        if (!categoryBtn.find(".category-pronunciation-indicator").length) {
          categoryBtn.append(
            '<div class="category-pronunciation-indicator" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 5L6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg></div>'
          );
        }
      } else {
        categoryBtn.removeClass("category-pronouncing");
        categoryBtn.find(".category-pronunciation-indicator").remove();
      }
    }

    async pronounceItemName(item) {
      // Check user preference first
      if (this.getUserPreference() === false) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Speech synthesis disabled by user");
        return;
      }

      // Don't speak if already speaking something else
      if (this.synth && this.synth.speaking) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Already speaking, skipping");
        return;
      }

      // Get the item name
      const nameElement = item.find(this.options.nameSelector);
      if (!nameElement.length) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] No name element found");
        return;
      }

      const itemName = nameElement.text().trim();
      if (!itemName) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Empty item name");
        return;
      }

      if (this.options.debug)
        console.log("[SpeechSynthesis] Pronouncing:", itemName);

      // Check if external TTS is enabled and available
      if (this.isExternalTTSEnabled() && this.tryExternalTTS(itemName, item)) {
        return;
      }

      // Fall back to browser speech synthesis
      this.fallbackBrowserTTS(itemName, item);
    }

    async tryExternalTTS(text, item) {
      // Check if external TTS is available
      if (
        typeof window.menuPopupExternalTTS === "undefined" ||
        !window.menuPopupExternalTTS.isAvailable()
      ) {
        return false;
      }

      try {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Using external TTS for:", text);

        // Show pronunciation indicator
        this.showPronunciationIndicator(item, true);

        // Get selected voice for current language
        const currentLang = this.getCurrentLanguage();
        const selectedVoice = this.getSelectedVoice(
          currentLang === "ar" ? "arabic" : "english"
        );
        const voiceName = selectedVoice ? selectedVoice.name : null;

        // Generate audio using external TTS
        const audioUrl = await window.menuPopupExternalTTS.generateAudio(text, {
          voice: voiceName,
          languageCode: currentLang === "ar" ? "ar-XA" : "en-US",
          speakingRate: this.getSpeechRate(),
          pitch: this.getSpeechPitch(),
        });

        // Play the audio
        const audio = new Audio(audioUrl);
        audio.volume = 0.7;

        audio.onended = () => {
          if (this.options.debug)
            console.log("[SpeechSynthesis] External TTS finished:", text);
          this.showPronunciationIndicator(item, false);
        };

        audio.onerror = (error) => {
          console.error("[SpeechSynthesis] External TTS audio error:", error);
          this.showPronunciationIndicator(item, false);
          // Fall back to browser TTS
          this.fallbackBrowserTTS(text, item);
        };

        audio.play();

        return true;
      } catch (error) {
        console.error("[SpeechSynthesis] External TTS failed:", error);
        this.showPronunciationIndicator(item, false);
        return false;
      }
    }

    fallbackBrowserTTS(itemName, item) {
      // Check if speech synthesis is allowed
      if (!this.synth) {
        if (this.options.debug)
          console.log("[SpeechSynthesis] Speech synthesis not available");
        return;
      }

      try {
        // Create utterance
        const utterance = new SpeechSynthesisUtterance(itemName);

        // Configure utterance
        if (this.currentVoice) {
          utterance.voice = this.currentVoice;
        }

        utterance.rate = this.getSpeechRate();
        utterance.pitch = this.getSpeechPitch();
        utterance.volume = 0.7; // Slightly softer volume

        // Set language based on content
        if (this.getCurrentLanguage() === "ar") {
          utterance.lang = "ar-SA"; // Saudi Arabic
          utterance.rate = 0.8; // Slower for Arabic
        } else {
          utterance.lang = "en-US"; // American English
        }

        // Event handlers
        utterance.onstart = () => {
          if (this.options.debug)
            console.log("[SpeechSynthesis] Started speaking:", itemName);
          this.showPronunciationIndicator(item, true);
        };

        utterance.onend = () => {
          if (this.options.debug)
            console.log("[SpeechSynthesis] Finished speaking:", itemName);
          this.showPronunciationIndicator(item, false);
          this.currentUtterance = null;
        };

        utterance.onerror = (event) => {
          console.log("[SpeechSynthesis] Speech synthesis error:", event.error);
          this.handleSynthError(event);
          this.showPronunciationIndicator(item, false);
          this.currentUtterance = null;
        };

        // Store reference to current utterance
        this.currentUtterance = utterance;

        // Speak (with error handling)
        this.synth.speak(utterance);
      } catch (error) {
        console.error("[SpeechSynthesis] Error creating utterance:", error);
        this.showPronunciationIndicator(item, false);
      }
    }

    handleSynthError(event) {
      const error = event.error;

      switch (error) {
        case "not-allowed":
          // User permission denied - this is expected in modern browsers
          if (this.options.debug) {
            console.log(
              "[SpeechSynthesis] Speech synthesis blocked by browser. This is normal - browsers require user interaction first."
            );
          }
          // Optionally disable this feature or show a message
          this.showPermissionRequired();
          break;
        case "network-error":
          console.error(
            "[SpeechSynthesis] Network error occurred during speech synthesis"
          );
          break;
        case "audio-busy":
          console.log(
            "[SpeechSynthesis] Audio already busy - likely another speech in progress"
          );
          break;
        case "interrupted":
          console.log("[SpeechSynthesis] Speech synthesis was interrupted");
          break;
        case "synthesis-unavailable":
          console.error(
            "[SpeechSynthesis] Speech synthesis unavailable for this utterance"
          );
          break;
        case "synthesis-failed":
          console.error("[SpeechSynthesis] Speech synthesis failed");
          break;
        default:
          console.error(
            "[SpeechSynthesis] Unknown speech synthesis error:",
            error
          );
      }
    }

    showPermissionRequired() {
      // Only show this once to avoid spam
      if (this.permissionWarned) return;

      this.permissionWarned = true;

      // Create a persistent button for permission instead of fading text
      const notice = $(`
        <div class="speech-permission-notice" style="
          position: fixed;
          top: 80px;
          right: 20px;
          z-index: 1000;
        ">
          <button class="speech-permission-btn">
            <svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 5L6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg> ${this.getLocalizedText("enable_speech")}
          </button>
        </div>
      `);

      $("body").append(notice);

      // Handle click on the button
      notice.find(".speech-permission-btn").on("click", () => {
        // Try to enable speech synthesis
        this.attemptSpeechSynthesis();
        // Always remove the notice after click (user took action)
        notice.fadeOut(() => notice.remove());
      });
    }

    attemptSpeechSynthesis() {
      // Try to speak a test utterance to trigger permission
      if (!this.synth) return;

      try {
        const testUtterance = new SpeechSynthesisUtterance(" ");
        testUtterance.volume = 0.01; // Very quiet test
        this.synth.speak(testUtterance);

        // After attempting, re-setup voices to see if permission worked
        setTimeout(() => {
          this.setupPreferredVoice();
        }, 100);
      } catch (error) {
        console.log("[SpeechSynthesis] Permission attempt failed:", error);
      }
    }

    getLocalizedText(key) {
      const messages = {
        en: {
          enable_speech: "Click here to enable voice pronunciation",
        },
        ar: {
          enable_speech: "اضغط هنا لتفعيل النطق الصوتي",
        },
      };

      const lang = this.getCurrentLanguage();
      return messages[lang]?.[key] || messages.en[key] || key;
    }

    getSpeechRate() {
      // Slower rate for better clarity
      return this.getCurrentLanguage() === "ar" ? 0.8 : 0.9;
    }

    isExternalTTSEnabled() {
      // Check if external TTS is enabled in settings
      return (
        typeof menuPopupAjax !== "undefined" &&
        menuPopupAjax.externalTtsEnabled === "1" &&
        menuPopupAjax.googleTtsApiKey &&
        menuPopupAjax.googleTtsApiKey.trim() !== ""
      );
    }

    getSpeechPitch() {
      // Slightly higher pitch for better audibility
      return this.getCurrentLanguage() === "ar" ? 1.1 : 1.0;
    }

    showPronunciationIndicator(item, show) {
      // Optional: Add visual indicator that pronunciation is happening
      if (show) {
        item.addClass("pronouncing");
        // Create a subtle visual indicator
        if (!item.find(".pronunciation-indicator").length) {
          $(`<div class="pronunciation-indicator" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 5L6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg></div>`).appendTo(item);
        }
      } else {
        item.removeClass("pronouncing");
        item.find(".pronunciation-indicator").remove();
      }
    }

    stopSpeaking() {
      if (this.synth && this.synth.speaking) {
        this.synth.cancel();
        this.currentUtterance = null;

        // Remove all pronunciation indicators
        $(this.options.itemSelector)
          .removeClass("pronouncing")
          .find(".pronunciation-indicator")
          .remove();
      }
    }

    updateLanguage(language) {
      // Force voice update when language changes
      if (language === "ar") {
        this.setupPreferredVoice();
      }
    }

    destroy() {
      // Stop any ongoing speech
      this.stopSpeaking();

      // Clear all timeouts
      Object.values(this.hoverTimeouts).forEach((timeout) => {
        clearTimeout(timeout);
      });
      this.hoverTimeouts = {};

      // Unbind events
      $(document).off("mouseenter", this.options.itemSelector);
      $(document).off("mouseleave", this.options.itemSelector);
      $(document).off("touchstart", this.options.itemSelector);
      $(document).off("touchend", this.options.itemSelector);
      $(document).off("click", ".language-btn");
      $(window).off("blur");
    }
  }

  // Initialize if enabled
  $(document).ready(function () {
    if (
      typeof menuPopupAjax !== "undefined" &&
      menuPopupAjax.pronounceOnHoverEnabled === "1"
    ) {
      window.menuPopupSpeechSynthesis = new SpeechSynthesis({
        voiceEnabled: menuPopupAjax.pronounceOnHoverEnabled === "1",
        enableMobileHover: menuPopupAjax.voiceHoverEnabledMobile === "1",
        debug: true,
      });
    }
  });
})(jQuery);
