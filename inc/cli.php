<?php

if (!defined('ABSPATH')) {
    exit();
}

class WPUImportExportCLI {

    /**
     * Import a CSV file into a registered post type.
     *
     * ## OPTIONS
     *
     * <post_type>
     * : Post type key, as registered through the "wpu_import_export_post_types" filter.
     *
     * <file>
     * : Path to the CSV file.
     *
     * ## EXAMPLES
     *
     *     wp wpu-import-export import my_post_type ./import.csv --user=1
     *
     * Posts are created with the current user as author : use the global --user flag,
     * otherwise post_author will be 0.
     */
    public function import($args) {
        global $WPUImportExport;

        list($post_type, $file) = $args;
        $this->check_post_type($post_type);

        /* Check file */
        if (!file_exists($file) || !is_readable($file)) {
            WP_CLI::error(sprintf('File "%s" does not exist or is not readable.', $file));
        }

        /* Validate & parse file */
        $lines = $WPUImportExport->get_lines_from_csv_file($file);
        if (is_wp_error($lines)) {
            WP_CLI::error($lines->get_error_message());
        }

        /* Track progress : the plugin reports each imported line through this hook */
        $progress = WP_CLI\Utils\make_progress_bar('Importing', count($lines));
        $line_number = 0;
        add_action('wpu_import_export_line_imported', function ($post_id) use ($progress, &$line_number) {
            $line_number++;
            if (!$post_id) {
                WP_CLI::warning(sprintf('Line %d: skipped or in error.', $line_number));
            }
            $progress->tick();
        });

        $details = $WPUImportExport->import_lines($post_type, $lines);
        $progress->finish();

        $summary = sprintf('%d lines processed: %d created, %d updated, %d skipped, %d errors, %d terms created',
            count($lines),
            $details['new'],
            $details['updated'],
            $details['skipped'],
            $details['error'],
            $details['terms_created']
        );

        if ($details['error']) {
            WP_CLI::error($summary);
        }
        WP_CLI::success($summary);
    }


    /**
     * Export a registered post type to a CSV file.
     *
     * ## OPTIONS
     *
     * <post_type>
     * : Post type key, as registered through the "wpu_import_export_post_types" filter.
     *
     * [<file>]
     * : Path to the CSV file to write. Defaults to standard output.
     *
     * [--status=<statuses>]
     * : Comma-separated post statuses. Default: publish.
     *
     * [--after=<date>]
     * : Only posts published on or after this date (YYYY-MM-DD).
     *
     * [--before=<date>]
     * : Only posts published on or before this date (YYYY-MM-DD).
     *
     * [--search=<text>]
     * : Search in title, content and excerpt.
     *
     * [--lang=<langs>]
     * : Comma-separated Polylang language slugs. Default: all languages.
     *
     * [--tax=<terms>]
     * : Comma-separated taxonomy:term-slug pairs, ex: category:news,category:events.
     *
     * ## EXAMPLES
     *
     *     wp wpu-import-export export my_post_type ./export.csv
     *     wp wpu-import-export export my_post_type --status=publish,draft | head -3
     *     wp wpu-import-export export my_post_type ./fr.csv --lang=fr --tax=category:news
     */
    public function export($args, $assoc_args) {
        global $WPUImportExport;

        $post_type = $args[0];
        $post_type_data = $this->check_post_type($post_type);
        $file = isset($args[1]) ? $args[1] : '';

        /* Check destination before running a potentially long query */
        if ($file) {
            $dir = dirname($file);
            if (!is_dir($dir) || !is_writable($dir)) {
                WP_CLI::error(sprintf('Directory "%s" does not exist or is not writable.', $dir));
            }
        }

        /* The plugin reads export filters from $_POST : fill it from the CLI flags */
        $_POST['filter'][$post_type] = $this->build_export_filter($assoc_args);

        $posts = get_posts($WPUImportExport->get_export_query_args($post_type));
        if (empty($posts)) {
            WP_CLI::error('No posts found for export with the current filters.');
        }

        $lines = $WPUImportExport->get_export_lines($posts, $post_type, $post_type_data);
        $csv = $WPUImportExport->basetoolbox->export_array_to_csv_string($lines);

        if (!$file) {
            echo $csv;
            return;
        }

        if (file_put_contents($file, $csv) === false) {
            WP_CLI::error(sprintf('Could not write "%s".', $file));
        }
        WP_CLI::success(sprintf('%d posts exported to %s', count($posts), $file));
    }

    /* Ensure a post type is registered, and return its settings */
    private function check_post_type($post_type) {
        global $WPUImportExport;

        $post_types = $WPUImportExport->get_post_types();
        if (!isset($post_types[$post_type])) {
            WP_CLI::error(sprintf('Unknown post type "%s". Available: %s', $post_type, implode(', ', array_keys($post_types))));
        }
        return $post_types[$post_type];
    }

    /* Build the $_POST filter array expected by the export methods */
    private function build_export_filter($assoc_args) {
        global $WPUImportExport;

        $filter = array();

        /* Statuses & languages are validated here : the plugin silently falls back
           to publish / all languages on invalid values, which would hide a typo. */
        if (!empty($assoc_args['status'])) {
            $valid = array_keys($WPUImportExport->get_valid_export_statuses());
            $filter['status'] = $this->validate_list($assoc_args['status'], $valid, 'status');
        }

        if (!empty($assoc_args['lang'])) {
            $valid = array_keys($WPUImportExport->get_languages());
            $filter['lang'] = $this->validate_list($assoc_args['lang'], $valid, 'language');
        }

        foreach (array('after' => 'date_after', 'before' => 'date_before') as $flag => $key) {
            if (empty($assoc_args[$flag])) {
                continue;
            }
            if (!$WPUImportExport->is_valid_export_date($assoc_args[$flag])) {
                WP_CLI::error(sprintf('Invalid --%s date "%s". Expected format: YYYY-MM-DD.', $flag, $assoc_args[$flag]));
            }
            $filter[$key] = $assoc_args[$flag];
        }

        if (!empty($assoc_args['search'])) {
            /* Mirror the slashed $_POST WordPress builds on web requests */
            $filter['search'] = wp_slash($assoc_args['search']);
        }

        if (!empty($assoc_args['tax'])) {
            foreach (explode(',', $assoc_args['tax']) as $pair) {
                $pair = trim($pair);
                if (strpos($pair, ':') === false) {
                    WP_CLI::error(sprintf('Invalid --tax value "%s". Expected format: taxonomy:term-slug.', $pair));
                }
                list($taxonomy, $slug) = explode(':', $pair, 2);
                $term = get_term_by('slug', $slug, $taxonomy);
                if (!$term) {
                    WP_CLI::error(sprintf('Unknown term "%s" in taxonomy "%s".', $slug, $taxonomy));
                }
                $filter['tax'][$taxonomy][] = $term->term_id;
            }
        }

        return $filter;
    }

    /* Split a comma-separated flag and check each value against a whitelist */
    private function validate_list($value, $valid, $label) {
        $values = array();
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if (!in_array($item, $valid, true)) {
                WP_CLI::error(sprintf('Invalid %s "%s". Available: %s', $label, $item, implode(', ', $valid)));
            }
            $values[] = $item;
        }
        return $values;
    }

}

WP_CLI::add_command('wpu-import-export', 'WPUImportExportCLI');
