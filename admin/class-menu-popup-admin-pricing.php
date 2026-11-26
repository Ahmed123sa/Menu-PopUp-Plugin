<?php
/**
 * Admin screens for branch pricing:
 *  - "Branch Pricing" page: how branches are used, default menu name, and a
 *    table of every item with its availability and price at each branch.
 *  - "Pricing by branch" box on the item edit screen (includes size prices).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Admin_Pricing {

    const PAGE = 'menu-popup-branch-pricing';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_page'), 20);
        add_action('admin_post_mp_save_branch_pricing', array($this, 'handle_save'));
        add_action('add_meta_boxes', array($this, 'add_item_box'));
        add_action('save_post', array($this, 'save_item_box'), 1000);
    }

    public function add_page() {
        add_submenu_page(
            'menu-popup-dashboard',
            __('Branch Pricing', 'menu-popup'),
            __('Branch Pricing', 'menu-popup'),
            'manage_options',
            self::PAGE,
            array($this, 'render_page')
        );
    }

    // ---------------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------------

    private function items() {
        $ids = get_posts(array(
            'post_type'      => 'menu_item',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ));
        $legacy = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(array('key' => '_menu_item_name', 'compare' => 'EXISTS')),
        ));
        return array_values(array_unique(array_merge($ids, $legacy)));
    }

    private function item_name($id) {
        $name = (string) get_post_meta($id, '_menu_item_name', true);
        return $name !== '' ? $name : get_the_title($id);
    }

    // ---------------------------------------------------------------------
    // Page
    // ---------------------------------------------------------------------

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $branches = Menu_Popup_Pricing::branches();
        $items    = $this->items();
        $forced   = get_option('menu_popup_force_branch_selection', '0') === '1';
        $menus    = get_option('menu_popup_menus', array());
        $menus    = is_array($menus) ? $menus : array();
        $default  = (string) get_option('menu_popup_default_menu', '');
        $menu_url = admin_url('admin.php?page=menu-popup-menus');

        $cat_names = array();
        $cats      = get_option('menu_popup_categories', array());
        if (is_array($cats)) {
            foreach ($cats as $c) {
                if (is_array($c) && isset($c['slug'])) {
                    $cat_names[$c['slug']] = isset($c['name']) ? $c['name'] : $c['slug'];
                }
            }
        }

        // Categories of each menu, for the table filter
        $menu_cats = array();
        foreach ($menus as $m) {
            if (!empty($m['slug'])) {
                $menu_cats[$m['slug']] = !empty($m['categories']) && is_array($m['categories']) ? array_values($m['categories']) : array();
            }
        }

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Changes saved.', 'menu-popup') . '</p></div>';
        }
        ?>
        <div class="wrap mp-bp">
            <h1><?php esc_html_e('Branch Pricing', 'menu-popup'); ?></h1>

            <ol class="mp-bp__steps" aria-label="<?php esc_attr_e('Steps', 'menu-popup'); ?>">
                <li><a href="<?php echo esc_url($menu_url); ?>"><strong><?php esc_html_e('1. Menus', 'menu-popup'); ?></strong><span><?php esc_html_e('Create a menu, choose its categories and link it to branches.', 'menu-popup'); ?></span></a></li>
                <li><a href="#mp-bp-default"><strong><?php esc_html_e('2. Default menu', 'menu-popup'); ?></strong><span><?php esc_html_e('Choose the menu customers see before they pick a branch.', 'menu-popup'); ?></span></a></li>
                <li><a href="#mp-bp-prices"><strong><?php esc_html_e('3. Prices', 'menu-popup'); ?></strong><span><?php esc_html_e('Set each item\'s price and availability per branch.', 'menu-popup'); ?></span></a></li>
            </ol>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="mp-bp-form">
                <input type="hidden" name="action" value="mp_save_branch_pricing">
                <?php wp_nonce_field('mp_save_branch_pricing', 'mp_bp_nonce'); ?>

                <section class="mp-bp__card" aria-labelledby="mp-bp-overview">
                    <h2 id="mp-bp-overview"><?php esc_html_e('Your menus', 'menu-popup'); ?></h2>
                    <?php if (empty($menus)) : ?>
                        <p><?php esc_html_e('No menus yet.', 'menu-popup'); ?> <a href="<?php echo esc_url($menu_url); ?>"><?php esc_html_e('Create your first menu', 'menu-popup'); ?></a></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr>
                                <th scope="col"><?php esc_html_e('Menu', 'menu-popup'); ?></th>
                                <th scope="col"><?php esc_html_e('Categories', 'menu-popup'); ?></th>
                                <th scope="col"><?php esc_html_e('Branches', 'menu-popup'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($menus as $m) :
                                if (empty($m['slug'])) { continue; }
                                $names = array();
                                foreach ($menu_cats[$m['slug']] as $slug) {
                                    $names[] = isset($cat_names[$slug]) ? $cat_names[$slug] : $slug;
                                }
                                $bn = array();
                                if (!empty($m['branches']) && is_array($m['branches'])) {
                                    foreach ($m['branches'] as $bid) {
                                        $bn[] = get_the_title((int) $bid);
                                    }
                                }
                                ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html(isset($m['name']) ? $m['name'] : $m['slug']); ?>
                                        <?php if ($default === $m['slug']) : ?><span class="mp-bp__badge"><?php esc_html_e('Default', 'menu-popup'); ?></span><?php endif; ?>
                                    </th>
                                    <td><?php echo esc_html(implode(', ', $names)); ?></td>
                                    <td><?php echo $bn ? esc_html(implode(', ', $bn)) : esc_html__('All branches', 'menu-popup'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><a class="button" href="<?php echo esc_url($menu_url); ?>"><?php esc_html_e('Edit menus', 'menu-popup'); ?></a></p>
                    <?php endif; ?>
                </section>

                <section class="mp-bp__card" id="mp-bp-default" aria-labelledby="mp-bp-mode">
                    <h2 id="mp-bp-mode"><?php esc_html_e('Default menu', 'menu-popup'); ?></h2>
                    <p>
                        <label for="mp-bp-default-menu"><strong><?php esc_html_e('Menu shown when no branch is chosen', 'menu-popup'); ?></strong></label><br>
                        <select id="mp-bp-default-menu" name="default_menu">
                            <option value=""><?php esc_html_e('Not set (keep the current behaviour)', 'menu-popup'); ?></option>
                            <?php foreach ($menus as $m) : if (empty($m['slug'])) { continue; } ?>
                                <option value="<?php echo esc_attr($m['slug']); ?>" <?php selected($default, $m['slug']); ?>><?php echo esc_html(isset($m['name']) ? $m['name'] : $m['slug']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="description"><?php esc_html_e('When set, a page that shows all menus opens this menu first. After a customer picks a branch, they see the menu linked to that branch. The default menu uses each item\'s normal price.', 'menu-popup'); ?></p>

                    <fieldset class="mp-bp__modes">
                        <legend><strong><?php esc_html_e('How customers pick a branch', 'menu-popup'); ?></strong></legend>
                        <label class="mp-bp__mode">
                            <input type="radio" name="force_branch" value="0" <?php checked(!$forced); ?>>
                            <span class="mp-bp__mode-body">
                                <strong><?php esc_html_e('Show the default menu first', 'menu-popup'); ?></strong>
                                <span><?php esc_html_e('Customers can switch to a branch whenever they like.', 'menu-popup'); ?></span>
                            </span>
                        </label>
                        <label class="mp-bp__mode">
                            <input type="radio" name="force_branch" value="1" <?php checked($forced); ?>>
                            <span class="mp-bp__mode-body">
                                <strong><?php esc_html_e('Choose a branch first', 'menu-popup'); ?></strong>
                                <span><?php esc_html_e('The menu opens only after the customer picks a branch.', 'menu-popup'); ?></span>
                            </span>
                        </label>
                    </fieldset>

                    <div class="mp-bp__names">
                        <p>
                            <label for="mp-bp-name-en"><?php esc_html_e('Label on the branch button (English)', 'menu-popup'); ?></label>
                            <input type="text" id="mp-bp-name-en" name="default_menu_name" class="regular-text"
                                   value="<?php echo esc_attr(get_option('menu_popup_default_menu_name', '')); ?>"
                                   placeholder="<?php echo esc_attr__('Main menu', 'menu-popup'); ?>">
                        </p>
                        <p>
                            <label for="mp-bp-name-ar"><?php esc_html_e('Label on the branch button (Arabic)', 'menu-popup'); ?></label>
                            <input type="text" id="mp-bp-name-ar" name="default_menu_name_ar" class="regular-text" dir="rtl"
                                   value="<?php echo esc_attr(get_option('menu_popup_default_menu_name_ar', '')); ?>"
                                   placeholder="المنيو الرئيسي">
                        </p>
                    </div>
                </section>

                <?php if (!empty($branches) && !empty($cat_names)) : ?>
                <section class="mp-bp__card" aria-labelledby="mp-bp-cats-h">
                    <h2 id="mp-bp-cats-h"><?php esc_html_e('Categories by branch', 'menu-popup'); ?></h2>
                    <p class="description"><?php esc_html_e('Untick a category to hide it, with all its items, at that branch. New items added to the category follow the same rule.', 'menu-popup'); ?></p>
                    <div class="mp-bp__tablewrap">
                        <table class="widefat striped">
                            <caption class="screen-reader-text"><?php esc_html_e('Which categories each branch sells', 'menu-popup'); ?></caption>
                            <thead><tr>
                                <th scope="col"><?php esc_html_e('Category', 'menu-popup'); ?></th>
                                <?php foreach ($branches as $b) : ?><th scope="col"><?php echo esc_html(get_the_title($b)); ?></th><?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($cat_names as $slug => $nm) : ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html($nm); ?><input type="hidden" name="cat_slugs[]" value="<?php echo esc_attr($slug); ?>"></th>
                                    <?php foreach ($branches as $b) : ?>
                                        <td><input type="checkbox" name="cat_on[<?php echo (int) $b->ID; ?>][]" value="<?php echo esc_attr($slug); ?>"
                                                   <?php checked(!in_array($slug, Menu_Popup_Pricing::off_categories($b->ID), true)); ?>
                                                   aria-label="<?php echo esc_attr(sprintf(__('%1$s sold at %2$s', 'menu-popup'), $nm, get_the_title($b))); ?>"></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <section id="mp-bp-prices" aria-labelledby="mp-bp-prices-h">
                    <h2 id="mp-bp-prices-h"><?php esc_html_e('Prices and availability by branch', 'menu-popup'); ?></h2>
                    <?php if (empty($branches)) : ?>
                        <div class="notice notice-info inline"><p><?php esc_html_e('Add a branch first, then come back to set its prices.', 'menu-popup'); ?></p></div>
                    <?php else : ?>
                    <div class="mp-bp__filters">
                        <p>
                            <label for="mp-f-menu"><?php esc_html_e('Menu', 'menu-popup'); ?></label>
                            <select id="mp-f-menu">
                                <option value=""><?php esc_html_e('All menus', 'menu-popup'); ?></option>
                                <?php foreach ($menus as $m) : if (empty($m['slug'])) { continue; } ?>
                                    <option value="<?php echo esc_attr($m['slug']); ?>"><?php echo esc_html(isset($m['name']) ? $m['name'] : $m['slug']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="mp-f-cat"><?php esc_html_e('Category', 'menu-popup'); ?></label>
                            <select id="mp-f-cat">
                                <option value=""><?php esc_html_e('All categories', 'menu-popup'); ?></option>
                                <?php foreach ($cat_names as $slug => $nm) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($nm); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="mp-f-q"><?php esc_html_e('Search', 'menu-popup'); ?></label>
                            <input type="search" id="mp-f-q" class="regular-text">
                        </p>
                        <p class="description" id="mp-f-count" role="status" aria-live="polite"></p>
                    </div>

                    <div class="mp-bp__tablewrap">
                        <table class="widefat striped mp-bp__table" id="mp-bp-table">
                            <caption class="screen-reader-text"><?php esc_html_e('Availability and price of each item at each branch', 'menu-popup'); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Item', 'menu-popup'); ?></th>
                                    <th scope="col"><?php esc_html_e('Default price', 'menu-popup'); ?></th>
                                    <?php foreach ($branches as $b) : ?>
                                        <th scope="col"><?php echo esc_html(get_the_title($b)); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $id) :
                                $rows  = Menu_Popup_Pricing::get_rows($id);
                                $price = (string) get_post_meta($id, '_menu_item_price', true);
                                $name  = $this->item_name($id);
                                $sizes = get_post_meta($id, '_menu_item_sizes', true);
                                $cat   = (string) get_post_meta($id, '_menu_item_category', true);
                                ?>
                                <tr data-cat="<?php echo esc_attr($cat); ?>" data-name="<?php echo esc_attr(function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name)); ?>">
                                    <th scope="row">
                                        <input type="hidden" name="items[]" value="<?php echo (int) $id; ?>">
                                        <a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html($name); ?></a>
                                        <?php if (is_array($sizes) && !empty($sizes)) : ?>
                                            <span class="description"><?php esc_html_e('Has sizes: set size prices in the item', 'menu-popup'); ?></span>
                                        <?php endif; ?>
                                    </th>
                                    <td><?php echo $price !== '' ? esc_html($price) : '<span class="description">-</span>'; ?></td>
                                    <?php foreach ($branches as $b) :
                                        $row  = isset($rows[$b->ID]) ? $rows[$b->ID] : array();
                                        $on   = Menu_Popup_Pricing::item_switch_on($id, $b->ID);
                                        $tied = get_post_meta($id, '_menu_item_branch', true);
                                        $tied_elsewhere = ($tied !== '' && $tied !== false && absint($tied) !== (int) $b->ID);
                                        $val  = isset($row['price']) ? $row['price'] : '';
                                        ?>
                                        <td class="mp-bp__cell">
                                            <?php if ($tied_elsewhere) : ?>
                                                <span class="description"><?php esc_html_e('Tied to another branch', 'menu-popup'); ?></span>
                                            <?php else : ?>
                                                <label class="mp-bp__on">
                                                    <input type="checkbox" name="pricing[<?php echo (int) $id; ?>][<?php echo (int) $b->ID; ?>][on]" value="1" <?php checked($on); ?>
                                                           aria-label="<?php echo esc_attr(sprintf(__('%1$s sold at %2$s', 'menu-popup'), $name, get_the_title($b))); ?>">
                                                </label>
                                                <input type="text" inputmode="decimal" class="mp-bp__price small-text"
                                                       name="pricing[<?php echo (int) $id; ?>][<?php echo (int) $b->ID; ?>][price]"
                                                       value="<?php echo esc_attr($val); ?>" placeholder="<?php echo esc_attr($price); ?>"
                                                       aria-label="<?php echo esc_attr(sprintf(__('Price of %1$s at %2$s', 'menu-popup'), $name, get_the_title($b))); ?>">
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="description"><?php esc_html_e('Leave a price empty to use the default price. Untick an item to hide it at that branch. Filtering only hides rows on screen; everything is saved.', 'menu-popup'); ?></p>
                    <?php endif; ?>
                </section>

                <div class="mp-bp__savebar">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save changes', 'menu-popup'); ?></button>
                </div>
            </form>
        </div>
        <?php
        $this->print_assets($menu_cats);
    }

    private function print_assets($menu_cats) {
        ?>
        <style>
        .mp-bp__steps{list-style:none;margin:16px 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;max-width:980px}
        .mp-bp__steps a{display:flex;flex-direction:column;gap:4px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;text-decoration:none;color:#1d2327;height:100%;box-sizing:border-box}
        .mp-bp__steps a:hover{border-color:#2271b1}
        .mp-bp__steps a:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
        .mp-bp__steps span{font-size:13px;color:#50575e}
        .mp-bp__badge{display:inline-block;margin-inline-start:8px;padding:1px 8px;border-radius:10px;background:#e7f1fb;color:#135e96;font-size:12px;font-weight:600}
        .mp-bp__card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:980px}
        .mp-bp__card h2{margin-top:0;font-size:16px}
        .mp-bp__modes{border:0;margin:16px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
        .mp-bp__modes legend{padding:0;margin-bottom:8px}
        .mp-bp__mode{display:flex;gap:10px;align-items:flex-start;border:1px solid #c3c4c7;border-radius:8px;padding:12px;cursor:pointer;background:#fff}
        .mp-bp__mode:has(input:checked){border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;background:#f0f6fc}
        .mp-bp__mode:focus-within{outline:2px solid #2271b1;outline-offset:2px}
        .mp-bp__mode-body{display:flex;flex-direction:column;gap:4px;font-size:13px}
        .mp-bp__names{display:flex;flex-wrap:wrap;gap:8px 24px;margin-top:12px}
        .mp-bp__names label,.mp-bp__filters label{display:block;font-weight:600;margin-bottom:4px}
        .mp-bp__filters{display:flex;flex-wrap:wrap;gap:8px 20px;align-items:flex-end;margin:8px 0}
        .mp-bp__tablewrap{overflow-x:auto;max-width:100%;border:1px solid #dcdcde;border-radius:8px;background:#fff}
        .mp-bp__table{border:0;min-width:640px}
        .mp-bp__table th[scope=row]{font-weight:600}
        .mp-bp__table th[scope=row] .description{display:block;font-weight:400}
        .mp-bp__cell{white-space:nowrap}
        .mp-bp__on{margin-inline-end:8px}
        .mp-bp__price{width:80px}
        .mp-bp__savebar{position:sticky;bottom:0;background:#f6f7f7;border-top:1px solid #dcdcde;padding:12px 0;margin-top:16px;z-index:5}
        .mp-bp input:focus-visible,.mp-bp select:focus-visible,.mp-bp button:focus-visible{outline:2px solid #2271b1;outline-offset:1px}
        </style>
        <script>
        (function(){
          var table=document.getElementById('mp-bp-table'); if(!table) return;
          var menuCats=<?php echo wp_json_encode($menu_cats); ?>;
          var fm=document.getElementById('mp-f-menu'), fc=document.getElementById('mp-f-cat'), fq=document.getElementById('mp-f-q'), out=document.getElementById('mp-f-count');
          var rows=table.querySelectorAll('tbody tr');
          function run(){
            var m=fm.value, c=fc.value, q=fq.value.trim().toLowerCase(), allowed=m&&menuCats[m]&&menuCats[m].length?menuCats[m]:null, n=0;
            rows.forEach(function(r){
              var cat=r.getAttribute('data-cat')||'', nm=r.getAttribute('data-name')||'';
              var ok=(!allowed||allowed.indexOf(cat)!==-1)&&(!c||cat===c)&&(!q||nm.indexOf(q)!==-1);
              r.style.display=ok?'':'none'; if(ok) n++;
            });
            out.textContent=n+' / '+rows.length;
          }
          [fm,fc].forEach(function(e){e.addEventListener('change',run);}); fq.addEventListener('input',run); run();
        })();
        </script>
        <?php
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'menu-popup'), 403);
        }
        check_admin_referer('mp_save_branch_pricing', 'mp_bp_nonce');

        update_option('menu_popup_force_branch_selection', (isset($_POST['force_branch']) && $_POST['force_branch'] === '1') ? '1' : '0');
        update_option('menu_popup_default_menu', sanitize_title(wp_unslash(isset($_POST['default_menu']) ? $_POST['default_menu'] : '')));
        update_option('menu_popup_default_menu_name', sanitize_text_field(wp_unslash(isset($_POST['default_menu_name']) ? $_POST['default_menu_name'] : '')));
        update_option('menu_popup_default_menu_name_ar', sanitize_text_field(wp_unslash(isset($_POST['default_menu_name_ar']) ? $_POST['default_menu_name_ar'] : '')));

        $branch_ids = array();
        foreach (Menu_Popup_Pricing::branches() as $b) {
            $branch_ids[] = (int) $b->ID;
        }

        // Categories switched off per branch
        $cat_slugs = isset($_POST['cat_slugs']) && is_array($_POST['cat_slugs']) ? array_map('sanitize_text_field', wp_unslash($_POST['cat_slugs'])) : array();
        if (!empty($cat_slugs)) {
            $cat_on  = isset($_POST['cat_on']) && is_array($_POST['cat_on']) ? wp_unslash($_POST['cat_on']) : array();
            $cat_off = array();
            foreach ($branch_ids as $bid) {
                $on = isset($cat_on[$bid]) && is_array($cat_on[$bid]) ? array_map('sanitize_text_field', $cat_on[$bid]) : array();
                $off = array_values(array_diff($cat_slugs, $on));
                if (!empty($off)) {
                    $cat_off[$bid] = $off;
                }
            }
            update_option(Menu_Popup_Pricing::CAT_OFF, $cat_off);
        }

        $item_ids = isset($_POST['items']) && is_array($_POST['items']) ? array_map('absint', wp_unslash($_POST['items'])) : array();
        $pricing  = isset($_POST['pricing']) && is_array($_POST['pricing']) ? wp_unslash($_POST['pricing']) : array();

        foreach ($item_ids as $id) {
            if ($id < 1 || !current_user_can('edit_post', $id)) {
                continue;
            }
            $type = get_post_type($id);
            if ($type !== 'menu_item' && !($type === 'post' && metadata_exists('post', $id, '_menu_item_name'))) {
                continue;
            }
            // Branches tied to the item the older way are not editable here
            $cover = $branch_ids;
            $tied  = get_post_meta($id, '_menu_item_branch', true);
            if ($tied !== '' && $tied !== false) {
                $cover = in_array((int) $tied, $branch_ids, true) ? array((int) $tied) : array();
            }
            $posted = isset($pricing[$id]) && is_array($pricing[$id]) ? $pricing[$id] : array();
            Menu_Popup_Pricing::save_item($id, $posted, $cover);
        }

        Menu_Popup_Pricing::refresh_active_flag();
        if (class_exists('Menu_Popup_Cache')) {
            Menu_Popup_Cache::get_instance()->clear_menu_cache();
        }

        wp_safe_redirect(add_query_arg(array('page' => self::PAGE, 'saved' => 1), admin_url('admin.php')));
        exit;
    }

    // ---------------------------------------------------------------------
    // Item edit screen
    // ---------------------------------------------------------------------

    public function add_item_box() {
        add_meta_box(
            'mp_branch_pricing',
            __('Pricing by branch', 'menu-popup'),
            array($this, 'render_item_box'),
            'menu_item',
            'normal',
            'default'
        );
    }

    public function render_item_box($post) {
        wp_nonce_field('mp_item_branch_pricing', 'mp_item_bp_nonce');
        $branches = Menu_Popup_Pricing::branches();
        if (empty($branches)) {
            echo '<p class="description">' . esc_html__('Add a branch to set prices per branch.', 'menu-popup') . '</p>';
            return;
        }
        $tied = get_post_meta($post->ID, '_menu_item_branch', true);
        if ($tied !== '' && $tied !== false) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(
                /* translators: %s: branch name */
                __('This item is tied to %s by the older Branch setting, so it is hidden at other branches whatever is ticked below. Set Branch to "All Branches" to control it from here.', 'menu-popup'),
                get_the_title((int) $tied)
            )) . '</p></div>';
        }
        $rows  = Menu_Popup_Pricing::get_rows($post->ID);
        $sizes = get_post_meta($post->ID, '_menu_item_sizes', true);
        $sizes = is_array($sizes) ? $sizes : array();
        $price = (string) get_post_meta($post->ID, '_menu_item_price', true);
        ?>
        <p class="description"><?php esc_html_e('Empty price = use the item\'s normal price. Untick to hide the item at that branch. Save the item first if you just added sizes.', 'menu-popup'); ?></p>
        <table class="widefat striped">
            <thead><tr>
                <th scope="col"><?php esc_html_e('Branch', 'menu-popup'); ?></th>
                <th scope="col"><?php esc_html_e('Sold here', 'menu-popup'); ?></th>
                <th scope="col"><?php esc_html_e('Price', 'menu-popup'); ?></th>
                <?php foreach ($sizes as $v) : if (!is_array($v) || !isset($v['size'])) { continue; } ?>
                    <th scope="col"><?php echo esc_html($v['size']); ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($branches as $b) :
                $row = isset($rows[$b->ID]) ? $rows[$b->ID] : array();
                $on  = Menu_Popup_Pricing::item_switch_on($post->ID, $b->ID);
                $bn  = get_the_title($b);
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html($bn); ?></th>
                    <td><input type="checkbox" name="mp_bp[<?php echo (int) $b->ID; ?>][on]" value="1" <?php checked($on); ?>
                               aria-label="<?php echo esc_attr(sprintf(__('Sold at %s', 'menu-popup'), $bn)); ?>"></td>
                    <td><input type="text" inputmode="decimal" class="small-text" name="mp_bp[<?php echo (int) $b->ID; ?>][price]"
                               value="<?php echo esc_attr(isset($row['price']) ? $row['price'] : ''); ?>" placeholder="<?php echo esc_attr($price); ?>"
                               aria-label="<?php echo esc_attr(sprintf(__('Price at %s', 'menu-popup'), $bn)); ?>"></td>
                    <?php foreach ($sizes as $v) : if (!is_array($v) || !isset($v['size'])) { continue; }
                        $sn  = (string) $v['size'];
                        $sv  = isset($row['sizes'][$sn]) ? $row['sizes'][$sn] : '';
                        ?>
                        <td><input type="text" inputmode="decimal" class="small-text" name="mp_bp[<?php echo (int) $b->ID; ?>][sizes][<?php echo esc_attr($sn); ?>]"
                                   value="<?php echo esc_attr($sv); ?>" placeholder="<?php echo esc_attr(isset($v['price']) ? $v['price'] : ''); ?>"
                                   aria-label="<?php echo esc_attr(sprintf(__('%1$s price at %2$s', 'menu-popup'), $sn, $bn)); ?>"></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function save_item_box($post_id) {
        if (get_post_type($post_id) !== 'menu_item' || empty($_POST) || !isset($_POST['mp_item_bp_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['mp_item_bp_nonce'], 'mp_item_branch_pricing')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $branch_ids = array();
        foreach (Menu_Popup_Pricing::branches() as $b) {
            $branch_ids[] = (int) $b->ID;
        }
        $posted = isset($_POST['mp_bp']) && is_array($_POST['mp_bp']) ? wp_unslash($_POST['mp_bp']) : array();

        // Items tied to one branch the older way keep that rule
        Menu_Popup_Pricing::save_item($post_id, $posted, $branch_ids);
        Menu_Popup_Pricing::refresh_active_flag();
        if (class_exists('Menu_Popup_Cache')) {
            Menu_Popup_Cache::get_instance()->clear_menu_cache();
        }
    }
}
