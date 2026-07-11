<?php
/*
Plugin Name: WPU Import Export
Plugin URI: https://github.com/WordPressUtilities/wpu_import_export
Update URI: https://github.com/WordPressUtilities/wpu_import_export
Description: Simple import export
Version: 0.10.0
Author: Darklg
Author URI: https://darklg.me/
Text Domain: wpu_import_export
Domain Path: /lang
Requires at least: 6.2
Requires PHP: 8.0
Network: Optional
License: MIT License
License URI: https://opensource.org/licenses/MIT
*/

if (!defined('ABSPATH')) {
    exit();
}

class WPUImportExport {
    private $plugin_version = '0.10.0';
    private $plugin_settings = array(
        'id' => 'wpu_import_export',
        'name' => 'WPU Import Export'
    );
    private $basetoolbox;
    private $capability = 'manage_options';
    private $messages;
    private $adminpages;
    private $basefields;
    private $import_details = array();
    private $plugin_description;
    private $post_data = array();
    private $value_types = array();
    private $post_types = array();
    private $translation_groups = array();
    public function __construct() {
        add_action('init', array(&$this, 'load_translation'));
        add_action('init', array(&$this, 'load_toolbox'));
        add_action('init', array(&$this, 'load_messages'));
        add_action('init', array(&$this, 'load_adminpage'));
        add_action('init', array(&$this, 'init'), 20);
        add_action('init', array(&$this, 'load_basefields'), 30);

        /* Admin assets */
        add_action('admin_enqueue_scripts', array(&$this,
            'admin_assets'
        ));

        /* Compatibility with WPU ACF Flexible */
        add_filter('wpu_import_export_post_types', array(&$this, 'wpu_acf_flexible__post_types'), 20, 1);

        /* Compatibility with WPU Post Metas */
        add_filter('wpu_import_export_post_types', array(&$this, 'wpu_post_metas__post_types'), 21, 1);
    }

    /* ----------------------------------------------------------
      DEPENDENCIES
    ---------------------------------------------------------- */

    public function load_translation() {
        $lang_dir = dirname(plugin_basename(__FILE__)) . '/lang/';
        if (strpos(__DIR__, 'mu-plugins') !== false) {
            load_muplugin_textdomain('wpu_import_export', $lang_dir);
        } else {
            load_plugin_textdomain('wpu_import_export', false, $lang_dir);
        }
        # TRANSLATION
        $this->plugin_description = __('Simple import export', 'wpu_import_export');
    }

    public function load_toolbox() {
        require_once __DIR__ . '/inc/WPUBaseToolbox/WPUBaseToolbox.php';
        $this->basetoolbox = new \wpu_import_export\WPUBaseToolbox(array(
            'need_form_js' => false
        ));
    }

    public function load_messages() {
        if (!is_admin()) {
            return;
        }
        require_once __DIR__ . '/inc/WPUBaseMessages/WPUBaseMessages.php';
        $this->messages = new \wpu_import_export\WPUBaseMessages($this->plugin_settings['id']);
    }

    public function load_adminpage() {
        # CUSTOM PAGE
        $admin_pages = array(
            'export' => array(
                'icon_url' => 'dashicons-cloud-upload',
                'menu_name' => $this->plugin_settings['name'],
                'name' => __('Export', 'wpu_import_export'),
                'settings_link' => true,
                'settings_name' => __('Settings', 'wpu_import_export'),
                'function_content' => array(&$this,
                    'page_content__export'
                ),
                'function_action' => array(&$this,
                    'page_action__export'
                )
            ),
            'import' => array(
                'icon_url' => 'dashicons-cloud-download',
                'name' => __('Import', 'wpu_import_export'),
                'has_file' => true,
                'parent' => 'export',
                'function_content' => array(&$this,
                    'page_content__import'
                ),
                'function_action' => array(&$this,
                    'page_action__import'
                )
            )
        );
        $this->capability = apply_filters('wpu_import_export_capability', $this->capability);
        $pages_options = array(
            'id' => $this->plugin_settings['id'],
            'level' => $this->capability,
            'basename' => plugin_basename(__FILE__)
        );
        // Init admin page
        require_once __DIR__ . '/inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $this->adminpages = new \wpu_import_export\WPUBaseAdminPage();
        $this->adminpages->init($pages_options, $admin_pages);
    }

    public function load_basefields() {

        /* Extract different uniqids */
        $unique_keys = array();
        foreach ($this->post_types as $post_type => $post_type_data) {
            if (!isset($post_type_data['unique_key'])) {
                continue;
            }
            $key = $post_type_data['unique_key'];
            if (!isset($unique_keys[$key])) {
                $unique_keys[$key] = array();
            }
            $unique_keys[$key][] = $post_type;
        }

        $fields = array();
        $field_groups = array();

        foreach ($unique_keys as $key => $post_types) {
            $fields[$key] = array(
                'group' => 'wpu_import_export_' . $key,
                'label' => 'Unique ID',
                'readonly' => true
            );
            $field_groups['wpu_import_export_' . $key] = array(
                'label' => 'Import - Export',
                'post_types' => $post_types
            );
        }

        require_once __DIR__ . '/inc/WPUBaseFields/WPUBaseFields.php';
        $this->basefields = new \wpu_import_export\WPUBaseFields($fields, $field_groups);
    }

    /* ----------------------------------------------------------
      Assets
    ---------------------------------------------------------- */

    public function admin_assets($hook_suffix) {
        /* Only load on the plugin admin pages */
        if (strpos($hook_suffix, $this->plugin_settings['id']) === false) {
            return;
        }
        wp_enqueue_style('wpu-import-export-style', plugins_url('assets/style.css', __FILE__), array(), $this->plugin_version);
    }

    /* ----------------------------------------------------------
      INIT
    ---------------------------------------------------------- */

    public function init() {
        $this->build_default_post_data();
        $this->build_default_value_types();
        $post_types = apply_filters('wpu_import_export_post_types', array());
        foreach ($post_types as $post_type => $post_type_data) {
            $post_type_data = $this->clean_post_type_data($post_type, $post_type_data);
            if (empty($post_type_data)) {
                continue;
            }
            $this->post_types[$post_type] = $post_type_data;
        }
    }

    public function build_default_post_data() {
        $post_data = array(
            'post_title' => array(
                'validate_value' => function ($value) {
                    if (!is_string($value)) {
                        return '';
                    }
                    return sanitize_text_field($value);
                },
                'get_value' => function ($post) {
                    return $post->post_title;
                }
            ),
            'post_content' => array(
                'get_value' => function ($post) {
                    return $post->post_content;
                },
                'validate_value' => function ($value) {
                    if (!is_string($value)) {
                        return '';
                    }
                    return wp_kses_post($value);
                }
            ),
            'post_name' => array(
                'get_value' => function ($post) {
                    return $post->post_name;
                },
                'validate_value' => function ($value) {
                    if (!is_string($value)) {
                        return '';
                    }
                    return sanitize_title($value);
                }
            ),
            'post_excerpt' => array(
                'get_value' => function ($post) {
                    return $post->post_excerpt;
                },
                'validate_value' => function ($value) {
                    if (!is_string($value)) {
                        return '';
                    }
                    return wp_kses_post($value);
                }
            ),
            'post_date' => array(
                'get_value' => function ($post) {
                    return $post->post_date;
                },
                'validate_value' => function ($value) {
                    $timestamp = strtotime($value);
                    if ($timestamp === false) {
                        /* Unparseable / empty date: ignore, keep existing post date */
                        return null;
                    }
                    return date('Y-m-d H:i:s', $timestamp);
                }
            )
        );
        $this->post_data = apply_filters('wpu_import_export_post_data', $post_data);
    }

    public function build_default_value_types() {
        $value_types = array(
            'number' => array(
                'default_value' => 0,
                'validate_value' => function ($value, $default) {
                    /* Keep digits, separators and sign only */
                    $clean = preg_replace('/[^0-9.,\-]/', '', (string) $value);
                    /* French format: comma is the decimal separator, dots are thousands */
                    if (strpos($clean, ',') !== false) {
                        $clean = str_replace(',', '.', str_replace('.', '', $clean));
                    }
                    if (!is_numeric($clean)) {
                        return $default;
                    }
                    return $clean + 0;
                }
            ),
            'email' => array(
                'default_value' => '',
                'validate_value' => function ($value, $default) {
                    $email = sanitize_email((string) $value);
                    return is_email($email) ? $email : $default;
                }
            ),
            'url' => array(
                'default_value' => '',
                'validate_value' => function ($value, $default) {
                    $url = esc_url_raw(trim((string) $value));
                    return $url !== '' ? $url : $default;
                }
            ),
            'text' => array(
                'default_value' => '',
                'validate_value' => function ($value, $default) {
                    return sanitize_text_field((string) $value);
                }
            )
        );
        $this->value_types = apply_filters('wpu_import_export_value_types', $value_types);
    }

    public function clean_post_type_data($post_type, $post_type_data) {

        /* Valid type */
        if (!is_array($post_type_data)) {
            return array();
        }
        if (!isset($post_type_data['post_type'])) {
            $post_type_data['post_type'] = $post_type;
        }
        if (!post_type_exists($post_type_data['post_type'])) {
            return array();
        }
        /* Build default data */
        $post_type_data['unique_key'] = isset($post_type_data['unique_key']) ? $post_type_data['unique_key'] : 'uniqid';
        $post_type_data['columns'] = isset($post_type_data['columns']) && is_array($post_type_data['columns']) ? $post_type_data['columns'] : array();

        /* Load post data */
        if (isset($post_type_data['load_post_data']) && $post_type_data['load_post_data'] === true) {
            $default_columns = array();
            foreach ($this->post_data as $post_data_key => $post_data_value) {
                $default_columns[$post_data_key] = array(
                    'type' => $post_data_key
                );
            }
            $post_type_data['columns'] = array_merge($default_columns, $post_type_data['columns']);
        }

        /* Build default columns */
        if (empty($post_type_data['columns'])) {
            $post_type_data['columns'] = array(
                'title' => array(
                    'type' => 'post_title'
                )
            );
        }

        /* Ensure columns are valid */
        $default_types = array_keys($this->post_data);
        $default_types = array_merge(array(
            'post_meta',
            'repeater',
            'taxonomy',
            'acf_flexible_block'
        ), $default_types);
        foreach ($post_type_data['columns'] as $column_name => $column_data) {
            if (!isset($column_data['type'])) {
                $post_type_data['columns'][$column_name]['type'] = 'post_meta';
            }
            if ($post_type_data['columns'][$column_name]['type'] == 'post_meta') {
                $post_type_data['columns'][$column_name]['meta_key'] = isset($column_data['meta_key']) ? $column_data['meta_key'] : $column_name;
            }
            if ($post_type_data['columns'][$column_name]['type'] === 'repeater') {
                if (!isset($column_data['sub_fields']) || !is_array($column_data['sub_fields'])) {
                    unset($post_type_data['columns'][$column_name]);
                    continue;
                }
                $post_type_data['columns'][$column_name]['meta_key'] = isset($column_data['meta_key']) ? $column_data['meta_key'] : $column_name;
                $post_type_data['columns'][$column_name]['format'] = isset($column_data['format']) ? $column_data['format'] : 'indexed';
                $post_type_data['columns'][$column_name]['sub_fields'] = array_values($column_data['sub_fields']);
                if ($post_type_data['columns'][$column_name]['format'] === 'columns') {
                    if (!isset($column_data['max']) || !is_numeric($column_data['max'])) {
                        unset($post_type_data['columns'][$column_name]);
                        continue;
                    }
                    $post_type_data['columns'][$column_name]['max'] = (int) $column_data['max'];
                }
            }
            if ($post_type_data['columns'][$column_name]['type'] === 'taxonomy') {
                if (empty($column_data['taxonomy'])) {
                    unset($post_type_data['columns'][$column_name]);
                    continue;
                }
            }
            if ($post_type_data['columns'][$column_name]['type'] === 'acf_flexible_block') {
                $post_type_data['columns'][$column_name]['field'] = isset($column_data['field']) ? $column_data['field'] : 'content-blocks';
                $post_type_data['columns'][$column_name]['subfield'] = isset($column_data['subfield']) ? $column_data['subfield'] : 'content';
                $post_type_data['columns'][$column_name]['layout'] = isset($column_data['layout']) ? $column_data['layout'] : 'content';
            }
            if (!in_array($post_type_data['columns'][$column_name]['type'], $default_types)) {
                unset($post_type_data['columns'][$column_name]);
                continue;
            }
            /* Normalize accepted columns (CSV header aliases for import) */
            $accepted_columns = isset($column_data['accepted_columns']) ? $column_data['accepted_columns'] : array();
            if (!is_array($accepted_columns)) {
                $accepted_columns = array($accepted_columns);
            }
            $accepted_columns = array_map(function ($name) {
                return strtolower(trim($name));
            }, $accepted_columns);
            $post_type_data['columns'][$column_name]['accepted_columns'] = $accepted_columns;
        }

        return $post_type_data;
    }

    public function wpu_acf_flexible__post_types($post_types) {
        $flexible_groups = apply_filters('wpu_import_export_wpu_acf_flexible_groups', array());

        foreach ($flexible_groups as $group_conf) {
            /* Valid group definition */
            if (!isset($group_conf['post_type'], $group_conf['flexible_key'], $group_conf['group'])) {
                continue;
            }

            /* Post type is tracked */
            if (!isset($post_types[$group_conf['post_type']]['columns'])) {
                continue;
            }
            $columns = $this->wpu_import_export_get_flexible_columns($group_conf['flexible_key'], $group_conf['group']);
            $post_types[$group_conf['post_type']]['columns'] = array_merge($post_types[$group_conf['post_type']]['columns'], $columns);
        }

        return $post_types;
    }

    public function wpu_import_export_get_flexible_columns($flexible_key, $group) {
        $flexible_content = apply_filters('wpu_acf_flexible_content', array());
        if (!is_array($flexible_content) || !isset($flexible_content[$flexible_key]['fields'][$group]['sub_fields'])) {
            return array();
        }

        return $this->wpu_import_export_collect_flexible_columns($flexible_content[$flexible_key]['fields'][$group]['sub_fields'], $group, $group);
    }

    public function wpu_import_export_collect_flexible_columns($fields, $prefix, $group) {
        $columns = array();
        foreach ($fields as $key => $field) {
            $meta_key = $prefix . '_' . $key;
            if (isset($field['has_wpu_import_export']) && $field['has_wpu_import_export']) {
                $column_key = substr($meta_key, strlen($group) + 1);
                $columns[$column_key] = $this->build_column_from_field($field, $meta_key);
            }
            if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                $columns = array_merge($columns, $this->wpu_import_export_collect_flexible_columns($field['sub_fields'], $meta_key, $group));
            }
        }

        return $columns;
    }

    public function wpu_post_metas__post_types($post_types) {
        $fields = apply_filters('wputh_post_metas_fields', array());
        $boxes = apply_filters('wputh_post_metas_boxes', array());

        foreach ($fields as $key => $field) {
            if (!isset($field['has_wpu_import_export'], $field['box']) || !$field['has_wpu_import_export']) {
                continue;
            }
            $box = isset($boxes[$field['box']]) ? $boxes[$field['box']] : false;
            if (!$box || !isset($box['post_type'])) {
                continue;
            }
            $post_type_keys = (array) $box['post_type'];
            foreach ($post_type_keys as $post_type) {
                if (!isset($post_types[$post_type]['columns'])) {
                    continue;
                }
                $post_types[$post_type]['columns'][$key] = $this->build_column_from_field($field, $key);
            }
        }

        return $post_types;
    }

    private function build_column_from_field($field, $meta_key) {
        $column = $field['has_wpu_import_export'];
        if (!is_array($column)) {
            $column = array();
        }
        if (!isset($column['meta_key'])) {
            $column['meta_key'] = $meta_key;
        }
        /* Derive value_type from the source field type unless explicitly set */
        if (!isset($column['value_type']) && isset($field['type'])) {
            $column['value_type'] = $field['type'];
        }
        return array_merge(array('type' => 'post_meta'), $column);
    }

    /* Resolve a post_meta value against an explicit closure or a value_type validator */
    private function validate_meta_value($value, $column_data) {
        /* Explicit column closure wins */
        if (isset($column_data['validate_value']) && is_callable($column_data['validate_value'])) {
            $default = isset($column_data['default_value']) ? $column_data['default_value'] : '';
            return call_user_func($column_data['validate_value'], $value, $default);
        }
        /* Fall back to the value_type registry */
        if (empty($column_data['value_type']) || !isset($this->value_types[$column_data['value_type']])) {
            return $value;
        }
        $type = $this->value_types[$column_data['value_type']];
        $default = isset($column_data['default_value']) ? $column_data['default_value'] : $type['default_value'];
        return call_user_func($type['validate_value'], $value, $default);
    }

    /* ----------------------------------------------------------
      EXPORT
    ---------------------------------------------------------- */

    public function page_content__export() {
        if (empty($this->post_types)) {
            $this->set_message('empty_post_types', __('No post types found', 'wpu_import_export'), 'error');
            return;
        }

        foreach ($this->post_types as $post_type => $post_type_data) {
            $post_type_object = get_post_type_object($post_type_data['post_type']);
            if (!$post_type_object) {
                continue;
            }

            echo '<h2>' . esc_html($post_type_object->label) . '</h2>';
            $this->display_export_filters($post_type);
            submit_button(__('Export', 'wpu_import_export'), 'primary', 'export_' . $post_type);
            echo '<hr />';
        }
    }

    /* Display taxonomy & status filters for a post type */
    public function display_export_filters($post_type) {

        echo '<details class="wpu-import-export-filters">';
        echo '<summary>' . esc_html__('Filters', 'wpu_import_export') . '</summary>';

        /* Taxonomies attached to the post type with a UI */
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        foreach ($taxonomies as $taxonomy) {
            if (!$taxonomy->show_ui) {
                continue;
            }
            $terms = get_terms(array(
                'taxonomy' => $taxonomy->name,
                'hide_empty' => true
            ));
            if (is_wp_error($terms) || count($terms) < 2) {
                continue;
            }
            $field_name = 'filter[' . esc_attr($post_type) . '][tax][' . esc_attr($taxonomy->name) . '][]';
            echo '<p>';
            echo '<label class="wpu-import-export-label">' . esc_html($taxonomy->label) . '</label>';
            echo '<span class="wpu-import-export-term-list">';
            foreach ($terms as $term) {
                $term_field_id = 'term_' . esc_attr($post_type) . '_' . esc_attr($taxonomy->name) . '_' . esc_attr($term->term_id);
                echo '<label for="' . $term_field_id . '" style="display:block;">';
                echo '<input type="checkbox" id="' . $term_field_id . '" name="' . $field_name . '" value="' . esc_attr($term->term_id) . '" /> ';
                echo esc_html($term->name);
                echo '</label>';
            }
            echo '</span>';
            echo '</p>';
        }

        /* Post statuses */
        $statuses = $this->get_valid_export_statuses();
        echo '<p>';
        echo '<label class="wpu-import-export-label">' . esc_html__('Status', 'wpu_import_export') . '</label>';
        foreach ($statuses as $status) {
            $field_id = 'status_' . esc_attr($post_type) . '_' . esc_attr($status->name);
            echo '<label for="' . $field_id . '" style="margin-right:1em;">';
            echo '<input type="checkbox" id="' . $field_id . '" name="filter[' . esc_attr($post_type) . '][status][]" value="' . esc_attr($status->name) . '"' . checked('publish', $status->name, false) . ' /> ';
            echo esc_html($status->label);
            echo '</label>';
        }
        echo '</p>';

        /* Date range */
        echo '<p>';
        echo '<label class="wpu-import-export-label">' . esc_html__('Date', 'wpu_import_export') . '</label>';
        echo '<label for="date_after_' . esc_attr($post_type) . '" style="margin-right:0.5em;">' . esc_html__('From', 'wpu_import_export') . '</label>';
        echo '<input type="date" id="date_after_' . esc_attr($post_type) . '" name="filter[' . esc_attr($post_type) . '][date_after]" style="margin-right:1em;" />';
        echo '<label for="date_before_' . esc_attr($post_type) . '" style="margin-right:0.5em;">' . esc_html__('To', 'wpu_import_export') . '</label>';
        echo '<input type="date" id="date_before_' . esc_attr($post_type) . '" name="filter[' . esc_attr($post_type) . '][date_before]" />';
        echo '</p>';

        /* Languages (Polylang) */
        $languages = $this->get_languages();
        if (count($languages) > 1 && $this->is_translated_post_type($post_type)) {
            echo '<p>';
            echo '<label class="wpu-import-export-label">' . esc_html__('Language', 'wpu_import_export') . '</label>';
            foreach ($languages as $slug => $name) {
                $field_id = 'lang_' . esc_attr($post_type) . '_' . esc_attr($slug);
                echo '<label for="' . $field_id . '" style="margin-right:1em;">';
                echo '<input type="checkbox" id="' . $field_id . '" name="filter[' . esc_attr($post_type) . '][lang][]" value="' . esc_attr($slug) . '" /> ';
                echo esc_html($name);
                echo '</label>';
            }
            echo '</p>';
        }

        echo '</details>';
    }

    /* Available Polylang languages, slug => name (empty if Polylang inactive) */
    public function get_languages() {
        if (!function_exists('pll_languages_list')) {
            return array();
        }
        $slugs = pll_languages_list(array('fields' => 'slug'));
        $names = pll_languages_list(array('fields' => 'name'));
        if (!is_array($slugs) || !is_array($names) || count($slugs) !== count($names)) {
            return array();
        }
        return array_combine($slugs, $names);
    }

    /* Whether the special lang/translation columns apply to this post type */
    public function is_translated_post_type($post_type) {
        return function_exists('pll_is_translated_post_type') && pll_is_translated_post_type($post_type);
    }

    public function page_action__export() {
        foreach ($this->post_types as $post_type => $post_type_data) {
            if (isset($_POST['export_' . $post_type])) {
                $this->export_post_type($post_type, $post_type_data);
            }
        }
    }

    public function export_post_type($post_type, $post_type_data) {
        $args = array(
            'post_type' => $post_type,
            'numberposts' => -1,
            'post_status' => $this->get_export_statuses($post_type),
            'tax_query' => $this->get_export_tax_query($post_type),
            'date_query' => $this->get_export_date_query($post_type)
        );
        $lang = $this->get_export_lang($post_type);
        if (!empty($lang)) {
            $args['lang'] = $lang;
        }

        $posts = get_posts($args);
        if (empty($posts)) {
            $this->set_message('export_empty', __('No posts found for export with the current filters.', 'wpu_import_export'), 'error');
            return;
        }
        $lines = array();
        foreach ($posts as $post) {
            $lines[] = $this->post_to_array($post, $post_type_data);
        }
        $lines = apply_filters('wpu_import_export_export_lines', $lines, $post_type, $post_type_data);
        $this->basetoolbox->export_array_to_csv($lines, $post_type);
    }

    /* Get valid export statuses (admin status list, without inactive & trash) */
    public function get_valid_export_statuses() {
        $excluded_statuses = array('auto-draft', 'acf-disabled', 'trash');
        $statuses = get_post_stati(array('show_in_admin_status_list' => true), 'objects');
        foreach ($excluded_statuses as $status) {
            if (isset($statuses[$status])) {
                unset($statuses[$status]);
            }
        }
        return $statuses;
    }

    /* Get sanitized statuses from posted filters, fallback to publish */
    public function get_export_statuses($post_type) {
        $posted = isset($_POST['filter'][$post_type]['status']) && is_array($_POST['filter'][$post_type]['status']) ? $_POST['filter'][$post_type]['status'] : array();
        $valid_statuses = array_keys($this->get_valid_export_statuses());
        $statuses = array();
        foreach ($posted as $status) {
            if (in_array($status, $valid_statuses, true)) {
                $statuses[] = $status;
            }
        }
        return empty($statuses) ? array('publish') : $statuses;
    }

    /* Build a date_query from posted filters */
    public function get_export_date_query($post_type) {
        $posted = isset($_POST['filter'][$post_type]) ? $_POST['filter'][$post_type] : array();
        $after = isset($posted['date_after']) ? sanitize_text_field($posted['date_after']) : '';
        $before = isset($posted['date_before']) ? sanitize_text_field($posted['date_before']) : '';

        $date_query = array();
        if ($after && preg_match('/^\d{4}-\d{2}-\d{2}$/', $after)) {
            $date_query['after'] = $after . ' 00:00:00';
            $date_query['inclusive'] = true;
        }
        if ($before && preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
            $date_query['before'] = $before . ' 23:59:59';
            $date_query['inclusive'] = true;
        }

        return empty($date_query) ? array() : array($date_query);
    }

    /* Build the Polylang lang arg from posted filters. Empty string = all languages (neutralizes Polylang's current-language default) */
    public function get_export_lang($post_type) {
        $posted = isset($_POST['filter'][$post_type]['lang']) && is_array($_POST['filter'][$post_type]['lang']) ? $_POST['filter'][$post_type]['lang'] : array();
        $valid = array_keys($this->get_languages());
        $langs = array();
        foreach ($posted as $lang) {
            if (in_array($lang, $valid, true)) {
                $langs[] = $lang;
            }
        }
        return implode(',', $langs);
    }

    /* Build a tax_query from posted filters */
    public function get_export_tax_query($post_type) {
        $posted = isset($_POST['filter'][$post_type]['tax']) && is_array($_POST['filter'][$post_type]['tax']) ? $_POST['filter'][$post_type]['tax'] : array();
        $valid_taxonomies = get_object_taxonomies($post_type);
        $tax_query = array('relation' => 'AND');
        foreach ($posted as $taxonomy => $term_ids) {
            if (!in_array($taxonomy, $valid_taxonomies, true) || !is_array($term_ids)) {
                continue;
            }
            $term_ids = array_filter(array_map('intval', $term_ids));
            if (empty($term_ids)) {
                continue;
            }
            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_ids,
                'operator' => 'IN'
            );
        }
        return count($tax_query) > 1 ? $tax_query : array();
    }

    public function post_to_array($post, $post_type_data) {
        $lines = array();
        $lines[$post_type_data['unique_key']] = $this->get_export_unique_key($post, $post_type_data['unique_key']);
        /* Special lang column, right after the unique key (Polylang, translated post types only) */
        if ($this->is_translated_post_type($post_type_data['post_type'])) {
            $lines['lang'] = pll_get_post_language($post->ID);
        }
        foreach ($post_type_data['columns'] as $column_name => $column_data) {
            foreach ($this->post_data as $post_data_key => $post_data_value) {
                if ($column_data['type'] == $post_data_key) {
                    $lines[$column_name] = call_user_func($post_data_value['get_value'], $post);
                }
            }
            if ($column_data['type'] == 'post_meta') {
                $lines[$column_name] = get_post_meta($post->ID, $column_data['meta_key'], true);
            }
            if ($column_data['type'] === 'taxonomy') {
                $terms = get_the_terms($post->ID, $column_data['taxonomy']);
                $lines[$column_name] = (!$terms || is_wp_error($terms)) ? '' : implode(',', wp_list_pluck($terms, 'slug'));
            }
            if ($column_data['type'] === 'repeater') {
                $exported = $this->export_repeater_meta($post->ID, $column_data);
                if (is_array($exported)) {
                    $lines = array_merge($lines, $exported);
                } else {
                    $lines[$column_name] = $exported;
                }
            }
            if ($column_data['type'] === 'acf_flexible_block') {
                /* Import-only: keep the header column but export nothing */
                $lines[$column_name] = '';
            }
        }
        return $lines;
    }

    public function export_repeater_meta($post_id, $column_data) {
        $meta_key = $column_data['meta_key'];
        $count = (int) get_post_meta($post_id, $meta_key, true);
        $sub_fields = $column_data['sub_fields'];

        if ($column_data['format'] === 'newline') {
            $values = array();
            for ($i = 0; $i < $count; $i++) {
                $val = get_post_meta($post_id, $meta_key . '_' . $i . '_' . $sub_fields[0], true);
                if ($val !== '') {
                    $values[] = $val;
                }
            }
            return implode("\n", $values);
        }
        if ($column_data['format'] === 'columns') {
            $max = $column_data['max'];
            $result = array();
            for ($i = 0; $i < $max; $i++) {
                foreach ($sub_fields as $sub_field) {
                    $result[$meta_key . '_' . $i . '_' . $sub_field] = get_post_meta($post_id, $meta_key . '_' . $i . '_' . $sub_field, true);
                }
            }
            return $result;
        }
        return '';
    }

    public function get_post_unique_key($post_id, $key) {
        $unique_key_value = get_post_meta($post_id, $key, true);
        if (!$unique_key_value) {
            $post_type = get_post_type($post_id);
            $unique_key_value = $post_type . '_' . $post_id . '_' . uniqid();
            update_post_meta($post_id, $key, $unique_key_value);
        }
        return $unique_key_value;

    }

    /* Unique key for export: secondary-language translations derive their key from the
       reference (default-language) translation as `base__<lang>`, overwriting any stored key.
       Reference-language and non-translated posts keep the standard per-post key. */
    public function get_export_unique_key($post, $key) {
        if (!$this->is_translated_post_type($post->post_type)) {
            return $this->get_post_unique_key($post->ID, $key);
        }
        $lang = pll_get_post_language($post->ID);
        if (!$lang) {
            return $this->get_post_unique_key($post->ID, $key);
        }
        $translations = pll_get_post_translations($post->ID);
        $anchor_lang = $this->get_group_anchor_lang($translations);
        if ($lang === $anchor_lang || !isset($translations[$anchor_lang])) {
            /* This post is the group anchor (or has no siblings): base key, no suffix */
            return $this->get_post_unique_key($post->ID, $key);
        }
        $derived = $this->get_post_unique_key($translations[$anchor_lang], $key) . '__' . $lang;
        update_post_meta($post->ID, $key, $derived);
        return $derived;
    }

    /* Deterministic anchor language of a translation group: default language if present,
       else the first present language in Polylang order. Every group member resolves the
       same anchor, so their derived keys share the same base. */
    public function get_group_anchor_lang($translations) {
        $default = pll_default_language();
        if (isset($translations[$default])) {
            return $default;
        }
        foreach (pll_languages_list(array('fields' => 'slug')) as $slug) {
            if (isset($translations[$slug])) {
                return $slug;
            }
        }
        return $default;
    }

    /* ----------------------------------------------------------
      IMPORT
    ---------------------------------------------------------- */

    public function page_content__import() {

        if (empty($this->post_types)) {
            $this->set_message('empty_post_types', __('No post types found', 'wpu_import_export'), 'error');
            return;
        }

        foreach ($this->post_types as $post_type => $post_type_data) {
            $post_type_object = get_post_type_object($post_type_data['post_type']);
            if (!$post_type_object) {
                continue;
            }
            echo '<h2>' . esc_html($post_type_object->label) . '</h2>';
            echo '<p>';
            echo '<label for="file_' . esc_attr($post_type) . '">' . esc_html__('CSV File', 'wpu_import_export') . '</label><br />';
            echo '<input id="file_' . esc_attr($post_type) . '" type="file" name="file_' . esc_attr($post_type) . '" />';
            echo '</p>';
            submit_button(__('Upload File', 'wpu_import_export'), 'primary', 'upload_' . esc_attr($post_type));
            echo '<hr />';
        }

    }

    public function page_action__import() {

        $post_type = '';
        foreach ($this->post_types as $post_type_key => $post_type_data) {
            if (isset($_POST['upload_' . $post_type_key])) {
                $post_type = $post_type_key;
                break;
            }
        }

        $file_key = 'file_' . $post_type;

        /* Check posted infos */
        if (!$post_type || !isset($_FILES[$file_key])) {
            $this->set_message('invalid_post', __('Invalid post', 'wpu_import_export'), 'error');
            return;
        }

        /* Check if file is valid and uploaded */
        if (!isset($_FILES[$file_key]['tmp_name']) || !is_uploaded_file($_FILES[$file_key]['tmp_name'])) {
            $this->set_message('invalid_file', __('Invalid file', 'wpu_import_export'), 'error');
            return;
        }

        /* Validate file extension & MIME type (CSV only) */
        $file_name = isset($_FILES[$file_key]['name']) ? sanitize_file_name($_FILES[$file_key]['name']) : '';
        $filetype = wp_check_filetype($file_name, array('csv' => 'text/csv', 'txt' => 'text/plain'));
        if (!$filetype['ext']) {
            $this->set_message('invalid_file_type', __('Invalid file type, please upload a CSV file', 'wpu_import_export'), 'error');
            return;
        }

        /* Get file content through WP_Filesystem */
        $tmp_name = $_FILES[$file_key]['tmp_name'];
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        WP_Filesystem();
        $file_content = $wp_filesystem->get_contents($tmp_name);
        if (!$file_content) {
            $this->set_message('invalid_file_content', __('Invalid file content', 'wpu_import_export'), 'error');
            return;
        }

        /* Parse file content */
        $lines = $this->basetoolbox->csv_to_array($file_content);
        if (count($lines) < 1) {
            $this->set_message('invalid_file_content', __('Invalid file content', 'wpu_import_export'), 'error');
            return;
        }
        $this->import_details = array(
            'new' => 0,
            'updated' => 0,
            'skipped' => 0,
            'error' => 0
        );

        /* Pass 1: create/update posts, assign language, accumulate translation groups */
        $this->translation_groups = array();
        foreach ($lines as $line) {
            $post_id = $this->create_or_update_post($post_type, $line);
            $this->collect_translation_group($post_type, $line, $post_id);
        }
        /* Pass 2: link translations (all posts now exist) */
        $this->link_translations();

        $message_type = 'error';
        $details_text = array(
            sprintf(__('Lines processed: %d', 'wpu_import_export'), count($lines))
        );
        if ($this->import_details['new']) {
            $message_type = 'updated';
            $details_text[] = sprintf(_n('1 new post created', '%d new posts created', $this->import_details['new'], 'wpu_import_export'), $this->import_details['new']);
        }
        if ($this->import_details['updated']) {
            $message_type = 'updated';
            $details_text[] = sprintf(_n('1 post updated', '%d posts updated', $this->import_details['updated'], 'wpu_import_export'), $this->import_details['updated']);
        }
        if ($this->import_details['skipped']) {
            $details_text[] = sprintf(_n('1 post skipped', '%d posts skipped', $this->import_details['skipped'], 'wpu_import_export'), $this->import_details['skipped']);
        }
        if ($this->import_details['error']) {
            $details_text[] = sprintf(_n('1 error', '%d errors', $this->import_details['error'], 'wpu_import_export'), $this->import_details['error']);
        }
        $details_text = implode('<br /> - ', $details_text);

        $this->set_message('import_success', $details_text, $message_type);

    }

    /* ----------------------------------------------------------
      HELPERS
    ---------------------------------------------------------- */

    /* Create or update a post */
    public function create_or_update_post($post_type, $line) {
        /* Get unique key */
        $post_type_data = $this->post_types[$post_type];
        if (!$post_type_data) {
            return;
        }
        $unique_key = $post_type_data['unique_key'];
        if (!$unique_key) {
            $this->set_message('missing_unique_key', __('Missing unique key', 'wpu_import_export'), 'error');
            $this->import_details['skipped']++;
            return;
        }
        $unique_key_value = isset($line[$unique_key]) && $line[$unique_key] !== '' ? $line[$unique_key] : null;
        $allow_create_without_key = !empty($post_type_data['allow_create_without_key']);
        if (!$unique_key_value) {
            if (!$allow_create_without_key) {
                $this->set_message('missing_unique_key_value', __('Missing unique key value', 'wpu_import_export'), 'error');
                $this->import_details['skipped']++;
                return;
            }
        }
        $post_id = $unique_key_value ? $this->get_post_by_key($post_type, $unique_key, $unique_key_value) : false;

        $post_metas = $unique_key_value ? array($unique_key => $unique_key_value) : array();
        $post_data = array(
            'post_type' => $post_type
        );

        /* Add post data */
        foreach ($post_type_data['columns'] as $column_name => $column_data) {

            if ($column_data['type'] === 'repeater' || $column_data['type'] === 'acf_flexible_block') {
                continue;
            }

            $new_value = $this->get_line_value($line, $column_name, $column_data);
            if ($new_value === null) {
                continue;
            }

            /* Convert markdown to HTML when flagged and value holds no HTML yet */
            if (!empty($column_data['markdown']) && wp_strip_all_tags($new_value) === $new_value) {
                $new_value = $this->basetoolbox->markdown_to_html($new_value);
            }

            /* Load from post data */
            foreach ($this->post_data as $post_data_key => $post_data_value) {
                if ($column_data['type'] != $post_data_key) {
                    continue;
                }
                if (isset($post_data_value['validate_value'])) {
                    $new_value = call_user_func($post_data_value['validate_value'], $new_value);
                }
                /* A null value means "ignore": keep the existing post value */
                if ($new_value === null) {
                    continue;
                }
                $post_data[$post_data_key] = $new_value;
            }
            if ($column_data['type'] == 'post_meta') {
                $post_metas[$column_data['meta_key']] = $this->validate_meta_value($new_value, $column_data);
            }
        }

        /* Keep both dates in sync and allow date edits on update */
        if (isset($post_data['post_date'])) {
            $post_data['post_date_gmt'] = get_gmt_from_date($post_data['post_date']);
            $post_data['edit_date'] = true;
        }

        $post_data = apply_filters('wpu_import_export_post_data_before_save', $post_data, $post_type, $line, $post_id);
        $post_metas = apply_filters('wpu_import_export_post_metas_before_save', $post_metas, $post_type, $line, $post_id);

        if (!$post_id) {
            /* Create post */
            $post_data['post_status'] = 'draft';
            $post_id = wp_insert_post($post_data);
            if (!$post_id) {
                $this->import_details['error']++;
                return false;
            }
            $this->import_details['new']++;
            if (!$unique_key_value) {
                $post_metas[$unique_key] = $this->get_post_unique_key($post_id, $unique_key);
            }

        } else {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
            $this->import_details['updated']++;
        }

        foreach ($post_metas as $meta_key => $meta_value) {
            update_post_meta($post_id, $meta_key, $meta_value);
        }

        foreach ($post_type_data['columns'] as $column_name => $column_data) {
            if ($column_data['type'] === 'taxonomy') {
                $value = $this->get_line_value($line, $column_name, $column_data);
                if ($value === null) {
                    continue;
                }
                $slugs = array_filter(array_map('trim', explode(',', $value)));
                $term_ids = array();
                foreach ($slugs as $slug) {
                    $term = get_term_by('slug', $slug, $column_data['taxonomy']);
                    if ($term) {
                        $term_ids[] = $term->term_id;
                    }
                }
                wp_set_post_terms($post_id, $term_ids, $column_data['taxonomy']);
            }
            if ($column_data['type'] !== 'repeater') {
                continue;
            }
            if ($column_data['format'] === 'columns') {
                $this->save_repeater_meta($post_id, $column_data, $line);
                continue;
            }
            $value = $this->get_line_value($line, $column_name, $column_data);
            if ($value === null) {
                continue;
            }
            $this->save_repeater_meta($post_id, $column_data, $value);
        }

        /* ACF flexible blocks: collect ordered rows per field, then replace in one call */
        if (function_exists('update_field')) {
            $rows_by_field = array();
            foreach ($post_type_data['columns'] as $column_name => $column_data) {
                if ($column_data['type'] !== 'acf_flexible_block') {
                    continue;
                }
                $value = $this->get_line_value($line, $column_name, $column_data);
                if ($value === null) {
                    continue;
                }
                $row = $this->parse_flexible_block_cell($value, $column_data, $post_id);
                if (!$row) {
                    continue;
                }
                $rows_by_field[$column_data['field']][] = $row;
            }

            /* Replace the whole flexible only when at least one valid cell was filled */
            foreach ($rows_by_field as $field => $rows) {
                $rows = $this->filter_valid_flexible_rows($field, $rows);
                if (!empty($rows)) {
                    update_field($field, array_values($rows), $post_id);
                }
            }
        }

        /* Return post id */
        return $post_id;
    }

    /* Assign the imported post language and accumulate its translation group (Polylang) */
    public function collect_translation_group($post_type, $line, $post_id) {
        if (!$post_id || !$this->is_translated_post_type($post_type)) {
            return;
        }
        $lang = isset($line['lang']) ? sanitize_key($line['lang']) : '';
        if (!$lang || !in_array($lang, array_keys($this->get_languages()), true)) {
            return;
        }
        pll_set_post_language($post_id, $lang);

        /* Group key = unique_key value without the trailing __<lang> suffix */
        $unique_key = $this->post_types[$post_type]['unique_key'];
        $key_value = isset($line[$unique_key]) ? $line[$unique_key] : '';
        if ($key_value === '') {
            return;
        }
        $group = $key_value;
        if ($lang !== pll_default_language()) {
            $suffix = '__' . $lang;
            if (substr($key_value, -strlen($suffix)) === $suffix) {
                $group = substr($key_value, 0, -strlen($suffix));
            }
        }
        $this->translation_groups[$group][$lang] = $post_id;
    }

    /* Link accumulated translation groups (needs every post to exist first) */
    public function link_translations() {
        if (!function_exists('pll_save_post_translations')) {
            return;
        }
        foreach ($this->translation_groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            pll_save_post_translations($group);
        }
    }

    /* Drop rows whose layout is not a registered layout of the ACF flexible field.
       ACF stores the layout name in the parent meta without validating it: an unknown
       layout silently creates a row with no recognized sub-fields ("group but no rows"). */
    public function filter_valid_flexible_rows($field, $rows) {
        if (!function_exists('acf_get_field')) {
            return $rows;
        }
        $field_object = acf_get_field($field);
        if (!is_array($field_object) || empty($field_object['layouts'])) {
            return $rows;
        }
        $valid_layouts = wp_list_pluck($field_object['layouts'], 'name');
        foreach ($rows as $i => $row) {
            if (!isset($row['acf_fc_layout']) || !in_array($row['acf_fc_layout'], $valid_layouts, true)) {
                $this->set_message('invalid_flexible_layout', sprintf(
                    /* translators: 1: layout name, 2: field name */
                    __('Unknown flexible layout "%1$s" for field "%2$s", block skipped', 'wpu_import_export'),
                    isset($row['acf_fc_layout']) ? $row['acf_fc_layout'] : '',
                    $field
                ), 'error');
                unset($rows[$i]);
            }
        }
        return $rows;
    }

    /* Parse a flexible block cell ("type:<layout>" first line, body after) into an ACF row */
    public function parse_flexible_block_cell($value, $column_data, $post_id) {
        $field = $column_data['field'];
        $body = $value;
        $layout = $column_data['layout'];

        /* First line "type:<layout>" overrides the default layout */
        $lines = preg_split('/\r\n|\r|\n/', $value);
        if (preg_match('/^\s*type\s*:\s*(.+?)\s*$/i', $lines[0], $matches)) {
            $layout = $matches[1];
            $body = implode("\n", array_slice($lines, 1));
        }

        if ($layout === '') {
            return null;
        }

        /* Convert markdown to HTML when flagged and value holds no HTML yet */
        if (!empty($column_data['markdown']) && wp_strip_all_tags($body) === $body) {
            $body = $this->basetoolbox->markdown_to_html($body);
        }

        $row = array(
            'acf_fc_layout' => $layout,
            $column_data['subfield'] => $body
        );

        /* Let the project map complex layouts (FAQ, similar, ...) or skip the row */
        $row = apply_filters('wpu_import_export_flexible_row', $row, $layout, $body, $post_id, $field);

        return is_array($row) ? $row : null;
    }

    public function save_repeater_meta($post_id, $column_data, $value) {
        $meta_key = $column_data['meta_key'];
        $sub_fields = $column_data['sub_fields'];

        if ($column_data['format'] === 'newline') {
            $rows = array_values(array_filter(explode("\n", $value), function ($v) {
                return trim($v) !== '';
            }));
            $old_count = (int) get_post_meta($post_id, $meta_key, true);
            for ($i = count($rows); $i < $old_count; $i++) {
                foreach ($sub_fields as $sub_field) {
                    delete_post_meta($post_id, $meta_key . '_' . $i . '_' . $sub_field);
                }
            }
            update_post_meta($post_id, $meta_key, count($rows));
            foreach ($rows as $i => $row_value) {
                update_post_meta($post_id, $meta_key . '_' . $i . '_' . $sub_fields[0], $row_value);
            }
        }
        if ($column_data['format'] === 'columns') {
            // $value is the full CSV $line array
            $max = $column_data['max'];
            $count = 0;
            for ($i = 0; $i < $max; $i++) {
                $row_has_value = false;
                foreach ($sub_fields as $sub_field) {
                    $csv_key = $meta_key . '_' . $i . '_' . $sub_field;
                    $val = isset($value[$csv_key]) ? $value[$csv_key] : '';
                    update_post_meta($post_id, $meta_key . '_' . $i . '_' . $sub_field, $val);
                    if (trim($val) !== '') {
                        $row_has_value = true;
                    }
                }
                if ($row_has_value) {
                    $count = $i + 1;
                }
            }
            update_post_meta($post_id, $meta_key, $count);
        }
    }

    /* Resolve a column value from a CSV line, supporting accepted_columns aliases */
    public function get_line_value($line, $column_name, $column_data) {
        $candidates = array(strtolower(trim($column_name)));
        if (!empty($column_data['accepted_columns'])) {
            $candidates = array_merge($candidates, $column_data['accepted_columns']);
        }
        foreach ($candidates as $candidate) {
            if (!isset($line[$candidate])) {
                continue;
            }
            $value = $line[$candidate];
            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }
        return null;
    }

    public function get_post_by_key($post_type, $key, $value) {
        $post = get_posts(array(
            'post_type' => $post_type,
            'meta_query' => array(
                array(
                    'key' => $key,
                    'value' => $value,
                    'compare' => '='
                )
            ),
            'fields' => 'ids',
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'numberposts' => 1
        ));

        return $post ? $post[0] : null;
    }

    /* Add a message */
    public function set_message($id, $message, $group = '') {
        if (!$this->messages) {
            error_log($id . ' - ' . $message);
            return;
        }
        $this->messages->set_message($id, $message, $group);
    }

}

$WPUImportExport = new WPUImportExport();
