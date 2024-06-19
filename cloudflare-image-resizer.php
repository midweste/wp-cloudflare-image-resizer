<?php

/*
 * Plugin Name:       WordPress Cloudflare Image Resizer
 * Version: 2024.06.18.00.00.00
 * Plugin URI:        https://github.org/midweste/wp-cloudflare-image-resizer
 * Description:       Cloudflare Image Resizer with full page buffering/replacement.
 * Author:            Midweste
 * Author URI:        https://github.org/midweste/wp-cloudflare-image-resizer
 * Update URI:        https://api.github.com/repos/midweste/wp-cloudflare-image-resizer/commits/main
 * License:           MIT
 */


/**
 * Cloudflare Image Resizer Class
 * Filters:
 * apply_filters('shutdown_html', $final)
 * apply_filters('cloudflare_image_resize_exclude', false)
 * apply_filters('cloudflare_image_resize_settings', $settings)
 * apply_filters('cloudflare_image_resize_max_size_exceeded', $settings, $image_path, $width, $height, $_SERVER['REQUEST_URI'])
 */
class CloudflareImageResizer
{
    private $settings = [];

    public function __construct()
    {
    }

    public function init()
    {
        $settings = $this->settings();
        if (!$settings['enabled'] || apply_filters('cloudflare_image_resize_exclude', false)) {
            return;
        }

        // Image replacement hooks
        if ($settings['hook_wp_get_attachment_image_src']) {
            add_filter('wp_get_attachment_image_src', [$this, 'filter_get_attachment_image_src'], PHP_INT_MAX, 4);
        }
        if ($settings['hook_wp_calculate_image_srcset']) {
            add_filter('wp_calculate_image_srcset', [$this, 'filter_calculate_image_srcset'], PHP_INT_MAX, 4);
        }
        if ($settings['hook_wp_get_attachment_url']) {
            add_filter('wp_get_attachment_url', [$this, 'filter_get_attachment_url'], PHP_INT_MAX, 2);
        }
        if ($settings['hook_html'] || $settings['hook_html_background_css']) {
            if (
                is_admin()
                || wp_doing_ajax()
                || stripos($_SERVER['REQUEST_URI'], '/wp-json/') === 0
                || (defined('REST_REQUEST') && REST_REQUEST === true)
            ) {
                return;
            }

            // Full output buffering
            // https://stackoverflow.com/questions/772510/wordpress-filter-to-modify-final-html-output
            ob_start();
            add_action('shutdown', function () {
                $final = '';
                $levels = ob_get_level();
                for ($i = 0; $i < $levels; $i++) {
                    $final .= ob_get_clean();
                }
                echo apply_filters('shutdown_html', $final);
            }, PHP_INT_MIN); // this priority has to be low

            add_filter('shutdown_html', function ($content) use ($settings) {
                if (empty($content)) {
                    return $content;
                }

                if ($settings['hook_html']) {
                    $content = $this->hook_html($content);
                }
                if ($settings['hook_html_background_css']) {
                    $content = $this->hook_html_background_css($content);
                }
                return $content;
            }, PHP_INT_MAX, 1);
        }
        // if (1 == 2 && $settings['hook_css']) {
        //     add_action('init', function () {
        //         add_rewrite_rule('^(.+\.css)$', 'index.php?css_request=$matches[1]', 'top');
        //         flush_rewrite_rules(true);
        //     });
        //     add_filter('query_vars', function ($vars) {
        //         $vars[] = 'css_request';
        //         return $vars;
        //     });
        //     add_action('template_redirect', [$this, 'hook_template_redirect']);
        // }
    }

    protected function settings(): array
    {
        if (!empty($this->settings)) {
            return $this->settings;
        }

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
            'hook_wp_get_attachment_image_src' => true,
            'hook_wp_calculate_image_srcset' => true,
            'hook_wp_get_attachment_url' => true,
            'hook_html' => true,
            'hook_html_background_css' => true,
            // 'hook_css' => true,
        ];

        // if (defined('CF_IMAGE_RESIZE_SETTINGS') && is_array(CF_IMAGE_RESIZE_SETTINGS)) {
        //     $filtered = array_filter(CF_IMAGE_RESIZE_SETTINGS, function ($key) use ($settings) {
        //         return array_key_exists($key, $settings);
        //     }, ARRAY_FILTER_USE_KEY);
        //     $settings = array_replace_recursive($settings, $filtered);
        // }

        $this->settings = apply_filters('cloudflare_image_resize_settings', $settings);
        return $this->settings;
    }

    protected function setting(string $key)
    {
        $settings = $this->settings();
        return (isset($settings[$key])) ? $settings[$key] : null;
    }

    protected function isLocalResource(string $uri): bool
    {
        if (stripos($uri, '/') === 0) {
            return true;
        }
        if (stripos($uri, site_url()) === 0) {
            return true;
        }
        if (stripos($uri, 'data:image') === 0) {
            return true;
        }
        $remote = wp_parse_url($uri, PHP_URL_HOST);
        $site = wp_parse_url(site_url(), PHP_URL_HOST);
        if (strtolower($remote) === strtolower($site)) {
            return true;
        }
        return false;
    }

    /*
     * Check if this is a valid image. JPEG, PNG, GIF (including animations), and WebP images.
     * @return bool
     */
    protected function isValidImage(string $image): bool
    {
        // ignore data:image
        if (stripos($image, 'data:image') === 0) {
            return false;
        }

        $types = implode('|', $this->setting('image_types'));
        if (preg_match('/\.(?:' . $types . ')/', $image, $matches, PREG_OFFSET_CAPTURE, 0)) {
            return true;
        }
        return false;
    }

    /*
     * Check if this URL is already pointed to Cloudflare CDN
     * @return bool
     */
    protected function isOptimizedImage(string $image_url): bool
    {
        // It seems this is faster and has consistent and better index (100) than strstr().
        if (stripos($image_url, '/cdn-cgi/image/') !== false) {
            return true;
        }
        return false;
    }

    /*
     * Try extract the image path using regex or fallback to wp_parse_url();
     * @return string
     */
    protected function extractPath(string $url): string
    {
        // If PREG_OFFSET_CAPTURE is set then unmatched captures (i.e. ones with '?') will not be present in the result array.
        if (@preg_match('/^(?:.*)(\/wp-content\/.*)$/', $url, $matches, PREG_OFFSET_CAPTURE, 0)) {
            return $matches[1][0];
        }

        // fallback
        $parsed_url = wp_parse_url($url);

        // Check if image host is external. If so, then don't strip the root url from the path
        $host = rtrim(str_replace(['http://', 'https://',], '', $this->setting('site_url')), '/');
        if (isset($parsed_url['host']) && $parsed_url['host'] !== $host) {
            $parsed_url['path'] = '/' . $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
        }

        return (isset($parsed_url['path']) && $parsed_url['path'] !== '') ? $parsed_url['path'] : '';
    }

    /*
     * Try to remove size from image filename
     * @return string
     */
    protected function getSourceImagePath(string $image_url): string
    {
        // get specified size as the source image
        // $image_style = $this->setting('image_style');
        // if ($image_style !== 'full') {
        //     $id = \_\image_parent_id($image_url);

        //     list($img_url, $width, $height, $is_intermediate) = \wp_get_attachment_image_url($id, $image_style);
        //     if (isset($img_url)) {
        //         return str_replace(site_url(), '', $img_url);
        //     }

        //     // get original fullsize image if we cant get specified size
        //     $original = \wp_get_original_image_url($id);
        //     if ($original) {
        //         return str_replace(site_url(), '', $original);
        //     }
        // }

        $pattern = '/^(.*)-\d*x\d*\.([A-Za-z]*)$/';
        $stripped = @preg_replace($pattern, '${1}.${2}', $this->extractPath($image_url));

        if (!file_exists($this->setting('site_dir') . $stripped)) {
            // d('doesnt exist', $stripped);
            return $image_url;
        }

        // d($image_url, $stripped);
        return $stripped;
    }

    /*
     * Try to extract size from image filename
     * @return array
     */
    protected function extractSizes(string $image_url): array
    {
        $width = 1;
        $height = 1;

        // Try extract from img url (eg: /wp-content/uploads/2020/07/project-9-1200x848.jpg)
        @preg_match('/(([0-9]{1,4})x([0-9]{1,4})){1}\.[A-Za-z]+$/', $image_url, $matches, PREG_OFFSET_CAPTURE, 0);

        if (isset($matches[2][0]) && isset($matches[3][0])) {
            $width = $matches[2][0];
            $height = $matches[3][0];
            return [$width, $height];
        }

        if (!file_exists($this->setting('site_dir') . $image_url)) {
            return [1, 1];
        }

        list($w, $h) = wp_getimagesize($this->setting('site_dir') . $image_url);
        return (is_int($w) && is_int($h)) ? [$w, $h] : [1, 1];
    }

    public function cloudflareUri(string $image_path, ?int $width = 0, ?int $height = 0, ?string $ref = ''): string
    {
        if (!$this->isLocalResource($image_path) || !$this->isValidImage($image_path)) {
            return $image_path;
        }

        // $source_image_path = $this->getSourceImagePath($image_path);
        $image_path = $this->extractPath($image_path);
        if (empty($image_path)) {
            return $image_path;
        }

        static $cache = [];
        $cache_name = $image_path . '^' . $width . '^' . $height;
        if (isset($cache[$cache_name])) {
            return $cache[$cache_name];
        }

        $settings = [
            'ref' => $ref,
            'quality' => $this->setting('quality'),
            'format' => $this->setting('format'),
            'onerror' => $this->setting('onerror'),
            'metadata' => $this->setting('metadata'),
            'gravity' => $this->setting('gravity'),
        ];

        // add width and height
        if (!empty($width) && !empty($height)) {
            // provided width and height
            $sizes = [$width, $height];
            $settings['fit'] = $this->setting('fit');
        } elseif (!empty($width) && empty($height)) {
            // find width and height from image
            $ogsizes = $this->extractSizes($image_path);
            $ratio = $ogsizes[0] / $ogsizes[1];
            $sizes = [$width, round($width / $ratio)];
            $settings['fit'] = $this->setting('fit');
        } else {
            // find width and height from image
            $sizes = $this->extractSizes($image_path);
            $settings['fit'] = $this->setting('fit');
        }

        if (!empty($sizes[0])) {
            $settings['width'] = $sizes[0];
        }
        if (!empty($sizes[1])) {
            $settings['height'] = $sizes[1];
        }

        // set width and height of a max width
        $max_width = $this->setting('max_width');
        if ($settings['width'] > $max_width) {
            apply_filters('cloudflare_image_resize_max_size_exceeded', $settings, $image_path, $width, $height, $_SERVER['REQUEST_URI']);
            if ($settings['width'] && $settings['height']) {
                $ratio = $max_width / $settings['width'];
                $settings['width'] = $max_width;
                $settings['height'] = round($settings['height'] * $ratio);
            } else {
                $settings['width'] = $max_width;
                // unset($settings['height']);
            }
        }

        // make settings string for url
        $settings_strings = [];
        foreach ($settings as $setting => $value) {
            $settings_strings[] = $setting . '=' . $value;
        }

        // create cf image resize url
        $newurl = $this->setting('site_url') . '/cdn-cgi/image/' . urlencode(implode(',', $settings_strings)) . $this->setting('site_folder') . $image_path;
        // if (filter_var($newurl, FILTER_VALIDATE_URL) === false) {
        // d($newurl, $image_path, $width, $height, $ref);
        // }
        $cache[$cache_name] = $newurl;
        return $newurl;
    }

    /** --------------- Hooks --------------- */

    public function filter_get_attachment_image_src($image, $attachment_id, $size, $icon)
    {
        // No image, there is nothing to do here.
        if (!isset($image[0]) || !$this->isValidImage($image[0])) {
            return $image;
        }
        // Many times the hook filter_get_attachment_url has ran before this, but it can only determine size based on the full image
        // We need to see if we are using at a smaller size than originally determined by filter_get_attachment_url
        // so we need to skip any check of the image already being optimized


        // $image_path = $this->getSourceImagePath($image[0]);
        $image[0] = $this->cloudflareUri($image[0], $image[1], $image[2], 'image_src');
        return $image;
    }

    public function filter_calculate_image_srcset($size_array, $image_src, $image_meta, $attachment_id)
    {
        foreach ($size_array as $key => $value) {
            if (!$this->isValidImage($value['url'])) {
                continue;
            }
            $size_array[$key]['url'] = $this->cloudflareUri($value['url'], $key, 0, 'srcset');
        }
        return $size_array;
    }

    public function filter_get_attachment_url($url, $post_id)
    {
        // This check will avoid images that are in whitelist (if enabled)
        if ($this->isOptimizedImage($url) || !$this->isValidImage($url)) {
            return $url;
        }
        return $this->cloudflareUri($url, 0, 0, 'attachment_url');
    }

    public function hook_html_background_css(string $html): string
    {
        $regex = '/(background(?:-image)?\s?:?\s*url\s*\(\s*[\'"]?(.*?)[\'"]?\s*\))/i';

        if (preg_match_all($regex, $html, $matches)) {
            $imageUrls = array_combine($matches[1], $matches[2]);
            $imageUrls = array_filter($imageUrls); // Remove empty values
        }

        if (empty($imageUrls)) {
            return $html;
        }

        $optimize = [];
        $skipped = [];
        foreach ($imageUrls as $match => $image) {
            if (!$this->isLocalResource($image) || $this->isOptimizedImage($image) || !$this->isValidImage($image)) {
                $skipped[] = $image;
                continue;
            }
            $image_path = $this->extractPath($image);
            if (empty($image_path)) {
                $skipped[] = $image;
                continue;
            }
            $optimize[$match] = str_replace($image, $this->cloudflareUri($image_path, null, null, 'regex'), $match);
        }

        foreach ($optimize as $old => $new) {
            $html = str_replace($old, $new, $html);
        }

        return $html;
    }

    public function hook_html(string $html): string
    {
        $dom = new \DOMDocument();

        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        if (!$loaded) {
            return $html;
        }
        $xpath = new \DOMXPath($dom);

        $imageChanges = $this->domElementResize($xpath->query('//img'), 'src');
        $videoChanges = $this->domElementResize($xpath->query('//video'), 'poster');

        if (!$imageChanges && !$videoChanges) {
            return $html;
        }

        $newHtml = $dom->saveHTML();
        return ($newHtml === false) ? $html : $newHtml;
    }

    protected function domElementResize(DomNodeList $elements, string $src_name): bool
    {
        $changed = false;
        if ($elements->length === 0) {
            return $changed;
        }

        foreach ($elements as $element) {
            $src = $element->getAttribute($src_name);
            if (empty($src) || !$this->isLocalResource($src) || $this->isOptimizedImage($src) || !$this->isValidImage($src)) {
                continue;
            }
            $width = $element->getAttribute('width');
            $height = $element->getAttribute('height');

            if (is_numeric($width) && is_numeric($height)) {
                $cfurl = $this->cloudflareUri($src, (int) $width, (int) $height, 'dom');
            } elseif (is_numeric($width)) {
                $cfurl = $this->cloudflareUri($src, (int) $width, null, 'dom');
            } else {
                $cfurl = $this->cloudflareUri($src, null, null, 'dom');
            }
            $element->setAttribute($src_name, $cfurl);
            $changed = true;


            // handle img srcset
            $srcset = $element->getAttribute('srcset');
            if ($src_name == 'src' && !empty($srcset)) {
                // $srcset = $attributes->srcset;
                $sources = array_map('trim', explode(',', $srcset));
                $srcset_elements = [];
                foreach ($sources as $source) {
                    list($srcset_path, $srcset_width) = explode(' ', trim($source));
                    $srcset_path = trim($srcset_path);
                    $srcset_width = str_replace('w', '', trim($srcset_width));
                    $srcset_image_path = self::extractPath($srcset_path);
                    if (empty($srcset_image_path)) {
                        continue;
                    }
                    $srcset_cf_path = $this->cloudflareUri($srcset_image_path, $srcset_width, null, 'dom');
                    $srcset_elements[] = ' ' . $srcset_cf_path . ' ' . $srcset_width . 'w';
                }
                $srcset_cf = trim(implode(',', $srcset_elements));
                $element->setAttribute('srcset', $srcset_cf);
            }
        }
        return $changed;
    }

    // public function hook_template_redirect()
    // {
    //     $cssRequest = get_query_var('css_request');
    //     if (empty($cssRequest)) {
    //         return;
    //     }

    //     // Security check to prevent directory traversal attacks
    //     if (strpos($cssRequest, '..') !== false) {
    //         // Bad request
    //         status_header(400);
    //         exit;
    //     }

    //     // Optional: Verify the request is for a valid CSS file within your theme or plugin directory
    //     $cssFilePath = get_stylesheet_directory() . '/' . $cssRequest; // Example for theme
    //     // $cssFilePath = WP_PLUGIN_DIR . '/your-plugin-name/' . $cssRequest; // Example for plugin

    //     if (file_exists($cssFilePath)) {
    //         // Process the CSS file
    //         $cssContent = file_get_contents($cssFilePath);
    //         // Perform your replacements here
    //         $cssContent = $this->hook_html_background_css($cssContent);

    //         // Set the correct content type
    //         header('Content-Type: text/css');
    //         echo $cssContent;
    //         exit;
    //     } else {
    //         // File not found
    //         status_header(404);
    //         exit;
    //     }
    // }
}

call_user_func(function () {
    add_action('plugins_loaded', function () {
        $cf = new \CloudflareImageResizer();
        $cf->init();
    });
});
