<?php
/**
 * Menu Popup Plugin Uninstall
 *
 * This file is called when the plugin is uninstalled via WordPress.
 * Plugin data is preserved - no data removal occurs during uninstallation.
 *
 * @package MenuPopup
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// No data removal - all plugin data and menu items are preserved
