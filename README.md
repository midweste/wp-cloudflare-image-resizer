# Wordpress Cloudflare Image Resizer

Rewrites the image src of images, srcsets, and other images to use Cloudflares Image Resizing (https://developers.cloudflare.com/images/transform-images/transform-via-url/).

## To Install

### As a normal plugin

- Unzip and add to the plugins folder, activate

## Configuration

By default, the settings are below and available via filter **cloudflare_image_resize_settings**. Most settings are covered on https://developers.cloudflare.com/images/transform-images/transform-via-url/#options

```
add_filter('cloudflare_image_resize_settings', function ($settings) {
    $settings['enabled'] = true;
    $settings['site_url'] = home_url();
    $settings['site_folder'] = '';
    $settings['site_dir'] = ABSPATH;
    $settings['image_style'] = 'full';
    $settings['fit'] = 'cover';
    $settings['gravity'] = '';
    $settings['quality'] = 80;
    $settings['sharpen'] = 0;
    $settings['format'] = 'auto';
    $settings['onerror'] = 'redirect';
    $settings['metadata'] = 'none';
    $settings['max_width'] = 1920;
    $settings['image_types'] = [
        'jpg',
        'jpeg',
        'gif',
        'png',
        'webp',
        'svg',
    ];
    $settings['hook_wp_get_attachment_image_src'] = true;
    $settings['hook_wp_calculate_image_srcset'] = true;
    $settings['hook_wp_get_attachment_url'] = true;
    $settings['hook_html'] = true;
    $settings['hook_html_background_css'] = true;
});
```

## Filters

apply_filters('cloudflare_image_resize_settings', $settings)

Array. This filter allows you to change default settings.

apply_filters('cloudflare_image_resize_exclude', false)

Boolean. This filter will allow you to add custom logic to exclude or prevent image src rewriting.

apply_filters('cloudflare_image_resize_shutdown_html', $final)

String. This filter will allow you to modify or change full page html buffering

## Actions

apply_filters('cloudflare_image_resize_max_size_exceeded', $settings, $image_path, $width, $height, \$\_SERVER['REQUEST_URI'])

This action allows for action to be taken when the max width of an image is exceeded.
