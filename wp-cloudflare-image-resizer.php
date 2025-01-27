<?php

/*
 * Plugin Name:       WordPress Cloudflare Image Resizer
 * Version: 2024.10.30.15.07.14
 * Plugin URI:        https://github.org/midweste/wp-cloudflare-image-resizer
 * Description:       Rewrites the image src of images, srcsets, and other images to use Cloudflares Image Resizing (https://developers.cloudflare.com/images/transform-images/transform-via-url/).
 * Author:            Midweste
 * Author URI:        https://github.org/midweste/wp-cloudflare-image-resizer
 * Update URI:        https://api.github.com/repos/midweste/wp-cloudflare-image-resizer/commits/main
 * License:           MIT
 */

/**
 * Get the CloudflareImageResizer instance
 *
 * @return CloudflareImageResizer
 */
function wp_cloudflare_image_resizer(): CloudflareImageResizer
{
    global $cf_image_resizer;
    if (!$cf_image_resizer instanceof CloudflareImageResizer) {
        $GLOBALS['cf_image_resizer'] = new CloudflareImageResizer();
    }
    return $GLOBALS['cf_image_resizer'];
}

/**
 * Get Cloudflare Image Resizer URI from an image path
 *
 * @param string $image_path
 * @param integer|null $width
 * @param integer|null $height
 * @param string|null $ref
 * @return string
 */
function wp_cloudflare_image_resizer_uri(string $image_path, ?int $width = 0, ?int $height = 0, ?string $ref = '', ?array $settings = []): string
{
    return wp_cloudflare_image_resizer()->cloudflareUri($image_path, $width, $height, $ref, $settings);
}


/**
 * Get Cloudflare Image Resizer URI from an attachment ID
 *
 * @param integer $attachment_id
 * @param integer|null $width
 * @param integer|null $height
 * @param string|null $ref
 * @return string
 */
function wp_cloudflare_image_resizer_uri_by_id(int $attachment_id, ?int $width = 0, ?int $height = 0, ?string $ref = '', ?array $settings = []): string
{
    $image = wp_get_attachment_image_src($attachment_id, 'full');
    if (empty($image)) {
        return '';
    }
    return wp_cloudflare_image_resizer_uri($image[0], $width, $height, $ref, $settings);
}

call_user_func(function () {
    foreach (glob(__DIR__ . '/src/*.php') as $file) {
        require_once $file;
    }

    add_action('plugins_loaded', function () {
        wp_cloudflare_image_resizer()->init();
    });
});
