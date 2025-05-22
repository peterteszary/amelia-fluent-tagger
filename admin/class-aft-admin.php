<?php
/**
 * Amelia FluentCRM Tagger - Adminisztrációs felület
 */

if (!defined('ABSPATH')) {
    exit; // Közvetlen hozzáférés tiltása
}

class Amelia_Fluent_Tagger_Admin {

    private static $instance;
    const SETTINGS_SLUG = 'amelia_fluent_tagger_settings';
    // Az OPTION_NAME konstans a fő fájlban definiált AFT_RULES_OPTION_NAME

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_aft_save_rules', [$this, 'ajax_save_rules']);
        // Az 'aft_tag_now' AJAX action a class-aft-cron.php-ban van kezelve
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Amelia-Fluent Tagger', 'amelia-fluent-tagger'),
            __('Amelia Tagger', 'amelia-fluent-tagger'),
            'manage_options', 
            self::SETTINGS_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-tag'
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_' . self::SETTINGS_SLUG !== $hook) {
            return;
        }
        wp_enqueue_style('aft-admin-style', AFT_PLUGIN_URL . 'admin/css/admin-style.css', [], AFT_PLUGIN_VERSION);
        wp_enqueue_script('aft-admin-script', AFT_PLUGIN_URL . 'admin/js/admin-script.js', ['jquery'], AFT_PLUGIN_VERSION, true);
        
        wp_localize_script('aft-admin-script', 'aft_ajax_obj', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce_save_rules'    => wp_create_nonce('aft_save_rules_nonce'),
            'nonce_tag_now'       => wp_create_nonce('aft_tag_now_nonce'), 
            'i18n'     => [
                'confirm_delete' => __('Biztosan törölni szeretné ezt a szabályt?', 'amelia-fluent-tagger'),
                'error_unknown' => __('Ismeretlen hiba történt.', 'amelia-fluent-tagger'),
                'error_server' => __('Szerverhiba történt.', 'amelia-fluent-tagger'),
                'rule_heading_1' => __('Szabály #1', 'amelia-fluent-tagger'),
                'tag_now_processing' => __('Feldolgozás...', 'amelia-fluent-tagger'),
                'tag_now_success' => __('Azonnali tag-elés sikeres.', 'amelia-fluent-tagger'),
                'tag_now_error' => __('Hiba az azonnali tag-elés során.', 'amelia-fluent-tagger'),
                'tag_now_no_bookings' => __('Nem található feldolgozható foglalás ehhez a szabályhoz.', 'amelia-fluent-tagger'),
            ]
        ]);
    }

    public function render_settings_page() {
        if (!Amelia_Fluent_Tagger_Integrations::is_amelia_active()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Az Amelia Booking bővítmény nincs telepítve vagy aktiválva. Kérjük, telepítse és aktiválja.', 'amelia-fluent-tagger') . '</p></div>';
        }
        if (!Amelia_Fluent_Tagger_Integrations::is_fluentcrm_active()) {
             echo '<div class="notice notice-error"><p>' . esc_html__('A FluentCRM bővítmény nincs telepítve vagy aktiválva. Kérjük, telepítse és aktiválja.', 'amelia-fluent-tagger') . '</p></div>';
        }
        ?>
        <div class="wrap aft-settings-wrap">
            <h1><?php esc_html_e('Amelia-FluentCRM Tagger Beállítások', 'amelia-fluent-tagger'); ?></h1>
            <p><?php esc_html_e('Hozzon létre és kezeljen szabályokat FluentCRM tagek automatikus hozzáadásához az Amelia eseményfoglalások alapján.', 'amelia-fluent-tagger'); ?></p>

            <form id="aft-rules-form" method="post">
                <?php wp_nonce_field('aft_save_rules_action', 'aft_save_rules_nonce_field'); ?>
                <div id="aft-rules-container">
                    <?php
                    $rules = get_option(AFT_RULES_OPTION_NAME, []); 
                    $default_rule_data = [
                        'event_id' => '',
                        'timing_type' => 'before',
                        'days_offset' => 2,
                        'hours_offset' => 0,
                        'minutes_offset' => 0,
                        'fluent_tag_id' => '',
                        'info_sheet_cpt_id_custom_field' => 'amelia_info_sheet_id_for_email'
                    ];
                    if (empty($rules)) {
                         $this->render_rule_template(0, $default_rule_data);
                    } else {
                        foreach ($rules as $index => $rule) {
                            $rule = wp_parse_args($rule, $default_rule_data);
                            $this->render_rule_template($index, $rule);
                        }
                    }
                    ?>
                </div>

                <button type="button" id="aft-add-rule" class="button">
                    <span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e('Új Szabály Hozzáadása', 'amelia-fluent-tagger'); ?>
                </button>
                <p class="submit">
                    <button type="submit" name="aft_save_rules_submit" id="aft-save-rules-submit" class="button button-primary">
                        <?php esc_html_e('Szabályok Mentése', 'amelia-fluent-tagger'); ?>
                    </button>
                </p>
                 <div id="aft-save-spinner" class="spinner" style="float:none; visibility: hidden;"></div>
                 <div id="aft-save-feedback" style="display:none; margin-top: 10px;"></div>
            </form>

            <div id="aft-rule-template" style="display: none;">
                <?php $this->render_rule_template('__INDEX__', $default_rule_data); ?>
            </div>

            <h2><?php esc_html_e('Fontos Tudnivalók', 'amelia-fluent-tagger'); ?></h2>
            <ul>
                <li><?php printf(
                    esc_html__('A "Részletes tájékoztató" egyedi bejegyzéstípust (CPT) hozza létre a %s bővítménnyel (javasolt slug: %s).', 'amelia-fluent-tagger'),
                    '<a href="https://metabox.io/" target="_blank">Meta Box</a>', '<code>' . esc_html(Amelia_Fluent_Tagger_Integrations::INFO_SHEET_CPT_SLUG) . '</code>'
                ); ?></li>
                <li><?php printf(
                    esc_html__('A CPT-ben adjon hozzá egy egyedi mezőt (pl. szöveges mező, meta kulcs: %s), ahol megadja a kapcsolódó Amelia esemény ID-ját.', 'amelia-fluent-tagger'),
                    '<code>' . esc_html(Amelia_Fluent_Tagger_Integrations::AMELIA_EVENT_ID_META_KEY) . '</code>'
                ); ?></li>
                <li><?php printf(
                    esc_html__('A FluentCRM email sablonjában használja a %s shortcode-ot a részletes tájékoztató tartalmának beillesztéséhez.', 'amelia-fluent-tagger'),
                    '<code>[reszletes_tajekoztato_tartalom]</code>'
                ); ?></li>
                 <li><?php esc_html_e('Javasolt a FluentCRM-ben egy egyedi kontakt mezőt létrehozni (pl. név: "Amelia Info Sheet ID", slug: <code>amelia_info_sheet_id_for_email</code>), és a cron job-ot úgy módosítani, hogy tag-eléskor ebbe a mezőbe mentse a releváns "Részletes tájékoztató" CPT bejegyzés ID-ját. Ezt az ID-t a shortcode fel tudja használni.', 'amelia-fluent-tagger'); ?></li>
                 <li><?php esc_html_e('A cron feladat alapértelmezetten 15 percenként fut. A tag-elés pontossága ettől a futási gyakoriságtól függ.', 'amelia-fluent-tagger'); ?></li>
            </ul>
        </div>
        <?php
    }

    private function render_rule_template($index, $rule_data) {
        $amelia_events = Amelia_Fluent_Tagger_Integrations::get_amelia_events();
        $fluent_tags = Amelia_Fluent_Tagger_Integrations::get_fluentcrm_tags();
        
        $default_rule_values = [
            'event_id' => '',
            'timing_type' => 'before',
            'days_offset' => 2,
            'hours_offset' => 0,
            'minutes_offset' => 0,
            'fluent_tag_id' => '',
            'info_sheet_cpt_id_custom_field' => 'amelia_info_sheet_id_for_email'
        ];
        $rule_data = wp_parse_args($rule_data, $default_rule_values);
        $rule_item_id = 'aft-rule-item-' . (is_numeric($index) ? $index : '__INDEX__');
        ?>
        <div class="aft-rule-item" id="<?php echo esc_attr($rule_item_id); ?>" data-index="<?php echo esc_attr($index); ?>">
            <div class="aft-rule-actions">
                <button type="button" class="button aft-tag-now-button" title="<?php esc_attr_e('Tag-elés Most', 'amelia-fluent-tagger'); ?>" data-rule-index="<?php echo esc_attr($index); ?>">
                    <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('Tag-elés Most', 'amelia-fluent-tagger'); ?>
                </button>
                <button type="button" class="button aft-remove-rule" title="<?php esc_attr_e('Szabály Törlése', 'amelia-fluent-tagger'); ?>"><span class="dashicons dashicons-trash"></span></button>
            </div>
            <h4><?php printf(esc_html__('Szabály #%s', 'amelia-fluent-tagger'), is_numeric($index) ? esc_html($index + 1) : '__INDEX_DISPLAY__'); ?></h4>
            <div class="aft-tag-now-feedback" style="display:none; margin-bottom:10px;"></div>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="aft_rule_<?php echo esc_attr($index); ?>_event_id"><?php esc_html_e('Amelia Esemény', 'amelia-fluent-tagger'); ?></label>
                        </th>
                        <td>
                            <select name="aft_rules[<?php echo esc_attr($index); ?>][event_id]" id="aft_rule_<?php echo esc_attr($index); ?>_event_id" class="aft-select-event" required>
                                <option value=""><?php esc_html_e('-- Válasszon eseményt --', 'amelia-fluent-tagger'); ?></option>
                                <?php if (!empty($amelia_events)) : ?>
                                    <?php foreach ($amelia_events as $event_id_val => $event_name) : // Renamed $event_id to $event_id_val to avoid conflict ?>
                                        <option value="<?php echo esc_attr($event_id_val); ?>" <?php selected($rule_data['event_id'], $event_id_val); ?>>
                                            <?php echo esc_html($event_name); ?> (ID: <?php echo esc_html($event_id_val); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value="" disabled><?php esc_html_e('Nincsenek Amelia események, vagy nem sikerült lekérdezni őket.', 'amelia-fluent-tagger'); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Időzítés (Cronhoz)', 'amelia-fluent-tagger'); ?></th>
                        <td>
                            <select name="aft_rules[<?php echo esc_attr($index); ?>][timing_type]" class="aft-select-timing" required>
                                <option value="before" <?php selected($rule_data['timing_type'], 'before'); ?>><?php esc_html_e('Esemény Előtt', 'amelia-fluent-tagger'); ?></option>
                                <option value="after" <?php selected($rule_data['timing_type'], 'after'); ?>><?php esc_html_e('Esemény Után', 'amelia-fluent-tagger'); ?></option>
                            </select>
                            <div class="timing-inputs-wrapper" style="margin-top: 8px;">
                                <div>
                                    <input type="number" name="aft_rules[<?php echo esc_attr($index); ?>][days_offset]" value="<?php echo esc_attr($rule_data['days_offset']); ?>" min="0" step="1" class="aft-input-days" required>
                                    <label><?php esc_html_e('nap', 'amelia-fluent-tagger'); ?></label>
                                </div>
                                <div>
                                    <input type="number" name="aft_rules[<?php echo esc_attr($index); ?>][hours_offset]" value="<?php echo esc_attr($rule_data['hours_offset']); ?>" min="0" max="23" step="1" class="aft-input-hours" required>
                                    <label><?php esc_html_e('óra', 'amelia-fluent-tagger'); ?></label>
                                </div>
                                <div>
                                    <input type="number" name="aft_rules[<?php echo esc_attr($index); ?>][minutes_offset]" value="<?php echo esc_attr($rule_data['minutes_offset']); ?>" min="0" max="59" step="1" class="aft-input-minutes" required>
                                    <label><?php esc_html_e('perc', 'amelia-fluent-tagger'); ?></label>
                                </div>
                            </div>
                             <p class="description" style="margin-top: 5px;"><?php esc_html_e('Ez az időzítés csak az automatikus (cron) tag-elésre vonatkozik. A "Tag-elés Most" gomb azonnal futtatja a logikát.', 'amelia-fluent-tagger'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="aft_rule_<?php echo esc_attr($index); ?>_fluent_tag_id"><?php esc_html_e('FluentCRM Tag', 'amelia-fluent-tagger'); ?></label>
                        </th>
                        <td>
                            <select name="aft_rules[<?php echo esc_attr($index); ?>][fluent_tag_id]" id="aft_rule_<?php echo esc_attr($index); ?>_fluent_tag_id" class="aft-select-tag" required>
                                <option value=""><?php esc_html_e('-- Válasszon taget --', 'amelia-fluent-tagger'); ?></option>
                                <?php if (!empty($fluent_tags)) : ?>
                                    <?php foreach ($fluent_tags as $tag) : ?>
                                        <option value="<?php echo esc_attr($tag->id); ?>" <?php selected($rule_data['fluent_tag_id'], $tag->id); ?>>
                                            <?php echo esc_html($tag->title); ?> (ID: <?php echo esc_html($tag->id); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                     <option value="" disabled><?php esc_html_e('Nincsenek FluentCRM tagek, vagy nem sikerült lekérdezni őket.', 'amelia-fluent-tagger'); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                     <tr>
                        <th scope="row">
                            <label for="aft_rule_<?php echo esc_attr($index); ?>_info_sheet_cpt_id_custom_field"><?php esc_html_e('FluentCRM Egyedi Mező (Info Sheet ID)', 'amelia-fluent-tagger'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="aft-input-custom-field" name="aft_rules[<?php echo esc_attr($index); ?>][info_sheet_cpt_id_custom_field]" id="aft_rule_<?php echo esc_attr($index); ?>_info_sheet_cpt_id_custom_field" value="<?php echo esc_attr($rule_data['info_sheet_cpt_id_custom_field']); ?>" placeholder="<?php echo esc_attr($default_rule_values['info_sheet_cpt_id_custom_field']); ?>">
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function ajax_save_rules() {
        check_ajax_referer('aft_save_rules_nonce', 'nonce_save_rules');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Nincs jogosultsága a művelethez.', 'amelia-fluent-tagger')]);
            return;
        }

        $posted_rules = isset($_POST['aft_rules']) && is_array($_POST['aft_rules']) ? $_POST['aft_rules'] : [];
        $rules_data = [];

        if (!empty($posted_rules)) {
            foreach ($posted_rules as $rule_input) {
                $days_offset = isset($rule_input['days_offset']) ? absint($rule_input['days_offset']) : 0;
                $hours_offset = isset($rule_input['hours_offset']) ? absint($rule_input['hours_offset']) : 0;
                $minutes_offset = isset($rule_input['minutes_offset']) ? absint($rule_input['minutes_offset']) : 0;

                if ($hours_offset < 0 || $hours_offset > 23) $hours_offset = 0;
                if ($minutes_offset < 0 || $minutes_offset > 59) $minutes_offset = 0;

                $clean_rule = [
                    'event_id'        => !empty($rule_input['event_id']) ? sanitize_text_field($rule_input['event_id']) : null,
                    'timing_type'     => isset($rule_input['timing_type']) && in_array($rule_input['timing_type'], ['before', 'after']) ? $rule_input['timing_type'] : 'before',
                    'days_offset'     => $days_offset,
                    'hours_offset'    => $hours_offset,
                    'minutes_offset'  => $minutes_offset,
                    'fluent_tag_id'   => !empty($rule_input['fluent_tag_id']) ? sanitize_text_field($rule_input['fluent_tag_id']) : null,
                    'info_sheet_cpt_id_custom_field' => !empty($rule_input['info_sheet_cpt_id_custom_field']) ? sanitize_key($rule_input['info_sheet_cpt_id_custom_field']) : '',
                ];
                if ($clean_rule['event_id'] && $clean_rule['fluent_tag_id'] && ctype_digit((string)$clean_rule['event_id']) && ctype_digit((string)$clean_rule['fluent_tag_id'])) {
                    $rules_data[] = $clean_rule;
                }
            }
        }

        update_option(AFT_RULES_OPTION_NAME, $rules_data); 

        if (empty($rules_data) && !empty($posted_rules)) {
             wp_send_json_error(['message' => __('Nem sikerült érvényes szabályokat menteni. Ellenőrizze a bemeneti adatokat.', 'amelia-fluent-tagger')]);
        } elseif (empty($rules_data) && empty($posted_rules)) {
            wp_send_json_success(['message' => __('Minden szabály törölve.', 'amelia-fluent-tagger')]);
        }
        else {
            wp_send_json_success(['message' => __('Szabályok sikeresen mentve!', 'amelia-fluent-tagger')]);
        }
        wp_die();
    }
}
?>
