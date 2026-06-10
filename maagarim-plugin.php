<?php
/**
 * Plugin Name: מאגרים – פרסום דירות לשבת
 * Plugin URI: https://maagarim-dirot.co.il
 * Description: מנהל פרסום וניהול דירות לשבת עם API לווידג'ט
 * Version: 1.0.0
 * Author: מאגרים
 * Text Domain: maagarim
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants & Lookup Tables ─────────────────────────────────────────────────

define( 'MG_RENTAL_TYPES', [
    1 => 'שבת בלבד',
    2 => 'שבת החל מיום חמישי',
    3 => 'כל השבוע ראשון עד שבת',
] );

define( 'MG_APT_TYPES', [
    1 => 'דירה רגילה',
    2 => 'דירה חדשה',
    3 => 'דירה משופצת',
    4 => 'דירת אירוח',
    5 => 'צימר',
    6 => 'דירה במושב',
    7 => 'דירה לחג הקרוב',
    8 => 'דירה לבין הזמנים',
    9 => 'וילה',
] );

define( 'MG_CITIES', [
    1  => 'ירושלים',
    2  => 'בני ברק',
    3  => 'אלעד',
    4  => 'מודיעין עילית',
    5  => 'ביתר עילית',
    6  => 'בית שמש',
    7  => 'צפת',
    8  => 'אשדוד',
    9  => 'נתניה',
    10 => 'תל אביב',
    11 => 'חיפה',
    12 => 'פתח תקווה',
    13 => 'ראשון לציון',
    14 => 'חדרה',
    15 => 'טבריה',
    16 => 'באר שבע',
    17 => 'אופקים',
    18 => 'עפולה',
] );

define( 'MG_NEIGHBORHOODS', [
    1  => [1 => 'מאה שערים', 2 => 'גאולה', 3 => 'קרית מטרסדורף', 4 => 'רמות', 5 => 'הר נוף', 6 => 'בית וגן', 7 => 'קרית יובל', 8 => 'פסגת זאב'],
    2  => [1 => 'מרכז', 2 => 'קרית הרצוג', 3 => 'קרית ויזניץ', 4 => 'זכרון מאיר', 5 => 'פארק נווה גן'],
    3  => [1 => 'מרכז', 2 => 'שכונה א', 3 => 'שכונה ב'],
    4  => [1 => 'קרית ספר', 2 => 'מתתיהו', 3 => 'חשמונאים'],
    5  => [1 => 'מרכז', 2 => 'שכונה א', 3 => 'שכונה ב'],
    6  => [1 => 'רמת בית שמש א', 2 => 'רמת בית שמש ב', 3 => 'רמת בית שמש ג', 4 => 'מרכז העיר'],
    7  => [1 => 'מרכז', 2 => 'קרית חב"ד', 3 => 'שכונת צאנז', 4 => 'שכונה ד'],
    8  => [1 => 'מרכז', 2 => 'שכונה יא', 3 => 'שכונה יב', 4 => 'שכונה ז'],
    9  => [1 => 'מרכז', 2 => 'קרית נורדאו', 3 => 'עיר ימים'],
    10 => [1 => 'לב תל אביב', 2 => 'פלורנטין', 3 => 'נוה צדק', 4 => 'יפו'],
    11 => [1 => 'הדר הכרמל', 2 => 'כרמל', 3 => 'נווה שאנן', 4 => 'רמות ויז\'ניץ'],
    12 => [1 => 'מרכז', 2 => 'כפר גנים', 3 => 'שכונה ד'],
    13 => [1 => 'מרכז', 2 => 'נחלת יהודה', 3 => 'שכונה ד'],
    14 => [1 => 'מרכז', 2 => 'שיכון ג'],
    15 => [1 => 'מרכז', 2 => 'קרית שמואל', 3 => 'שכונת תל גנן'],
    16 => [1 => 'מרכז', 2 => 'רמות', 3 => 'נאות לון'],
    17 => [1 => 'מרכז', 2 => 'שכונה ב'],
    18 => [1 => 'מרכז', 2 => 'שכונה ב'],
] );

define( 'MG_VALID_FEATURES', [
    'parking', 'ac', 'elevator', 'balcony', 'wifi', 'washing',
    'dishwasher', 'bathtub', 'shabbat_mode', 'crib', 'synagogue',
    'garden', 'handicapped', 'quiet',
    'pool', 'jacuzzi', 'swings', 'hammock', 'ping_pong',
    'bouncy_castle', 'snooker', 'table_tennis',
] );

// ── Secret Key ────────────────────────────────────────────────────────────────

function mg_secret_key(): string {
    $stored = get_option( 'maagarim_secret' );
    if ( ! $stored ) {
        $stored = wp_generate_password( 32, false );
        update_option( 'maagarim_secret', $stored );
    }
    return 'mg_' . $stored;
}

// ── Token Functions ───────────────────────────────────────────────────────────

function mg_make_token( string $email ): string {
    $payload = base64_encode( $email . ':' . time() );
    $hmac    = hash_hmac( 'sha256', $payload, mg_secret_key() );
    return $payload . '.' . $hmac;
}

function mg_email_from_token( string $token ): ?string {
    $parts = explode( '.', $token, 2 );
    if ( count( $parts ) !== 2 ) return null;

    [ $payload, $hmac ] = $parts;

    $expected = hash_hmac( 'sha256', $payload, mg_secret_key() );
    if ( ! hash_equals( $expected, $hmac ) ) return null;

    $decoded = base64_decode( $payload, true );
    if ( $decoded === false ) return null;

    $colon = strrpos( $decoded, ':' );
    if ( $colon === false ) return null;

    $email = substr( $decoded, 0, $colon );
    $ts    = (int) substr( $decoded, $colon + 1 );

    if ( time() - $ts > 30 * DAY_IN_SECONDS ) return null;

    return $email ?: null;
}

// ── Shabbat Helpers ───────────────────────────────────────────────────────────

function mg_next_shabbat_end(): int {
    $tz  = new DateTimeZone( 'Asia/Jerusalem' );
    $now = new DateTime( 'now', $tz );
    $dow = (int) $now->format( 'N' ); // 1=Mon … 7=Sun
    $min = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );

    // Days until next Saturday (dow=6)
    $d = ( 6 - $dow + 7 ) % 7;
    if ( $d === 0 && $min >= 20 * 60 + 30 ) $d = 7; // already past Shabbat end this week

    $end = clone $now;
    $end->modify( "+{$d} days" )->setTime( 20, 30, 0 );

    return $end->getTimestamp();
}

// ── Custom Post Type ──────────────────────────────────────────────────────────

add_action( 'init', function () {
    register_post_type( 'maagarim_apt', [
        'public'            => false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'menu_position'     => 25,
        'menu_icon'         => 'dashicons-building',
        'label'             => 'דירות לשבת',
        'labels'            => [
            'name'               => 'דירות לשבת',
            'singular_name'      => 'דירה לשבת',
            'add_new'            => 'הוסף דירה',
            'add_new_item'       => 'הוסף דירה חדשה',
            'edit_item'          => 'ערוך דירה',
            'view_item'          => 'צפה בדירה',
            'all_items'          => 'כל הדירות',
            'search_items'       => 'חפש דירות',
            'not_found'          => 'לא נמצאו דירות',
            'not_found_in_trash' => 'לא נמצאו דירות בפח',
        ],
        'supports'          => [ 'title' ],
        'capability_type'   => 'post',
        'map_meta_cap'      => true,
    ] );
} );

// ── Admin Columns ─────────────────────────────────────────────────────────────

add_filter( 'manage_maagarim_apt_posts_columns', function ( array $cols ): array {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) {
            $new['mg_city']    = 'עיר';
            $new['mg_email']   = 'אימייל';
            $new['mg_expires'] = 'תוקף';
        }
    }
    return $new;
} );

add_action( 'manage_maagarim_apt_posts_custom_column', function ( string $col, int $post_id ) {
    switch ( $col ) {
        case 'mg_city':
            $city_id = (int) get_post_meta( $post_id, '_maagarim_city', true );
            echo esc_html( MG_CITIES[ $city_id ] ?? $city_id );
            break;
        case 'mg_email':
            echo esc_html( get_post_meta( $post_id, '_maagarim_email', true ) );
            break;
        case 'mg_expires':
            $ts = (int) get_post_meta( $post_id, '_maagarim_expires', true );
            echo $ts ? esc_html( date_i18n( 'd/m/Y H:i', $ts ) ) : '—';
            break;
    }
}, 10, 2 );

// ── WP Cron – expire apartments ───────────────────────────────────────────────

add_action( 'maagarim_expire_apts', function () {
    $posts = get_posts( [
        'post_type'      => 'maagarim_apt',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_maagarim_expires',
                'value'   => time(),
                'compare' => '<',
                'type'    => 'NUMERIC',
            ],
        ],
    ] );
    foreach ( $posts as $pid ) {
        wp_trash_post( $pid );
    }
} );

if ( ! wp_next_scheduled( 'maagarim_expire_apts' ) ) {
    wp_schedule_event( time(), 'daily', 'maagarim_expire_apts' );
}

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'maagarim_expire_apts' );
} );

// ── REST API ──────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'maagarim/v1', '/api', [
        'methods'             => [ 'POST', 'OPTIONS' ],
        'callback'            => 'mg_api_handler',
        'permission_callback' => '__return_true',
    ] );
} );

function mg_cors_headers(): void {
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
}

function mg_json( array $data, int $status = 200 ): WP_REST_Response {
    mg_cors_headers();
    $response = new WP_REST_Response( $data, $status );
    $response->header( 'Content-Type', 'application/json; charset=utf-8' );
    return $response;
}

function mg_err( string $msg, int $status = 400 ): WP_REST_Response {
    return mg_json( [ 'ok' => false, 'error' => $msg ], $status );
}

function mg_api_handler( WP_REST_Request $request ): WP_REST_Response {
    // Handle preflight
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        mg_cors_headers();
        return mg_json( [], 200 );
    }

    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) {
        $raw  = $request->get_body();
        $body = json_decode( $raw, true );
    }
    if ( ! is_array( $body ) ) $body = [];

    $action = sanitize_key( $body['action'] ?? '' );
    $token  = sanitize_text_field( $body['token'] ?? '' );

    switch ( $action ) {
        case 'form_data':
            return mg_action_form_data();

        case 'email_login':
            return mg_action_email_login( $body );

        case 'my_listings':
            return mg_action_my_listings( $token );

        case 'publish':
            return mg_action_publish( $body, $token );

        case 'delete_apt':
            return mg_action_delete_apt( $body, $token );

        case 'all_listings':
            return mg_action_all_listings( $body );

        default:
            return mg_err( 'פעולה לא מוכרת' );
    }
}

// ── Action: form_data ─────────────────────────────────────────────────────────

function mg_action_form_data(): WP_REST_Response {
    return mg_json( [
        'ok'           => true,
        'cities'       => MG_CITIES,
        'neighborhoods'=> MG_NEIGHBORHOODS,
        'apt_types'    => MG_APT_TYPES,
        'rental_types' => MG_RENTAL_TYPES,
    ] );
}

// ── Action: all_listings ──────────────────────────────────────────────────────

function mg_action_all_listings( array $body ): WP_REST_Response {
    $args = [
        'post_type'      => 'maagarim_apt',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    // Optional city filter
    $city = (int) ( $body['city'] ?? 0 );
    // Optional rental_type filter
    $rental_type = (int) ( $body['rental_type'] ?? 0 );

    if ( $city || $rental_type ) {
        $clauses = [];
        if ( $city && array_key_exists( $city, MG_CITIES ) ) {
            $clauses[] = [ 'key' => '_maagarim_city', 'value' => $city, 'type' => 'NUMERIC' ];
        }
        if ( $rental_type && array_key_exists( $rental_type, MG_RENTAL_TYPES ) ) {
            $clauses[] = [ 'key' => '_maagarim_rental_type', 'value' => $rental_type, 'type' => 'NUMERIC' ];
        }
        if ( $clauses ) {
            $args['meta_query'] = array_merge( [ 'relation' => 'AND' ], $clauses );
        }
    }

    $posts = get_posts( $args );

    $listings = array_map( fn( $p ) => mg_post_to_listing( $p->ID ), $posts );

    return mg_json( [
        'ok'           => true,
        'listings'     => $listings,
        'cities'       => MG_CITIES,
        'apt_types'    => MG_APT_TYPES,
        'rental_types' => MG_RENTAL_TYPES,
    ] );
}

// ── Action: email_login ───────────────────────────────────────────────────────

function mg_action_email_login( array $body ): WP_REST_Response {
    $email = strtolower( sanitize_email( $body['email'] ?? '' ) );
    if ( ! is_email( $email ) ) {
        return mg_err( 'כתובת אימייל לא תקינה' );
    }

    $token = mg_make_token( $email );

    return mg_json( [
        'ok'    => true,
        'token' => $token,
        'email' => $email,
    ] );
}

// ── Action: my_listings ───────────────────────────────────────────────────────

function mg_action_my_listings( string $token ): WP_REST_Response {
    $email = mg_email_from_token( $token );
    if ( ! $email ) {
        return mg_err( 'טוקן לא מאומת – נא להתחבר מחדש', 401 );
    }

    $posts = get_posts( [
        'post_type'      => 'maagarim_apt',
        'post_status'    => [ 'publish', 'draft' ],
        'posts_per_page' => 50,
        'meta_query'     => [
            [
                'key'   => '_maagarim_email',
                'value' => $email,
            ],
        ],
    ] );

    $listings = [];
    foreach ( $posts as $post ) {
        $listings[] = mg_post_to_listing( $post->ID );
    }

    return mg_json( [
        'ok'           => true,
        'listings'     => $listings,
        'cities'       => MG_CITIES,
        'apt_types'    => MG_APT_TYPES,
        'rental_types' => MG_RENTAL_TYPES,
    ] );
}

function mg_post_to_listing( int $post_id ): array {
    $meta = get_post_meta( $post_id );

    $get = function ( string $key, $default = '' ) use ( $meta ) {
        return isset( $meta[ $key ][0] ) ? $meta[ $key ][0] : $default;
    };

    $features_raw = $get( '_maagarim_features', '[]' );
    $features     = json_decode( $features_raw, true );
    if ( ! is_array( $features ) ) $features = [];

    $images_raw = $get( '_maagarim_images', '[]' );
    $images     = json_decode( $images_raw, true );
    if ( ! is_array( $images ) ) $images = [];

    return [
        'id'            => (string) $post_id,
        'city'          => (int) $get( '_maagarim_city', 0 ),
        'neighborhood'  => (int) $get( '_maagarim_neighborhood', 0 ),
        'apt_type'      => (int) $get( '_maagarim_apt_type', 1 ),
        'rental_type'   => (int) $get( '_maagarim_rental_type', 1 ),
        'beds'          => (int) $get( '_maagarim_beds', 1 ),
        'bedrooms'      => (int) $get( '_maagarim_bedrooms', 1 ),
        'price'         => (int) $get( '_maagarim_price', 0 ),
        'floor'         => $get( '_maagarim_floor', '' ),
        'features'      => $features,
        'description'   => $get( '_maagarim_description', '' ),
        'contact_name'  => $get( '_maagarim_contact_name', '' ),
        'contact_phone' => $get( '_maagarim_contact_phone', '' ),
        'expires'       => (int) $get( '_maagarim_expires', 0 ),
        'images'        => $images,
    ];
}

// ── Action: publish ───────────────────────────────────────────────────────────

function mg_action_publish( array $body, string $token ): WP_REST_Response {
    $email = mg_email_from_token( $token );
    if ( ! $email ) {
        return mg_err( 'טוקן לא מאומת – נא להתחבר מחדש', 401 );
    }

    // Validate required fields
    $city     = (int) ( $body['city']     ?? 0 );
    $apt_type = (int) ( $body['apt_type'] ?? 0 );

    if ( ! array_key_exists( $city, MG_CITIES ) ) {
        return mg_err( 'עיר לא תקינה' );
    }
    if ( ! array_key_exists( $apt_type, MG_APT_TYPES ) ) {
        return mg_err( 'סוג דירה לא תקין' );
    }

    $neighborhood = (int) ( $body['neighborhood'] ?? 0 );
    $rental_type  = (int) ( $body['rental_type']  ?? 1 );
    $beds         = (int) ( $body['beds']         ?? 1 );
    $bedrooms     = (int) ( $body['bedrooms']     ?? 1 );
    $price        = (int) ( $body['price']        ?? 0 );
    $floor        = sanitize_text_field( $body['floor']         ?? '' );
    $description  = sanitize_textarea_field( $body['description']  ?? '' );
    $contact_name = sanitize_text_field( $body['contact_name']  ?? '' );
    $contact_phone= sanitize_text_field( $body['contact_phone'] ?? '' );

    if ( ! array_key_exists( $rental_type, MG_RENTAL_TYPES ) ) $rental_type = 1;

    // Clamp numeric fields
    $beds     = max( 1, min( 99, $beds ) );
    $bedrooms = max( 0, min( 20, $bedrooms ) );
    $price    = max( 0, min( 99999, $price ) );

    // Validate features whitelist
    $raw_features = is_array( $body['features'] ?? null ) ? $body['features'] : [];
    $features     = array_values( array_filter( $raw_features, function ( $f ) {
        return in_array( $f, MG_VALID_FEATURES, true );
    } ) );

    // Handle images
    $image_urls = mg_save_images( $body['images'] ?? [], $email );

    // Expiry
    $expires = mg_next_shabbat_end();

    // Build post title
    $city_name = MG_CITIES[ $city ];
    $title     = $city_name . ' – ' . ( MG_APT_TYPES[ $apt_type ] ?? '' );

    // Insert post
    $post_id = wp_insert_post( [
        'post_type'   => 'maagarim_apt',
        'post_title'  => $title,
        'post_status' => 'publish',
    ], true );

    if ( is_wp_error( $post_id ) ) {
        return mg_err( 'שגיאה ביצירת הפרסום: ' . $post_id->get_error_message() );
    }

    // Save meta
    $meta_fields = [
        '_maagarim_email'        => $email,
        '_maagarim_city'         => $city,
        '_maagarim_neighborhood' => $neighborhood,
        '_maagarim_apt_type'     => $apt_type,
        '_maagarim_rental_type'  => $rental_type,
        '_maagarim_beds'         => $beds,
        '_maagarim_bedrooms'     => $bedrooms,
        '_maagarim_price'        => $price,
        '_maagarim_floor'        => $floor,
        '_maagarim_features'     => json_encode( $features, JSON_UNESCAPED_UNICODE ),
        '_maagarim_description'  => $description,
        '_maagarim_contact_name' => $contact_name,
        '_maagarim_contact_phone'=> $contact_phone,
        '_maagarim_images'       => json_encode( $image_urls, JSON_UNESCAPED_UNICODE ),
        '_maagarim_expires'      => $expires,
    ];

    foreach ( $meta_fields as $key => $value ) {
        update_post_meta( $post_id, $key, $value );
    }

    return mg_json( [
        'ok'      => true,
        'id'      => (string) $post_id,
        'expires' => $expires,
    ] );
}

// ── Action: delete_apt ────────────────────────────────────────────────────────

function mg_action_delete_apt( array $body, string $token ): WP_REST_Response {
    $email = mg_email_from_token( $token );
    if ( ! $email ) {
        return mg_err( 'טוקן לא מאומת – נא להתחבר מחדש', 401 );
    }

    $apt_id = (int) ( $body['apt_id'] ?? 0 );
    if ( $apt_id <= 0 ) {
        return mg_err( 'מזהה דירה לא תקין' );
    }

    $post = get_post( $apt_id );
    if ( ! $post || $post->post_type !== 'maagarim_apt' ) {
        return mg_err( 'פרסום לא נמצא' );
    }

    $owner = get_post_meta( $apt_id, '_maagarim_email', true );
    if ( $owner !== $email ) {
        return mg_err( 'אין הרשאה למחוק פרסום זה', 403 );
    }

    wp_trash_post( $apt_id );

    return mg_json( [ 'ok' => true ] );
}

// ── Image Handling ────────────────────────────────────────────────────────────

function mg_save_images( $images_input, string $email ): array {
    if ( ! is_array( $images_input ) || empty( $images_input ) ) {
        return [];
    }

    $upload_dir = wp_upload_dir();
    $base_dir   = $upload_dir['basedir'] . '/maagarim-apts';
    $base_url   = $upload_dir['baseurl'] . '/maagarim-apts';

    if ( ! file_exists( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
        // Prevent directory listing
        file_put_contents( $base_dir . '/index.php', '<?php // silence' );
    }

    $urls = [];

    foreach ( array_slice( $images_input, 0, 4 ) as $img_data ) {
        if ( ! is_string( $img_data ) ) continue;

        // Strip data URI prefix if present
        if ( strpos( $img_data, ',' ) !== false ) {
            $img_data = explode( ',', $img_data, 2 )[1];
        }

        $binary = base64_decode( $img_data, true );
        if ( $binary === false || strlen( $binary ) < 100 ) continue;

        // Verify it looks like a JPEG or acceptable image
        // (canvas.toDataURL produces JPEG)
        $filename  = 'apt-' . substr( md5( $email . microtime() . random_int( 0, 99999 ) ), 0, 16 ) . '.jpg';
        $filepath  = $base_dir . '/' . $filename;

        $written = file_put_contents( $filepath, $binary );
        if ( $written === false ) continue;

        $urls[] = $base_url . '/' . $filename;
    }

    return $urls;
}

// ── CORS for REST OPTIONS preflight ──────────────────────────────────────────

add_action( 'rest_pre_serve_request', function () {
    if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
    }
} );
