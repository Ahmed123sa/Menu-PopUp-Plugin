<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Post_Types {

    public function __construct() {
        add_action('init', array($this, 'register_custom_post_types'));
        add_action('init', array($this, 'register_custom_taxonomies'));
    }

    public function register_custom_post_types() {
        // Register custom post type: Branch
        $branch_labels = array(
            'name'                  => _x('Branches', 'Post Type General Name', 'menu-popup'),
            'singular_name'         => _x('Branch', 'Post Type Singular Name', 'menu-popup'),
            'menu_name'             => __('Branches', 'menu-popup'),
            'name_admin_bar'        => __('Branch', 'menu-popup'),
            'archives'              => __('Branch Archives', 'menu-popup'),
            'attributes'            => __('Branch Attributes', 'menu-popup'),
            'parent_item_colon'     => __('Parent Branch:', 'menu-popup'),
            'all_items'             => __('All Branches', 'menu-popup'),
            'add_new_item'          => __('Add New Branch', 'menu-popup'),
            'add_new'               => __('Add New', 'menu-popup'),
            'new_item'              => __('New Branch', 'menu-popup'),
            'edit_item'             => __('Edit Branch', 'menu-popup'),
            'update_item'           => __('Update Branch', 'menu-popup'),
            'view_item'             => __('View Branch', 'menu-popup'),
            'view_items'            => __('View Branches', 'menu-popup'),
            'search_items'          => __('Search Branch', 'menu-popup'),
            'not_found'             => __('Not found', 'menu-popup'),
            'not_found_in_trash'    => __('Not found in Trash', 'menu-popup'),
            'featured_image'        => __('Branch Image', 'menu-popup'),
            'set_featured_image'    => __('Set branch image', 'menu-popup'),
            'remove_featured_image' => __('Remove branch image', 'menu-popup'),
            'use_featured_image'    => __('Use as branch image', 'menu-popup'),
            'insert_into_item'      => __('Insert into branch', 'menu-popup'),
            'uploaded_to_this_item' => __('Uploaded to this branch', 'menu-popup'),
            'items_list'            => __('Branches list', 'menu-popup'),
            'items_list_navigation' => __('Branches list navigation', 'menu-popup'),
            'filter_items_list'     => __('Filter branches list', 'menu-popup'),
            //'menu_position'         => 20, // Comment out - will use submenu
    //'menu_icon'             => 'dashicons-location-alt', // Comment out - will use submenu
'show_in_menu'          => 'menu-popup-dashboard', // Show under Menu Pop-up

        );
        $branch_args = array(
            'label'                 => __('Branch', 'menu-popup'),
            'description'           => __('Restaurant Branches', 'menu-popup'),
            'labels'                => $branch_labels,
            'supports'              => array('title', 'editor', 'thumbnail'),
            'hierarchical'          => false,
            'public'                => false, // Set to false to hide from frontend queries directly
            'show_ui'               => true,
            'show_in_menu'          => true, // Will show under "Menu Pop-up" parent menu
            'menu_position'         => 20, // After Posts, before Media
            'menu_icon'             => 'dashicons-location-alt',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post',
            'show_in_rest'          => false,
        );
        register_post_type('menu_popup_branch', $branch_args);
    }

    public function register_custom_taxonomies() {
        // Register taxonomy: Branch City/Area
        $city_labels = array(
            'name'                       => _x('Cities/Areas', 'Taxonomy General Name', 'menu-popup'),
            'singular_name'              => _x('City/Area', 'Taxonomy Singular Name', 'menu-popup'),
            'menu_name'                  => __('Cities/Areas', 'menu-popup'),
            'all_items'                  => __('All Cities/Areas', 'menu-popup'),
            'parent_item'                => __('Parent City/Area', 'menu-popup'),
            'parent_item_colon'          => __('Parent City/Area:', 'menu-popup'),
            'new_item_name'              => __('New City/Area Name', 'menu-popup'),
            'add_new_item'               => __('Add New City/Area', 'menu-popup'),
            'edit_item'                  => __('Edit City/Area', 'menu-popup'),
            'update_item'                => __('Update City/Area', 'menu-popup'),
            'view_item'                  => __('View City/Area', 'menu-popup'),
            'separate_items_with_commas' => __('Separate cities/areas with commas', 'menu-popup'),
            'add_or_remove_items'        => __('Add or remove cities/areas', 'menu-popup'),
            'choose_from_most_used'      => __('Choose from the most used', 'menu-popup'),
            'popular_items'              => __('Popular Cities/Areas', 'menu-popup'),
            'search_items'               => __('Search Cities/Areas', 'menu-popup'),
            'not_found'                  => __('Not Found', 'menu-popup'),
            'no_terms'                   => __('No cities/areas', 'menu-popup'),
            'items_list'                 => __('Cities/Areas list', 'menu-popup'),
            'items_list_navigation'      => __('Cities/Areas list navigation', 'menu-popup'),
        );
        $city_args = array(
            'labels'                     => $city_labels,
            'hierarchical'               => true,
            'public'                     => false, // Set to false to hide from frontend queries directly
            'show_ui'                    => true,
            'show_admin_column'          => true,
            'show_in_nav_menus'          => true,
            'show_tagcloud'              => false,
            'show_in_rest'               => false,
        );
        register_taxonomy('menu_popup_branch_city', array('menu_popup_branch'), $city_args);
    }
}
