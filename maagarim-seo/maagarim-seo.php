<?php
/**
 * Plugin Name: מאגרים דירות SEO
 * Plugin URI:  https://www.maagarim-dirot.co.il/
 * Description: אופטימיזציית SEO מלאה לאתר מאגרים דירות – כולל מטא-תגים, Open Graph, Schema.org, Sitemap, Breadcrumbs וניהול Robots.txt
 * Version:     1.0.0
 * Author:      מאגרים דירות
 * License:     GPL-2.0+
 * Text Domain: maagarim-seo
 */

defined('ABSPATH') || exit;

define('MAAGARIM_SEO_VERSION', '1.0.0');
define('MAAGARIM_SEO_FILE',    __FILE__);
define('MAAGARIM_SEO_DIR',     plugin_dir_path(__FILE__));
define('MAAGARIM_SEO_URL',     plugin_dir_url(__FILE__));

/* ─────────────────────────────────────────────
   1. REMOVE DEFAULT <title> AND ADD CUSTOM ONE
   ───────────────────────────────────────────── */
remove_action('wp_head', '_wp_render_title_tag', 1);
add_action('wp_head', 'maagarim_render_title', 1);

function maagarim_render_title() {
    $site_name = get_bloginfo('name');
    $title = maagarim_get_seo_title();
    echo '<title>' . esc_html($title) . '</title>' . "\n";
}

function maagarim_get_seo_title() {
    $site_name = get_bloginfo('name');

    if (is_home() || is_front_page()) {
        $custom = get_option('maagarim_seo_home_title');
        return $custom ?: "{$site_name} | דירות למכירה ולהשכרה בישראל";
    }
    if (is_singular()) {
        $post_title = get_the_title();
        $custom     = get_post_meta(get_the_ID(), '_maagarim_seo_title', true);
        return $custom ?: "{$post_title} | {$site_name}";
    }
    if (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        return isset($term->name) ? "{$term->name} | {$site_name}" : $site_name;
    }
    if (is_search()) {
        return 'תוצאות חיפוש: "' . get_search_query() . '" | ' . $site_name;
    }
    if (is_404()) {
        return 'עמוד לא נמצא (404) | ' . $site_name;
    }
    if (is_archive()) {
        return get_the_archive_title() . ' | ' . $site_name;
    }
    return $site_name;
}

/* ─────────────────────────────────────────────
   2. META TAGS (description + keywords)
   ───────────────────────────────────────────── */
add_action('wp_head', 'maagarim_meta_tags', 2);

function maagarim_meta_tags() {
    $description = maagarim_get_meta_description();
    $site_name   = get_bloginfo('name');

    echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    echo '<meta name="author" content="' . esc_attr($site_name) . '">' . "\n";
    echo '<meta name="robots" content="' . maagarim_robots_content() . '">' . "\n";

    // Canonical
    $canonical = maagarim_get_canonical();
    if ($canonical) {
        echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    }

    // Hebrew / IL language
    echo '<meta name="language" content="Hebrew">' . "\n";
    echo '<link rel="alternate" hreflang="he-IL" href="' . esc_url($canonical ?: home_url('/')) . '">' . "\n";
}

function maagarim_get_meta_description() {
    if (is_singular()) {
        $custom = get_post_meta(get_the_ID(), '_maagarim_seo_description', true);
        if ($custom) return $custom;
        $excerpt = get_the_excerpt();
        if ($excerpt) return wp_trim_words(strip_tags($excerpt), 25);
        $content = get_the_content();
        return wp_trim_words(strip_tags($content), 25);
    }
    if (is_home() || is_front_page()) {
        $custom = get_option('maagarim_seo_home_description');
        return $custom ?: 'מאגרים דירות – המאגר המקיף ביותר לדירות למכירה ולהשכרה בכל רחבי ישראל. מצאו דירה בתל אביב, ירושלים, חיפה ועוד. חיפוש נדל"ן מהיר, פשוט ואמין.';
    }
    if (is_tax() || is_category()) {
        $term = get_queried_object();
        if (isset($term->description) && $term->description) return $term->description;
        if (isset($term->name)) return "דירות ב{$term->name} – מצאו את הנכס המושלם במאגרים דירות.";
    }
    return get_bloginfo('description') ?: 'מאגר דירות למכירה ולהשכרה בישראל';
}

function maagarim_robots_content() {
    if (is_search() || is_404() || (is_paged() && get_query_var('paged') > 2)) {
        return 'noindex, follow';
    }
    return 'index, follow';
}

function maagarim_get_canonical() {
    if (is_singular()) return get_permalink();
    if (is_home() || is_front_page()) return home_url('/');
    if (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        return $term ? get_term_link($term) : null;
    }
    if (is_paged()) {
        global $wp;
        return add_query_arg([], home_url($wp->request));
    }
    return null;
}

/* ─────────────────────────────────────────────
   3. OPEN GRAPH + TWITTER CARD
   ───────────────────────────────────────────── */
add_action('wp_head', 'maagarim_opengraph', 3);

function maagarim_opengraph() {
    $site_name   = get_bloginfo('name');
    $title       = maagarim_get_seo_title();
    $description = maagarim_get_meta_description();
    $url         = maagarim_get_canonical() ?: home_url('/');
    $image       = maagarim_get_og_image();
    $type        = is_singular('post') ? 'article' : 'website';

    echo "\n<!-- Open Graph -->\n";
    echo '<meta property="og:type"        content="' . esc_attr($type) . '">' . "\n";
    echo '<meta property="og:url"         content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:title"       content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
    echo '<meta property="og:site_name"   content="' . esc_attr($site_name) . '">' . "\n";
    echo '<meta property="og:locale"      content="he_IL">' . "\n";
    if ($image) {
        echo '<meta property="og:image"       content="' . esc_url($image) . '">' . "\n";
        echo '<meta property="og:image:width" content="1200">' . "\n";
        echo '<meta property="og:image:height" content="630">' . "\n";
    }
    if ($type === 'article' && is_singular()) {
        echo '<meta property="article:published_time" content="' . esc_attr(get_the_date('c')) . '">' . "\n";
        echo '<meta property="article:modified_time"  content="' . esc_attr(get_the_modified_date('c')) . '">' . "\n";
    }

    echo "\n<!-- Twitter Card -->\n";
    echo '<meta name="twitter:card"        content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title"       content="' . esc_attr($title) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
    if ($image) {
        echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
    }
    echo "\n";
}

function maagarim_get_og_image() {
    if (is_singular() && has_post_thumbnail()) {
        return get_the_post_thumbnail_url(get_the_ID(), 'large');
    }
    $default = get_option('maagarim_seo_og_image');
    return $default ?: null;
}

/* ─────────────────────────────────────────────
   4. SCHEMA.ORG STRUCTURED DATA
   ───────────────────────────────────────────── */
add_action('wp_head', 'maagarim_schema', 5);

function maagarim_schema() {
    $schemas = [];

    // WebSite + Sitelinks Searchbox
    $schemas[] = [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        'name'            => get_bloginfo('name'),
        'url'             => home_url('/'),
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => home_url('/?s={search_term_string}'),
            'query-input' => 'required name=search_term_string',
        ],
    ];

    // Organization / RealEstateAgent (homepage only)
    if (is_home() || is_front_page()) {
        $schemas[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'RealEstateAgent',
            'name'        => get_bloginfo('name'),
            'url'         => home_url('/'),
            'description' => maagarim_get_meta_description(),
            'logo'        => [
                '@type' => 'ImageObject',
                'url'   => get_option('maagarim_seo_logo') ?: '',
            ],
            'areaServed'  => [
                '@type' => 'Country',
                'name'  => 'ישראל',
            ],
            'address'     => [
                '@type'          => 'PostalAddress',
                'addressCountry' => 'IL',
            ],
            'contactPoint' => [
                '@type'       => 'ContactPoint',
                'contactType' => 'customer support',
                'availableLanguage' => 'Hebrew',
            ],
        ];
    }

    // BreadcrumbList for singular pages
    if (is_singular()) {
        $schemas[] = maagarim_breadcrumb_schema();
    }

    // Article schema for blog posts
    if (is_singular('post')) {
        $schemas[] = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => get_the_title(),
            'description'      => maagarim_get_meta_description(),
            'datePublished'    => get_the_date('c'),
            'dateModified'     => get_the_modified_date('c'),
            'image'            => maagarim_get_og_image() ?: home_url('/og-image.jpg'),
            'author'           => [
                '@type' => 'Organization',
                'name'  => get_bloginfo('name'),
            ],
            'publisher'        => [
                '@type' => 'Organization',
                'name'  => get_bloginfo('name'),
            ],
        ];
    }

    // FAQ schema – auto-detect from post content shortcode [faq] or custom field
    if (is_singular()) {
        $faq = maagarim_get_faq_schema();
        if ($faq) $schemas[] = $faq;
    }

    foreach ($schemas as $schema) {
        if (!empty(array_filter($schema))) {
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            echo "\n" . '</script>' . "\n";
        }
    }
}

function maagarim_breadcrumb_schema() {
    $items   = [];
    $items[] = ['@type' => 'ListItem', 'position' => 1, 'name' => 'דף הבית', 'item' => home_url('/')];

    if (is_singular()) {
        $post = get_post();
        // Category middle crumb for posts
        if ($post->post_type === 'post') {
            $cats = get_the_category($post->ID);
            if ($cats) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => $cats[0]->name,
                    'item'     => get_category_link($cats[0]->term_id),
                ];
                $items[] = ['@type' => 'ListItem', 'position' => 3, 'name' => get_the_title(), 'item' => get_permalink()];
            } else {
                $items[] = ['@type' => 'ListItem', 'position' => 2, 'name' => get_the_title(), 'item' => get_permalink()];
            }
        } else {
            $items[] = ['@type' => 'ListItem', 'position' => 2, 'name' => get_the_title(), 'item' => get_permalink()];
        }
    }

    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
}

function maagarim_get_faq_schema() {
    $faq_data = get_post_meta(get_the_ID(), '_maagarim_faq', true);
    if (!$faq_data || !is_array($faq_data)) return null;

    $entities = array_map(function ($item) {
        return [
            '@type'          => 'Question',
            'name'           => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ];
    }, $faq_data);

    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    ];
}

/* ─────────────────────────────────────────────
   5. XML SITEMAP
   ───────────────────────────────────────────── */
add_action('init', 'maagarim_sitemap_rewrite');

function maagarim_sitemap_rewrite() {
    add_rewrite_rule('^sitemap\.xml$', 'index.php?maagarim_sitemap=1', 'top');
    add_rewrite_rule('^sitemap-([a-z]+)-?(\d*)\.xml$', 'index.php?maagarim_sitemap=$matches[1]&sitemap_page=$matches[2]', 'top');
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'maagarim_sitemap';
    $vars[] = 'sitemap_page';
    return $vars;
});

add_action('template_redirect', 'maagarim_serve_sitemap');

function maagarim_serve_sitemap() {
    $type = get_query_var('maagarim_sitemap');
    if (!$type) return;

    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Robots-Tag: noindex');

    if ($type === '1' || $type === 'index') {
        echo maagarim_sitemap_index();
    } elseif ($type === 'pages') {
        echo maagarim_sitemap_pages();
    } elseif ($type === 'posts') {
        echo maagarim_sitemap_posts();
    } elseif ($type === 'categories') {
        echo maagarim_sitemap_categories();
    } else {
        // try custom post type
        echo maagarim_sitemap_cpt($type);
    }
    exit;
}

function maagarim_sitemap_header() {
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
}

function maagarim_sitemap_index() {
    $out = maagarim_sitemap_header();
    $out .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $types = ['pages', 'posts', 'categories'];

    // Add custom post types
    $cpts = get_post_types(['public' => true, '_builtin' => false], 'names');
    foreach ($cpts as $cpt) $types[] = $cpt;

    foreach ($types as $t) {
        $out .= "  <sitemap>\n";
        $out .= '    <loc>' . esc_url(home_url("/sitemap-{$t}.xml")) . "</loc>\n";
        $out .= '    <lastmod>' . date('Y-m-d') . "</lastmod>\n";
        $out .= "  </sitemap>\n";
    }
    $out .= '</sitemapindex>';
    return $out;
}

function maagarim_sitemap_posts($post_type = 'post') {
    $posts = get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1]);
    $out   = maagarim_sitemap_header();
    $out  .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($posts as $p) {
        $out .= "  <url>\n";
        $out .= '    <loc>' . esc_url(get_permalink($p)) . "</loc>\n";
        $out .= '    <lastmod>' . date('Y-m-d', strtotime($p->post_modified)) . "</lastmod>\n";
        $out .= "    <changefreq>weekly</changefreq>\n";
        $out .= "    <priority>0.7</priority>\n";
        $out .= "  </url>\n";
    }
    $out .= '</urlset>';
    return $out;
}

function maagarim_sitemap_pages() {
    $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1]);
    $out   = maagarim_sitemap_header();
    $out  .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Home page
    $out .= "  <url>\n";
    $out .= '    <loc>' . esc_url(home_url('/')) . "</loc>\n";
    $out .= '    <lastmod>' . date('Y-m-d') . "</lastmod>\n";
    $out .= "    <changefreq>daily</changefreq>\n";
    $out .= "    <priority>1.0</priority>\n";
    $out .= "  </url>\n";

    foreach ($pages as $p) {
        if ((int)get_option('page_on_front') === $p->ID) continue;
        $out .= "  <url>\n";
        $out .= '    <loc>' . esc_url(get_permalink($p)) . "</loc>\n";
        $out .= '    <lastmod>' . date('Y-m-d', strtotime($p->post_modified)) . "</lastmod>\n";
        $out .= "    <changefreq>monthly</changefreq>\n";
        $out .= "    <priority>0.8</priority>\n";
        $out .= "  </url>\n";
    }
    $out .= '</urlset>';
    return $out;
}

function maagarim_sitemap_categories() {
    $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => true]);
    $out   = maagarim_sitemap_header();
    $out  .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $out .= "  <url>\n";
            $out .= '    <loc>' . esc_url(get_term_link($term)) . "</loc>\n";
            $out .= '    <lastmod>' . date('Y-m-d') . "</lastmod>\n";
            $out .= "    <changefreq>daily</changefreq>\n";
            $out .= "    <priority>0.7</priority>\n";
            $out .= "  </url>\n";
        }
    }
    $out .= '</urlset>';
    return $out;
}

function maagarim_sitemap_cpt($post_type) {
    if (!post_type_exists($post_type)) return '';
    return maagarim_sitemap_posts($post_type);
}

/* ─────────────────────────────────────────────
   6. ROBOTS.TXT
   ───────────────────────────────────────────── */
add_filter('robots_txt', 'maagarim_robots_txt', 10, 2);

function maagarim_robots_txt($output, $public) {
    if (!$public) return $output;
    $sitemap_url = home_url('/sitemap.xml');
    $output  = "User-agent: *\n";
    $output .= "Allow: /\n\n";
    $output .= "# Disallow admin and private areas\n";
    $output .= "Disallow: /wp-admin/\n";
    $output .= "Disallow: /wp-login.php\n";
    $output .= "Disallow: /feed/\n";
    $output .= "Disallow: /xmlrpc.php\n";
    $output .= "Disallow: /?s=\n";
    $output .= "Disallow: /?p=\n\n";
    $output .= "# Sitemap\n";
    $output .= "Sitemap: {$sitemap_url}\n\n";
    $output .= "# Crawl delay\n";
    $output .= "Crawl-delay: 1\n";
    return $output;
}

/* ─────────────────────────────────────────────
   7. PERFORMANCE / <HEAD> CLEANUP
   ───────────────────────────────────────────── */
// Remove generator tag (security + clean head)
remove_action('wp_head', 'wp_generator');

// Remove unnecessary links
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);

// Remove emoji scripts (performance)
remove_action('wp_head',    'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('admin_print_styles',  'print_emoji_styles');

// Disable XML-RPC
add_filter('xmlrpc_enabled', '__return_false');

// Remove REST API exposure header
remove_action('template_redirect', 'rest_output_link_header', 11);
remove_action('wp_head', 'rest_output_link_wp_head', 10);

/* ─────────────────────────────────────────────
   8. IMAGE ALT TEXT AUTO-FILL
   ───────────────────────────────────────────── */
add_filter('wp_get_attachment_image_attributes', 'maagarim_auto_alt', 10, 2);

function maagarim_auto_alt($attr, $attachment) {
    if (empty($attr['alt'])) {
        $title     = get_the_title($attachment->ID);
        $attr['alt'] = $title ?: get_bloginfo('name');
    }
    return $attr;
}

/* ─────────────────────────────────────────────
   9. POST/PAGE META BOX (custom SEO fields)
   ───────────────────────────────────────────── */
add_action('add_meta_boxes', 'maagarim_add_meta_box');

function maagarim_add_meta_box() {
    add_meta_box(
        'maagarim_seo_box',
        'הגדרות SEO – מאגרים דירות',
        'maagarim_meta_box_html',
        ['post', 'page'],
        'normal',
        'high'
    );
}

function maagarim_meta_box_html($post) {
    wp_nonce_field('maagarim_seo_save', 'maagarim_seo_nonce');
    $seo_title = get_post_meta($post->ID, '_maagarim_seo_title',       true);
    $seo_desc  = get_post_meta($post->ID, '_maagarim_seo_description', true);
    ?>
    <table style="width:100%;border-collapse:collapse">
      <tr>
        <td style="padding:8px 0;width:150px;font-weight:bold">כותרת SEO:</td>
        <td style="padding:8px 0">
          <input type="text" name="maagarim_seo_title" value="<?php echo esc_attr($seo_title); ?>"
                 style="width:100%" maxlength="70"
                 placeholder="השאר ריק לכותרת אוטומטית" />
          <small style="color:#777">מומלץ עד 60 תווים. נוכחי: <span id="maagarim-title-len"><?php echo mb_strlen($seo_title); ?></span></small>
        </td>
      </tr>
      <tr>
        <td style="padding:8px 0;font-weight:bold">תיאור SEO:</td>
        <td style="padding:8px 0">
          <textarea name="maagarim_seo_description" rows="3"
                    style="width:100%" maxlength="165"
                    placeholder="תיאור קצר של הדף לתוצאות החיפוש (עד 160 תווים)"><?php echo esc_textarea($seo_desc); ?></textarea>
          <small style="color:#777">מומלץ עד 155 תווים. נוכחי: <span id="maagarim-desc-len"><?php echo mb_strlen($seo_desc); ?></span></small>
        </td>
      </tr>
    </table>
    <script>
    (function(){
      document.querySelector('[name=maagarim_seo_title]').addEventListener('input', function(){
        document.getElementById('maagarim-title-len').textContent = this.value.length;
      });
      document.querySelector('[name=maagarim_seo_description]').addEventListener('input', function(){
        document.getElementById('maagarim-desc-len').textContent = this.value.length;
      });
    })();
    </script>
    <?php
}

add_action('save_post', 'maagarim_save_meta_box');

function maagarim_save_meta_box($post_id) {
    if (!isset($_POST['maagarim_seo_nonce'])) return;
    if (!wp_verify_nonce($_POST['maagarim_seo_nonce'], 'maagarim_seo_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['maagarim_seo_title'])) {
        update_post_meta($post_id, '_maagarim_seo_title', sanitize_text_field($_POST['maagarim_seo_title']));
    }
    if (isset($_POST['maagarim_seo_description'])) {
        update_post_meta($post_id, '_maagarim_seo_description', sanitize_textarea_field($_POST['maagarim_seo_description']));
    }
}

/* ─────────────────────────────────────────────
   10. ADMIN SETTINGS PAGE
   ───────────────────────────────────────────── */
add_action('admin_menu', 'maagarim_admin_menu');

function maagarim_admin_menu() {
    add_options_page(
        'הגדרות SEO – מאגרים דירות',
        'מאגרים SEO',
        'manage_options',
        'maagarim-seo',
        'maagarim_settings_page'
    );
}

add_action('admin_init', 'maagarim_register_settings');

function maagarim_register_settings() {
    $fields = [
        'maagarim_seo_home_title',
        'maagarim_seo_home_description',
        'maagarim_seo_og_image',
        'maagarim_seo_logo',
        'maagarim_seo_ga_id',
        'maagarim_seo_gtm_id',
    ];
    foreach ($fields as $f) {
        register_setting('maagarim_seo_group', $f, ['sanitize_callback' => 'sanitize_text_field']);
    }
}

function maagarim_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap" dir="rtl">
      <h1>הגדרות SEO – מאגרים דירות</h1>
      <form method="post" action="options.php">
        <?php settings_fields('maagarim_seo_group'); ?>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="maagarim_seo_home_title">כותרת עמוד הבית</label></th>
            <td>
              <input type="text" id="maagarim_seo_home_title" name="maagarim_seo_home_title"
                     class="large-text" maxlength="70"
                     value="<?php echo esc_attr(get_option('maagarim_seo_home_title')); ?>" />
              <p class="description">השאר ריק לברירת מחדל: "שם האתר | דירות למכירה ולהשכרה בישראל"</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="maagarim_seo_home_description">תיאור עמוד הבית</label></th>
            <td>
              <textarea id="maagarim_seo_home_description" name="maagarim_seo_home_description"
                        class="large-text" rows="3" maxlength="165"><?php echo esc_textarea(get_option('maagarim_seo_home_description')); ?></textarea>
              <p class="description">תיאור מטא לעמוד הבית (עד 155 תווים)</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="maagarim_seo_og_image">תמונת Open Graph ברירת מחדל</label></th>
            <td>
              <input type="url" id="maagarim_seo_og_image" name="maagarim_seo_og_image"
                     class="large-text"
                     value="<?php echo esc_attr(get_option('maagarim_seo_og_image')); ?>" />
              <p class="description">URL של תמונה ברירת מחדל לשיתוף ברשתות חברתיות (1200×630 פיקסל)</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="maagarim_seo_logo">URL של לוגו</label></th>
            <td>
              <input type="url" id="maagarim_seo_logo" name="maagarim_seo_logo"
                     class="large-text"
                     value="<?php echo esc_attr(get_option('maagarim_seo_logo')); ?>" />
              <p class="description">URL של לוגו האתר לסכמת Schema.org</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="maagarim_seo_ga_id">Google Analytics ID</label></th>
            <td>
              <input type="text" id="maagarim_seo_ga_id" name="maagarim_seo_ga_id"
                     class="regular-text" placeholder="G-XXXXXXXXXX"
                     value="<?php echo esc_attr(get_option('maagarim_seo_ga_id')); ?>" />
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="maagarim_seo_gtm_id">Google Tag Manager ID</label></th>
            <td>
              <input type="text" id="maagarim_seo_gtm_id" name="maagarim_seo_gtm_id"
                     class="regular-text" placeholder="GTM-XXXXXXX"
                     value="<?php echo esc_attr(get_option('maagarim_seo_gtm_id')); ?>" />
            </td>
          </tr>
        </table>
        <?php submit_button('שמור הגדרות'); ?>
      </form>

      <hr/>
      <h2>מפת האתר (Sitemap)</h2>
      <p>
        מפת האתר זמינה בכתובת:
        <a href="<?php echo esc_url(home_url('/sitemap.xml')); ?>" target="_blank"><?php echo esc_url(home_url('/sitemap.xml')); ?></a>
        <br>
        לאחר הפעלת התוסף, <strong>עדכנו את ה-Permalinks</strong> (הגדרות ← קישורים קבועים ← שמור) כדי לאפשר את כתובת ה-sitemap.
      </p>
    </div>
    <?php
}

/* ─────────────────────────────────────────────
   11. GOOGLE ANALYTICS / GTM
   ───────────────────────────────────────────── */
add_action('wp_head', 'maagarim_analytics', 1);

function maagarim_analytics() {
    $ga_id  = get_option('maagarim_seo_ga_id');
    $gtm_id = get_option('maagarim_seo_gtm_id');

    if ($gtm_id) {
        echo "<!-- Google Tag Manager -->\n";
        echo '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f);})(window,document,\'script\',\'dataLayer\',\'' . esc_js($gtm_id) . '\');</script>' . "\n";
        echo "<!-- End Google Tag Manager -->\n\n";
    } elseif ($ga_id) {
        echo "<!-- Google Analytics -->\n";
        echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr($ga_id) . '"></script>' . "\n";
        echo '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag(\'js\',new Date());gtag(\'config\',\'' . esc_js($ga_id) . '\',{anonymize_ip:true});</script>' . "\n\n";
    }
}

add_action('wp_body_open', 'maagarim_gtm_noscript');

function maagarim_gtm_noscript() {
    $gtm_id = get_option('maagarim_seo_gtm_id');
    if ($gtm_id) {
        echo '<!-- Google Tag Manager (noscript) -->' . "\n";
        echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr($gtm_id) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
        echo '<!-- End Google Tag Manager (noscript) -->' . "\n";
    }
}

/* ─────────────────────────────────────────────
   12. ACTIVATION / FLUSH REWRITE RULES
   ───────────────────────────────────────────── */
register_activation_hook(__FILE__, 'maagarim_activate');

function maagarim_activate() {
    maagarim_sitemap_rewrite();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'maagarim_deactivate');

function maagarim_deactivate() {
    flush_rewrite_rules();
}
