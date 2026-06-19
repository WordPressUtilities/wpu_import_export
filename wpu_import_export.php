<?php
/*
Plugin Name: WPU Import Export
Plugin URI: https://github.com/WordPressUtilities/wpu_import_export
Update URI: https://github.com/WordPressUtilities/wpu_import_export
Description: Simple import export
Version: 0.1.0
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
    private $plugin_version = '0.1.0';
    private $plugin_settings = array(
        'id' => 'wpu_import_export',
        'name' => 'WPU Import Export'
    );
    private $basetoolbox;
    private $messages = false;
    private $adminpages;
    private $plugin_description;
    private $post_data = array(
        'post_title' => '',
        'post_content' => '',
        'post_excerpt' => ''
    );
    private $post_types = array();
    public function __construct() {
        add_action('init', array(&$this, 'load_translation'));
        add_action('init', array(&$this, 'load_toolbox'));
        add_action('init', array(&$this, 'load_messages'));
        add_action('init', array(&$this, 'load_adminpage'));
        add_action('init', array(&$this, 'init'));
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
        $pages_options = array(
            'id' => $this->plugin_settings['id'],
            'level' => 'manage_options',
            'basename' => plugin_basename(__FILE__)
        );
        // Init admin page
        require_once __DIR__ . '/inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $this->adminpages = new \wpu_import_export\WPUBaseAdminPage();
        $this->adminpages->init($pages_options, $admin_pages);
        # MESSAGES
        if (is_admin()) {
            require_once __DIR__ . '/inc/WPUBaseMessages/WPUBaseMessages.php';
            $this->messages = new \wpu_import_export\WPUBaseMessages($this->plugin_settings['id']);
        }
    }

    /* ----------------------------------------------------------
      INIT
    ---------------------------------------------------------- */

    public function init() {
        $post_types = apply_filters('wpu_import_export_post_types', array());
        foreach ($post_types as $post_type => $post_type_data) {
            $post_type_data = $this->clean_post_type_data($post_type, $post_type_data);
            if (empty($post_type_data)) {
                continue;
            }
            $this->post_types[$post_type] = $post_type_data;
        }
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
        $default_types[] = 'post_meta';
        foreach ($post_type_data['columns'] as $column_name => $column_data) {
            if (!isset($column_data['type'])) {
                $post_type_data['columns'][$column_name]['type'] = 'post_meta';
            }
            if (!in_array($post_type_data['columns'][$column_name]['type'], $default_types)) {
                unset($post_type_data['columns'][$column_name]);
            }
        }

        return $post_type_data;
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
            submit_button(__('Export', 'wpu_import_export'), 'primary', 'export_' . $post_type, false);
        }
    }

    public function page_action__export() {
        foreach ($this->post_types as $post_type => $post_type_data) {
            if (isset($_POST['export_' . $post_type])) {
                $this->export_post_type($post_type, $post_type_data);
            }

        }
    }

    public function export_post_type($post_type, $post_type_data) {
        $posts = get_posts(array(
            'post_type' => $post_type,
            'numberposts' => -1
        ));
        $lines = array();
        foreach ($posts as $post) {
            $lines[] = $this->post_to_array($post, $post_type_data);
        }
        $this->basetoolbox->export_array_to_csv($lines, $post_type);
    }

    public function post_to_array($post, $post_type_data) {
        $lines = array();
        $lines[$post_type_data['unique_key']] = $this->get_post_unique_key($post->ID, $post_type_data['unique_key']);
        foreach ($post_type_data['columns'] as $column_name => $column_data) {
            if ($column_data['type'] == 'post_title') {
                $lines[$column_name] = trim($post->post_title);
            }
            if ($column_data['type'] == 'post_content') {
                $lines[$column_name] = trim($post->post_content);
            }
            if ($column_data['type'] == 'post_excerpt') {
                $lines[$column_name] = trim($post->post_excerpt);
            }
            if ($column_data['type'] == 'post_meta') {
                $lines[$column_name] = get_post_meta($post->ID, $column_name, true);
            }
        }
        return $lines;
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
            echo '<label for="file_' . esc_attr($post_type) . '">' . __('CSV File', 'wpu_import_export') . '</label><br />';
            echo '<input id="file_' . esc_attr($post_type) . '" type="file" name="file_' . esc_attr($post_type) . '" />';
            echo '<input type="hidden" name="upload_post_type" value="' . esc_attr($post_type) . '" />';
            echo '</p>';
            submit_button(__('Upload File', 'wpu_import_export'), 'primary', 'upload_' . esc_attr($post_type), false);
        }

    }

    public function page_action__import() {

        $post_type = isset($_POST['upload_post_type']) ? sanitize_key(wp_unslash($_POST['upload_post_type'])) : '';
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

        /* Get file content */
        $file_content = file_get_contents($_FILES[$file_key]['tmp_name']);
        if (!$file_content) {
            $this->set_message('invalid_file_content', __('Invalid file content', 'wpu_import_export'), 'error');
            return;
        }

        /* Parse file content */
        $lines = $this->basetoolbox->csv_to_array($file_content);
        $number_of_lines = 0;
        foreach ($lines as $line) {
            $post_id = $this->create_or_update_post($post_type, $line);
            if ($post_id) {
                $number_of_lines++;
            }
        }
        $this->set_message('import_success', __('Import successful', 'wpu_import_export') . ' (' . $number_of_lines . ' ' . __('posts imported', 'wpu_import_export') . ')', 'updated');

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
            return;
        }
        $unique_key_value = isset($line[$unique_key]) ? $line[$unique_key] : null;
        if (!$unique_key_value) {
            $this->set_message('missing_unique_key_value', __('Missing unique key value', 'wpu_import_export'), 'error');
            return;
        }
        $post_id = $this->get_post_by_key($post_type, $unique_key, $unique_key_value);

        $post_metas = array();
        $post_data = array(
            'post_type' => $post_type
        );

        /* Add post data */
        foreach ($post_type_data['columns'] as $column_name => $column_data) {
            if (!isset($line[$column_name])) {
                continue;
            }
            foreach ($this->post_data as $post_data_key => $post_data_value) {
                if ($column_data['type'] == $post_data_key) {
                    $post_data[$post_data_key] = $line[$column_name];
                }
            }
            if ($column_data['type'] == 'post_meta') {
                $post_metas[$column_name] = $line[$column_name];
            }
        }

        if (!$post_id) {
            /* Create post */
            $post_data['post_status'] = 'draft';
            $post_id = wp_insert_post($post_data);
            if (!$post_id) {
                return false;
            }

        } else {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        }

        foreach ($post_metas as $meta_key => $meta_value) {
            update_post_meta($post_id, $meta_key, $meta_value);
        }

        /* Return post id */
        return $post_id;
    }

    public function get_post_by_key($post_type, $key, $value) {
        $post = get_posts(array(
            'post_type' => $post_type,
            'meta_key' => $key,
            'meta_value' => $value,
            'fields' => 'ids',
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
