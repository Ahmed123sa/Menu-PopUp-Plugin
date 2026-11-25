/**
 * Menu Popup Voice Search Module
 * Handles voice recognition and search integration
 */

(function ($) {
  "use strict";

  class VoiceSearch {
    constructor(options = {}) {
      this.options = $.extend(
        {
          enabled: true,
          buttonSelector: ".voice-search-btn",
          ajaxAction: "filter_menu_items",
          ajaxUrl: menuPopupAjax.ajaxurl,
          nonce: menuPopupAjax.nonce,
          container: null, // Container for scoping operations
          currentLang: "en", // Will be set dynamically
          googleSttApiKey: menuPopupAjax.googleSttApiKey || '', // Google Cloud STT API key
          debug: true,
        },
        options
      );

      // Set container or default to document body if none provided
      this.container = this.options.container
        ? $(this.options.container)
        : $(document.body);

      this.recognition = null;
      this.isListening = false;
      this.voiceButton = null;
      this.categories = []; // Store available categories
      this.useCloudStt = false; // Use Google Cloud STT fallback
      this.cloudStream = null;
      this.mediaRecorder = null;
      this.audioChunks = [];

      this.init();
    }

    init() {
      if (!this.checkBrowserSupport()) {
        this.hideVoiceButton();
        return;
      }

      this.setupVoiceRecognition();
      this.createVoiceButton();
      this.bindEvents();
      this.updateCurrentLanguage();
      this.updateCachedCategories(); // Cache categories on initialization
    }

    checkBrowserSupport() {
      // Check for SpeechRecognition support
      const hasNativeSupport = !!window.SpeechRecognition || !!window.webkitSpeechRecognition;
      
      // Allow if native support OR if Google Cloud STT API key is available
      if (!hasNativeSupport && this.options.googleSttApiKey) {
        // Will use cloud STT fallback
        return true;
      }
      
      return hasNativeSupport;
    }

    setupVoiceRecognition() {
      const SpeechRecognition =
        window.SpeechRecognition || window.webkitSpeechRecognition;

      if (!SpeechRecognition) {
        // No native support - check if Google Cloud STT is available
        if (this.options.googleSttApiKey) {
          this.useCloudStt = true;
          return;
        }
        return;
      }

      this.recognition = new SpeechRecognition();

      // Configure recognition with improved accuracy
      this.recognition.continuous = false;
      this.recognition.interimResults = false;
      this.recognition.maxAlternatives = 5; // Multiple alternatives for better accuracy
      this.recognition.lang =
        this.options.currentLang + "-" + this.options.currentLang.toUpperCase();

      // Bind event handlers
      this.recognition.onstart = () => this.onRecognitionStart();
      this.recognition.onend = () => this.onRecognitionEnd();
      this.recognition.onerror = (event) => this.onRecognitionError(event);
      this.recognition.onresult = (event) => this.onRecognitionResult(event);
    }

    createVoiceButton() {
      if (this.container.find(this.options.buttonSelector).length > 0) {
        this.voiceButton = this.container.find(this.options.buttonSelector);
        return;
      }

      // Create voice button and insert beside filters
      const voiceBtnHtml = `
                <div class="voice-search-container">
                    <button class="voice-search-btn" type="button" title="${this.getLocalizedText(
                      "voice_search_title"
                    )}" aria-label="${this.getLocalizedText(
        "voice_search_title"
      )}">
                        <span class="voice-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 1C10.34 1 9 2.34 9 4v6c0 1.66 1.34 3 3 3s3-1.34 3-3V4c0-1.66-1.34-3-3-3zm0 10.5c-1.38 0-2.5-1.12-2.5-2.5V4c0-.83.67-1.5 1.5-1.5S13.5 3.17 13.5 4v5c0 1.38-1.12 2.5-2.5 2.5z" fill="currentColor"/>
                                <path d="M17.5 7c-.28 0-.5.22-.5.5v3c0 2.48-2.02 4.5-4.5 4.5S8 12.98 8 10.5V7.5c0-.28-.22-.5-.5-.5s-.5.22-.5.5v2.5c0 3.04 2.13 5.61 5 6.32v2.68H7.5c-.28 0-.5.22-.5.5s.22.5.5.5H9v1.5c0 .28.22.5.5.5h5c.28 0 .5-.22.5-.5V16h1.5c.28 0 .5-.22.5-.5s-.22-.5-.5-.5H15v-2.68c2.87-.71 5-3.28 5-6.32V7.5c0-.28-.22-.5-.5-.5z" fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="voice-state" data-state="idle"></span>
                    </button>
                    <div class="voice-tooltip"></div>
                </div>
            `;

      // Find search buttons container and insert voice button beside search input
      const searchButtonsContainer = this.container.find(".search-buttons");
      if (searchButtonsContainer.length > 0) {
        searchButtonsContainer.append(voiceBtnHtml);
        this.voiceButton = this.container.find(this.options.buttonSelector);
      } else {
        // Fallback to category section if search buttons container not found
        const categorySection = this.container.find(".menu-categories");
        if (categorySection.length > 0) {
          categorySection.append(voiceBtnHtml);
          this.voiceButton = this.container.find(this.options.buttonSelector);
        }
      }
    }

    bindEvents() {
      // Voice button click - scoped to this container only
      this.container.on("click", this.options.buttonSelector, (e) => {
        e.preventDefault();
        this.toggleVoiceListening();
      });

      // Hide button if disabled on mobile
      $(window).on("resize", () => {
        this.handleResponsiveBehavior();
      });

      // Initial responsive check
      this.handleResponsiveBehavior();
    }

    toggleVoiceListening() {
      if (this.isListening) {
        this.stopListening();
      } else {
        this.startListening();
      }
    }

    startListening() {
      // Check if we should use cloud STT
      if (this.useCloudStt) {
        this.startCloudListening();
        return;
      }

      if (!this.recognition) {
        return;
      }

      try {
        // Update language before starting
        this.updateCurrentLanguage();

        this.isListening = true;
        this.updateButtonState("listening");
        this.recognition.start();
      } catch (error) {
        this.handleError("recognition_start_failed");
      }
    }

    stopListening() {
      if (this.recognition && this.isListening) {
        this.isListening = false;
        this.updateButtonState("idle");
        this.recognition.stop();
      }
    }

    // Cloud STT Methods (Google Cloud Speech-to-Text)
    startCloudListening() {
      if (!this.options.googleSttApiKey) {
        this.handleError("recognition_start_failed");
        return;
      }

      this.isListening = true;
      this.updateButtonState("listening");
      this.showFeedback("listening", this.getLocalizedText("listening_message"));

      // Use MediaRecorder API to capture audio
      if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices
          .getUserMedia({ audio: true })
          .then((stream) => {
            this.cloudStream = stream;
            this.audioChunks = [];
            this.mediaRecorder = new MediaRecorder(stream);

            this.mediaRecorder.ondataavailable = (event) => {
              this.audioChunks.push(event.data);
            };

            this.mediaRecorder.onstop = () => {
              this.processCloudAudio();
            };

            this.mediaRecorder.start();
          })
          .catch((error) => {
            this.handleError("audio_capture_error");
            this.isListening = false;
            this.updateButtonState("idle");
          });
      } else {
        this.handleError("audio_capture_error");
        this.isListening = false;
        this.updateButtonState("idle");
      }
    }

    stopCloudListening() {
      if (this.mediaRecorder && this.mediaRecorder.state === "recording") {
        this.mediaRecorder.stop();
      }
      if (this.cloudStream) {
        this.cloudStream.getTracks().forEach((track) => track.stop());
      }
      this.isListening = false;
      this.updateButtonState("idle");
      this.hideFeedback();
    }

    processCloudAudio() {
      if (this.audioChunks.length === 0) {
        this.handleError("no_speech_detected");
        return;
      }

      this.updateButtonState("processing");
      this.showFeedback("processing", this.getLocalizedText("processing_message"));

      const audioBlob = new Blob(this.audioChunks, { type: "audio/webm" });
      const reader = new FileReader();

      reader.onload = () => {
        const base64Audio = reader.result.split(",")[1];
        this.sendToGoogleCloudSTT(base64Audio);
      };

      reader.readAsDataURL(audioBlob);
    }

    sendToGoogleCloudSTT(base64Audio) {
      const langCode = this.options.currentLang === "ar" ? "ar-SA" : "en-US";

      const requestData = {
        config: {
          encoding: "WEBM_OPUS",
          sampleRateHertz: 48000,
          languageCode: langCode,
        },
        audio: {
          content: base64Audio,
        },
      };

      fetch(
        `https://speech.googleapis.com/v1/speech:recognize?key=${this.options.googleSttApiKey}`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify(requestData),
        }
      )
        .then((response) => response.json())
        .then((data) => {
          if (data.results && data.results.length > 0) {
            const transcript = data.results[0].alternatives[0].transcript;
            this.onCloudRecognitionResult(transcript);
          } else {
            // No results - show full confirmation dialog
            this.showConfirmationDialog("", []);
          }
        })
        .catch((error) => {
          console.error("Google Cloud STT Error:", error);
          this.handleError("network_error");
        });
    }

    onCloudRecognitionResult(transcript) {
      // Process the result similar to native API
      if (transcript) {
        const primaryTranscript = transcript.toLowerCase().trim();
        this.trySmartAutoMatch(primaryTranscript, [primaryTranscript]);
      } else {
        this.handleError("no_speech_detected");
      }
    }

    // Override stopListening to handle cloud STT
    stopListening() {
      if (this.useCloudStt) {
        this.stopCloudListening();
        return;
      }

      if (this.recognition && this.isListening) {
        this.isListening = false;
        this.updateButtonState("idle");
        this.recognition.stop();
      }
    }

    onRecognitionStart() {
      this.updateButtonState("listening");
      this.showFeedback(
        "listening",
        this.getLocalizedText("listening_message")
      );
    }

    onRecognitionEnd() {
      this.isListening = false;
      this.updateButtonState("idle");
      this.hideFeedback();
    }

    onRecognitionError(event) {
      let errorType = "generic_error";

      switch (event.error) {
        case "not-allowed":
          errorType = "microphone_permission_denied";
          break;
        case "network":
          errorType = "network_error";
          break;
        case "audio-capture":
          errorType = "audio_capture_error";
          break;
        case "no-speech":
          errorType = "no_speech_detected";
          break;
      }

      this.handleError(errorType);
    }

    onRecognitionResult(event) {
      // Get the primary transcript
      const primaryTranscript = event.results[0][0].transcript
        .toLowerCase()
        .trim();

      // Collect all alternatives up to maxAlternatives (5)
      const alternatives = [];
      for (
        let i = 0;
        i < Math.min(event.results[0].length, this.recognition.maxAlternatives);
        i++
      ) {
        const altTranscript = event.results[0][i].transcript
          .toLowerCase()
          .trim();
        if (altTranscript) {
          alternatives.push(altTranscript);
        }
      }

      if (primaryTranscript) {
        // Try automatic matching first - only show dialog if uncertain
        this.trySmartAutoMatch(primaryTranscript, alternatives);
      }
    }

    processVoiceInput(transcript) {
      // Normalize transcript for better matching
      const normalizedText = this.normalizeTranscript(transcript);

      // Determine if it's a category or item search
      const searchType = this.determineSearchType(normalizedText);

      // Perform search
      this.performVoiceSearch(searchType, normalizedText);
    }

    normalizeTranscript(transcript) {
      // Remove common filler words and normalize
      const fillers = [
        "the",
        "a",
        "an",
        "some",
        "please",
        "can you",
        "find me",
        "search for",
        "show me",
      ];
      let normalized = transcript.toLowerCase();

      fillers.forEach((word) => {
        normalized = normalized.replace(
          new RegExp("\\b" + word + "\\b", "g"),
          ""
        );
      });

      // Clean up extra spaces
      normalized = normalized.replace(/\s+/g, " ").trim();

      // Handle Arabic number conversion if needed
      // (Arabic speakers sometimes use Arabic numerals)

      return normalized;
    }

    determineSearchType(text) {
      // Update cached categories if needed
      this.updateCachedCategories();

      // Check for common category phrases first
      const categoryPhrases = [
        "show me",
        "find me",
        "give me",
        "i want",
        "get me",
        "filter by",
        "category",
        "all",
        "everything",
        "ستشو",
        "أعطني",
        "بحث عن",
        "فلتر",
      ];

      // Check for category keywords in the text
      let isCategorySearch = false;
      categoryPhrases.forEach((phrase) => {
        if (text.includes(phrase.toLowerCase())) {
          isCategorySearch = true;
        }
      });

      let bestMatch = null;
      let bestSimilarity = 0;

      // Enhanced category matching
      for (const category of this.categories) {
        const categoryName = category.name
          ? category.name.toLowerCase()
          : category.slug.toLowerCase();
        const categorySlug = category.slug.toLowerCase();

        // Direct matches have highest priority
        if (text.includes(categoryName) || text.includes(categorySlug)) {
          return {
            type: "category",
            value: category.slug,
            matchedText: text,
            categoryName: category.name || category.slug,
          };
        }

        // Check for partial word matches
        const words = text.split(" ");
        for (const word of words) {
          if (word.length > 2) {
            // Only check words longer than 2 characters
            if (categoryName.includes(word) || word.includes(categorySlug)) {
              return {
                type: "category",
                value: category.slug,
                matchedText: text,
                categoryName: category.name || category.slug,
              };
            }
          }
        }

        // Fuzzy similarity matching
        const similarity = this.calculateSimilarity(text, categoryName);
        if (similarity > bestSimilarity && similarity > 0.4) {
          // More lenient threshold
          bestSimilarity = similarity;
          bestMatch = category;
        }
      }

      // If we have a reasonably good fuzzy match and it seems like a category search
      if (bestMatch && bestSimilarity > 0.6) {
        return {
          type: "category",
          value: bestMatch.slug,
          matchedText: text,
          categoryName: bestMatch.name || bestMatch.slug,
        };
      }

      // Check for all/everything variations
      if (
        text.includes("all") ||
        text.includes("everything") ||
        text.includes("كل") ||
        text.includes("الكل") ||
        text.includes("جميع")
      ) {
        return {
          type: "category",
          value: "all",
          matchedText: text,
          categoryName: "All",
        };
      }

      // If it seems like a category search but no match, check if it's a plural/related term
      if (isCategorySearch) {
        // Try removing common endings for possible match
        const modifiedText = text.replace(/s$|es$|ies$/, ""); // Remove simple English plurals
        for (const category of this.categories) {
          const categoryName = category.name
            ? category.name.toLowerCase()
            : category.slug.toLowerCase();
          if (modifiedText.includes(categoryName)) {
            return {
              type: "category",
              value: category.slug,
              matchedText: text,
              categoryName: category.name || category.slug,
            };
          }
        }
      }

      // Default to item search
      return {
        type: "item",
        value: text,
        matchedText: text,
      };
    }

    isSimilarCategory(text, category) {
      // Simple similarity check - could be enhanced
      const similarityThreshold = 0.6;
      return this.calculateSimilarity(text, category) > similarityThreshold;
    }

    updateCachedCategories() {
      // Cache categories for faster access and better debugging
      this.categories = [];

      // Scope category search to this.container
      this.container
        .find(".category-btn[data-category]")
        .each((index, element) => {
          const $element = $(element);
          const categorySlug = $element.data("category");

          if (categorySlug && categorySlug !== "all") {
            // Try to get the category name from button text or data attribute
            const categoryName =
              $element.text().trim() ||
              $element.data("category-name") ||
              categorySlug;

            this.categories.push({
              slug: categorySlug,
              name: categoryName.toLowerCase(),
              index: index,
            });
          }
        });
    }

    calculateSimilarity(text1, text2) {
      // Simple Jaccard similarity
      const words1 = new Set(text1.split(" "));
      const words2 = new Set(text2.split(" "));

      const intersection = new Set([...words1].filter((x) => words2.has(x)));
      const union = new Set([...words1, ...words2]);

      return intersection.size / union.size;
    }

    performVoiceSearch(searchType, query) {
      // Show feedback
      this.showFeedback(
        "processing",
        this.getLocalizedText("searching_message")
      );

      // Create filter based on search type
      let category = "all";

      if (searchType.type === "category") {
        category = searchType.value;
      }

      // For item searches, we need to use AJAX filtering
      // Store the voice search data for processing in AJAX response
      sessionStorage.setItem("voiceSearchActive", "true");
      sessionStorage.setItem("voiceSearchType", searchType.type);
      sessionStorage.setItem("voiceSearchQuery", query);

      // Perform AJAX filter with the determined category or trigger backend search
      this.triggerAjaxFilter(searchType);
    }

    triggerAjaxFilter(searchType) {
      const container = this.container;

      // Show loading state
      container.addClass("loading");

      // Determine category to filter by
      let filterCategory = "all";
      if (searchType.type === "category") {
        filterCategory = searchType.value;
        // Also store item search parameters for backend processing
        sessionStorage.setItem("itemSearchQuery", searchType.matchedText);
      }

      // Get menu context from container data attribute
      const menuContext = this.container.data("menu") || "all";

      $.ajax({
        url: this.options.ajaxUrl,
        type: "POST",
        data: {
          action: this.options.ajaxAction,
          category: filterCategory,
          menu: menuContext,
          voice_search: "true",
          voice_search_type: searchType.type,
          voice_search_query: searchType.matchedText,
          nonce: this.options.nonce,
        },
        success: (response) => {
          if (response.success) {
            this.handleSearchResults(response.data, searchType);
          } else {
            this.handleError("search_failed");
          }
        },
        error: (jqXHR, textStatus, errorThrown) => {
          this.handleError("network_error");
        },
        complete: () => {
          container.removeClass("loading");
        },
      });
    }

    handleSearchResults(data, searchType) {
      // Replace menu grid content - scope to this container
      this.container.find(".menu-grid").html(data);

      // Update active category button - scope to this container
      this.container.find(".category-btn").removeClass("active");
      if (searchType.type === "category") {
        this.container
          .find(`.category-btn[data-category="${searchType.value}"]`)
          .addClass("active");
      } else {
        // For item searches, keep current category active or mark as search
        this.container
          .find('.category-btn[data-category="all"]')
          .addClass("active");
      }

      // NOTE: Video popup events are now handled by container-wide event delegation in menu-popup-frontend.js
      // DO NOT manually rebind events here - it breaks the delegation system!

      // Show success feedback
      this.showFeedback(
        "success",
        this.getLocalizedText("search_success_message")
      );

      // Clear voice search session data
      sessionStorage.removeItem("voiceSearchActive");
      sessionStorage.removeItem("voiceSearchType");
      sessionStorage.removeItem("voiceSearchQuery");
      sessionStorage.removeItem("itemSearchQuery");
    }

    updateButtonState(state) {
      if (!this.voiceButton) return;

      const voiceState = this.voiceButton.find(".voice-state");
      const voiceIcon = this.voiceButton.find(".voice-icon");

      voiceState.attr("data-state", state);

      switch (state) {
        case "listening":
          voiceIcon.html('<span class="red-dot"></span>'); // Professional red indicator
          this.voiceButton.addClass("listening");
          break;
        case "processing":
          voiceIcon.html('<span class="spin">⏳</span>'); // Hourglass for processing
          break;
        case "success":
          voiceIcon.html("✓"); // Professional check mark
          setTimeout(() => this.updateButtonState("idle"), 2000);
          break;
        case "error":
          voiceIcon.html("⚠"); // Professional warning symbol
          setTimeout(() => this.updateButtonState("idle"), 2000);
          break;
        default:
          // Return to original microphone SVG icon
          voiceIcon.html(`
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M12 1C10.34 1 9 2.34 9 4v6c0 1.66 1.34 3 3 3s3-1.34 3-3V4c0-1.66-1.34-3-3-3zm0 10.5c-1.38 0-2.5-1.12-2.5-2.5V4c0-.83.67-1.5 1.5-1.5S13.5 3.17 13.5 4v5c0 1.38-1.12 2.5-2.5 2.5z" fill="currentColor"/>
              <path d="M17.5 7c-.28 0-.5.22-.5.5v3c0 2.48-2.02 4.5-4.5 4.5S8 12.98 8 10.5V7.5c0-.28-.22-.5-.5-.5s-.5.22-.5.5v2.5c0 3.04 2.13 5.61 5 6.32v2.68H7.5c-.28 0-.5.22-.5.5s.22.5.5.5H9v1.5c0 .28.22.5.5.5h5c.28 0 .5-.22.5-.5V16h1.5c.28 0 .5-.22.5-.5s-.22-.5-.5-.5H15v-2.68c2.87-.71 5-3.28 5-6.32V7.5c0-.28-.22-.5-.5-.5z" fill="currentColor"/>
            </svg>
          `);
          this.voiceButton.removeClass("listening");
      }
    }

    showFeedback(type, message) {
      const tooltip = this.voiceButton
        .closest(".voice-search-container")
        .find(".voice-tooltip");

      tooltip.text(message).show();

      if (type !== "listening") {
        setTimeout(() => this.hideFeedback(), 3000);
      }
    }

    hideFeedback() {
      const tooltip = this.voiceButton
        .closest(".voice-search-container")
        .find(".voice-tooltip");
      tooltip.hide();
    }

    handleError(errorType) {
      const message =
        this.getLocalizedText(`error_${errorType}`) ||
        this.getLocalizedText("error_generic");
      this.showFeedback("error", message);
      this.updateButtonState("error");
    }

    updateCurrentLanguage() {
      // Detect current language from page (check for lang attribute or URL)
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

      this.options.currentLang = currentLang;

      if (this.recognition) {
        this.recognition.lang = currentLang + "-" + currentLang.toUpperCase();
      }
    }

    handleResponsiveBehavior() {
      // The voice search button follows the "Enable Voice Search" setting only.
      // "Hover pronunciation on mobile" belongs to speech-synthesis.js and must not
      // hide this button on phones. Unsupported browsers are handled in init().
      this.showVoiceButton();
    }

    hideVoiceButton() {
      if (this.voiceButton) {
        this.voiceButton.closest(".voice-search-container").hide();
      }
    }

    showVoiceButton() {
      if (this.voiceButton) {
        this.voiceButton.closest(".voice-search-container").show();
      }
    }

    // Show confirmation dialog with transcript alternatives
    showConfirmationDialog(primaryTranscript, alternatives) {
      // Create or update confirmation dialog
      let confirmDialog = $("#voice-confirmation-dialog");

      if (confirmDialog.length === 0) {
        // Create new dialog
        const dialogHtml = `
          <div id="voice-confirmation-dialog" class="voice-confirmation-dialog">
            <div class="voice-confirmation-overlay"></div>
            <div class="voice-confirmation-content">
              <div class="voice-confirmation-header">
                <div class="voice-confirmation-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg></div>
                <h3>${this.getLocalizedText("confirm_search_title")}</h3>
                <button class="voice-confirmation-close">&times;</button>
              </div>
              <div class="voice-confirmation-body">
                <div class="voice-transcript-display">
                  <p class="voice-transcript-label">${this.getLocalizedText(
                    "did_you_say"
                  )}:</p>
                  <div class="voice-transcript-input-container">
                    <input type="text" id="voice-transcript-input" class="voice-transcript-input"
                           value="${primaryTranscript}" autocomplete="off">
                    <button id="voice-transcript-clear" class="voice-transcript-clear" title="${this.getLocalizedText(
                      "clear"
                    )}">&times;</button>
                  </div>
                </div>
                <div class="voice-alternatives">
                  <p class="voice-alternatives-label">${this.getLocalizedText(
                    "alternatives"
                  )}:</p>
                  <div class="voice-alternatives-list">
                    ${alternatives
                      .slice(0, 4)
                      .map(
                        (alt, index) =>
                          `<button class="voice-alternative-btn" data-transcript="${alt}" ${
                            index === 0 ? 'data-primary="true"' : ""
                          }>${alt}</button>`
                      )
                      .join("")}
                  </div>
                </div>
                <div class="voice-confirmation-actions">
                  <button class="voice-action-btn voice-retry-btn">${this.getLocalizedText(
                    "say_again"
                  )}</button>
                  <button class="voice-action-btn voice-confirm-btn voice-primary-action">${this.getLocalizedText(
                    "search"
                  )}</button>
                </div>
              </div>
            </div>
          </div>
        `;

        $(document.body).append(dialogHtml);
        confirmDialog = $("#voice-confirmation-dialog");

        // Bind dialog events
        this.bindConfirmationDialogEvents();
      } else {
        // Update existing dialog
        const input = confirmDialog.find("#voice-transcript-input");
        input.val(primaryTranscript);

        // Update alternatives (first 4)
        const altContainer = confirmDialog.find(".voice-alternatives-list");
        altContainer.html(
          alternatives
            .slice(0, 4)
            .map(
              (alt, index) =>
                `<button class="voice-alternative-btn" data-transcript="${alt}" ${
                  index === 0 ? 'data-primary="true"' : ""
                }>${alt}</button>`
            )
            .join("")
        );

        // Re-bind alternative buttons
        this.bindAlternativeButtons();
        confirmDialog.show();
      }

      // Focus the input field for easy editing
      setTimeout(() => {
        confirmDialog.find("#voice-transcript-input").focus().select();
      }, 100);
    }

    // Bind confirmation dialog events
    bindConfirmationDialogEvents() {
      $(document).on("click", ".voice-confirmation-close", () => {
        this.hideConfirmationDialog();
      });

      $(document).on("click", ".voice-confirmation-overlay", () => {
        this.hideConfirmationDialog();
      });

      $(document).on("keydown", (e) => {
        if (e.keyCode === 27) {
          // ESC key
          this.hideConfirmationDialog();
        } else if (e.keyCode === 13) {
          // Enter key
          if (!$("#voice-confirmation-dialog").hasClass("hidden")) {
            this.confirmVoiceSearch();
          }
        }
      });

      // Clear button
      $(document).on("click", "#voice-transcript-clear", () => {
        $("#voice-transcript-input").val("").focus();
      });

      // Confirm button
      $(document).on("click", ".voice-confirm-btn", () => {
        this.confirmVoiceSearch();
      });

      // Retry button
      $(document).on("click", ".voice-retry-btn", () => {
        this.hideConfirmationDialog();
        this.startListening();
      });

      // Bind alternative buttons
      this.bindAlternativeButtons();
    }

    // Bind alternative transcript buttons
    bindAlternativeButtons() {
      $(document)
        .off("click.voiceAlternatives")
        .on("click.voiceAlternatives", ".voice-alternative-btn", (e) => {
          e.preventDefault();
          const transcript = $(e.currentTarget).data("transcript");
          $("#voice-transcript-input").val(transcript);
        });
    }

    // Hide confirmation dialog
    hideConfirmationDialog() {
      $("#voice-confirmation-dialog").hide();
    }

    // Confirm and proceed with voice search
    confirmVoiceSearch() {
      const transcript = $("#voice-transcript-input").val().trim();
      if (transcript) {
        this.hideConfirmationDialog();
        // Process the confirmed transcript
        this.processVoiceInput(transcript);
      }
    }

    // Improved fuzzy matching with Levenshtein distance
    calculateLevenshteinDistance(str1, str2) {
      const matrix = [];
      const len1 = str1.length;
      const len2 = str2.length;

      // Initialize matrix
      for (let i = 0; i <= len2; i++) {
        matrix[i] = [i];
      }
      for (let j = 0; j <= len1; j++) {
        matrix[0][j] = j;
      }

      // Fill matrix
      for (let i = 1; i <= len2; i++) {
        for (let j = 1; j <= len1; j++) {
          if (str2.charAt(i - 1) === str1.charAt(j - 1)) {
            matrix[i][j] = matrix[i - 1][j - 1];
          } else {
            matrix[i][j] = Math.min(
              matrix[i - 1][j - 1] + 1, // substitution
              matrix[i][j - 1] + 1, // insertion
              matrix[i - 1][j] + 1 // deletion
            );
          }
        }
      }

      return matrix[len2][len1];
    }

    // Calculate similarity score (0-1, higher is better)
    calculateSimilarityScore(searchTerm, targetTerm) {
      const searchLower = searchTerm.toLowerCase();
      const targetLower = targetTerm.toLowerCase();

      // Exact match
      if (searchLower === targetLower) {
        return 1.0;
      }

      // Contains
      if (
        targetLower.includes(searchLower) ||
        searchLower.includes(targetLower)
      ) {
        return 0.8;
      }

      // Word overlap (Jaccard similarity)
      const searchWords = new Set(searchLower.split(" "));
      const targetWords = new Set(targetLower.split(" "));

      if (searchWords.size > 0 && targetWords.size > 0) {
        const intersection = new Set(
          [...searchWords].filter((x) => targetWords.has(x))
        );
        const union = new Set([...searchWords, ...targetWords]);
        const jaccard = intersection.size / union.size;

        if (jaccard > 0.5) {
          return Math.min(0.9, jaccard + 0.1);
        }
      }

      // Levenshtein distance similarity
      const distance = this.calculateLevenshteinDistance(
        searchLower,
        targetLower
      );
      const maxLen = Math.max(searchLower.length, targetLower.length);

      if (maxLen === 0) return 1.0;

      const similarity = 1 - distance / maxLen;

      // Apply threshold - only return if significant similarity
      return similarity > 0.6 ? similarity : 0;
    }

    // Smart auto-matching: decides whether to proceed directly or show confirmation dialog
    trySmartAutoMatch(primaryTranscript, alternatives) {
      // Check if confirmations are enabled for clear voice catches
      const confirmationsEnabled =
        typeof menuPopupAjax !== "undefined" &&
        menuPopupAjax.voiceConfirmCorrectCatchesEnabled === "1";

      // First try direct category matching with primary transcript
      const primarySearchType = this.determineSearchType(primaryTranscript);

      // Check if it's a clear category match
      if (primarySearchType.type === "category") {
        if (confirmationsEnabled) {
          // Show quick confirmation for category
          this.showCategoryConfirmation(primarySearchType);
        } else {
          // Skip confirmation and proceed directly
          this.performVoiceSearch(primarySearchType, primaryTranscript);
        }
        return;
      }

      // For item searches, try to find exact or high-confidence matches
      const directMatches = this.findDirectItemMatches(primaryTranscript);

      if (directMatches.exactMatches.length > 0) {
        // Exact item match - check confirmation setting
        if (confirmationsEnabled) {
          // Show confirmation dialog for exact matches
          this.showQuickItemConfirmation(
            primaryTranscript,
            directMatches.exactMatches[0],
            true // Pass flag indicating this is an exact match
          );
        } else {
          // Proceed directly without confirmation
          this.performVoiceSearch(
            {
              type: "item",
              value: primaryTranscript,
              matchedText: primaryTranscript,
            },
            primaryTranscript
          );
        }
        return;
      }

      if (directMatches.highConfidenceMatches.length > 0) {
        // High confidence fuzzy match (80%+)
        // Check if confirmations are enabled for clear voice catches
        const confirmationsEnabled =
          typeof menuPopupAjax !== "undefined" &&
          menuPopupAjax.voiceConfirmCorrectCatchesEnabled === "1";

        if (confirmationsEnabled) {
          this.showQuickItemConfirmation(
            primaryTranscript,
            directMatches.highConfidenceMatches[0]
          );
        } else {
          // Skip confirmation and proceed directly
          const bestMatch = directMatches.highConfidenceMatches[0];
          const searchTerm =
            bestMatch.matchType === "name"
              ? bestMatch.item.name
              : bestMatch.item.description;
          this.performVoiceSearch(
            { type: "item", value: searchTerm, matchedText: searchTerm },
            searchTerm
          );
        }
        return;
      }

      // No clear matches found - try alternatives
      let bestAlternative = null;
      let bestAltMatches = null;

      for (const alt of alternatives.slice(1, 5)) {
        // Check up to 4 alternatives (skip primary)
        const altMatches = this.findDirectItemMatches(alt);
        if (altMatches.exactMatches.length > 0) {
          bestAlternative = alt;
          bestAltMatches = altMatches;
          break; // Found exact match, use it
        }
        if (altMatches.highConfidenceMatches.length > 0 && !bestAlternative) {
          bestAlternative = alt;
          bestAltMatches = altMatches;
        }
      }

      if (bestAlternative) {
        // Check if confirmations are enabled for clear voice catches
        const confirmationsEnabled =
          typeof menuPopupAjax !== "undefined" &&
          menuPopupAjax.voiceConfirmCorrectCatchesEnabled === "1";

        if (confirmationsEnabled) {
          this.showQuickItemConfirmation(
            bestAlternative,
            bestAltMatches.highConfidenceMatches[0]
          );
        } else {
          // Skip confirmation and proceed directly
          const bestMatch = bestAltMatches.highConfidenceMatches[0];
          const searchTerm =
            bestMatch.matchType === "name"
              ? bestMatch.item.name
              : bestMatch.item.description;
          this.performVoiceSearch(
            { type: "item", value: searchTerm, matchedText: searchTerm },
            searchTerm
          );
        }
        return;
      }

      // No good matches found - show full confirmation dialog
      this.showConfirmationDialog(primaryTranscript, alternatives);
    }

    // Find direct matches for items (exact and high confidence fuzzy) - scoped to this container
    findDirectItemMatches(searchTerm) {
      const exactMatches = [];
      const highConfidenceMatches = [];
      const normalizedSearch = this.normalizeTranscript(searchTerm);

      // Update cached items - scope to this.container
      if (!this.menuItemsCache) {
        this.menuItemsCache = [];
        this.container.find(".menu-item").each((index, element) => {
          const $element = $(element);
          const itemName =
            $element.find(".menu-item-name").text().trim().toLowerCase() ||
            $element.data("name") ||
            "";
          const itemDesc =
            $element
              .find(".menu-item-description")
              .text()
              .trim()
              .toLowerCase() ||
            $element.data("description") ||
            "";

          if (itemName) {
            this.menuItemsCache.push({
              name: itemName,
              description: itemDesc,
              element: $element,
            });
          }
        });
      }

      // Check for direct matches
      this.menuItemsCache.forEach((item) => {
        const nameSimilarity = this.calculateSimilarityScore(
          normalizedSearch,
          item.name
        );
        const descSimilarity = item.description
          ? this.calculateSimilarityScore(normalizedSearch, item.description)
          : 0;

        const highestSimilarity = Math.max(nameSimilarity, descSimilarity);

        if (highestSimilarity === 1.0) {
          // Exact match
          exactMatches.push({
            item: item,
            similarity: 1.0,
            matchType:
              nameSimilarity >= descSimilarity ? "name" : "description",
          });
        } else if (highestSimilarity >= 0.8) {
          // High confidence (80%+ similarity)
          highConfidenceMatches.push({
            item: item,
            similarity: highestSimilarity,
            matchType:
              nameSimilarity >= descSimilarity ? "name" : "description",
          });
        }
      });

      return {
        exactMatches: exactMatches,
        highConfidenceMatches: highConfidenceMatches,
      };
    }

    // Show quick confirmation for category matches
    showCategoryConfirmation(searchType) {
      // Create simple confirmation dialog for categories
      const dialogHtml = `
        <div id="voice-category-confirm-dialog" class="voice-category-confirm-dialog">
          <div class="voice-category-overlay"></div>
          <div class="voice-category-content">
            <div class="voice-category-message">
              ${this.getLocalizedText("go_to_category")} ${
        searchType.categoryName
      }?
            </div>
            <div class="voice-category-actions">
              <button class="voice-category-yes">${this.getLocalizedText(
                "yes"
              )}</button>
              <button class="voice-category-no">${this.getLocalizedText(
                "no_try_again"
              )}</button>
            </div>
          </div>
        </div>
      `;

      $(document.body).append(dialogHtml);

      // Bind events
      $(document).one("click", ".voice-category-yes", () => {
        $("#voice-category-confirm-dialog").remove();
        this.performVoiceSearch(searchType, searchType.matchedText);
      });

      $(document).one("click", ".voice-category-no", () => {
        $("#voice-category-confirm-dialog").remove();
        this.startListening();
      });

      // Auto-remove after 5 seconds
      setTimeout(() => {
        $("#voice-category-confirm-dialog").fadeOut(() => {
          $(this).remove();
        });
      }, 5000);
    }

    // Show quick confirmation for high-confidence item matches
    showQuickItemConfirmation(transcript, bestMatch) {
      const dialogHtml = `
        <div id="voice-item-confirm-dialog" class="voice-item-confirm-dialog">
          <div class="voice-item-overlay"></div>
          <div class="voice-item-content">
            <div class="voice-item-message">
              ${this.getLocalizedText("did_you_mean")} "${bestMatch.item.name}"?
            </div>
            <div class="voice-item-actions">
              <button class="voice-item-yes">${this.getLocalizedText(
                "yes"
              )}</button>
              <button class="voice-item-edit">${this.getLocalizedText(
                "edit"
              )}</button>
            </div>
          </div>
        </div>
      `;

      $(document.body).append(dialogHtml);

      // Bind events
      $(document).one("click", ".voice-item-yes", () => {
        $("#voice-item-confirm-dialog").remove();
        // Search for the matched item
        const searchTerm =
          bestMatch.matchType === "name"
            ? bestMatch.item.name
            : bestMatch.item.description;
        this.performVoiceSearch(
          { type: "item", value: searchTerm, matchedText: searchTerm },
          searchTerm
        );
      });

      $(document).one("click", ".voice-item-edit", () => {
        $("#voice-item-confirm-dialog").remove();
        // Show full confirmation dialog
        this.showConfirmationDialog(transcript);
      });

      // Auto-proceed after 3 seconds
      setTimeout(() => {
        $("#voice-item-confirm-dialog").fadeOut(() => {
          $(this).remove();
          // Auto-proceed with the match
          const searchTerm =
            bestMatch.matchType === "name"
              ? bestMatch.item.name
              : bestMatch.item.description;
          this.performVoiceSearch(
            { type: "item", value: searchTerm, matchedText: searchTerm },
            searchTerm
          );
        });
      }, 3000);
    }

    // Find fuzzy matches for item search - scoped to this container
    findFuzzyMatches(searchTerm) {
      const matches = [];
      const minSimilarity = 0.4;

      // Cache all menu items for fuzzy matching - scope to this.container
      if (!this.menuItemsCache) {
        this.menuItemsCache = [];
        this.container.find(".menu-item").each((index, element) => {
          const $element = $(element);
          const itemId = $element.data("item-id") || $element.attr("id");
          const itemName =
            $element.find(".menu-item-name").text().trim().toLowerCase() ||
            $element.data("name") ||
            "";
          const itemDesc =
            $element
              .find(".menu-item-description")
              .text()
              .trim()
              .toLowerCase() ||
            $element.data("description") ||
            "";

          if (itemName) {
            this.menuItemsCache.push({
              id: itemId,
              name: itemName,
              description: itemDesc,
              element: $element,
            });
          }
        });
      }

      // Find matches
      this.menuItemsCache.forEach((item) => {
        const nameScore = this.calculateSimilarityScore(searchTerm, item.name);
        const descScore = item.description
          ? this.calculateSimilarityScore(searchTerm, item.description)
          : 0;

        const bestScore = Math.max(nameScore, descScore);

        if (bestScore >= minSimilarity) {
          matches.push({
            item: item,
            score: bestScore,
            matchType: nameScore >= descScore ? "name" : "description",
          });
        }
      });

      // Sort by similarity score (highest first)
      matches.sort((a, b) => b.score - a.score);

      return matches;
    }

    getLocalizedText(key) {
      // Basic localization - could be enhanced with WP i18n
      const messages = {
        en: {
          voice_search_title: "Voice Search",
          listening_message: "Listening...",
          processing_message: "Processing...",
          searching_message: "Searching...",
          search_success_message: "Search completed",
          confirm_search_title: "Confirm Voice Search",
          did_you_say: "Did you say:",
          alternatives: "Or did you mean:",
          clear: "Clear text",
          say_again: "Try Again",
          search: "Search",
          go_to_category: "Go to category",
          yes: "Yes",
          no_try_again: "No, try again",
          did_you_mean: "Did you mean",
          for_sure: "Yes",
          edit: "Edit",
          error_recognition_start_failed: "Could not start voice recognition",
          error_microphone_permission_denied: "Microphone permission denied",
          error_network_error: "Network error occurred",
          error_audio_capture_error: "Audio capture error",
          error_no_speech_detected: "No speech detected",
          error_search_failed: "Search failed, please try again",
          error_network_error: "Network error, please try again",
          error_generic: "An error occurred, please try again",
        },
        ar: {
          voice_search_title: "البحث الصوتي",
          listening_message: "يستمع...",
          processing_message: "جارٍ المعالجة...",
          searching_message: "يبحث...",          search_success_message: "انتهى البحث",
          confirm_search_title: "تأكيد البحث الصوتي",
          did_you_say: "هل قلت:",
          alternatives: "أم تقصد:",
          clear: "مسح النص",
          say_again: "جرب مرة أخرى",
          search: "بحث",
          go_to_category: "الذهاب إلى فئة",
          yes: "نعم",
          no_try_again: "لا، جرب مرة أخرى",
          did_you_mean: "هل تقصد",
          for_sure: "نعم",
          edit: "تحرير",
          error_recognition_start_failed: "تعذر بدء التعرف على الصوت",
          error_microphone_permission_denied: "تم رفض إذن الميكروفون",
          error_network_error: "حدث خطأ في الشبكة",
          error_audio_capture_error: "خطأ في التقاط الصوت",
          error_no_speech_detected: "لم يتم الكشف عن صوت",
          error_search_failed: "فشل البحث، يرجى المحاولة مرة أخرى",
          error_network_error: "خطأ في الشبكة، يرجى المحاولة مرة أخرى",
          error_generic: "حدث خطأ، يرجى المحاولة مرة أخرى",
        },
      };

      return (
        messages[this.options.currentLang]?.[key] || messages.en[key] || key
      );
    }

    destroy() {
      this.stopListening();
      $(document).off("click", this.options.buttonSelector);
      $(window).off("resize");
      if (this.voiceButton) {
        this.voiceButton.remove();
      }
    }
  }

  // Initialize if enabled - create instance for each menu container
  $(document).ready(function () {
    if (
      typeof menuPopupAjax !== "undefined" &&
      menuPopupAjax.voiceSearchEnabled === "1"
    ) {
      if (typeof window.menuPopup === "object") {
        // Find all menu containers and create a VoiceSearch instance for each
        $(".menu-popup-container").each(function () {
          const container = $(this);
          if (container.length > 0) {
            // Store instance on the container for potential cleanup
            container.data(
              "voiceSearchInstance",
              new VoiceSearch({
                container: container,
                enabled: menuPopupAjax.voiceSearchEnabled === "1",
                enableMobileHover:
                  menuPopupAjax.voiceHoverEnabledMobile === "1",
                debug: true,
              })
            );
          }
        });
      }
    }
  });
})(jQuery);
