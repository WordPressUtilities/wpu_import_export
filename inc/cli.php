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

        /* Check post type */
        $post_types = $WPUImportExport->get_post_types();
        if (!isset($post_types[$post_type])) {
            WP_CLI::error(sprintf('Unknown post type "%s". Available: %s', $post_type, implode(', ', array_keys($post_types))));
        }

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

}

WP_CLI::add_command('wpu-import-export', 'WPUImportExportCLI');
