# WPU Import Export

WPU Import Export is a wonderful plugin.

## Hook

```php
add_filter( 'wpu_import_export_post_types', function( $post_types ) {
    $post_types['post'] = [
        'post_type' => 'post',
        'unique_key' => 'uniqid', // Post meta holding the unique key, optional, defaults to 'uniqid'
        'load_post_data' => true, // Automatically load columns from post object, optional, defaults to false
        'columns' => []
    ];
    $post_types['event'] = [
        'post_type'  => 'event',
        'unique_key' => 'uniqid',
        // Import a line with an empty (or missing) unique key column instead of skipping it:
        'allow_create_without_key' => true,
        'columns'    => [
            'title'         => [
                'type' => 'post_title'
            ],
            'contact_email' => [
                'type'          => 'post_meta',
                'meta_key'      => 'contact_email', // optional, defaults to column name, used to target another
                'value_type'    => 'email', // optional, defaults to 'post_meta', used to validate import format
                'default_value' => '', // optional, defaults to ''
                'accepted_columns' => ['e-mail', 'mail'], // Alternative CSV header names accepted on import, optional
            ],
            'description'   => [
                'type'     => 'post_content',
                'markdown' => true, // Convert markdown to HTML on import when the cell holds no HTML, optional
            ],
            'categories'    => [
                'type'     => 'taxonomy',
                'taxonomy' => 'category', // Required. Cell holds comma-separated term slugs
            ],
            'speakers'      => [
                'type'       => 'repeater',
                'meta_key'   => 'speakers', // optional, defaults to column name
                'sub_fields' => ['name', 'role'], // Required. ACF-style repeater sub fields
                'format'     => 'indexed', // 'indexed' (one cell), 'newline' (one value per line) or 'columns', optional
                'max'        => 5, // Required with format 'columns' only: number of generated columns
            ],
            'blocks'        => [
                'type'     => 'acf_flexible_block', // Import-only: fills an ACF flexible field, never exported
                'field'    => 'content-blocks', // optional, defaults to 'content-blocks'
                'layout'   => 'content', // optional, defaults to 'content'
                'subfield' => 'content', // optional, defaults to 'content'
            ],
        ],
    ];
    return $post_types;
} );
```
