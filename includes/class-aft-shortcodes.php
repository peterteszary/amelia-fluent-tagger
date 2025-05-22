<?php
/**
 * Amelia FluentCRM Tagger - Shortcode-ok
 */

if (!defined('ABSPATH')) {
    exit; // Közvetlen hozzáférés tiltása
}

class Amelia_Fluent_Tagger_Shortcodes {

    private static $instance;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('reszletes_tajekoztato_tartalom', [$this, 'render_info_sheet_shortcode']);
    }

    /**
     * Shortcode a "Részletes tájékoztató" tartalmának megjelenítéséhez.
     * Használat:
     * [reszletes_tajekoztato_tartalom] - Megpróbálja automatikusan megtalálni a FluentCRM kontakt egyedi mezőjéből.
     * [reszletes_tajekoztato_tartalom id="123"] - Direkt CPT ID alapján.
     * [reszletes_tajekoztato_tartalom event_id="45"] - Amelia esemény ID alapján keresi a CPT-t.
     */
    public function render_info_sheet_shortcode($atts) {
        $atts = shortcode_atts([
            'id' => null,       // A "Részletes tájékoztató" CPT bejegyzés ID-ja (manuálisan megadva)
            'event_id' => null, // Alternatívaként Amelia esemény ID (manuálisan megadva)
        ], $atts, 'reszletes_tajekoztato_tartalom');

        $info_sheet_post_id = $atts['id'] ? intval($atts['id']) : null;

        // 1. Próbálkozás: FluentCRM kontakt egyedi mezője (ha a shortcode FluentCRM emailben van)
        if (!$info_sheet_post_id && Amelia_Fluent_Tagger_Integrations::is_fluentcrm_active() && function_exists('fluentcrm_get_current_contact')) {
            $contact = fluentcrm_get_current_contact();
            if ($contact && !empty($contact->custom_fields)) {
                $rules = get_option(Amelia_Fluent_Tagger_Admin::OPTION_NAME, []);
                $processed_custom_field_keys = []; // Hogy ne fussunk végig ugyanazon a kulcson többször

                foreach ($rules as $rule) {
                    if (!empty($rule['info_sheet_cpt_id_custom_field']) && 
                        !in_array($rule['info_sheet_cpt_id_custom_field'], $processed_custom_field_keys) &&
                        isset($contact->custom_fields[$rule['info_sheet_cpt_id_custom_field']])) {
                        
                        $potential_id = intval($contact->custom_fields[$rule['info_sheet_cpt_id_custom_field']]);
                        if ($potential_id > 0) {
                            $info_sheet_post_id = $potential_id;
                            break; 
                        }
                        $processed_custom_field_keys[] = $rule['info_sheet_cpt_id_custom_field'];
                    }
                }
            }
        }
        
        // 2. Próbálkozás: 'event_id' attribútum alapján, ha még mindig nincs CPT ID
        if (!$info_sheet_post_id && $atts['event_id']) {
             $info_sheet_post_id = Amelia_Fluent_Tagger_Integrations::get_info_sheet_id_for_event(intval($atts['event_id']));
        }

        if (!$info_sheet_post_id || $info_sheet_post_id <= 0) {
            return '';
        }

        $post = get_post($info_sheet_post_id);

        if (!$post || $post->post_type !== Amelia_Fluent_Tagger_Integrations::INFO_SHEET_CPT_SLUG || $post->post_status !== 'publish') {
            return '';
        }

        // Tartalom visszaadása, filterek alkalmazásával (pl. shortcode-ok feldolgozása a tartalomban)
        // Fontos: a do_shortcode itt rekurziót okozhat, ha a CPT tartalma is tartalmazza ezt a shortcode-ot.
        // Az apply_filters('the_content', ...) általában kezeli a shortcode-okat.
        $content = $post->post_content;
        if (has_shortcode($content, 'reszletes_tajekoztato_tartalom')) {
             // Alapvető védelem a végtelen ciklus ellen
             return '';
        }
        return apply_filters('the_content', $content);
    }
}
?>
