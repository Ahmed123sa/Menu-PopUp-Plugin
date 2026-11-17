/**
 * External TTS Service for Menu Pop-up Plugin
 * Handles Google Cloud Text-to-Speech API integration
 */

class MenuPopupExternalTTS {
  constructor() {
    // Initialize with defaults, will be updated when settings are available
    this.apiKey = "";
    this.cacheEnabled = true;
    this.cache = new Map();
    this.isEnabled = false;

    // Update settings when available
    this.updateSettings();

    // Initialize cache from localStorage
    this.loadCache();

    // Arabic voice configurations for Google TTS
    this.arabicVoices = {
      "ar-XA-Wavenet-A": {
        name: "ar-XA-Wavenet-A",
        language: "ar-XA",
        gender: "FEMALE",
      },
      "ar-XA-Wavenet-B": {
        name: "ar-XA-Wavenet-B",
        language: "ar-XA",
        gender: "MALE",
      },
      "ar-XA-Wavenet-C": {
        name: "ar-XA-Wavenet-C",
        language: "ar-XA",
        gender: "MALE",
      },
      "ar-XA-Wavenet-D": {
        name: "ar-XA-Wavenet-D",
        language: "ar-XA",
        gender: "FEMALE",
      },
      "ar-XA-Standard-A": {
        name: "ar-XA-Standard-A",
        language: "ar-XA",
        gender: "FEMALE",
      },
      "ar-XA-Standard-B": {
        name: "ar-XA-Standard-B",
        language: "ar-XA",
        gender: "MALE",
      },
      "ar-XA-Standard-C": {
        name: "ar-XA-Standard-C",
        language: "ar-XA",
        gender: "MALE",
      },
      "ar-XA-Standard-D": {
        name: "ar-XA-Standard-D",
        language: "ar-XA",
        gender: "FEMALE",
      },
    };
  }

  /**
   * Update settings from global menuPopupSettings object
   */
  updateSettings() {
    // Check if menuPopupSettings is available
    if (typeof window.menuPopupSettings !== "undefined") {
      this.apiKey = window.menuPopupSettings.googleTtsApiKey || "";
      this.cacheEnabled = window.menuPopupSettings.ttsCacheEnabled !== "0";
      this.isEnabled = window.menuPopupSettings.externalTtsEnabled === "1";
    } else {
      // If settings not available yet, try again after a short delay
      setTimeout(() => {
        this.updateSettings();
      }, 100);
    }
  }

  /**
   * Check if external TTS is available and configured
   */
  isAvailable() {
    return this.isEnabled && this.apiKey && this.apiKey.length > 0;
  }

  /**
   * Generate audio for text using Google Cloud TTS
   */
  async generateAudio(text, options = {}) {
    if (!this.isAvailable()) {
      throw new Error("External TTS not available or not configured");
    }

    const cacheKey = this.generateCacheKey(text, options);

    // Check cache first
    if (this.cacheEnabled && this.cache.has(cacheKey)) {
      return this.cache.get(cacheKey);
    }

    try {
      const audioData = await this.callGoogleTTS(text, options);
      const audioUrl = this.createAudioBlobUrl(audioData);

      // Cache the result
      if (this.cacheEnabled) {
        this.cache.set(cacheKey, audioUrl);
        this.saveCache();
      }

      return audioUrl;
    } catch (error) {
      console.error("Google TTS API error:", error);
      throw error;
    }
  }

  /**
   * Call Google Cloud Text-to-Speech API
   */
  async callGoogleTTS(text, options = {}) {
    const voice = options.voice || "ar-XA-Wavenet-A";
    const languageCode = options.languageCode || "ar-XA";
    const speakingRate = options.speakingRate || 0.8;
    const pitch = options.pitch || 0;

    const requestBody = {
      input: { text: text },
      voice: {
        languageCode: languageCode,
        name: voice,
      },
      audioConfig: {
        audioEncoding: "MP3",
        speakingRate: speakingRate,
        pitch: pitch,
      },
    };

    const response = await fetch(
      `https://texttospeech.googleapis.com/v1/text:synthesize?key=${this.apiKey}`,
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(requestBody),
      }
    );

    if (!response.ok) {
      const errorData = await response.json();
      throw new Error(
        `Google TTS API error: ${
          errorData.error?.message || response.statusText
        }`
      );
    }

    const data = await response.json();
    return data.audioContent;
  }

  /**
   * Create blob URL from base64 audio data
   */
  createAudioBlobUrl(base64Audio) {
    // Decode base64 to binary
    const binaryString = atob(base64Audio);
    const bytes = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
      bytes[i] = binaryString.charCodeAt(i);
    }

    // Create blob and URL
    const blob = new Blob([bytes], { type: "audio/mp3" });
    return URL.createObjectURL(blob);
  }

  /**
   * Generate cache key for text and options
   */
  generateCacheKey(text, options = {}) {
    const keyData = {
      text: text,
      voice: options.voice || "ar-XA-Wavenet-A",
      languageCode: options.languageCode || "ar-XA",
      speakingRate: options.speakingRate || 0.8,
      pitch: options.pitch || 0,
    };
    // Use Unicode-safe encoding for btoa to handle Arabic characters
    return btoa(unescape(encodeURIComponent(JSON.stringify(keyData))));
  }

  /**
   * Load cache from localStorage
   */
  loadCache() {
    try {
      const cached = localStorage.getItem("menuPopupTTSCache");
      if (cached) {
        const cacheData = JSON.parse(cached);
        this.cache = new Map(Object.entries(cacheData));
      }
    } catch (error) {
      console.warn("Failed to load TTS cache:", error);
      this.cache = new Map();
    }
  }

  /**
   * Save cache to localStorage
   */
  saveCache() {
    try {
      const cacheObject = Object.fromEntries(this.cache);
      localStorage.setItem("menuPopupTTSCache", JSON.stringify(cacheObject));
    } catch (error) {
      console.warn("Failed to save TTS cache:", error);
    }
  }

  /**
   * Clear cache
   */
  clearCache() {
    this.cache.clear();
    localStorage.removeItem("menuPopupTTSCache");
  }

  /**
   * Get available Arabic voices
   */
  getAvailableVoices() {
    return Object.keys(this.arabicVoices);
  }

  /**
   * Get voice info
   */
  getVoiceInfo(voiceName) {
    return this.arabicVoices[voiceName] || null;
  }

  /**
   * Test voice with sample text
   */
  async testVoice(
    voiceName,
    testText = "مرحباً! هذا اختبار للنطق باللغة العربية"
  ) {
    try {
      const voiceInfo = this.getVoiceInfo(voiceName);
      if (!voiceInfo) {
        throw new Error("Voice not found");
      }

      const audioUrl = await this.generateAudio(testText, {
        voice: voiceName,
        languageCode: voiceInfo.language,
      });

      // Play the audio
      const audio = new Audio(audioUrl);
      audio.play();

      return audioUrl;
    } catch (error) {
      console.error("Voice test failed:", error);
      throw error;
    }
  }

  /**
   * Get usage statistics
   */
  getCacheStats() {
    return {
      cachedItems: this.cache.size,
      isEnabled: this.cacheEnabled,
      isAvailable: this.isAvailable(),
    };
  }
}

// Initialize global instance
window.menuPopupExternalTTS = new MenuPopupExternalTTS();

// Export for module usage if needed
if (typeof module !== "undefined" && module.exports) {
  module.exports = MenuPopupExternalTTS;
}
