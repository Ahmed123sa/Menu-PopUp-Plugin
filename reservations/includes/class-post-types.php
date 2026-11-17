<?php
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Reservations_Post_Types
{

    public function __construct()
    {
        add_action('init', array($this, 'register_post_types'));
    }

    public function register_post_types()
    {
        // Register Reservation Post Type
        register_post_type('menu_reservation', array(
            'labels' => array(
                'name' => __('Reservations', 'menu-popup'),
                'singular_name' => __('Reservation', 'menu-popup'),
                'add_new' => __('Add Reservation', 'menu-popup'),
                'add_new_item' => __('Add New Reservation', 'menu-popup'),
                'edit_item' => __('Edit Reservation', 'menu-popup'),
                'new_item' => __('New Reservation', 'menu-popup'),
                'view_item' => __('View Reservation', 'menu-popup'),
                'search_items' => __('Search Reservations', 'menu-popup'),
                'not_found' => __('No reservations found', 'menu-popup'),
                'not_found_in_trash' => __('No reservations found in trash', 'menu-popup'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Will be added to custom dashboard
            'supports' => array('title', 'custom-fields'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));

        // Register Restaurant Table Post Type
        register_post_type('restaurant_table', array(
            'labels' => array(
                'name' => __('Restaurant Tables', 'menu-popup'),
                'singular_name' => __('Table', 'menu-popup'),
                'add_new' => __('Add Table', 'menu-popup'),
                'add_new_item' => __('Add New Table', 'menu-popup'),
                'edit_item' => __('Edit Table', 'menu-popup'),
                'new_item' => __('New Table', 'menu-popup'),
                'view_item' => __('View Table', 'menu-popup'),
                'search_items' => __('Search Tables', 'menu-popup'),
                'not_found' => __('No tables found', 'menu-popup'),
                'not_found_in_trash' => __('No tables found in trash', 'menu-popup'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'menu-popup-dashboard', // Show under main plugin menu
            'supports' => array('title', 'custom-fields'),
            'menu_icon' => 'dashicons-layout',
        ));

        // Register Availability Rule Post Type (Holidays, Exceptions)
        register_post_type('reservation_rule', array(
            'labels' => array(
                'name' => __('Availability Rules', 'menu-popup'),
                'singular_name' => __('Availability Rule', 'menu-popup'),
                'add_new' => __('Add Rule', 'menu-popup'),
                'add_new_item' => __('Add New Rule', 'menu-popup'),
                'edit_item' => __('Edit Rule', 'menu-popup'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'menu-popup-dashboard',
            'supports' => array('title', 'custom-fields'), // Title = Rule Name
            'menu_icon' => 'dashicons-calendar-alt',
        ));
    }
}
