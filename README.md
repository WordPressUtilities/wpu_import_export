# WPU Import Export

WPU Import Export is a wonderful plugin.

## Hook

```php
add_filter( 'wpu_import_export_post_types', function( $post_types ) {
    $post_types['event'] = [
        'post_type'  => 'event',
        'unique_key' => 'uniqid',
        'columns'    => [
            'title'     => [
                'type' => 'post_title'
            ],
        ],
    ];
    return $post_types;
} );
```
