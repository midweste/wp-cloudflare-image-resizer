# Wordpress Cloudflare Image Resizer

Wordpress mu plugin to transform local image urls to use Cloudflare Image Resizer (https://developers.cloudflare.com/images/transform-images/transform-via-url/) to optimize images and serve them from the edge.

## Hooks:
- wp_get_attachment_image_src
- wp_calculate_image_srcset
- wp_get_attachment_url
- Full output buffer for images (srcset supported) and background css

## Installation
- add to mu-plugins folder

## Filters
### Change Settings
```
add_filter('cloudflare_image_resize_settings', function ($settings) {
    $settings = [
    'enabled' => true,
    'site_url' => home_url(),
    'site_folder' => '',
    'site_dir' => ABSPATH,
    'image_style' => 'full',
    'fit' => 'scale-down',
    'gravity' => '',
    'quality' => 80,
    'sharpen' => 0,
    'format' => 'auto',
    'onerror' => 'redirect',
    'metadata' => 'none',
    'max_width' => 1600,
    'image_types' => [
        'jpg' => true,
        'jpeg' => true,
        'gif' => true,
        'png' => true,
        'webp' => true,
        'svg' => true,
    ],
    // 'image_type_jpg' => true,
    // 'image_type_jpeg' => true,
    // 'image_type_gif' => true,
    // 'image_type_png' => true,
    // 'image_type_webp' => true,
    // 'image_type_svg' => true,
    'hook_wp_get_attachment_image_src' => true,
    'hook_wp_calculate_image_srcset' => true,
    'hook_wp_get_attachment_url' => true,
    'hook_html' => true,
    'hook_html_background_css' => true,
    ];
    return $settings;
});
```
TODO....
