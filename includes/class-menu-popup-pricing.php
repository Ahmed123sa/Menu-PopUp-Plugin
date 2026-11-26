<?php
/**
 * Branch pricing and availability.
 *
 * One menu item can be sold at some branches and not at others, with its own
 * price (and size prices) per branch. The "Default menu" is what the customer
 * sees before a branch is chosen; it uses the item's normal price and sizes,
 * so nothing changes for sites that do not use this feature.
 *
 * While a branch is "in context", reading an item's price or sizes returns
 * that branch's values, so cards, cart and order all agree.
 *
 * Stored per item (meta _mp_branch_pricing), keyed by branch id:
 *   array( 789 => array( 'on' => 1, 'price' => '120', 'sizes' => array( 'Small' => '100' ) ) )
 * A missing branch, empty price or empty size price means "use the Default
 * menu value". Switched-off branches also get one _mp_unavail meta row each so
 * lists can be filtered in the database.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Pricing {

    const META          = '_mp_branch_pricing';
    const UNAVAIL       = '_mp_unavail';
    const ACTIVE_OPTION = 'menu_popup_branch_pricing_active';

    /** @var int|null Branch whose prices are being read (0 = Default menu, null = off). */
    private static $context = null;

    private static $busy = false;
    private static $unavail_cache = array();

    public static function init() {
        add_filter('get_post_metadata', array(__CLASS__, 'filter_meta'), 10, 4);
        add_action('pre_get_posts', array(__CLASS__, 'hide_unavailable'));
    }

    public static function set_context($branch_id) {
        self::$context = max(0, (int) $branch_id);
    }

    public static function clear_context() {
        self::$context = null;
    }

    public static function context() {
        return self::$context;
    }

    const CAT_OFF = 'menu_popup_branch_cat_off';

    /** True once any item or category has branch settings. */
    public static function is_active() {
        return get_option(self::ACTIVE_OPTION, '0') === '1';
    }

    /** Category slugs switched off at a branch. */
    public static function off_categories($branch_id) {
        $all = get_option(self::CAT_OFF, array());
        $branch_id = (int) $branch_id;
        return (is_array($all) && isset($all[$branch_id]) && is_array($all[$branch_id])) ? array_values($all[$branch_id]) : array();
    }

    public static function refresh_active_flag() {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
            self::META
        ));
        $cats = get_option(self::CAT_OFF, array());
        $has_cats = false;
        if (is_array($cats)) {
            foreach ($cats as $list) {
                if (!empty($list)) {
                    $has_cats = true;
                    break;
                }
            }
        }
        update_option(self::ACTIVE_OPTION, ($found || $has_cats) ? '1' : '0');
    }

    /** Branch settings of one item: array( branch_id => array(on, price, sizes) ). */
    public static function get_rows($item_id) {
        self::$busy = true;
        $rows = get_post_meta((int) $item_id, self::META, true);
        self::$busy = false;
        return is_array($rows) ? $rows : array();
    }

    /** Is the item sold at this branch (0 = Default menu)? */
    public static function is_available($item_id, $branch_id) {
        $item_id   = (int) $item_id;
        $branch_id = (int) $branch_id;

        // Older setting: an item tied to one branch
        if ($branch_id > 0) {
            $tied = get_post_meta($item_id, '_menu_item_branch', true);
            if ($tied !== '' && $tied !== false && absint($tied) !== $branch_id) {
                return false;
            }
        }

        // The whole category is switched off at this branch
        if ($branch_id > 0) {
            $off = self::off_categories($branch_id);
            if (!empty($off) && in_array((string) get_post_meta($item_id, '_menu_item_category', true), $off, true)) {
                return false;
            }
        }

        $rows = self::get_rows($item_id);
        if (isset($rows[$branch_id]['on']) && (int) $rows[$branch_id]['on'] === 0) {
            return false;
        }
        return true;
    }

    /**
     * The item's own on/off setting at a branch (ignores category rules).
     * This is what the admin tables show and save.
     */
    public static function item_switch_on($item_id, $branch_id) {
        $rows = self::get_rows($item_id);
        return !(isset($rows[(int) $branch_id]['on']) && (int) $rows[(int) $branch_id]['on'] === 0);
    }

    /** Ids of items switched off at a branch. */
    public static function unavailable_ids($branch_id) {
        global $wpdb;
        $branch_id = (int) $branch_id;
        if (!isset(self::$unavail_cache[$branch_id])) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                self::UNAVAIL,
                (string) $branch_id
            ));
            self::$unavail_cache[$branch_id] = array_map('intval', $ids);
        }
        return self::$unavail_cache[$branch_id];
    }

    /** Keep switched-off items out of the menu lists (only while a branch is in context). */
    public static function hide_unavailable($query) {
        if (self::$context === null || self::$context < 1 || !self::is_active() || !($query instanceof WP_Query)) {
            return;
        }
        $types = (array) $query->get('post_type');
        if (!in_array('menu_item', $types, true) && !in_array('post', $types, true)) {
            return;
        }
        // Only the menu's own lists (they always filter by menu item fields)
        $meta_query = $query->get('meta_query');
        if (empty($meta_query)) {
            return;
        }
        $ids = self::unavailable_ids(self::$context);
        if (!empty($ids)) {
            $existing = (array) $query->get('post__not_in');
            $query->set('post__not_in', array_values(array_unique(array_merge($existing, $ids))));
        }

        // Categories switched off at this branch
        $off = self::off_categories(self::$context);
        if (!empty($off)) {
            $meta_query = $query->get('meta_query');
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => '_menu_item_category', 'value' => $off, 'compare' => 'NOT IN'),
                array('key' => '_menu_item_category', 'compare' => 'NOT EXISTS'),
            );
            $query->set('meta_query', $meta_query);
        }
    }

    /** Return the branch's price / sizes instead of the Default ones. */
    public static function filter_meta($value, $object_id, $meta_key, $single) {
        if ($value !== null || self::$context === null || self::$context < 1 || self::$busy) {
            return $value;
        }
        if ($meta_key !== '_menu_item_price' && $meta_key !== '_menu_item_sizes') {
            return $value;
        }

        $rows = self::get_rows($object_id);
        if (!isset($rows[self::$context]) || !is_array($rows[self::$context])) {
            return $value;
        }
        $row = $rows[self::$context];

        if ($meta_key === '_menu_item_price') {
            $price = isset($row['price']) ? trim((string) $row['price']) : '';
            return $price === '' ? $value : array($price);
        }

        if (empty($row['sizes']) || !is_array($row['sizes'])) {
            return $value;
        }
        self::$busy = true;
        $default = get_post_meta($object_id, '_menu_item_sizes', true);
        self::$busy = false;
        if (!is_array($default) || empty($default)) {
            return $value;
        }

        $changed = false;
        $result  = array();
        foreach ($default as $variation) {
            if (is_array($variation) && isset($variation['size'])) {
                $name = (string) $variation['size'];
                if (isset($row['sizes'][$name]) && trim((string) $row['sizes'][$name]) !== '') {
                    $variation['price'] = trim((string) $row['sizes'][$name]);
                    $changed = true;
                }
            }
            $result[] = $variation;
        }
        return $changed ? array($result) : $value;
    }

    /**
     * Save the branch settings of one item.
     *
     * @param int   $item_id
     * @param array $posted     branch_id => array( 'on' => '1', 'price' => '', 'sizes' => array( name => price ) )
     * @param array $branch_ids Branch ids this form covers.
     */
    public static function save_item($item_id, $posted, array $branch_ids) {
        $item_id = (int) $item_id;
        if ($item_id < 1) {
            return;
        }
        $branch_ids = array_map('intval', $branch_ids);

        self::$busy = true;
        $default = get_post_meta($item_id, '_menu_item_sizes', true);
        self::$busy = false;
        $valid_sizes = array();
        if (is_array($default)) {
            foreach ($default as $variation) {
                if (is_array($variation) && isset($variation['size'])) {
                    $valid_sizes[(string) $variation['size']] = true;
                }
            }
        }

        $existing_rows = self::get_rows($item_id);
        $rows = array();
        foreach ($branch_ids as $bid) {
            if ($bid < 1) {
                continue;
            }
            $in  = (is_array($posted) && isset($posted[$bid]) && is_array($posted[$bid])) ? $posted[$bid] : array();
            $row = array('on' => !empty($in['on']) ? 1 : 0);

            $price = isset($in['price']) ? self::clean_price($in['price']) : '';
            if ($price !== '') {
                $row['price'] = $price;
            }
            if (!isset($in['sizes']) && !empty($existing_rows[$bid]['sizes'])) {
                // This form does not edit sizes: keep what is stored
                $row['sizes'] = $existing_rows[$bid]['sizes'];
            }
            if (!empty($in['sizes']) && is_array($in['sizes'])) {
                $sizes = array();
                foreach ($in['sizes'] as $name => $p) {
                    $p = self::clean_price($p);
                    if ($p !== '' && isset($valid_sizes[(string) $name])) {
                        $sizes[(string) $name] = $p;
                    }
                }
                if (!empty($sizes)) {
                    $row['sizes'] = $sizes;
                }
            }
            // Nothing to remember for a branch that is on and uses Default values
            if ($row['on'] === 1 && count($row) === 1) {
                continue;
            }
            $rows[$bid] = $row;
        }

        // Keep settings of branches this form did not cover
        foreach ($existing_rows as $bid => $row) {
            if (!in_array((int) $bid, $branch_ids, true)) {
                $rows[(int) $bid] = $row;
            }
        }

        delete_post_meta($item_id, self::UNAVAIL);
        if (empty($rows)) {
            delete_post_meta($item_id, self::META);
        } else {
            update_post_meta($item_id, self::META, $rows);
            foreach ($rows as $bid => $row) {
                if (isset($row['on']) && (int) $row['on'] === 0) {
                    add_post_meta($item_id, self::UNAVAIL, (string) (int) $bid);
                }
            }
        }
        self::$unavail_cache = array();
    }

    public static function clean_price($value) {
        return trim(sanitize_text_field((string) $value));
    }

    public static function branches() {
        return get_posts(array(
            'post_type'      => 'menu_popup_branch',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
    }

    /** Name shown for the Default menu. */
    public static function default_menu_name($arabic = false) {
        $name = (string) get_option($arabic ? 'menu_popup_default_menu_name_ar' : 'menu_popup_default_menu_name', '');
        if ($name !== '') {
            return $name;
        }
        return $arabic ? 'المنيو الرئيسي' : __('Main menu', 'menu-popup');
    }
}
