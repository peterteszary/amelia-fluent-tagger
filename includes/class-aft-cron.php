<?php
/**
 * Amelia FluentCRM Tagger - Cron Feladatok és AJAX Handlerek
 */

if (!defined('ABSPATH')) {
    exit; // Közvetlen hozzáférés tiltása
}

class Amelia_Fluent_Tagger_Cron {

    private static $instance;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(AFT_CRON_HOOK, [$this, 'execute_tagging_logic']);
        add_action('wp_ajax_aft_tag_now', [$this, 'ajax_tag_now_handler']);
    }

    /**
     * AJAX handler for the "Tag Now" button.
     * Processes a single rule immediately.
     */
    public function ajax_tag_now_handler() {
        check_ajax_referer('aft_tag_now_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Nincs jogosultsága a művelethez.', 'amelia-fluent-tagger')]);
            return;
        }

        $event_id_posted = isset($_POST['event_id']) ? sanitize_text_field($_POST['event_id']) : null;
        $tag_id_posted = isset($_POST['tag_id']) ? sanitize_text_field($_POST['tag_id']) : null;
        $info_field_posted = isset($_POST['info_field']) ? sanitize_key($_POST['info_field']) : null;

        if (!ctype_digit((string)$event_id_posted) || !ctype_digit((string)$tag_id_posted)) {
             wp_send_json_error(['message' => __('Érvénytelen szabály adatok (esemény vagy tag ID).', 'amelia-fluent-tagger')]);
            return;
        }
        
        $rule_to_process = [
            'event_id' => (int)$event_id_posted,
            'fluent_tag_id' => (int)$tag_id_posted,
            'info_sheet_cpt_id_custom_field' => $info_field_posted
        ];
        
        error_log('Amelia Fluent Tagger (Tag Now): Feldolgozandó szabály: ' . print_r($rule_to_process, true)); // NAPLÓZÁS BEKAPCSOLVA

        if (!Amelia_Fluent_Tagger_Integrations::is_fluentcrm_active() || !Amelia_Fluent_Tagger_Integrations::is_amelia_active()) {
            error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): FluentCRM vagy Amelia nem aktív.');
            wp_send_json_error(['message' => __('FluentCRM vagy Amelia nem aktív.', 'amelia-fluent-tagger')]);
            return;
        }

        $amelia_event_id = $rule_to_process['event_id'];
        $fluent_tag_id = $rule_to_process['fluent_tag_id'];
        $info_sheet_custom_field_key = $rule_to_process['info_sheet_cpt_id_custom_field'];
        
        error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Foglalások lekérdezése az eseményhez (ID: '.$amelia_event_id.').');
        $bookings = Amelia_Fluent_Tagger_Integrations::get_amelia_bookings_for_event($amelia_event_id);

        if (empty($bookings)) {
            error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Nincsenek "jóváhagyott" foglalások az eseményhez (ID: '.$amelia_event_id.').');
            wp_send_json_success(['message' => __('Nincsenek "jóváhagyott" státuszú foglalások ehhez az Amelia eseményhez.', 'amelia-fluent-tagger'), 'tagged_count' => 0]);
            return;
        }
        error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Talált foglalások száma: ' . count($bookings) . ' az eseményhez (ID: '.$amelia_event_id.').');

        $tagged_count = 0;
        $processed_emails = [];

        foreach ($bookings as $booking_key => $booking) {
            error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Feldolgozás alatt: Booking Key ' . $booking_key . ', Adatok: ' . print_r($booking, true));
            if (!isset($booking->email) || !isset($booking->customerId)) {
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Hiányos foglalási adatok (email vagy customerId): ' . print_r($booking, true));
                continue;
            }
            $customer_email = $booking->email;

            if (empty($customer_email) || !is_email($customer_email)) {
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Érvénytelen email cím a felhasználónál (Customer ID: '.$booking->customerId.')');
                continue;
            }
            
            if (in_array($customer_email, $processed_emails)) {
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Email (' . $customer_email . ') már feldolgozva ebben a futásban, kihagyom.');
                continue;
            }
            $processed_emails[] = $customer_email;

            $contactApi = FluentCrmApi('contacts');
            $contact = $contactApi->getContact($customer_email);

            if ($contact) {
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Kontakt megtalálva FluentCRM-ben: ' . $customer_email . ' (ID: ' . $contact->id . ')');
                $has_tag = false;
                if (!empty($contact->tags)) { 
                    foreach ($contact->tags as $existing_tag) {
                        if ($existing_tag->id == $fluent_tag_id) {
                            $has_tag = true;
                            break;
                        }
                    }
                }

                if (!$has_tag) {
                    $contact->attachTags([$fluent_tag_id]);
                    $tagged_count++;
                    error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Tag (ID: '.$fluent_tag_id.') HOZZÁADVA ehhez: '.$customer_email.' az eseményhez (ID: '.$amelia_event_id.')');

                    if ($info_sheet_custom_field_key) {
                        $info_sheet_post_id = Amelia_Fluent_Tagger_Integrations::get_info_sheet_id_for_event($amelia_event_id);
                        if ($info_sheet_post_id) {
                            $custom_data = [$info_sheet_custom_field_key => $info_sheet_post_id];
                            $contact->updateCustomFields($custom_data);
                            error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Info sheet CPT ID ('.$info_sheet_post_id.') mentve a "'.$info_sheet_custom_field_key.'" mezőbe ehhez: '.$customer_email);
                        } else {
                             error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Nem található Info sheet CPT ID az eseményhez (ID: '.$amelia_event_id.') a "'.$info_sheet_custom_field_key.'" mezőhöz.');
                        }
                    }
                } else {
                     error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Tag (ID: '.$fluent_tag_id.') már létezik ehhez: '.$customer_email.'. Nem lett újra hozzáadva.');
                }
            } else {
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Tag Now): Kontakt NEM található FluentCRM-ben: '.$customer_email.'.');
            }
        }

        wp_send_json_success([
            'message' => sprintf(_n('%d kontakt sikeresen tag-elve.', '%d kontakt sikeresen tag-elve.', $tagged_count, 'amelia-fluent-tagger'), $tagged_count),
            'tagged_count' => $tagged_count
        ]);
        wp_die();
    }


    public function execute_tagging_logic() {
        if (defined('AFT_DOING_CRON') && AFT_DOING_CRON) {
            return;
        }
        define('AFT_DOING_CRON', true);
        error_log('Amelia Fluent Tagger: execute_tagging_logic - Cron futás elindult.');

        $rules = get_option(AFT_RULES_OPTION_NAME, []); 
        if (empty($rules)) {
            error_log('['.current_time('mysql').'] Amelia Fluent Tagger: Nincsenek aktív szabályok a cron futásakor.');
            return;
        }

        if (!Amelia_Fluent_Tagger_Integrations::is_fluentcrm_active() || !Amelia_Fluent_Tagger_Integrations::is_amelia_active()) {
            error_log('['.current_time('mysql').'] Amelia Fluent Tagger: FluentCRM vagy Amelia nem aktív a cron futásakor.');
            return;
        }

        $current_timestamp_gmt = current_time('timestamp', true); 
        $cron_interval_seconds = 15 * 60; 
        $transient_expiration = $cron_interval_seconds * 2; 

        error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Jelenlegi GMT timestamp: ' . $current_timestamp_gmt . ', Cron intervallum: ' . $cron_interval_seconds . 's');

        foreach ($rules as $rule_index => $rule) {
            error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Szabály feldolgozása (index: '.$rule_index.'): ' . print_r($rule, true));
            $rule = wp_parse_args($rule, [
                'event_id' => '',
                'fluent_tag_id' => '',
                'days_offset' => 0,
                'hours_offset' => 0,
                'minutes_offset' => 0,
                'timing_type' => 'before',
                'info_sheet_cpt_id_custom_field' => ''
            ]);

            if (empty($rule['event_id']) || empty($rule['fluent_tag_id'])) {
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Hiányos szabály (event_id vagy fluent_tag_id) (index: '.$rule_index.').');
                continue;
            }
            
            if (intval($rule['days_offset']) == 0 && intval($rule['hours_offset']) == 0 && intval($rule['minutes_offset']) == 0) {
                 error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Érvénytelen időeltolás (minden nulla) a szabályban (index: '.$rule_index.').');
                continue;
            }

            $amelia_event_id = intval($rule['event_id']);
            $fluent_tag_id = intval($rule['fluent_tag_id']);
            // ... (többi változó definíciója) ...
            $days_offset = intval($rule['days_offset']);
            $hours_offset = intval($rule['hours_offset']);
            $minutes_offset = intval($rule['minutes_offset']);
            $timing_type = $rule['timing_type'];
            $info_sheet_custom_field_key = !empty($rule['info_sheet_cpt_id_custom_field']) ? sanitize_key($rule['info_sheet_cpt_id_custom_field']) : null;

            error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Foglalások lekérdezése az eseményhez (ID: '.$amelia_event_id.') a szabályhoz (index: '.$rule_index.').');
            $bookings = Amelia_Fluent_Tagger_Integrations::get_amelia_bookings_for_event($amelia_event_id);

            if (empty($bookings)) {
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Nincsenek foglalások az eseményhez (ID: '.$amelia_event_id.') a szabályhoz (index: '.$rule_index.').');
                continue;
            }
            error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Talált foglalások száma: ' . count($bookings) . ' az eseményhez (ID: '.$amelia_event_id.').');

            foreach ($bookings as $booking_key_cron => $booking) { // $booking_key átnevezve $booking_key_cron-ra
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Feldolgozás alatt: Booking Key ' . $booking_key_cron . ', Adatok: ' . print_r($booking, true));
                if (!isset($booking->bookingStart) || !isset($booking->email) || !isset($booking->appointmentId) || !isset($booking->customerId)) {
                    error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Hiányos foglalási adatok: ' . print_r($booking, true));
                    continue;
                }

                // ... (további logika a cron futáshoz, a naplózó sorok már be vannak kapcsolva a Tag Now részben, hasonlóan itt is lehet) ...
                 $booking_start_gmt_str = $booking->bookingStart; 
                $booking_start_ts_gmt = strtotime($booking_start_gmt_str);

                if ($booking_start_ts_gmt === false) {
                    error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Érvénytelen bookingStart dátum: '.$booking_start_gmt_str.' a foglalásnál (Appointment ID: '.$booking->appointmentId.')');
                    continue;
                }

                $total_offset_seconds = ($days_offset * 24 * 60 * 60) + ($hours_offset * 60 * 60) + ($minutes_offset * 60);
                $target_tagging_ts_gmt = 0;

                if ($timing_type === 'before') {
                    $target_tagging_ts_gmt = $booking_start_ts_gmt - $total_offset_seconds;
                } else { 
                    $target_tagging_ts_gmt = $booking_start_ts_gmt + $total_offset_seconds;
                }
                
                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Booking ID ' . $booking->appointmentId . ' - Esemény kezdete (GMT): ' . $booking_start_gmt_str . ' (TS: '.$booking_start_ts_gmt.'), Cél tag-elési idő (GMT TS): ' . $target_tagging_ts_gmt);
                
                if ($target_tagging_ts_gmt <= $current_timestamp_gmt && $target_tagging_ts_gmt > ($current_timestamp_gmt - $cron_interval_seconds)) {
                    error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Booking ID ' . $booking->appointmentId . ' - FELDOLGOZANDÓ. Célidő: ' . date('Y-m-d H:i:s', $target_tagging_ts_gmt) . ', Jelenlegi idő: ' . date('Y-m-d H:i:s', $current_timestamp_gmt));
                    $customer_email = $booking->email;
                    if (empty($customer_email) || !is_email($customer_email)) {
                        error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Érvénytelen email cím a felhasználónál (Customer ID: '.$booking->customerId.')');
                        continue;
                    }

                    $contactApi = FluentCrmApi('contacts');
                    $contact = $contactApi->getContact($customer_email);

                    if ($contact) {
                        error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Kontakt megtalálva FluentCRM-ben: ' . $customer_email . ' (ID: ' . $contact->id . ')');
                        $has_tag = false;
                        if (!empty($contact->tags)) { 
                            foreach ($contact->tags as $existing_tag) {
                                if ($existing_tag->id == $fluent_tag_id) {
                                    $has_tag = true;
                                    break;
                                }
                            }
                        }

                        if (!$has_tag) {
                            $processed_key = 'aft_processed_' . $booking->appointmentId . '_' . $amelia_event_id . '_' . $fluent_tag_id . '_' . $timing_type . '_' . $days_offset . '_' . $hours_offset . '_' . $minutes_offset;
                            if (get_transient($processed_key)) {
                                error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Már feldolgozva (transient létezik): '.$processed_key);
                                continue;
                            }

                            $contact->attachTags([$fluent_tag_id]);
                            set_transient($processed_key, time(), $transient_expiration); 
                            error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Tag (ID: '.$fluent_tag_id.') HOZZÁADVA ehhez: '.$customer_email.' az eseményhez (ID: '.$amelia_event_id.'), foglalás (ID: '.$booking->appointmentId.')');

                            if ($info_sheet_custom_field_key) {
                                $info_sheet_post_id = Amelia_Fluent_Tagger_Integrations::get_info_sheet_id_for_event($amelia_event_id);
                                if ($info_sheet_post_id) {
                                    $custom_data = [$info_sheet_custom_field_key => $info_sheet_post_id];
                                    $contact->updateCustomFields($custom_data);
                                    error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Info sheet CPT ID ('.$info_sheet_post_id.') mentve a "'.$info_sheet_custom_field_key.'" mezőbe ehhez: '.$customer_email);
                                } else {
                                     error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Nem található Info sheet CPT ID az eseményhez (ID: '.$amelia_event_id.') a "'.$info_sheet_custom_field_key.'" mezőhöz.');
                                }
                            }
                        } else {
                             error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Tag (ID: '.$fluent_tag_id.') már létezik ehhez: '.$customer_email.'. Nem lett újra hozzáadva.');
                        }
                    } else {
                        error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Kontakt NEM található FluentCRM-ben: '.$customer_email.'.');
                    }
                } else {
                     error_log('['.current_time('mysql').'] Amelia Fluent Tagger (Cron): Booking ID ' . $booking->appointmentId . ' - NEM FELDOLGOZANDÓ. Célidő: ' . date('Y-m-d H:i:s', $target_tagging_ts_gmt) . ', Jelenlegi idő: ' . date('Y-m-d H:i:s', $current_timestamp_gmt));
                }
            }
        }
        error_log('Amelia Fluent Tagger: Cron futás befejeződött.');
    }
}
?>
