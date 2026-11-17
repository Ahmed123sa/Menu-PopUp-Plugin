<?php
/**
 * Voice & Search Settings Module
 * 
 * @package MenuPopup
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Voice & Search Settings Class
 */
class Menu_Popup_Settings_Voice {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Settings are registered in the loader
    }
    
    /**
     * Render voice settings
     */
    public function render_voice_settings() {
        $voice_search_enabled = get_option('menu_popup_voice_search_enabled', '0');
        $pronounce_enabled = get_option('menu_popup_pronounce_on_hover_enabled', '0');
        $google_tts_enabled = get_option('menu_popup_google_tts_enabled', '0');
        $google_tts_api_key = get_option('menu_popup_google_tts_api_key', '');
        $default_voice = get_option('menu_popup_default_voice', 'arabic');
        ?>
        <div class="menu-popup-settings-section" id="voice-settings">
            <h2><?php _e('Voice & Search Settings', 'menu-popup'); ?></h2>
            
            <h3><?php _e('Voice Search', 'menu-popup'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="menu_popup_voice_search_enabled"><?php _e('Enable Voice Search', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="menu_popup_voice_search_enabled" name="menu_popup_voice_search_enabled" value="1" <?php checked($voice_search_enabled, '1'); ?>>
                        <p class="description"><?php _e('Enable voice recognition for menu search', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_pronounce_on_hover_enabled"><?php _e('Pronounce on Hover', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="menu_popup_pronounce_on_hover_enabled" name="menu_popup_pronounce_on_hover_enabled" value="1" <?php checked($pronounce_enabled, '1'); ?>>
                        <p class="description"><?php _e('Speak menu item name when hovering over it', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_default_voice"><?php _e('Default Voice Language', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <select id="menu_popup_default_voice" name="menu_popup_default_voice">
                            <option value="arabic" <?php selected($default_voice, 'arabic'); ?>><?php _e('Arabic', 'menu-popup'); ?></option>
                            <option value="english" <?php selected($default_voice, 'english'); ?>><?php _e('English', 'menu-popup'); ?></option>
                        </select>
                        <p class="description"><?php _e('Default voice for text-to-speech', 'menu-popup'); ?></p>
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Google Cloud TTS', 'menu-popup'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="menu_popup_google_tts_enabled"><?php _e('Use Google Cloud TTS', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="menu_popup_google_tts_enabled" name="menu_popup_google_tts_enabled" value="1" <?php checked($google_tts_enabled, '1'); ?>>
                        <p class="description"><?php _e('Use Google Cloud Text-to-Speech for higher quality voice', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_google_tts_api_key"><?php _e('Google Cloud API Key', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="password" id="menu_popup_google_tts_api_key" name="menu_popup_google_tts_api_key" value="<?php echo esc_attr($google_tts_api_key); ?>" class="regular-text">
                        <p class="description"><?php _e('Enter your Google Cloud API key for Text-to-Speech', 'menu-popup'); ?></p>
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Voice Test', 'menu-popup'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Test Voice', 'menu-popup'); ?></th>
                    <td>
                        <button type="button" id="test-voice-btn" class="button">
                            <?php _e('Test Voice Output', 'menu-popup'); ?>
                        </button>
                        <p class="description"><?php _e('Click to test the current voice settings', 'menu-popup'); ?></p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Save Voice Settings', 'menu-popup')); ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-voice-btn').on('click', function() {
                if ('speechSynthesis' in window) {
                    var utterance = new SpeechSynthesisUtterance('مرحبا بك');
                    utterance.lang = 'ar-SA';
                    window.speechSynthesis.speak(utterance);
                    alert('<?php _e('Voice test played!', 'menu-popup'); ?>');
                } else {
                    alert('<?php _e('Your browser does not support speech synthesis', 'menu-popup'); ?>');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Sanitize voice settings
     */
    public function sanitize_settings($input) {
        $output = [];
        
        $output['menu_popup_voice_search_enabled'] = isset($input['menu_popup_voice_search_enabled']) ? '1' : '0';
        $output['menu_popup_pronounce_on_hover_enabled'] = isset($input['menu_popup_pronounce_on_hover_enabled']) ? '1' : '0';
        $output['menu_popup_google_tts_enabled'] = isset($input['menu_popup_google_tts_enabled']) ? '1' : '0';
        $output['menu_popup_google_tts_api_key'] = isset($input['menu_popup_google_tts_api_key']) ? sanitize_text_field($input['menu_popup_google_tts_api_key']) : '';
        $output['menu_popup_default_voice'] = isset($input['menu_popup_default_voice']) ? sanitize_text_field($input['menu_popup_default_voice']) : 'arabic';
        
        return $output;
    }
}

// Initialize
new Menu_Popup_Settings_Voice();
