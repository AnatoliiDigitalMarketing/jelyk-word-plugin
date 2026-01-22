<?php
/**
 * Plugin Name: Jelyk Word Plugin
 * Description: Дані слова (Substantiv/Verb/Adjektiv) + Bedeutungen (1..N) + Cards (0..N) + збереження перекладів для карток.
 * Author: Anatolii (Jelyk)
 * Version: 0.2.14
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Jelyk_Word_Plugin {

    const META_PREFIX = '_jelyk_';
    const DB_VERSION  = 2;
        const VERSION     = '0.2.14';
        const DB_VERSION_OPTION = 'jelyk_db_version';

    // Мови, які зберігаємо “з коробки”. (DE = базова мова речення, тому тут не потрібно)
    // Важливо: українська стандартно = 'uk' (а не 'ua' / 'UK').
    const DEFAULT_LANGS = [ 'en', 'uk', 'ru', 'tr', 'ar', 'pl', 'ro', 'fr', 'es', 'it' ];

	/**
	 * Display label for language codes in dropdown.
	 * We keep DEFAULT_LANGS as a simple array of codes (strings).
	 */
        protected static function lang_label( $code ) {
                $map = [
                        'de' => 'DE',
                        'en' => 'EN',
                        'uk' => 'UK',
			'ru' => 'RU',
			'tr' => 'TR',
			'ar' => 'AR',
			'pl' => 'PL',
			'ro' => 'RO',
			'fr' => 'FR',
			'es' => 'ES',
			'it' => 'IT',
		];
                $code = strtolower( (string) $code );
                return isset( $map[ $code ] ) ? $map[ $code ] : strtoupper( $code );
        }

        /**
         * Normalize language code for consistent lookup / output.
         */
        protected static function norm_lang( $code ) {
                $code = strtolower( trim( (string) $code ) );
                $code = preg_replace( '/[^a-z0-9_-]/i', '', $code );
                if ( $code === 'ua' ) {
                        $code = 'uk';
                }
                return $code;
        }

    /** @var array<int,bool> */
    protected static $has_meanings_cache = [];

    public static function init() {
        register_activation_hook( __FILE__, [ __CLASS__, 'on_activate' ] );

        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_upgrade' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybe_upgrade' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_tools_page' ] );
        add_action( 'admin_post_jelyk_cleanup', [ __CLASS__, 'handle_cleanup' ] );
        add_action( 'admin_post_jelyk_cleanup_preview', [ __CLASS__, 'handle_cleanup_preview' ] );

        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post', [ __CLASS__, 'save_all' ] );


		// Frontend output (no shortcode): auto-append Bedeutungen & Cards to the end of the post content.
		// Some themes/plugins may remove/reset `the_content` filters after init, so we enforce
		// our hooks again later in the request lifecycle.
		self::ensure_frontend_hooks();
		add_action( 'wp', [ __CLASS__, 'ensure_frontend_hooks' ], 999 );
		add_action( 'template_redirect', [ __CLASS__, 'ensure_frontend_hooks' ], 999 );
    }

	/**
	 * Register (or re-register) frontend hooks.
	 *
	 * Some themes/plugins remove or reset `the_content` filters late in the request.
	 * Calling this method on `init` + `wp` + `template_redirect` makes the output
	 * stable without duplicating the appended HTML.
	 */
	public static function ensure_frontend_hooks() {
		if ( is_admin() ) {
			return;
		}

		// Placeholder: ensures themes that skip empty content still run the_content().
		if ( ! has_action( 'the_post', [ __CLASS__, 'ensure_content_placeholder' ] ) ) {
			add_action( 'the_post', [ __CLASS__, 'ensure_content_placeholder' ], 5, 1 );
		}

		// Auto-append meanings + cards.
		if ( false === has_filter( 'the_content', [ __CLASS__, 'append_frontend_output' ] ) ) {
			add_filter( 'the_content', [ __CLASS__, 'append_frontend_output' ], 999999 );
		}

		// Frontend styles.
		if ( ! has_action( 'wp_enqueue_scripts', [ __CLASS__, 'frontend_assets' ] ) ) {
			add_action( 'wp_enqueue_scripts', [ __CLASS__, 'frontend_assets' ] );
		}
	}

    /* =========================
     * DB / Tables
     * ========================= */
    protected static function table_meanings() {
        global $wpdb;
        return $wpdb->prefix . 'jelyk_meanings';
    }

    protected static function table_cards() {
        global $wpdb;
        return $wpdb->prefix . 'jelyk_cards';
    }

    protected static function table_translations() {
        global $wpdb;
        return $wpdb->prefix . 'jelyk_card_translations';
    }

        protected static function table_meaning_translations() {
                global $wpdb;
                return $wpdb->prefix . 'jelyk_meaning_translations';
        }

    public static function on_activate() {
        self::create_tables();
                update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
                update_option( 'jelyk_word_plugin_db_version', self::DB_VERSION );
    }

    public static function maybe_upgrade() {
                $stored_versions = [
                        (int) get_option( self::DB_VERSION_OPTION, 0 ),
                        (int) get_option( 'jelyk_word_plugin_db_version', 0 ),
                ];
                $v = max( $stored_versions );

                if ( $v < self::DB_VERSION ) {
                        self::create_tables();
                        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
                        update_option( 'jelyk_word_plugin_db_version', self::DB_VERSION );
                }
    }

    protected static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $t_meanings = self::table_meanings();
        $t_cards    = self::table_cards();
        $t_tr       = self::table_translations();
                $t_mtr      = self::table_meaning_translations();

        $sql_meanings = "CREATE TABLE {$t_meanings} (
            meaning_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            meaning_order INT(11) NOT NULL DEFAULT 0,
            gloss_de TEXT NOT NULL,
            usage_note_de TEXT NULL,
            synonyms TEXT NULL,
            antonyms TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (meaning_id),
            KEY post_id (post_id),
            KEY meaning_order (meaning_order)
        ) {$charset_collate};";

        $sql_cards = "CREATE TABLE {$t_cards} (
            card_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            meaning_id BIGINT(20) UNSIGNED NOT NULL,
            card_order INT(11) NOT NULL DEFAULT 0,
            image_id BIGINT(20) UNSIGNED NULL,
            sentence_de TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (card_id),
            KEY meaning_id (meaning_id),
            KEY card_order (card_order)
        ) {$charset_collate};";

        $sql_tr = "CREATE TABLE {$t_tr} (
            translation_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            card_id BIGINT(20) UNSIGNED NOT NULL,
            lang VARCHAR(10) NOT NULL,
            text TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            source VARCHAR(20) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (translation_id),
            UNIQUE KEY card_lang (card_id, lang),
            KEY lang (lang),
            KEY status (status)
        ) {$charset_collate};";

                $sql_mtr = "CREATE TABLE {$t_mtr} (
                        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                        meaning_id BIGINT(20) UNSIGNED NOT NULL,
                        field VARCHAR(50) NOT NULL,
                        lang VARCHAR(10) NOT NULL,
                        text TEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'pending',
                        source VARCHAR(20) NULL,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY meaning_field_lang (meaning_id, field, lang),
                        KEY meaning_id (meaning_id),
                        KEY lang (lang),
                        KEY status (status)
                ) {$charset_collate};";

        dbDelta( $sql_meanings );
        dbDelta( $sql_cards );
        dbDelta( $sql_tr );
                dbDelta( $sql_mtr );
    }

    public static function get_default_langs() {
        $langs = self::DEFAULT_LANGS;
        return apply_filters( 'jelyk_default_langs', $langs );
    }

    /* =========================
     * Admin assets (JS/CSS)
     * ========================= */
    public static function enqueue_admin_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $post_type = $screen && ! empty( $screen->post_type ) ? $screen->post_type : null;
        if ( 'post' !== $post_type ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script( 'jquery' );

        $data = [
            'langs'  => self::get_default_langs(),
        ];

        $admin_js_path  = plugin_dir_path( __FILE__ ) . 'assets/jelyk-word-admin.js';
        $admin_css_path = plugin_dir_path( __FILE__ ) . 'assets/jelyk-word-admin.css';
        $admin_js_url   = plugin_dir_url( __FILE__ ) . 'assets/jelyk-word-admin.js';
        $admin_css_url  = plugin_dir_url( __FILE__ ) . 'assets/jelyk-word-admin.css';
        $admin_js_ver   = file_exists( $admin_js_path ) ? filemtime( $admin_js_path ) : self::VERSION;
        $admin_css_ver  = file_exists( $admin_css_path ) ? filemtime( $admin_css_path ) : self::VERSION;

        wp_register_script( 'jelyk-word-admin', $admin_js_url, [ 'jquery' ], $admin_js_ver, true );
        wp_enqueue_script( 'jelyk-word-admin' );
        wp_add_inline_script( 'jelyk-word-admin', 'window.JELYK_WORD=' . wp_json_encode( $data ) . ';', 'before' );

        wp_register_style( 'jelyk-word-admin-css', $admin_css_url, [], $admin_css_ver );
        wp_enqueue_style( 'jelyk-word-admin-css' );
    }

    /* =========================
     * Meta boxes
     * ========================= */
    public static function add_meta_boxes() {
        add_meta_box(
            'jelyk_word_meta',
            'Jelyk – Дані слова (Word-level)',
            [ __CLASS__, 'render_word_meta_box' ],
            'post',
            'normal',
            'high'
        );

        add_meta_box(
            'jelyk_meanings_cards',
            'Jelyk – Bedeutungen & Cards',
            [ __CLASS__, 'render_meanings_cards_box' ],
            'post',
            'normal',
            'high'
        );
    }

    /* =========================
     * Word-level fields
     * ========================= */

    public static function get_word_fields() {
        return [
            'singular' => [
                'label' => 'Singular',
                'type'  => 'text',
                'types' => [ 'substantiv' ],
                'description' => 'Форма в однині (die Abnahmemenge).',
            ],
            'plural' => [
                'label' => 'Plural',
                'type'  => 'text',
                'types' => [ 'substantiv' ],
                'description' => 'Форма в множині (die Abnahmemengen).',
            ],

            'infinitiv' => [
                'label' => 'Infinitiv',
                'type'  => 'text',
                'types' => [ 'verb' ],
                'description' => 'Напр. "laufen".',
            ],
            'praesens' => [
                'label' => 'Präsens (er ...)',
                'type'  => 'text',
                'types' => [ 'verb' ],
                'description' => 'Напр. "er läuft".',
            ],
            'praeteritum' => [
                'label' => 'Präteritum (er ...)',
                'type'  => 'text',
                'types' => [ 'verb' ],
                'description' => 'Напр. "er lief".',
            ],
            'perfekt' => [
                'label' => 'Perfekt',
                'type'  => 'text',
                'types' => [ 'verb' ],
                'description' => 'Напр. "er ist gelaufen".',
            ],

            'positiv' => [
                'label' => 'Positiv',
                'type'  => 'text',
                'types' => [ 'adjektiv' ],
                'description' => 'Напр. "schnell".',
            ],
            'komparativ' => [
                'label' => 'Komparativ',
                'type'  => 'text',
                'types' => [ 'adjektiv' ],
                'description' => 'Напр. "schneller".',
            ],
            'superlativ' => [
                'label' => 'Superlativ',
                'type'  => 'text',
                'types' => [ 'adjektiv' ],
                'description' => 'Напр. "am schnellsten".',
            ],
        ];
    }

    protected static function get_word_types_for_post( $post_id ) {
        $types = [ 'all' ];
        $terms = get_the_terms( $post_id, 'category' );
        if ( ! is_array( $terms ) ) {
            return $types;
        }

        $map = [
            'substantiv' => 'substantiv',
            'adjektiv'   => 'adjektiv',
            'verben'     => 'verb',
            'verb'       => 'verb',
        ];

        foreach ( $terms as $term ) {
            $slug = strtolower( $term->slug );
            if ( isset( $map[ $slug ] ) ) {
                $types[] = $map[ $slug ];
            }
        }

        return array_unique( $types );
    }

    protected static function field_applies_to_post( $field, $post_types ) {
        if ( empty( $field['types'] ) ) return true;
        if ( in_array( 'all', $field['types'], true ) ) return true;

        foreach ( $post_types as $t ) {
            if ( in_array( $t, $field['types'], true ) ) return true;
        }
        return false;
    }

    public static function render_word_meta_box( $post ) {
        wp_nonce_field( 'jelyk_word_save_meta', 'jelyk_word_meta_nonce' );

        $fields     = self::get_word_fields();
        $post_types = self::get_word_types_for_post( $post->ID );
        $title      = get_the_title( $post );

        echo '<table class="form-table jelyk-word-meta">';
        foreach ( $fields as $key => $field ) {
            if ( ! self::field_applies_to_post( $field, $post_types ) ) continue;

            $meta_key = self::META_PREFIX . $key;
            $value    = get_post_meta( $post->ID, $meta_key, true );

            $label = isset( $field['label'] ) ? $field['label'] : $key;
            if ( strpos( $label, '%s' ) !== false ) {
                $label = sprintf( $label, $title );
            }

            echo '<tr>';
            echo '<th scope="row"><label for="' . esc_attr( $meta_key ) . '">' . esc_html( $label ) . '</label></th>';
            echo '<td>';

            if ( $field['type'] === 'textarea' ) {
                echo '<textarea style="width:100%;min-height:90px;" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '">'
                     . esc_textarea( $value ) . '</textarea>';
            } else {
                echo '<input type="text" class="regular-text" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $value ) . '" />';
            }

            if ( ! empty( $field['description'] ) ) {
                echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
            }

            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    protected static function save_word_meta( $post_id ) {
        if ( ! isset( $_POST['jelyk_word_meta_nonce'] ) ||
             ! wp_verify_nonce( $_POST['jelyk_word_meta_nonce'], 'jelyk_word_save_meta' ) ) {
            return;
        }

        $fields = self::get_word_fields();
        foreach ( $fields as $key => $field ) {
            $meta_key = self::META_PREFIX . $key;

            if ( ! isset( $_POST[ $meta_key ] ) ) {
                delete_post_meta( $post_id, $meta_key );
                continue;
            }

            $raw = wp_unslash( $_POST[ $meta_key ] );
            $value = ( $field['type'] === 'textarea' )
                ? sanitize_textarea_field( $raw )
                : sanitize_text_field( $raw );

            update_post_meta( $post_id, $meta_key, $value );
        }
    }

    /* =========================
     * Bedeutungen + Cards
     * ========================= */

    protected static function fetch_meanings_for_post( $post_id ) {
        global $wpdb;
        $t = self::table_meanings();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t} WHERE post_id=%d ORDER BY meaning_order ASC, meaning_id ASC",
                $post_id
            ),
            ARRAY_A
        );
    }

    protected static function fetch_cards_for_meaning( $meaning_id ) {
        global $wpdb;
        $t = self::table_cards();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t} WHERE meaning_id=%d ORDER BY card_order ASC, card_id ASC",
                $meaning_id
            ),
            ARRAY_A
        );
    }

    protected static function fetch_translations_for_card( $card_id ) {
        global $wpdb;
        $t = self::table_translations();
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT lang, text, status FROM {$t} WHERE card_id=%d", $card_id ),
            ARRAY_A
        );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ $r['lang'] ] = [
                'text'   => (string) $r['text'],
                'status' => (string) $r['status'],
            ];
        }
        return $out;
    }

        protected static function fetch_meaning_translations( $meaning_id, $field = 'gloss' ) {
                global $wpdb;
                $t = self::table_meaning_translations();
                $rows = $wpdb->get_results(
                        $wpdb->prepare( "SELECT lang, text, status FROM {$t} WHERE meaning_id=%d AND field=%s", $meaning_id, $field ),
                        ARRAY_A
                );

                $out = [];
                foreach ( (array) $rows as $r ) {
                        $lang = isset( $r['lang'] ) ? self::norm_lang( $r['lang'] ) : '';
                        if ( $lang === '' ) {
                                continue;
                        }
                        $out[ $lang ] = [
                                'text'   => (string) ( $r['text'] ?? '' ),
                                'status' => (string) ( $r['status'] ?? '' ),
                        ];
                }

                return $out;
        }

    public static function render_meanings_cards_box( $post ) {
        wp_nonce_field( 'jelyk_meanings_save', 'jelyk_meanings_nonce' );

        $meanings = self::fetch_meanings_for_post( $post->ID );
        $langs    = self::get_default_langs();

        echo '<p class="description">Структура: <strong>Post → Bedeutungen (1..N) → Cards (0..N)</strong>. Переклади карток зберігаємо по мовах (default + on-demand).</p>';

        echo '<div class="jelyk-meanings-wrap">';

        if ( empty( $meanings ) ) {
            echo '<p><em>Поки немає Bedeutungen. Натисни “Add Bedeutung”.</em></p>';
        }

        foreach ( $meanings as $m ) {
            $meaning_id        = (int) $m['meaning_id'];
            $meaning_key       = 'm' . $meaning_id;
                        $gloss_translations = self::fetch_meaning_translations( $meaning_id );
            $cards             = self::fetch_cards_for_meaning( $meaning_id );
            echo self::render_meaning_block( $meaning_key, $m, $cards, $langs, $gloss_translations );
        }

        echo '</div>';

        echo '<p><a href="#" class="button button-primary jelyk-add-meaning">+ Add Bedeutung</a></p>';

        echo '<script type="text/html" id="jelyk-meaning-template">';
        echo self::render_meaning_block( '__MEANING_KEY__', [
            'meaning_id'     => 0,
            'meaning_order'  => 0,
            'gloss_de'       => '',
            'usage_note_de'  => '',
            'synonyms'       => '',
            'antonyms'       => '',
        ], [], $langs, [], true );
        echo '</script>';

        echo '<script type="text/html" id="jelyk-card-template">';
        echo self::render_card_block( '__MEANING_KEY__', '__CARD_KEY__', [
            'card_id'     => 0,
            'card_order'  => 0,
            'image_id'    => '',
            'sentence_de' => '',
        ], [], $langs, true );
        echo '</script>';
    }

    protected static function render_meaning_block( $meaning_key, $meaning_row, $cards, $langs, $meaning_translations = [], $is_template = false ) {
        $meaning_id    = isset( $meaning_row['meaning_id'] ) ? (int) $meaning_row['meaning_id'] : 0;
        $order         = isset( $meaning_row['meaning_order'] ) ? (int) $meaning_row['meaning_order'] : 0;
        $gloss_de      = isset( $meaning_row['gloss_de'] ) ? (string) $meaning_row['gloss_de'] : '';
        $usage_note_de = isset( $meaning_row['usage_note_de'] ) ? (string) $meaning_row['usage_note_de'] : '';
        $synonyms      = isset( $meaning_row['synonyms'] ) ? (string) $meaning_row['synonyms'] : '';
        $antonyms      = isset( $meaning_row['antonyms'] ) ? (string) $meaning_row['antonyms'] : '';
                $meaning_tr    = is_array( $meaning_translations ) ? $meaning_translations : [];

        $html  = '<div class="jelyk-meaning" data-meaning-key="' . esc_attr( $meaning_key ) . '">';
        $html .= '<div class="jelyk-meaning-head">';
        $html .= '<div class="jelyk-meaning-title">Bedeutung</div>';
        $html .= '<div class="jelyk-actions"><a href="#" class="button-link-delete jelyk-remove-meaning">Remove</a></div>';
        $html .= '</div>';

        $html .= '<input type="hidden" name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][meaning_id]" value="' . esc_attr( $meaning_id ) . '" />';

        $html .= '<div class="jelyk-row">';
        $html .= '<div class="jelyk-col">';
        $html .= '<label>Order</label>';
        $html .= '<input type="text" name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][meaning_order]" value="' . esc_attr( $order ) . '" />';
        $html .= '<p class="description">0,1,2… (для сортування значень)</p>';
        $html .= '</div>';

        $html .= '<div class="jelyk-col">';
        $html .= '<label>Kurz-Definition / Gloss (DE)</label>';
        $html .= '<input type="text" name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][gloss_de]" value="' . esc_attr( $gloss_de ) . '" />';
        $html .= '<p class="description">Коротко німецькою (те, що в тебе “Etwas …”).</p>';
        $html .= '</div>';
        $html .= '</div>';

                $translation_langs = [];
                foreach ( (array) $langs as $code ) {
                        $norm = self::norm_lang( $code );
                        if ( $norm === '' || $norm === 'de' ) {
                                continue;
                        }
                        if ( in_array( $norm, $translation_langs, true ) ) {
                                continue;
                        }
                        $translation_langs[] = $norm;
                }

                if ( $translation_langs ) {
                        $html .= '<details class="jelyk-meaning-translations">';
                        $html .= '<summary>Gloss translations</summary>';
                        $html .= '<div class="jelyk-admin-translation-box">';
                        $html .= '<div class="jelyk-row">';
                        foreach ( $translation_langs as $gl_lang ) {
                                $norm_lang = self::norm_lang( $gl_lang );
                                $existing  = $meaning_tr[ $norm_lang ]['text'] ?? '';
                                $label     = sprintf( 'Gloss (%s)', strtoupper( $norm_lang ) );
                                $html     .= '<div class="jelyk-col">';
                                $html     .= '<label>' . esc_html( $label ) . '</label>';
                                $html     .= '<textarea name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][gloss_tr][' . esc_attr( $norm_lang ) . ']" rows="2">' . esc_textarea( $existing ) . '</textarea>';
                                $html     .= '</div>';
                        }
                        $html .= '</div>';
                        $html .= '</div>';
                        $html .= '</details>';
                }

        $html .= '<div class="jelyk-row">';
        $html .= '<div class="jelyk-col">';
        $html .= '<label>Synonyme (для цього значення)</label>';
        $html .= '<textarea name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][synonyms]" rows="3">' . esc_textarea( $synonyms ) . '</textarea>';
        $html .= '<p class="description">Через кому або кожен з нового рядка.</p>';
        $html .= '</div>';

        $html .= '<div class="jelyk-col">';
        $html .= '<label>Antonyme (опційно)</label>';
        $html .= '<textarea name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][antonyms]" rows="3">' . esc_textarea( $antonyms ) . '</textarea>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="jelyk-row">';
        $html .= '<div class="jelyk-col">';
        $html .= '<label>Mini-Note / типове вживання (DE, опційно)</label>';
        $html .= '<textarea name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][usage_note_de]" rows="2">' . esc_textarea( $usage_note_de ) . '</textarea>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<hr style="margin:14px 0;" />';
        $html .= '<div class="jelyk-card-head"><strong>Cards (image + DE sentence)</strong><a href="#" class="button jelyk-add-card">+ Add Card</a></div>';
        $html .= '<div class="jelyk-cards-wrap">';

        if ( ! empty( $cards ) ) {
            foreach ( $cards as $c ) {
                $card_id = (int) $c['card_id'];
                $card_key = 'c' . $card_id;
                $translations = self::fetch_translations_for_card( $card_id );
                $html .= self::render_card_block( $meaning_key, $card_key, $c, $translations, $langs, false );
            }
        }

        $html .= '</div></div>';
        return $html;
    }

    protected static function render_card_block( $meaning_key, $card_key, $card_row, $translations, $langs, $is_template = false ) {
        $card_id     = isset( $card_row['card_id'] ) ? (int) $card_row['card_id'] : 0;
        $order       = isset( $card_row['card_order'] ) ? (int) $card_row['card_order'] : 0;
        $image_id    = isset( $card_row['image_id'] ) ? $card_row['image_id'] : '';
        $sentence_de = isset( $card_row['sentence_de'] ) ? (string) $card_row['sentence_de'] : '';

        $thumb = '';
        if ( $image_id ) {
            $src = wp_get_attachment_image_src( (int) $image_id, 'thumbnail' );
            if ( is_array( $src ) && ! empty( $src[0] ) ) {
                $thumb = '<img src="' . esc_url( $src[0] ) . '" style="max-width:140px;height:auto;" />';
            }
        }

        $html  = '<div class="jelyk-card">';
        $html .= '<div class="jelyk-card-head"><strong>Card</strong><div class="jelyk-actions"><a href="#" class="jelyk-toggle-translations">Translations</a> &nbsp;|&nbsp; <a href="#" class="button-link-delete jelyk-remove-card">Remove</a></div></div>';

        $html .= '<input type="hidden" name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][cards][' . esc_attr( $card_key ) . '][card_id]" value="' . esc_attr( $card_id ) . '" />';

        $html .= '<div class="jelyk-row">';
        $html .= '<div class="jelyk-col" style="max-width:220px;flex:1 1 220px;">';
        $html .= '<label>Image</label>';
        $html .= '<div class="jelyk-image-preview">' . $thumb . '</div>';
        $html .= '<input type="hidden" class="jelyk-image-id" name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][cards][' . esc_attr( $card_key ) . '][image_id]" value="' . esc_attr( $image_id ) . '" />';
        $html .= '<p style="margin-top:8px;"><a href="#" class="button jelyk-pick-image">Pick</a> <a href="#" class="button jelyk-clear-image">Clear</a></p>';
        $html .= '</div>';

        $html .= '<div class="jelyk-col">';
        $html .= '<label>Order</label>';
        $html .= '<input type="text" name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][cards][' . esc_attr( $card_key ) . '][card_order]" value="' . esc_attr( $order ) . '" />';
        $html .= '<label style="margin-top:10px;">DE sentence</label>';
        $html .= '<textarea name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][cards][' . esc_attr( $card_key ) . '][sentence_de]" rows="2">' . esc_textarea( $sentence_de ) . '</textarea>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="jelyk-translations"><p class="description">Порожні значення залишаються як <code>pending</code> (для AI/cron).</p>';

        foreach ( $langs as $lang ) {
            $t = isset( $translations[ $lang ] ) ? $translations[ $lang ] : [ 'text' => '', 'status' => 'pending' ];
            $val = isset( $t['text'] ) ? (string) $t['text'] : '';
            $status = isset( $t['status'] ) ? (string) $t['status'] : 'pending';

            $html .= '<div class="jelyk-trans-row">';
            $html .= '<code>' . esc_html( strtoupper( $lang ) ) . '</code>';
            $html .= '<input type="text" style="flex:1 1 auto;" name="jelyk_meanings[' . esc_attr( $meaning_key ) . '][cards][' . esc_attr( $card_key ) . '][translations][' . esc_attr( $lang ) . ']" value="' . esc_attr( $val ) . '" />';
            $html .= '<span style="opacity:.7;">' . esc_html( $status ) . '</span>';
            $html .= '</div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    protected static function sanitize_list_text( $raw ) {
        $raw = (string) $raw;
        $raw = str_replace( [ "\r\n", "\r" ], "\n", $raw );
        return trim( $raw );
    }

    protected static function upsert_translation( $card_id, $lang, $text, $status, $source = null ) {
        global $wpdb;
        $t = self::table_translations();

        $lang = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $lang ) );
        if ( $lang === '' ) return;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$t} (card_id, lang, text, status, source)
                 VALUES (%d, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE text=VALUES(text), status=VALUES(status), source=VALUES(source), updated_at=CURRENT_TIMESTAMP",
                $card_id, $lang, $text, $status, $source
            )
        );
    }

    protected static function delete_translations_for_card( $card_id ) {
        global $wpdb;
        $t = self::table_translations();
        $wpdb->delete( $t, [ 'card_id' => (int) $card_id ], [ '%d' ] );
    }

        protected static function upsert_meaning_translation( $meaning_id, $field, $lang, $text, $status = 'manual', $source = 'manual' ) {
                global $wpdb;
                $t = self::table_meaning_translations();

                $lang  = self::norm_lang( $lang );
                $field = trim( (string) $field );

                if ( $meaning_id <= 0 || $lang === '' || $field === '' ) {
                        return;
                }

                $wpdb->replace(
                        $t,
                        [
                                'meaning_id' => $meaning_id,
                                'field'      => $field,
                                'lang'       => $lang,
                                'text'       => $text,
                                'status'     => $status,
                                'source'     => $source,
                        ],
                        [ '%d', '%s', '%s', '%s', '%s', '%s' ]
                );
        }

        protected static function delete_meaning_translation( $meaning_id, $field, $lang ) {
                global $wpdb;
                $t = self::table_meaning_translations();
                $wpdb->delete(
                        $t,
                        [
                                'meaning_id' => (int) $meaning_id,
                                'field'      => (string) $field,
                                'lang'       => (string) self::norm_lang( $lang ),
                        ],
                        [ '%d', '%s', '%s' ]
                );
        }

        protected static function delete_meaning_translations_for_meaning( $meaning_id ) {
                global $wpdb;
                $t = self::table_meaning_translations();
                $wpdb->delete( $t, [ 'meaning_id' => (int) $meaning_id ], [ '%d' ] );
        }

    protected static function delete_meaning_cascade( $meaning_id ) {
        global $wpdb;
        $tM = self::table_meanings();
        $tC = self::table_cards();

        $card_ids = $wpdb->get_col(
            $wpdb->prepare( "SELECT card_id FROM {$tC} WHERE meaning_id=%d", $meaning_id )
        );
        foreach ( (array) $card_ids as $cid ) {
            self::delete_translations_for_card( (int) $cid );
        }
        $wpdb->delete( $tC, [ 'meaning_id' => (int) $meaning_id ], [ '%d' ] );
                self::delete_meaning_translations_for_meaning( (int) $meaning_id );
        $wpdb->delete( $tM, [ 'meaning_id' => (int) $meaning_id ], [ '%d' ] );
    }

    protected static function delete_all_meanings_for_post( $post_id ) {
        global $wpdb;
        $tM = self::table_meanings();
        $meaning_ids = $wpdb->get_col(
            $wpdb->prepare( "SELECT meaning_id FROM {$tM} WHERE post_id=%d", $post_id )
        );
        foreach ( (array) $meaning_ids as $mid ) {
            self::delete_meaning_cascade( (int) $mid );
        }
    }

    protected static function save_meanings_cards( $post_id ) {
        if ( ! isset( $_POST['jelyk_meanings_nonce'] ) ||
             ! wp_verify_nonce( $_POST['jelyk_meanings_nonce'], 'jelyk_meanings_save' ) ) {
            return;
        }

        if ( ! isset( $_POST['jelyk_meanings'] ) || ! is_array( $_POST['jelyk_meanings'] ) ) {
            self::delete_all_meanings_for_post( $post_id );
            return;
        }

        global $wpdb;
        $tM = self::table_meanings();
        $tC = self::table_cards();

        $submitted = wp_unslash( $_POST['jelyk_meanings'] );
        $default_langs = self::get_default_langs();

        $existing_meanings = $wpdb->get_col(
            $wpdb->prepare( "SELECT meaning_id FROM {$tM} WHERE post_id=%d", $post_id )
        );
        $existing_meanings = array_map( 'intval', (array) $existing_meanings );

        $kept_meanings = [];

        foreach ( $submitted as $meaning_key => $m ) {
            if ( ! is_array( $m ) ) continue;

            $meaning_id    = isset( $m['meaning_id'] ) ? (int) $m['meaning_id'] : 0;
            $meaning_order = isset( $m['meaning_order'] ) ? (int) $m['meaning_order'] : 0;

            $gloss_de      = isset( $m['gloss_de'] ) ? sanitize_text_field( $m['gloss_de'] ) : '';
            $usage_note_de = isset( $m['usage_note_de'] ) ? sanitize_textarea_field( $m['usage_note_de'] ) : '';
            $synonyms      = isset( $m['synonyms'] ) ? self::sanitize_list_text( sanitize_textarea_field( $m['synonyms'] ) ) : '';
            $antonyms      = isset( $m['antonyms'] ) ? self::sanitize_list_text( sanitize_textarea_field( $m['antonyms'] ) ) : '';

            if ( $gloss_de === '' ) {
                continue; // не зберігаємо порожнє Bedeutung
            }

            if ( $meaning_id > 0 && in_array( $meaning_id, $existing_meanings, true ) ) {
                $wpdb->update(
                    $tM,
                    [
                        'meaning_order' => $meaning_order,
                        'gloss_de' => $gloss_de,
                        'usage_note_de' => $usage_note_de,
                        'synonyms' => $synonyms,
                        'antonyms' => $antonyms,
                    ],
                    [ 'meaning_id' => $meaning_id ],
                    [ '%d', '%s', '%s', '%s', '%s' ],
                    [ '%d' ]
                );
            } else {
                $wpdb->insert(
                    $tM,
                    [
                        'post_id' => $post_id,
                        'meaning_order' => $meaning_order,
                        'gloss_de' => $gloss_de,
                        'usage_note_de' => $usage_note_de,
                        'synonyms' => $synonyms,
                        'antonyms' => $antonyms,
                    ],
                    [ '%d', '%d', '%s', '%s', '%s', '%s' ]
                );
                $meaning_id = (int) $wpdb->insert_id;
            }

            $kept_meanings[] = $meaning_id;

            $gloss_input = [];
            if ( isset( $m['gloss_tr'] ) && is_array( $m['gloss_tr'] ) ) {
                $gloss_input = $m['gloss_tr'];
            }

            $translation_langs = [];
            foreach ( (array) $default_langs as $code ) {
                $norm = self::norm_lang( $code );
                if ( $norm === '' || $norm === 'de' ) {
                    continue;
                }
                if ( in_array( $norm, $translation_langs, true ) ) {
                    continue;
                }
                $translation_langs[] = $norm;
            }

            foreach ( $translation_langs as $g_lang ) {
                $norm_lang = self::norm_lang( $g_lang );
                $val       = '';
                if ( isset( $gloss_input[ $norm_lang ] ) ) {
                    $val = sanitize_textarea_field( $gloss_input[ $norm_lang ] );
                }
                $val = trim( $val );

                if ( $val !== '' ) {
                    self::upsert_meaning_translation( $meaning_id, 'gloss', $norm_lang, $val, 'manual', 'manual' );
                } else {
                    self::delete_meaning_translation( $meaning_id, 'gloss', $norm_lang );
                }
            }

            $existing_cards = $wpdb->get_col(
                $wpdb->prepare( "SELECT card_id FROM {$tC} WHERE meaning_id=%d", $meaning_id )
            );
            $existing_cards = array_map( 'intval', (array) $existing_cards );
            $kept_cards = [];

            if ( isset( $m['cards'] ) && is_array( $m['cards'] ) ) {
                foreach ( $m['cards'] as $card_key => $c ) {
                    if ( ! is_array( $c ) ) continue;

                    $card_id    = isset( $c['card_id'] ) ? (int) $c['card_id'] : 0;
                    $card_order = isset( $c['card_order'] ) ? (int) $c['card_order'] : 0;
                    $image_id   = isset( $c['image_id'] ) ? (int) $c['image_id'] : 0;
                    $sentence_de= isset( $c['sentence_de'] ) ? sanitize_textarea_field( $c['sentence_de'] ) : '';

                    if ( $sentence_de === '' && $image_id === 0 ) continue;

                    if ( $card_id > 0 && in_array( $card_id, $existing_cards, true ) ) {
                        $wpdb->update(
                            $tC,
                            [
                                'card_order' => $card_order,
                                'image_id' => $image_id ? $image_id : null,
                                'sentence_de' => $sentence_de,
                            ],
                            [ 'card_id' => $card_id ],
                            [ '%d', '%d', '%s' ],
                            [ '%d' ]
                        );
                    } else {
                        $wpdb->insert(
                            $tC,
                            [
                                'meaning_id' => $meaning_id,
                                'card_order' => $card_order,
                                'image_id' => $image_id ? $image_id : null,
                                'sentence_de' => $sentence_de,
                            ],
                            [ '%d', '%d', '%d', '%s' ]
                        );
                        $card_id = (int) $wpdb->insert_id;
                    }

                    $kept_cards[] = $card_id;

                    $submitted_tr = [];
                    if ( isset( $c['translations'] ) && is_array( $c['translations'] ) ) {
                        $submitted_tr = $c['translations'];
                    }

                    foreach ( $default_langs as $lang ) {
                        $val = '';
                        if ( isset( $submitted_tr[ $lang ] ) ) {
                            $val = sanitize_text_field( $submitted_tr[ $lang ] );
                        }

                        if ( $val !== '' ) {
                            self::upsert_translation( $card_id, $lang, $val, 'manual', 'manual' );
                        } else {
                            self::upsert_translation( $card_id, $lang, '', 'pending', null );
                        }
                    }
                }
            }

            $to_delete_cards = array_diff( $existing_cards, $kept_cards );
            foreach ( $to_delete_cards as $cid ) {
                self::delete_translations_for_card( (int) $cid );
                $wpdb->delete( $tC, [ 'card_id' => (int) $cid ], [ '%d' ] );
            }
        }

        $to_delete_meanings = array_diff( $existing_meanings, $kept_meanings );
        foreach ( $to_delete_meanings as $mid ) {
            self::delete_meaning_cascade( (int) $mid );
        }
    }

    /* =========================
     * Frontend: auto append Bedeutungen & Cards
     * ========================= */
	/**
	 * Some themes don't call the_content() when the editor content is empty.
	 * In that case our auto-appended block would never render.
	 *
	 * We add a tiny placeholder comment to empty content, but only if this post
	 * actually has Bedeutungen/Cards in our tables.
	 */
	/**
	 * Some themes print the raw post_content (or skip the_content() when it's empty).
	 *
	 * To make the frontend block reliable, we inject a placeholder for empty content
	 * and append our Bedeutungen/Cards HTML directly into $post->post_content at runtime.
	 *
	 * This only affects the currently viewed single post (queried object) and does
	 * NOT write anything to the database.
	 */
	public static function ensure_content_placeholder( $post ) {
		if ( is_admin() || is_feed() || is_preview() ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		if ( ! is_singular( 'post' ) || $post->post_type !== 'post' ) {
			return;
		}
		$queried_id = (int) get_queried_object_id();
		if ( $queried_id <= 0 || (int) $post->ID !== $queried_id ) {
			return;
		}

		// If there is nothing to show, don't touch the content.
		if ( ! self::post_has_meanings( (int) $post->ID ) ) {
			return;
		}

			// If editor content is empty, some themes skip the_content().
			// We add a tiny placeholder comment (no HTML output) so the theme sees "non-empty" content
			// and calls the_content(), where we can append our block via the_content filter.
			if ( trim( (string) $post->post_content ) === '' && strpos( (string) $post->post_content, '<!-- jelyk-auto-content -->' ) === false ) {
				$post->post_content = "<!-- jelyk-auto-content -->";
			}
	}

	protected static function post_has_meanings( int $post_id ): bool {
		if ( isset( self::$has_meanings_cache[ $post_id ] ) ) {
			return (bool) self::$has_meanings_cache[ $post_id ];
		}
		global $wpdb;
		$tM = self::table_meanings();
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$tM} WHERE post_id = %d LIMIT 1", $post_id ) );
		self::$has_meanings_cache[ $post_id ] = $exists;
		return $exists;
	}

    public static function frontend_assets() {
        // Load only on single posts.
        if ( is_admin() || ! is_singular( 'post' ) ) {
            return;
        }

        // Minimal CSS that follows the current theme typography.
        wp_register_style( 'jelyk-word-frontend', false, [], self::VERSION );
        wp_enqueue_style( 'jelyk-word-frontend' );

        $css = <<<'CSS'
.jelyk-word-block{margin-top:2.25rem;padding-top:1.25rem;border-top:1px solid rgba(0,0,0,.1)}
.jelyk-langbar{display:flex;gap:.75rem;align-items:center;margin:0 0 1rem 0}
.jelyk-langbar label{font-weight:600}
.jelyk-langbar select{max-width:260px}
.jelyk-word-meta{margin:0 0 1rem 0}
.jelyk-kv-label{opacity:.75}
.jelyk-meaning{margin:1.75rem 0 0 0;padding:0}
.jelyk-meaning-title{margin:0 0 .35rem 0;font-weight:700}
.jelyk-meaning-gloss{margin:0 0 .75rem 0;font-size:1.05em}
.jelyk-meaning-tr{display:none;margin:.1rem 0 .6rem 0;font-size:1.02em;opacity:.92}
.jelyk-hr{border:0;border-top:1px solid rgba(0,0,0,.12);margin:.75rem 0 1rem 0}
.jelyk-row{margin:0 0 1rem 0}
.jelyk-row-title{font-weight:700;margin:0 0 .35rem 0}
.jelyk-tokens{display:flex;flex-wrap:wrap;gap:.35rem .45rem}
.jelyk-token{display:inline-flex;align-items:center;padding:.18rem .5rem;border:1px solid rgba(0,0,0,.15);border-radius:.4rem;text-decoration:none;line-height:1.2}
.jelyk-token:hover{text-decoration:none;border-color:rgba(0,0,0,.3)}
.jelyk-token--plain{cursor:default}
.jelyk-cards-title{font-weight:700;margin:0 0 .65rem 0}
.jelyk-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}
.jelyk-card{background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:.6rem;overflow:hidden}
.jelyk-card figure{margin:0}
.jelyk-card img{width:100%;height:auto;display:block}
.jelyk-card-body{padding:.75rem .85rem}
.jelyk-card-de{margin:0;font-weight:600}
.jelyk-card-tr-wrap{margin:.45rem 0 0 0;font-size:.95em;opacity:.9}
.jelyk-card-tr{display:none;margin:0}
.jelyk-note{margin:.25rem 0 0 0;opacity:.85}
CSS;
        wp_add_inline_style( 'jelyk-word-frontend', $css );

        // Tiny JS: language switcher for card translations (no reload).
        wp_register_script( 'jelyk-word-frontend', false, [], self::VERSION, true );
        wp_enqueue_script( 'jelyk-word-frontend' );

        $js = <<<'JS'
(function(){
  function normLang(code){
    var c = (code || '').toString().trim().toLowerCase();
    if(c === 'ua'){ c = 'uk'; }
    return c;
  }

  function updateTranslations(lang){
    lang = normLang(lang);
    var wraps = document.querySelectorAll('.jelyk-card-tr-wrap');
    wraps.forEach(function(w){
      var items = w.querySelectorAll('.jelyk-card-tr');
      items.forEach(function(i){ i.style.display = 'none'; });
      if(!lang || lang==='de'){ w.style.display = 'none'; return; }
      var active = w.querySelector('.jelyk-card-tr[data-lang="'+lang+'"]');
      if(active && active.textContent.trim().length){
        w.style.display = 'block';
        active.style.display = 'block';
      } else {
        w.style.display = 'none';
      }
    });

    var meaningEls = document.querySelectorAll('.jelyk-meaning-tr');
    meaningEls.forEach(function(el){
      var elLang = normLang(el.getAttribute('data-lang'));
      if(!lang || lang === 'de' || elLang !== lang || !el.textContent.trim().length){
        el.style.display = 'none';
      } else {
        el.style.display = 'block';
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    var sel = document.getElementById('jelyk-lang-select');
    if(!sel) return;
    var saved = null;
    try { saved = normLang(localStorage.getItem('jelykLang') || ''); } catch(e) { saved = null; }
    if(saved){
      var opt = sel.querySelector('option[value="'+saved+'"]');
      if(opt) sel.value = saved;
    }
    updateTranslations(sel.value);
    sel.addEventListener('change', function(){
      var val = normLang(sel.value);
      sel.value = val || 'de';
      try { localStorage.setItem('jelykLang', val); } catch(e) {}
      updateTranslations(val);
    });
  });
})();
JS;
        wp_add_inline_script( 'jelyk-word-frontend', $js );
    }

    public static function append_frontend_output( $content ) {
	        if ( is_admin() || is_feed() || is_preview() || ! is_singular( 'post' ) ) {
            return $content;
        }

		// Some themes output the post body without being in the main loop / query.
		// To avoid missing output (and to avoid injecting into related posts loops),
		// we only append for the *queried* singular post.
		$queried_id = (int) get_queried_object_id();
		$current_id = (int) get_the_ID();
		if ( $queried_id && $current_id && $queried_id !== $current_id ) {
			return $content;
		}

	        // Prevent duplicates.
	        if ( is_string( $content ) && strpos( $content, 'jelyk-meanings-cards' ) !== false ) {
	            return $content;
	        }

	        // Remove placeholder (if we injected one to force the_content() to run).
	        if ( is_string( $content ) ) {
	            $content = str_replace( '<!-- jelyk-auto-content -->', '', $content );
	        }

	        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return $content;
        }

        $data = self::get_frontend_meanings_cards( $post_id );
        if ( empty( $data['meanings'] ) ) {
            // No new structure yet -> don't change the_content (keeps legacy output intact).
			// Debug hint (visible in page source) for admins when WP_DEBUG is enabled.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
				return $content . "\n<!-- Jelyk Word Plugin: no Bedeutungen found for post_id={$post_id} -->\n";
			}
			return $content;
        }

	        $block = self::render_frontend_meanings_cards( $post_id, $data );
	        if ( ! is_string( $block ) || trim( $block ) === '' ) {
	            return $content;
	        }

	        return $content . $block;
    }

    protected static function get_frontend_meanings_cards( $post_id ) {
        global $wpdb;

		// Use helpers (they include $wpdb->prefix).
                $tM = self::table_meanings();
                $tC = self::table_cards();
                $tT = self::table_translations();
                $tMT = self::table_meaning_translations();

        $meanings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meaning_id AS id, meaning_order AS order_index, gloss_de, synonyms, antonyms, usage_note_de AS note_de FROM {$tM} WHERE post_id=%d ORDER BY meaning_order ASC, meaning_id ASC",
                $post_id
            ),
            ARRAY_A
        );

        if ( ! $meanings ) {
            return [ 'meanings' => [], 'cards_by_meaning' => [], 'translations' => [], 'meaning_translations' => [] ];
        }

        $meaning_ids = array_map( 'intval', array_column( $meanings, 'id' ) );
        $placeholders = implode( ',', array_fill( 0, count( $meaning_ids ), '%d' ) );

        $cards = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT card_id AS id, meaning_id, card_order AS order_index, image_id, sentence_de
                FROM {$tC} WHERE meaning_id IN ({$placeholders}) ORDER BY meaning_id ASC, card_order ASC, card_id ASC",
                $meaning_ids
            ),
            ARRAY_A
        );

        $cards_by_meaning = [];
        $card_ids = [];
        foreach ( (array) $cards as $c ) {
            $mid = (int) $c['meaning_id'];
            if ( ! isset( $cards_by_meaning[ $mid ] ) ) {
                $cards_by_meaning[ $mid ] = [];
            }
            $cards_by_meaning[ $mid ][] = $c;
            $card_ids[] = (int) $c['id'];
        }

        $translations = [];
                $meaning_translations = [];

                if ( $meaning_ids ) {
                        $mph   = implode( ',', array_fill( 0, count( $meaning_ids ), '%d' ) );
                        $query = $wpdb->prepare(
                                "SELECT meaning_id, lang, text, status FROM {$tMT} WHERE meaning_id IN ({$mph}) AND field=%s AND TRIM(text) <> ''",
                                array_merge( $meaning_ids, [ 'gloss' ] )
                        );

                        $mrows = $wpdb->get_results( $query, ARRAY_A );

                        foreach ( (array) $mrows as $mr ) {
                                $mid  = (int) ( $mr['meaning_id'] ?? 0 );
                                $lang = self::norm_lang( $mr['lang'] ?? '' );
                                $text = trim( (string) ( $mr['text'] ?? '' ) );
                                if ( $mid <= 0 || $lang === '' || $text === '' ) {
                                        continue;
                                }
                                if ( ! isset( $meaning_translations[ $mid ] ) ) {
                                        $meaning_translations[ $mid ] = [];
                                }
                                $meaning_translations[ $mid ][ $lang ] = $text;
                        }
                }

        if ( $card_ids ) {
            $cph   = implode( ',', array_fill( 0, count( $card_ids ), '%d' ) );

                        // Fetch all translations for these cards (DE handled separately via sentence_de).
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                                        "SELECT card_id, lang, text FROM {$tT} WHERE card_id IN ({$cph})",
                    $card_ids
                ),
                ARRAY_A
            );

            foreach ( (array) $rows as $r ) {
                $cid = (int) $r['card_id'];
                                $lang = self::norm_lang( $r['lang'] ?? '' );
                                $text = trim( (string) ( $r['text'] ?? '' ) );
                if ( $text === '' || $lang === '' ) {
                    continue;
                }
                if ( ! isset( $translations[ $cid ] ) ) {
                    $translations[ $cid ] = [];
                }
                $translations[ $cid ][ $lang ] = $text;
            }
        }

        return [
            'meanings'         => $meanings,
            'cards_by_meaning' => $cards_by_meaning,
            'translations'     => $translations,
                        'meaning_translations' => $meaning_translations,
        ];
    }

    protected static function render_frontend_meanings_cards( $post_id, $data ) {
        $title = get_the_title( $post_id );
                // Build language dropdown entries (DE + default languages)
                $langs = [];
                $seen = [];
                foreach ( array_merge( [ 'de' ], self::get_default_langs() ) as $code ) {
                        $norm = self::norm_lang( $code );
                        if ( $norm === '' || isset( $seen[ $norm ] ) ) {
                                continue;
                        }
                        $seen[ $norm ] = true;
                        $langs[] = [
                                'code'  => $norm,
                                'label' => self::lang_label( $norm ),
                        ];
                }
                $active_lang = (string) ( $langs[0]['code'] ?? '' );

        // Prefetch possible internal links for synonyms/antonyms.
        $all_tokens = [];
        foreach ( (array) $data['meanings'] as $m ) {
            $all_tokens = array_merge( $all_tokens, self::parse_tokens( (string) ( $m['synonyms'] ?? '' ) ) );
            $all_tokens = array_merge( $all_tokens, self::parse_tokens( (string) ( $m['antonyms'] ?? '' ) ) );
        }
        $link_index = self::prefetch_token_links( array_unique( $all_tokens ) );

        ob_start();
        ?>
        <section class="jelyk-word-block" aria-label="Bedeutungen und Beispielsätze">
            <div class="jelyk-langbar">
                <label for="jelyk-lang-select"><?php echo esc_html__( 'Übersetzung', 'jelyk' ); ?>:</label>
                <select id="jelyk-lang-select">
                    <?php foreach ( $langs as $l ) : ?>
                        <option value="<?php echo esc_attr( $l['code'] ); ?>"><?php echo esc_html( $l['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php echo self::render_frontend_word_meta( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php
            $idx = 0;
            foreach ( (array) $data['meanings'] as $m ) :
                $idx++;
                $mid   = (int) $m['id'];
                $gloss = (string) ( $m['gloss_de'] ?? '' );
                $syn   = (string) ( $m['synonyms'] ?? '' );
                $ant   = (string) ( $m['antonyms'] ?? '' );
                $note  = (string) ( $m['note_de'] ?? '' );
                                $meaning_trs = $data['meaning_translations'][ $mid ] ?? [];
                                $cards = $data['cards_by_meaning'][ $mid ] ?? [];
                ?>
                <div class="jelyk-meaning">
                    <h3 class="jelyk-meaning-title"><?php echo esc_html( self::meaning_heading_de( $idx, $m ) ); ?></h3>

                    <hr class="jelyk-hr" />

                                        <?php if ( trim( $gloss ) !== '' ) : ?>
                                                <p class="jelyk-meaning-gloss"><?php echo esc_html( $gloss ); ?></p>
                                                <?php
                                                $normalized_gloss_trs = [];
                                                foreach ( (array) $meaning_trs as $lang => $txt ) {
                                                        $norm_lang = self::norm_lang( $lang );
                                                        $clean_txt = trim( (string) $txt );
                                                        if ( $norm_lang === '' || $norm_lang === 'de' || $clean_txt === '' ) {
                                                                continue;
                                                        }
                                                        $normalized_gloss_trs[ $norm_lang ] = $clean_txt;
                                                }

                                                if ( $normalized_gloss_trs ) :
                                                        foreach ( $normalized_gloss_trs as $lang_code => $txt ) :
                                                                $display_style = ( $active_lang === $lang_code && $active_lang !== 'de' ) ? 'display:block;' : 'display:none;';
                                                                ?>
                                                                <div class="jelyk-meaning-tr" data-lang="<?php echo esc_attr( $lang_code ); ?>" style="<?php echo esc_attr( $display_style ); ?>"><?php echo esc_html( $txt ); ?></div>
                                                        <?php endforeach; ?>
                                                <?php endif; ?>
                                        <?php endif; ?>

                    <?php if ( trim( $syn ) !== '' ) : ?>
                        <div class="jelyk-row">
                            <div class="jelyk-row-title"><?php echo esc_html__( 'Synonyme', 'jelyk' ); ?></div>
                            <div class="jelyk-tokens">
                                <?php foreach ( self::parse_tokens( $syn ) as $tok ) : ?>
                                    <?php echo self::render_token( $tok, $link_index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( trim( $ant ) !== '' ) : ?>
                        <div class="jelyk-row">
                            <div class="jelyk-row-title"><?php echo esc_html__( 'Antonyme', 'jelyk' ); ?></div>
                            <div class="jelyk-tokens">
                                <?php foreach ( self::parse_tokens( $ant ) as $tok ) : ?>
                                    <?php echo self::render_token( $tok, $link_index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( trim( $note ) !== '' ) : ?>
                        <p class="jelyk-note"><?php echo esc_html( $note ); ?></p>
                    <?php endif; ?>

                    <?php if ( $cards ) : ?>
                        <div class="jelyk-row">
                            <div class="jelyk-cards-title"><?php echo esc_html__( 'Beispielsätze', 'jelyk' ); ?></div>
                            <div class="jelyk-cards">
                                <?php foreach ( $cards as $c ) :
                                    $card_id = (int) ( $c['id'] ?? $c['card_id'] ?? 0 );
                                    $img_id  = (int) $c['image_id'];
                                    $sent    = (string) $c['sentence_de'];
                                    $trs     = $data['translations'][ $card_id ] ?? [];
                                    ?>
                                    <div class="jelyk-card">
                                        <figure>
                                            <?php
                                            if ( $img_id ) {
                                                echo wp_get_attachment_image( $img_id, 'medium', false );
                                            }
                                            ?>
                                        </figure>
                                        <div class="jelyk-card-body">
                                            <p class="jelyk-card-de"><?php echo esc_html( $sent ); ?></p>

                                            <?php if ( $trs ) : ?>
                                                <?php
                                                $normalized_trs = [];
                                                foreach ( (array) $trs as $lang => $txt ) {
                                                        $norm_lang = self::norm_lang( $lang );
                                                        if ( $norm_lang === '' ) {
                                                                continue;
                                                        }
                                                        $normalized_trs[ $norm_lang ] = $txt;
                                                }

                                                $has_active_translation = false;
                                                if ( $active_lang !== '' && $active_lang !== 'de' ) {
                                                        $active_txt = $normalized_trs[ $active_lang ] ?? '';
                                                        if ( trim( (string) $active_txt ) !== '' ) {
                                                                $has_active_translation = true;
                                                        }
                                                }

                                                $wrap_style = $has_active_translation ? 'display:block;' : 'display:none;';
                                                ?>
                                                <div class="jelyk-card-tr-wrap" style="<?php echo esc_attr( $wrap_style ); ?>">
                                                    <?php foreach ( $normalized_trs as $norm_lang => $txt ) :
                                                            $is_active = $has_active_translation && $norm_lang === $active_lang;
                                                            $display_style = $is_active ? 'display:block;' : 'display:none;';
                                                        ?>
                                                        <p class="jelyk-card-tr" data-lang="<?php echo esc_attr( $norm_lang ); ?>" style="<?php echo esc_attr( $display_style ); ?>"><?php echo esc_html( $txt ); ?></p>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else : ?>
                                                <div class="jelyk-card-tr-wrap" style="display:none"></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    protected static function render_frontend_word_meta( $post_id ) {
        $fields = self::get_word_fields();
        $type_meta = (string) get_post_meta( $post_id, self::META_PREFIX . 'type', true );
        if ( $type_meta === '' ) {
            $types = self::get_word_types_for_post( $post_id );
            $type_meta = (string) ( $types[0] ?? '' );
        }
        $type_label_map = array(
            'substantiv' => 'Substantiv',
            'verb'       => 'Verb',
            'adjektiv'   => 'Adjektiv',
        );
        $tokens = array();

        if ( $type_meta !== '' ) {
            $tokens[] = array( 'Wortart', $type_label_map[ $type_meta ] ?? ucfirst( $type_meta ) );
        }

        foreach ( $fields as $key => $f ) {
            if ( $key === 'type' ) {
                continue;
            }
            $val = (string) get_post_meta( $post_id, self::META_PREFIX . $key, true );
            $val = trim( $val );
            if ( $val === '' ) {
                continue;
            }
            $tokens[] = array( (string) ( $f['label'] ?? $key ), $val );
        }

        if ( empty( $tokens ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="jelyk-word-meta" aria-label="Wort-Infos">
            <div class="jelyk-tokens">
                <?php foreach ( $tokens as $t ) : ?>
                    <span class="jelyk-token jelyk-token-plain">
                        <span class="jelyk-kv-label"><?php echo esc_html( (string) $t[0] ); ?>:</span>
                        <span class="jelyk-kv-val"><?php echo esc_html( (string) $t[1] ); ?></span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function meaning_heading_de( $index, $meaning ) {
        return sprintf( 'Bedeutung %d', (int) $index );
    }

    protected static function ordinal_de_feminine( $n ) {
        $map = [
            1 => 'Erste',
            2 => 'Zweite',
            3 => 'Dritte',
            4 => 'Vierte',
            5 => 'Fünfte',
            6 => 'Sechste',
            7 => 'Siebte',
            8 => 'Achte',
            9 => 'Neunte',
            10 => 'Zehnte',
        ];
        if ( isset( $map[ $n ] ) ) {
            return $map[ $n ];
        }
        return $n . '.';
    }

    protected static function parse_tokens( $text ) {
        $text = (string) $text;
        $parts = preg_split( '/[\n\r,;]+/u', $text );
        $out = [];
        foreach ( (array) $parts as $p ) {
            $t = trim( (string) $p );
            if ( $t === '' ) {
                continue;
            }
            // Trim trailing punctuation.
            $t = rtrim( $t, " \t\n\r\0\x0B.,;:!?" );
            $t = trim( $t );
            if ( $t !== '' ) {
                $out[] = $t;
            }
        }
        return $out;
    }

    protected static function prefetch_token_links( $tokens ) {
        global $wpdb;
        $tokens = array_values( array_filter( array_map( 'trim', (array) $tokens ) ) );
        if ( empty( $tokens ) ) {
            return [ 'title' => [], 'slug' => [] ];
        }

        $slugs = [];
        foreach ( $tokens as $t ) {
            $slugs[] = sanitize_title( $t );
        }

        $tph = implode( ',', array_fill( 0, count( $tokens ), '%s' ) );
        $sph = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
        $params = array_merge( $tokens, $slugs );

        $sql = "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND (post_title IN ({$tph}) OR post_name IN ({$sph}))";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        $by_title = [];
        $by_slug  = [];
        foreach ( (array) $rows as $r ) {
            $pid = (int) $r['ID'];
            $url = get_permalink( $pid );
            if ( ! $url ) {
                continue;
            }
            $by_title[ mb_strtolower( (string) $r['post_title'] ) ] = $url;
            $by_slug[ (string) $r['post_name'] ] = $url;
        }

        return [ 'title' => $by_title, 'slug' => $by_slug ];
    }

    protected static function render_token( $token, $link_index ) {
        $token = (string) $token;
        $lower = mb_strtolower( $token );
        $slug  = sanitize_title( $token );

        $url = '';
        if ( ! empty( $link_index['title'][ $lower ] ) ) {
            $url = $link_index['title'][ $lower ];
        } elseif ( ! empty( $link_index['slug'][ $slug ] ) ) {
            $url = $link_index['slug'][ $slug ];
        }

        if ( $url ) {
            return '<a class="jelyk-token" href="' . esc_url( $url ) . '">' . esc_html( $token ) . '</a>';
        }
        return '<span class="jelyk-token jelyk-token--plain">' . esc_html( $token ) . '</span>';
    }

    /* =========================
     * Save entry point
     * ========================= */
    public static function save_all( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['jelyk_word_meta_nonce'] ) ) {
            self::save_word_meta( $post_id );
        }
        if ( isset( $_POST['jelyk_meanings_nonce'] ) ) {
            self::save_meanings_cards( $post_id );
        }
    }

    /* =========================
     * Cleanup (legacy data)
     * ========================= */

    protected static function legacy_meta_keys() {
        return [
            self::META_PREFIX . 'meanings',
            self::META_PREFIX . 'synonyms',
            self::META_PREFIX . 'translation',
            self::META_PREFIX . 'examples',
        ];
    }

    public static function register_tools_page() {
        add_management_page(
            'Jelyk Cleanup',
            'Jelyk Cleanup',
            'manage_options',
            'jelyk-cleanup',
            [ __CLASS__, 'render_cleanup_page' ]
        );
    }

    public static function render_cleanup_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'jelyk' ) );
        }

        $deleted = isset( $_GET['jelyk_deleted'] ) ? (int) $_GET['jelyk_deleted'] : 0;
        $preview_key = isset( $_GET['jelyk_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['jelyk_preview'] ) ) : '';
        $error       = isset( $_GET['jelyk_error'] ) ? sanitize_text_field( wp_unslash( $_GET['jelyk_error'] ) ) : '';

        $preview_data = [];
        if ( $preview_key && get_current_user_id() ) {
            $preview_data = get_transient( 'jelyk_cleanup_preview_' . get_current_user_id() );
            delete_transient( 'jelyk_cleanup_preview_' . get_current_user_id() );
            if ( ! is_array( $preview_data ) ) {
                $preview_data = [];
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Jelyk Cleanup', 'jelyk' ); ?></h1>
            <p><?php esc_html_e( 'Delete legacy meta fields from posts. This will not drop any tables or columns.', 'jelyk' ); ?></p>
            <?php if ( $deleted > 0 ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Deleted %d legacy meta entries.', 'jelyk' ), $deleted ) ); ?></p></div>
            <?php elseif ( isset( $_GET['jelyk_deleted'] ) ) : ?>
                <div class="notice notice-info"><p><?php esc_html_e( 'No legacy meta entries were found.', 'jelyk' ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'confirm' === $error ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Please confirm that you understand this action will delete data.', 'jelyk' ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Preview legacy data', 'jelyk' ); ?></h2>
            <p><?php esc_html_e( 'Preview the legacy meta entries targeted for deletion. No data will be removed.', 'jelyk' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'jelyk_cleanup_preview_action', 'jelyk_cleanup_preview_nonce' ); ?>
                <input type="hidden" name="action" value="jelyk_cleanup_preview" />
                <p>
                    <button type="submit" class="button"><?php esc_html_e( 'Preview legacy data', 'jelyk' ); ?></button>
                </p>
            </form>

            <?php if ( ! empty( $preview_data ) ) : ?>
                <h3><?php esc_html_e( 'Legacy meta keys', 'jelyk' ); ?></h3>
                <ul>
                    <?php foreach ( $preview_data['meta_keys'] as $meta_key ) : ?>
                        <li><?php echo esc_html( $meta_key ); ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php if ( ! empty( $preview_data['counts'] ) ) : ?>
                    <h3><?php esc_html_e( 'Counts by meta key', 'jelyk' ); ?></h3>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Meta key', 'jelyk' ); ?></th>
                                <th><?php esc_html_e( 'Count', 'jelyk' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $preview_data['counts'] as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['meta_key'] ); ?></td>
                                    <td><?php echo esc_html( $row['count'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e( 'No legacy meta entries were found.', 'jelyk' ); ?></p>
                <?php endif; ?>

                <?php if ( ! empty( $preview_data['samples'] ) ) : ?>
                    <h3><?php esc_html_e( 'Sample rows', 'jelyk' ); ?></h3>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'meta_id', 'jelyk' ); ?></th>
                                <th><?php esc_html_e( 'post_id', 'jelyk' ); ?></th>
                                <th><?php esc_html_e( 'meta_key', 'jelyk' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $preview_data['samples'] as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['meta_id'] ); ?></td>
                                    <td><?php echo esc_html( $row['post_id'] ); ?></td>
                                    <td><?php echo esc_html( $row['meta_key'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'jelyk_cleanup_action', 'jelyk_cleanup_nonce' ); ?>
                <input type="hidden" name="action" value="jelyk_cleanup" />
                <p>
                    <label>
                        <input type="checkbox" name="jelyk_cleanup_confirm" value="1" />
                        <?php esc_html_e( 'I understand this will delete data.', 'jelyk' ); ?>
                    </label>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Warning: This action cannot be undone.', 'jelyk' ); ?></strong>
                </p>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Delete legacy data', 'jelyk' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    public static function handle_cleanup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'jelyk' ) );
        }

        if ( ! isset( $_POST['jelyk_cleanup_nonce'] ) || ! wp_verify_nonce( $_POST['jelyk_cleanup_nonce'], 'jelyk_cleanup_action' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'jelyk' ) );
        }

        if ( empty( $_POST['jelyk_cleanup_confirm'] ) ) {
            $redirect = add_query_arg(
                [
                    'page'         => 'jelyk-cleanup',
                    'jelyk_error'  => 'confirm',
                ],
                admin_url( 'tools.php' )
            );

            wp_safe_redirect( $redirect );
            exit;
        }

        $deleted = self::cleanup_legacy_data();

        $redirect = add_query_arg(
            [
                'page'           => 'jelyk-cleanup',
                'jelyk_deleted'  => $deleted,
            ],
            admin_url( 'tools.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handle_cleanup_preview() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'jelyk' ) );
        }

        if ( ! isset( $_POST['jelyk_cleanup_preview_nonce'] ) || ! wp_verify_nonce( $_POST['jelyk_cleanup_preview_nonce'], 'jelyk_cleanup_preview_action' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'jelyk' ) );
        }

        $preview_data = self::preview_legacy_data();
        $user_id      = get_current_user_id();

        if ( $user_id ) {
            set_transient( 'jelyk_cleanup_preview_' . $user_id, $preview_data, 5 * MINUTE_IN_SECONDS );
        }

        $redirect = add_query_arg(
            [
                'page'          => 'jelyk-cleanup',
                'jelyk_preview' => 1,
            ],
            admin_url( 'tools.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    protected static function cleanup_legacy_data() {
        global $wpdb;

        $total_deleted = 0;
        $table = $wpdb->postmeta;

        foreach ( self::legacy_meta_keys() as $key ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE meta_key = %s", $key ) );
            $total_deleted += (int) $wpdb->rows_affected;
        }

        return $total_deleted;
    }

    protected static function preview_legacy_data() {
        global $wpdb;

        $meta_keys   = self::legacy_meta_keys();
        $placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
        $table        = $wpdb->postmeta;

        $counts_sql = $wpdb->prepare(
            "SELECT meta_key, COUNT(*) as count FROM {$table} WHERE meta_key IN ({$placeholders}) GROUP BY meta_key",
            $meta_keys
        );

        $counts = $wpdb->get_results( $counts_sql, ARRAY_A );

        $samples_sql = $wpdb->prepare(
            "SELECT meta_id, post_id, meta_key FROM {$table} WHERE meta_key IN ({$placeholders}) ORDER BY meta_id DESC LIMIT 50",
            $meta_keys
        );

        $samples = $wpdb->get_results( $samples_sql, ARRAY_A );

        return [
            'meta_keys' => $meta_keys,
            'counts'    => $counts,
            'samples'   => $samples,
        ];
    }
}

Jelyk_Word_Plugin::init();
