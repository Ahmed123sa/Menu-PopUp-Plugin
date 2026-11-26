<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Admin_Menu_Items extends Menu_Popup_Abstract_Handler {

    private $version;
    private $utils;

    public function __construct($version, $utils) {
        $this->version = $version;
        $this->utils = $utils;
        $this->init_hooks();
    }

    private function init_hooks() {
        // Meta boxes for posts (legacy support)
        add_action('add_meta_boxes', array($this, 'add_menu_item_meta_boxes'));
        add_action('save_post', array($this, 'save_menu_item_meta'));

        // Register custom post type and admin interface
        add_action('init', array($this, 'register_menu_item_post_type'));
        add_action('add_meta_boxes', array($this, 'add_menu_item_cpt_meta_boxes'));
        add_action('save_post', array($this, 'save_menu_item_cpt_meta'), 999); // Very high priority to ensure form data is available
        add_filter('manage_menu_item_posts_columns', array($this, 'add_menu_item_cpt_columns'));
        add_filter('manage_edit-menu_item_sortable_columns', array($this, 'make_menu_item_cpt_columns_sortable'));
        add_action('manage_menu_item_posts_custom_column', array($this, 'fill_menu_item_cpt_columns'), 10, 2);
        add_action('manage_menu_item_posts_custom_column', array($this, 'fill_menu_item_cpt_sortable_handle'), 10, 2);

        // Make posts sortable by menu_order
        add_filter('pre_get_posts', array($this, 'sort_menu_items_by_menu_order'));

        // AJAX for item ordering
        add_action('wp_ajax_update_menu_item_order', array($this, 'update_menu_item_order'));
    }

    /**
     * Add meta boxes for posts (legacy support)
     */
    public function add_menu_item_meta_boxes() {
        add_meta_box(
            'menu_item_details',
            __('Menu Item Details', 'menu-popup'),
            array($this, 'render_menu_item_meta_box'),
            'post',
            'normal',
            'high'
        );
    }

    /**
     * Render meta box for posts (legacy support)
     */
    public function render_menu_item_meta_box($post) {
        wp_nonce_field('menu_item_meta_box', 'menu_item_meta_box_nonce');

        $menu_item_name = get_post_meta($post->ID, '_menu_item_name', true);
        $menu_item_name_arabic = get_post_meta($post->ID, '_menu_item_name_arabic', true);
        $menu_item_description = get_post_meta($post->ID, '_menu_item_description', true);
        $menu_item_description_arabic = get_post_meta($post->ID, '_menu_item_description_arabic', true);
        $menu_item_price = get_post_meta($post->ID, '_menu_item_price', true);
        $menu_item_menu = get_post_meta($post->ID, '_menu_item_menu', true);
        $menu_item_category = get_post_meta($post->ID, '_menu_item_category', true);
        $menu_item_image = get_post_meta($post->ID, '_menu_item_image', true);
        $menu_item_video = get_post_meta($post->ID, '_menu_item_video', true);
        $menu_item_video_arabic = get_post_meta($post->ID, '_menu_item_video_arabic', true);
        $menu_item_add_to_cart_arabic = get_post_meta($post->ID, '_menu_item_add_to_cart_arabic', true);
        $menu_item_order_now_arabic = get_post_meta($post->ID, '_menu_item_order_now_arabic', true);
        $menu_item_language = get_post_meta($post->ID, '_menu_item_language', true);
        $menu_item_featured = get_post_meta($post->ID, '_menu_item_featured', true);

        // Get custom categories and menus
        $categories = get_option('menu_popup_categories', array());
        $menus = get_option('menu_popup_menus', array());

        ?>
        <table class="form-table">
            <tr>
                <th><label for="menu_item_name"><?php _e('Menu Item Name (English)', 'menu-popup'); ?></label></th>
                <td><input type="text" id="menu_item_name" name="menu_item_name" value="<?php echo esc_attr($menu_item_name); ?>" size="50" /></td>
            </tr>
            <tr>
                <th><label for="menu_item_name_arabic"><?php _e('Menu Item Name (العربية)', 'menu-popup'); ?></label></th>
                <td><input type="text" id="menu_item_name_arabic" name="menu_item_name_arabic" value="<?php echo esc_attr($menu_item_name_arabic); ?>" size="50" dir="rtl" /></td>
            </tr>
            <tr>
                <th><label for="menu_item_description"><?php _e('Description (English)', 'menu-popup'); ?></label></th>
                <td><textarea id="menu_item_description" name="menu_item_description" rows="4" cols="50"><?php echo esc_textarea($menu_item_description); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="menu_item_description_arabic"><?php _e('Description (العربية)', 'menu-popup'); ?></label></th>
                <td><textarea id="menu_item_description_arabic" name="menu_item_description_arabic" rows="4" cols="50" dir="rtl"><?php echo esc_textarea($menu_item_description_arabic); ?></textarea></td>
            </tr>
            <!-- Size and Price Variations -->
            <tr>
                <th><?php _e('Size & Price Variations', 'menu-popup'); ?></th>
                <td>
                    <div id="menu-item-variations">
                        <?php
                        $sizes_variations = get_post_meta($post->ID, '_menu_item_sizes', true);
                        if (!empty($sizes_variations) && is_array($sizes_variations)) {
                            foreach ($sizes_variations as $index => $variation) {
                                $size_name = isset($variation['size']) ? $variation['size'] : '';
                                $size_price = isset($variation['price']) ? $variation['price'] : '';
                                echo '<div class="variation-item" data-index="' . esc_attr($index) . '">';
                                echo '<input type="text" name="menu_item_sizes[' . esc_attr($index) . '][size]" value="' . esc_attr($size_name) . '" placeholder="Size (e.g., Small, Medium, Large)" size="25" />';
                                echo '<input type="text" name="menu_item_sizes[' . esc_attr($index) . '][price]" value="' . esc_attr($size_price) . '" placeholder="Price (e.g., $10.99)" size="15" />';
                                echo '<button type="button" class="button remove-variation">Remove</button>';
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>
                    <button type="button" id="add-variation" class="button">Add Size Variation</button>
                    <p class="description"><?php _e('Add different sizes and their corresponding prices for this menu item.', 'menu-popup'); ?></p>
                </td>
            </tr>

            <!-- Legacy Price Field (kept for backward compatibility) -->
            <tr>
                <th><label for="menu_item_price"><?php _e('Default Price (Optional)', 'menu-popup'); ?></label></th>
                <td>
                    <input type="text" id="menu_item_price" name="menu_item_price" value="<?php echo esc_attr($menu_item_price); ?>" size="20" />
                    <p class="description"><?php _e('Used as fallback price if no size variations are specified.', 'menu-popup'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="menu_item_menu"><?php _e('Menu', 'menu-popup'); ?></label></th>
                <td>
                    <select id="menu_item_menu" name="menu_item_menu">
                        <option value=""><?php _e('Select Menu', 'menu-popup'); ?></option>
                        <?php if (!empty($menus)): ?>
                            <?php foreach ($menus as $menu): ?>
                                <option value="<?php echo esc_attr($menu['slug']); ?>" <?php selected($menu_item_menu, $menu['slug']); ?>>
                                    <?php echo esc_html($menu['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (empty($menus)): ?>
                            <p class="description" style="margin-bottom: 10px; color: #666;">
                                <?php _e('No menus found. You can create menus from the', 'menu-popup'); ?>
                                <a href="<?php echo admin_url('admin.php?page=menu-popup-menus'); ?>" target="_blank">
                                    <?php _e('Menus page', 'menu-popup'); ?>
                                </a>
                                <?php _e('to organize your items.', 'menu-popup'); ?>
                            </p>
                        <?php endif; ?>
                    </select>
                    <p class="description">
                        <?php _e('Select which menu this item belongs to.', 'menu-popup'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="menu_item_video_arabic"><?php _e('Arabic Video URL', 'menu-popup'); ?></label></th>
                <td><input type="url" id="menu_item_video_arabic" name="menu_item_video_arabic" value="<?php echo esc_url($menu_item_video_arabic); ?>" size="50" placeholder="https://youtube.com/watch?v=..." /></td>
            </tr>
            <tr>
                <th><label for="menu_item_gallery"><?php _e('Gallery Images', 'menu-popup'); ?></label></th>
                <td>
                    <div id="menu-item-gallery-container">
                        <?php
                        $gallery_images = get_post_meta($post->ID, '_menu_item_gallery', true);
                        if (!empty($gallery_images) && is_array($gallery_images)) {
                            foreach ($gallery_images as $index => $image_url) {
                                if (!empty($image_url)) {
                                    $item_id = 'gallery_item_' . $index . '_' . uniqid();
                                    echo '<div class="gallery-image-item" data-index="' . $index . '">';
                                    echo '<input type="url" id="' . $item_id . '" name="menu_item_gallery[]" value="' . esc_url($image_url) . '" size="40" placeholder="https://example.com/image.jpg" />';
                                    echo '<button type="button" class="button menu-upload-gallery-btn" data-target="' . $item_id . '">Upload</button>';
                                    echo '<button type="button" class="remove-gallery-image button button-small">Remove</button>';
                                    echo '<button type="button" class="move-up-gallery-image button button-small">↑</button>';
                                    echo '<button type="button" class="move-down-gallery-image button button-small">↓</button>';
                                    echo '</div>';
                                }
                            }
                        }
                        ?>
                    </div>
                    <button type="button" id="add-gallery-image" class="button">Add Gallery Image</button>
                    <p class="description"><?php _e('Add multiple images to create a gallery. Images will be displayed alongside the video in the lightbox.', 'menu-popup'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="menu_item_featured"><?php _e('Featured Item', 'menu-popup'); ?></label></th>
                <td>
                    <input type="checkbox" id="menu_item_featured" name="menu_item_featured" value="1" <?php checked($menu_item_featured, '1'); ?> />
                    <p class="description"><?php _e('Check to display this item in the featured slideshow.', 'menu-popup'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="menu_item_language"><?php _e('Language', 'menu-popup'); ?></label></th>
                <td>
                    <select id="menu_item_language" name="menu_item_language">
                        <option value="en" <?php selected($menu_item_language, 'en'); ?>><?php _e('English', 'menu-popup'); ?></option>
                        <option value="ar" <?php selected($menu_item_language, 'ar'); ?>><?php _e('العربية', 'menu-popup'); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save meta data for posts (legacy support)
     */
    public function save_menu_item_meta($post_id) {
        // Only save for regular posts (not menu_item CPT)
        if (get_post_type($post_id) !== 'post') {
            return;
        }

        if (!isset($_POST['menu_item_meta_box_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['menu_item_meta_box_nonce'], 'menu_item_meta_box')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Save meta fields
        update_post_meta($post_id, '_menu_item_name', sanitize_text_field($_POST['menu_item_name']));
        update_post_meta($post_id, '_menu_item_name_arabic', sanitize_text_field($_POST['menu_item_name_arabic']));
        update_post_meta($post_id, '_menu_item_description', sanitize_textarea_field($_POST['menu_item_description']));
        update_post_meta($post_id, '_menu_item_description_arabic', sanitize_textarea_field($_POST['menu_item_description_arabic']));
        update_post_meta($post_id, '_menu_item_price', sanitize_text_field($_POST['menu_item_price']));
        update_post_meta($post_id, '_menu_item_menu', sanitize_text_field($_POST['menu_item_menu']));
        update_post_meta($post_id, '_menu_item_category', sanitize_text_field($_POST['menu_item_category']));
        // Only when the field was shown: items that never used it keep no branch
        if (isset($_POST['menu_item_branch'])) {
            update_post_meta($post_id, '_menu_item_branch', sanitize_text_field($_POST['menu_item_branch']));
        }
        update_post_meta($post_id, '_menu_item_image', esc_url_raw($_POST['menu_item_image']));
        update_post_meta($post_id, '_menu_item_add_to_cart_arabic', sanitize_text_field($_POST['menu_item_add_to_cart_arabic']));
        update_post_meta($post_id, '_menu_item_order_now_arabic', sanitize_text_field($_POST['menu_item_order_now_arabic']));

        // Save gallery images (array of URLs)
        if (isset($_POST['menu_item_gallery']) && is_array($_POST['menu_item_gallery'])) {
            $gallery_images = array_map('esc_url_raw', $_POST['menu_item_gallery']);
            $gallery_images = array_filter($gallery_images); // Remove empty values
            update_post_meta($post_id, '_menu_item_gallery', $gallery_images);
        } else {
            update_post_meta($post_id, '_menu_item_gallery', array());
        }

        // Process English video URL - convert watch URLs to embed URLs
        $original_url = $_POST['menu_item_video'];
        $video_url = $this->utils->process_video_url($original_url);
        update_post_meta($post_id, '_menu_item_video', esc_url_raw($video_url));

        // Process Arabic video URL - convert watch URLs to embed URLs
        if (isset($_POST['menu_item_video_arabic'])) {
            $arabic_original_url = $_POST['menu_item_video_arabic'];
            $arabic_video_url = $this->utils->process_video_url($arabic_original_url);
            update_post_meta($post_id, '_menu_item_video_arabic', esc_url_raw($arabic_video_url));
        }

        // Save size variations if provided
        if (isset($_POST['menu_item_sizes']) && is_array($_POST['menu_item_sizes'])) {
            $sizes_variations = array();
            foreach ($_POST['menu_item_sizes'] as $variation) {
                if (!empty($variation['size']) && !empty($variation['price'])) {
                    $sizes_variations[] = array(
                        'size' => sanitize_text_field($variation['size']),
                        'price' => sanitize_text_field($variation['price'])
                    );
                }
            }
            update_post_meta($post_id, '_menu_item_sizes', $sizes_variations);
        } else {
            update_post_meta($post_id, '_menu_item_sizes', array());
        }

        update_post_meta($post_id, '_menu_item_language', sanitize_text_field($_POST['menu_item_language']));
        update_post_meta($post_id, '_menu_item_featured', isset($_POST['menu_item_featured']) ? '1' : '');
        update_post_meta($post_id, '_menu_item_featured', isset($_POST['menu_item_featured']) ? '1' : '');
    }

    /**
     * Register custom post type for standalone menu items
     */
    public function register_menu_item_post_type() {
        $labels = array(
            'name' => __('Menu Items', 'menu-popup'),
            'singular_name' => __('Menu Item', 'menu-popup'),
            'add_new' => __('Add New Menu Item', 'menu-popup'),
            'add_new_item' => __('Add New Menu Item', 'menu-popup'),
            'edit_item' => __('Edit Menu Item', 'menu-popup'),
            'new_item' => __('New Menu Item', 'menu-popup'),
            'view_item' => __('View Menu Item', 'menu-popup'),
            'search_items' => __('Search Menu Items', 'menu-popup'),
            'not_found' => __('No menu items found', 'menu-popup'),
            'not_found_in_trash' => __('No menu items found in trash', 'menu-popup'),
            'all_items' => __('All Menu Items', 'menu-popup'),
            'menu_name' => __('Menu Items', 'menu-popup')
        );

        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Don't auto-register menu - we handle this manually in dashboard
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => 60,
            'menu_icon' => 'dashicons-carrot',
            'supports' => array('title', 'editor', 'thumbnail', 'author', 'page-attributes'),
            'show_in_rest' => false
        );

        register_post_type('menu_item', $args);
    }

    /**
     * Add meta boxes for menu item CPT
     */
    public function add_menu_item_cpt_meta_boxes() {
        add_meta_box(
            'menu_item_details_cpt',
            __('Menu Item Details', 'menu-popup'),
            array($this, 'render_menu_item_cpt_meta_box'),
            'menu_item',
            'normal',
            'high'
        );
    }

    /**
     * Render meta box for menu item CPT
     */
    public function render_menu_item_cpt_meta_box($post) {
        wp_nonce_field('menu_item_cpt_meta_box', 'menu_item_cpt_meta_box_nonce');

        $menu_item_name = get_post_meta($post->ID, '_menu_item_name', true);
        $menu_item_name_arabic = get_post_meta($post->ID, '_menu_item_name_arabic', true);
        $menu_item_description = get_post_meta($post->ID, '_menu_item_description', true);
        $menu_item_description_arabic = get_post_meta($post->ID, '_menu_item_description_arabic', true);
        $menu_item_price = get_post_meta($post->ID, '_menu_item_price', true);
        $menu_item_category = get_post_meta($post->ID, '_menu_item_category', true);
        $menu_item_image = get_post_meta($post->ID, '_menu_item_image', true);
        $menu_item_video = get_post_meta($post->ID, '_menu_item_video', true);
        $menu_item_video_arabic = get_post_meta($post->ID, '_menu_item_video_arabic', true);
        $menu_item_add_to_cart_arabic = get_post_meta($post->ID, '_menu_item_add_to_cart_arabic', true);
        $menu_item_order_now_arabic = get_post_meta($post->ID, '_menu_item_order_now_arabic', true);
        $menu_item_language = get_post_meta($post->ID, '_menu_item_language', true);
        $menu_item_featured = get_post_meta($post->ID, '_menu_item_featured', true);

        // Get custom categories and menus
        $categories = get_option('menu_popup_categories', array());
        $menus = get_option('menu_popup_menus', array());

        ?>
        <table class="form-table">
            <tr>
                <th><label for="menu_item_name"><?php _e('Menu Item Name (English)', 'menu-popup'); ?></label></th>
                <td><input type="text" id="menu_item_name" name="menu_item_name" value="<?php echo esc_attr($menu_item_name); ?>" size="50" /></td>
            </tr>
            <tr>
                <th><label for="menu_item_name_arabic"><?php _e('Menu Item Name (العربية)', 'menu-popup'); ?></label></th>
                <td><input type="text" id="menu_item_name_arabic" name="menu_item_name_arabic" value="<?php echo esc_attr($menu_item_name_arabic); ?>" size="50" dir="rtl" /></td>
            </tr>
            <tr>
                <th><label for="menu_item_description"><?php _e('Description (English)', 'menu-popup'); ?></label></th>
                <td><textarea id="menu_item_description" name="menu_item_description" rows="4" cols="50"><?php echo esc_textarea($menu_item_description); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="menu_item_description_arabic"><?php _e('Description (العربية)', 'menu-popup'); ?></label></th>
                <td><textarea id="menu_item_description_arabic" name="menu_item_description_arabic" rows="4" cols="50" dir="rtl"><?php echo esc_textarea($menu_item_description_arabic); ?></textarea></td>
            </tr>
            <!-- Size and Price Variations -->
            <tr>
                <th><?php _e('Size & Price Variations', 'menu-popup'); ?></th>
                <td>
                    <div id="menu-item-variations">
                        <?php
                        $sizes_variations = get_post_meta($post->ID, '_menu_item_sizes', true);
                        if (!empty($sizes_variations) && is_array($sizes_variations)) {
                            foreach ($sizes_variations as $index => $variation) {
                                $size_name = isset($variation['size']) ? $variation['size'] : '';
                                $size_price = isset($variation['price']) ? $variation['price'] : '';
                                echo '<div class="variation-item" data-index="' . esc_attr($index) . '">';
                                echo '<input type="text" name="menu_item_sizes[' . esc_attr($index) . '][size]" value="' . esc_attr($size_name) . '" placeholder="Size (e.g., Small, Medium, Large)" size="25" />';
                                echo '<input type="text" name="menu_item_sizes[' . esc_attr($index) . '][price]" value="' . esc_attr($size_price) . '" placeholder="Price (e.g., $10.99)" size="15" />';
                                echo '<button type="button" class="button remove-variation">Remove</button>';
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>
                    <button type="button" id="add-variation" class="button">Add Size Variation</button>
                    <p class="description"><?php _e('Add different sizes and their corresponding prices for this menu item.', 'menu-popup'); ?></p>
                </td>
            </tr>

            <!-- Legacy Price Field -->
            <tr>
                <th><label for="menu_item_price"><?php _e('Default Price (Optional)', 'menu-popup'); ?></label></th>
                <td>
                    <input type="text" id="menu_item_price" name="menu_item_price" value="<?php echo esc_attr($menu_item_price); ?>" size="20" />
                    <p class="description"><?php _e('Used as fallback price if no size variations are specified.', 'menu-popup'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="menu_item_category"><?php _e('Menu Category', 'menu-popup'); ?></label></th>
                <td>
                    <select id="menu_item_category" name="menu_item_category">
                        <option value=""><?php _e('Select Menu Category', 'menu-popup'); ?></option>
                        <?php if (!empty($categories)): ?>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo esc_attr($category['slug']); ?>" <?php selected($menu_item_category, $category['slug']); ?>>
                                    <?php echo esc_html($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (empty($categories)): ?>
                            <p class="description" style="margin-bottom: 10px; color: #666;">
                                <?php _e('No menu categories found. Please add categories from the', 'menu-popup'); ?>
                                <a href="<?php echo admin_url('admin.php?page=menu-popup-categories'); ?>" target="_blank">
                                    <?php _e('Categories page', 'menu-popup'); ?>
                                </a>
                                <?php _e('first.', 'menu-popup'); ?>
                            </p>
                        <?php endif; ?>
                    </select>
                    <p class="description">
                        <?php _e('Select which menu category this item belongs to.', 'menu-popup'); ?>
                    </p>
                </td>
            </tr>
            <?php
            // The single-branch field is only kept for items that already use it.
            // New items use "Pricing by branch" below instead.
            $mp_tied_branch = get_post_meta($post->ID, '_menu_item_branch', true);
            if ($mp_tied_branch !== '' && $mp_tied_branch !== false) :
            ?>
            <tr>
                <th><label for="menu_item_branch"><?php _e('Branch', 'menu-popup'); ?></label></th>
                <td>
                    <select id="menu_item_branch" name="menu_item_branch">
                        <option value=""><?php _e('All Branches (Global)', 'menu-popup'); ?></option>
                        <?php
                        // Get all branches
                        $branches = get_posts(array(
                            'post_type' => 'menu_popup_branch',
                            'posts_per_page' => -1,
                            'post_status' => 'publish'
                        ));
                        $menu_item_branch = get_post_meta($post->ID, '_menu_item_branch', true);
                        if (!empty($branches)): ?>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo esc_attr($branch->ID); ?>" <?php selected($menu_item_branch, $branch->ID); ?>>
                                    <?php echo esc_html($branch->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="description">
                        <?php _e('This item is tied to one branch (older setting). Choose "All Branches" and use "Pricing by branch" below to control it per branch.', 'menu-popup'); ?>
                    </p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><label for="menu_item_image"><?php _e('Image URL', 'menu-popup'); ?></label></th>
                <td><input type="url" id="menu_item_image" name="menu_item_image" value="<?php echo esc_url($menu_item_image); ?>" size="50" placeholder="https://example.com/image.jpg" /></td>
            </tr>
            <tr>
                <th><label for="menu_item_video"><?php _e('English Video URL', 'menu-popup'); ?></label></th>
                <td><input type="url" id="menu_item_video" name="menu_item_video" value="<?php echo esc_url($menu_item_video); ?>" size="50" placeholder="https://youtube.com/watch?v=..." /></td>
            </tr>
            <tr>
                <th><label for="menu_item_video_arabic"><?php _e('Arabic Video URL', 'menu-popup'); ?></label></th>
                <td><input type="url" id="menu_item_video_arabic" name="menu_item_video_arabic" value="<?php echo esc_url($menu_item_video_arabic); ?>" size="50" placeholder="https://youtube.com/watch?v=..." /></td>
            </tr>
            <tr>
                <th><label for="menu_item_gallery"><?php _e('Gallery Images', 'menu-popup'); ?></label></th>
                <td>
                    <div id="menu-item-gallery-container">
                        <?php
                        $gallery_images = get_post_meta($post->ID, '_menu_item_gallery', true);
                        if (!empty($gallery_images) && is_array($gallery_images)) {
                            foreach ($gallery_images as $index => $image_url) {
                                if (!empty($image_url)) {
                                    $item_id = 'gallery_item_' . $index . '_' . uniqid();
                                    echo '<div class="gallery-image-item" data-index="' . $index . '">';
                                    echo '<input type="url" id="' . $item_id . '" name="menu_item_gallery[]" value="' . esc_url($image_url) . '" size="40" placeholder="https://example.com/image.jpg" />';
                                    echo '<button type="button" class="button menu-upload-gallery-btn" data-target="' . $item_id . '">Upload</button>';
                                    echo '<button type="button" class="remove-gallery-image button button-small">Remove</button>';
                                    echo '<button type="button" class="move-up-gallery-image button button-small">↑</button>';
                                    echo '<button type="button" class="move-down-gallery-image button button-small">↓</button>';
                                    echo '</div>';
                                }
                            }
                        }
                        ?>
                    </div>
                    <button type="button" id="add-gallery-image" class="button">Add Gallery Image</button>
                    <p class="description"><?php _e('Add multiple images to create a gallery. Images will be displayed alongside the video in the lightbox.', 'menu-popup'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="menu_item_featured"><?php _e('Featured Item', 'menu-popup'); ?></label></th>
                <td>
                    <input type="checkbox" id="menu_item_featured" name="menu_item_featured" value="1" <?php checked($menu_item_featured, '1'); ?> />
                    <p class="description"><?php _e('Check to display this item in the featured slideshow.', 'menu-popup'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="menu_item_language"><?php _e('Language', 'menu-popup'); ?></label></th>
                <td>
                    <select id="menu_item_language" name="menu_item_language">
                        <option value="en" <?php selected($menu_item_language, 'en'); ?>><?php _e('English', 'menu-popup'); ?></option>
                        <option value="ar" <?php selected($menu_item_language, 'ar'); ?>><?php _e('العربية', 'menu-popup'); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save meta data for menu item CPT
     */
    public function save_menu_item_cpt_meta($post_id) {
        // Only save for menu_item CPT
        if (get_post_type($post_id) !== 'menu_item') {
            return;
        }

        if (empty($_POST)) {
            return;
        }

        if (!isset($_POST['menu_item_cpt_meta_box_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['menu_item_cpt_meta_box_nonce'], 'menu_item_cpt_meta_box')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Use the same save logic as posts
        $this->save_menu_item_meta_fields($post_id);
    }

    /**
     * Shared function to save menu item meta fields
     */
    private function save_menu_item_meta_fields($post_id) {
        update_post_meta($post_id, '_menu_item_name', sanitize_text_field($_POST['menu_item_name']));
        update_post_meta($post_id, '_menu_item_name_arabic', sanitize_text_field($_POST['menu_item_name_arabic']));
        update_post_meta($post_id, '_menu_item_description', sanitize_textarea_field($_POST['menu_item_description']));
        update_post_meta($post_id, '_menu_item_description_arabic', sanitize_textarea_field($_POST['menu_item_description_arabic']));
        update_post_meta($post_id, '_menu_item_price', sanitize_text_field($_POST['menu_item_price']));
        update_post_meta($post_id, '_menu_item_menu', isset($_POST['menu_item_menu']) ? sanitize_text_field($_POST['menu_item_menu']) : '');
        update_post_meta($post_id, '_menu_item_category', sanitize_text_field($_POST['menu_item_category']));
        // Only when the field was shown: items that never used it keep no branch
        if (isset($_POST['menu_item_branch'])) {
            update_post_meta($post_id, '_menu_item_branch', sanitize_text_field($_POST['menu_item_branch']));
        }
        update_post_meta($post_id, '_menu_item_image', esc_url_raw($_POST['menu_item_image']));

        // Process videos
        $original_url = $_POST['menu_item_video'];
        $video_url = $this->utils->process_video_url($original_url);
        update_post_meta($post_id, '_menu_item_video', esc_url_raw($video_url));

        if (isset($_POST['menu_item_video_arabic'])) {
            $arabic_original_url = $_POST['menu_item_video_arabic'];
            $arabic_video_url = $this->utils->process_video_url($arabic_original_url);
            update_post_meta($post_id, '_menu_item_video_arabic', esc_url_raw($arabic_video_url));
        }

        // Cart buttons
        update_post_meta($post_id, '_menu_item_add_to_cart_arabic', isset($_POST['menu_item_add_to_cart_arabic']) ? sanitize_text_field($_POST['menu_item_add_to_cart_arabic']) : '');
        update_post_meta($post_id, '_menu_item_order_now_arabic', isset($_POST['menu_item_order_now_arabic']) ? sanitize_text_field($_POST['menu_item_order_now_arabic']) : '');

        // Size variations
        if (isset($_POST['menu_item_sizes']) && is_array($_POST['menu_item_sizes'])) {
            $sizes_variations = array();
            foreach ($_POST['menu_item_sizes'] as $variation) {
                if (!empty($variation['size']) && !empty($variation['price'])) {
                    $sizes_variations[] = array(
                        'size' => sanitize_text_field($variation['size']),
                        'price' => sanitize_text_field($variation['price'])
                    );
                }
            }
            update_post_meta($post_id, '_menu_item_sizes', $sizes_variations);
        } else {
            update_post_meta($post_id, '_menu_item_sizes', array());
        }

        // Gallery images
        if (isset($_POST['menu_item_gallery']) && is_array($_POST['menu_item_gallery'])) {
            $gallery_images = array_map('esc_url_raw', $_POST['menu_item_gallery']);
            $gallery_images = array_filter($gallery_images);
            update_post_meta($post_id, '_menu_item_gallery', $gallery_images);
        } else {
            update_post_meta($post_id, '_menu_item_gallery', array());
        }

        update_post_meta($post_id, '_menu_item_language', sanitize_text_field($_POST['menu_item_language']));
    }

    /**
     * Add custom columns to menu item CPT list
     */
    public function add_menu_item_cpt_columns($columns) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['sortable-handle'] = '<span class="sortable-handle dashicons dashicons-menu" title="' . __('Drag to reorder', 'menu-popup') . '"></span>';
        $new_columns['title'] = __('Menu Item', 'menu-popup');
        $new_columns['category'] = __('Category', 'menu-popup');
        $new_columns['price'] = __('Price', 'menu-popup');
        $new_columns['order'] = __('Order', 'menu-popup');
        $new_columns['language'] = __('Language', 'menu-popup');
        $new_columns['date'] = $columns['date'];

        return $new_columns;
    }

    /**
     * Make columns sortable
     */
    public function make_menu_item_cpt_columns_sortable($columns) {
        $columns['order'] = 'menu_order';
        return $columns;
    }

    /**
     * Fill sortable handle column
     */
    public function fill_menu_item_cpt_sortable_handle($column, $post_id) {
        if ($column === 'sortable-handle') {
            echo '<span class="sortable-handle dashicons dashicons-menu" style="cursor: move;" title="' . __('Drag to reorder', 'menu-popup') . '"></span>';
        }
    }

    /**
     * Fill custom columns for menu item CPT
     */
    public function fill_menu_item_cpt_columns($column, $post_id) {
        switch ($column) {
            case 'category':
                $category_slug = get_post_meta($post_id, '_menu_item_category', true);
                $categories = get_option('menu_popup_categories', array());
                foreach ($categories as $cat) {
                    if ($cat['slug'] === $category_slug) {
                        echo esc_html($cat['name']);
                        break;
                    }
                }
                break;
            case 'price':
                $variations = get_post_meta($post_id, '_menu_item_sizes', true);
                if (!empty($variations) && is_array($variations)) {
                    echo esc_html($variations[0]['price']);
                } else {
                    $price = get_post_meta($post_id, '_menu_item_price', true);
                    echo esc_html($price);
                }
                break;
            case 'language':
                $language = get_post_meta($post_id, '_menu_item_language', true);
                if ($language === 'ar') {
                    echo __('العربية', 'menu-popup');
                } else {
                    echo __('English', 'menu-popup');
                }
                break;
            case 'order':
                echo esc_html(get_post_field('menu_order', $post_id));
                break;
        }
    }

    /**
     * AJAX handler to update menu item order
     */
    public function update_menu_item_order() {
        // Verify nonce and permissions
        check_ajax_referer('menu_popup_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $item_ids = isset($_POST['item_ids']) ? array_map('intval', $_POST['item_ids']) : array();

        if (empty($item_ids)) {
            wp_send_json_error(__('No items to update', 'menu-popup'));
        }

        // Update menu order for each item
        foreach ($item_ids as $order => $item_id) {
            // Use menu_order field for ordering
            wp_update_post(array(
                'ID' => $item_id,
                'menu_order' => $order
            ));
        }

        wp_send_json_success(__('Item order updated successfully', 'menu-popup'));
    }

    /**
     * Make menu items sortable by menu_order in admin
     */
    public function sort_menu_items_by_menu_order($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') === 'menu_item') {
            $query->set('orderby', 'menu_order');
            $query->set('order', 'ASC');
        }
    }

    /**
     * Migrate existing video URLs from watch format to embed format
     */
    public function migrate_video_urls() {
        // Get all posts that have menu item video URLs
        $args = array(
            'post_type' => array('post', 'menu_item'),
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_video',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_menu_item_video',
                    'value' => '',
                    'compare' => '!='
                )
            ),
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $posts = get_posts($args);

        if (empty($posts)) {
            return;
        }

        $migrated_count = 0;

        foreach ($posts as $post_id) {
            $current_url = get_post_meta($post_id, '_menu_item_video', true);

            if (empty($current_url)) {
                continue;
            }

            // Check if it's already in embed format
            if (preg_match('/youtube\.com\/embed\//', $current_url) || preg_match('/player\.vimeo\.com\/video\//', $current_url)) {
                continue;
            }

            // Process and update the URL
            $processed_url = $this->utils->process_video_url($current_url);

            // Only update if the URL changed
            if ($processed_url !== $current_url) {
                update_post_meta($post_id, '_menu_item_video', esc_url_raw($processed_url));
                $migrated_count++;
            }
        }

        // Mark migration as complete to avoid running it again
        update_option('menu_popup_video_migration_complete', '1');
    }
}
