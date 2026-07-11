# WPU Import Export

WPU Import Export is a wonderful plugin.

## Hook

```php
add_filter( 'wpu_import_export_post_types', function( $post_types ) {
    $post_types['event'] = [
        'post_type'  => 'event',
        'unique_key' => 'uniqid',
        'columns'    => [
            'title'         => [
                'type' => 'post_title'
            ],
            'contact_email' => [
                'type'          => 'post_meta',
                'meta_key'      => 'contact_email', // optional, defaults to column name
                'value_type'    => 'email', // optional, defaults to 'post_meta'
                'default_value' => '', // optional, defaults to ''
            ],
        ],
    ];
    return $post_types;
} );
```
