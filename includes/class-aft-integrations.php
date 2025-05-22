<?php
/**
 * Amelia FluentCRM Tagger - Integrációs funkciók
 */

if (!defined('ABSPATH')) {
    exit; // Közvetlen hozzáférés tiltása
}

class Amelia_Fluent_Tagger_Integrations {

    const INFO_SHEET_CPT_SLUG = 'reszletes-tajekoztato'; 
    const AMELIA_EVENT_ID_META_KEY = 'amelia_event_id'; 

    public static function is_amelia_active() {
        return class_exists('AmeliaBooking\\Infrastructure\\Common\\Container');
    }

    public static function is_fluentcrm_active() {
        return defined('FLUENTCRM');
    }

    public static function get_amelia_events() {
        if (!self::is_amelia_active()) {
            return [];
        }

        global $wpdb;
        $events_table = $wpdb->prefix . 'amelia_events';
        $services_table = $wpdb->prefix . 'amelia_services';

        $events_data = [];

        // Események lekérdezése
        if ($wpdb->get_var("SHOW TABLES LIKE '$events_table'") === $events_table) {
            $results = $wpdb->get_results(
                $wpdb->prepare("SELECT id, name FROM {$events_table} WHERE status = %s ORDER BY name ASC", 'approved')
            );
            if ($results) {
                foreach ($results as $event) {
                    // Megkülönböztetjük, hogy ez egy 'event' típusú
                    $events_data[$event->id] = ['name' => $event->name, 'type' => 'event'];
                }
            }
        }

        // Szolgáltatások lekérdezése
        if ($wpdb->get_var("SHOW TABLES LIKE '$services_table'") === $services_table) {
            $service_results = $wpdb->get_results(
                 $wpdb->prepare("SELECT id, name FROM {$services_table} WHERE status = %s ORDER BY name ASC", 'visible')
            );
            if ($service_results) {
                foreach ($service_results as $service) {
                    // Ha ugyanazzal az ID-vel már van esemény, azt nem írjuk felül, de jelezzük, hogy szolgáltatásként is létezhet
                    if (isset($events_data[$service->id]) && $events_data[$service->id]['type'] === 'event') {
                        $events_data[$service->id]['name'] .= ' (Esemény/Szolgáltatás)'; // Jelzés, ha mindkettő
                    } else {
                         $events_data[$service->id] = ['name' => $service->name, 'type' => 'service'];
                    }
                }
            }
        }
        
        // Átalakítás a régi formátumra a legördülő menühöz, de a típus információt is megőrizhetnénk, ha szükséges lenne a jövőben
        $display_events_data = [];
        foreach($events_data as $id => $data) {
            $display_events_data[$id] = $data['name'] . ($data['type'] === 'event' ? ' (Esemény)' : ' (Szolgáltatás)');
        }

        // if (empty($display_events_data)) {
        //     error_log('Amelia Fluent Tagger: Nem sikerült Amelia eseményeket/szolgáltatásokat lekérdezni.');
        // }
        return $display_events_data;
    }

    public static function get_fluentcrm_tags() {
        if (!self::is_fluentcrm_active() || !function_exists('FluentCrmApi')) {
            return [];
        }
        $tagsApi = FluentCrmApi('tags');
        return $tagsApi->all();
    }

    public static function get_amelia_bookings_for_event($event_or_service_id) {
        if (!self::is_amelia_active() || empty($event_or_service_id)) {
            error_log('Amelia Fluent Tagger (get_amelia_bookings_for_event DEBUG): Amelia nem aktív vagy hiányzó event_or_service_id: ' . print_r($event_or_service_id, true));
            return [];
        }

        global $wpdb;
        $customer_bookings_table = $wpdb->prefix . 'amelia_customer_bookings';
        $appointments_table = $wpdb->prefix . 'amelia_appointments';
        $users_table = $wpdb->prefix . 'amelia_users';
        $events_table = $wpdb->prefix . 'amelia_events';
        $events_periods_table = $wpdb->prefix . 'amelia_events_periods';
        $bookings_to_periods_table = $wpdb->prefix . 'amelia_customer_bookings_to_events_periods';

        $results = [];
        $possible_statuses = ['approved', 'confirmed', 'paid', 'wc-completed', 'wc-processing']; // Elfogadható státuszok
        $status_placeholders = implode(', ', array_fill(0, count($possible_statuses), '%s'));

        // Próbálkozás ESEMÉNY foglalások lekérdezésével
        error_log('Amelia Fluent Tagger (get_amelia_bookings_for_event DEBUG): Keresés ESEMÉNYKÉNT (eventId): ' . $event_or_service_id);
        $event_query_sql = $wpdb->prepare(
            "SELECT 
                cb.id as customerBookingId, 
                cb.customerId, 
                cb.status as customerBookingStatus, 
                cb.price as customerBookingPrice,
                ep.periodStart as bookingStart, 
                ep.periodEnd as bookingEnd,
                ev.status as eventStatus, 
                ev.id as eventId,
                u.email, 
                u.id as ameliaUserId, 
                u.firstName, 
                u.lastName,
                cb.id as appointmentId -- Az eseményfoglalás ID-ját használjuk 'appointmentId'-ként a konzisztencia érdekében
            FROM {$customer_bookings_table} AS cb
            INNER JOIN {$users_table} AS u ON cb.customerId = u.id
            INNER JOIN {$bookings_to_periods_table} AS cb_ep ON cb.id = cb_ep.customerBookingId
            INNER JOIN {$events_periods_table} AS ep ON cb_ep.eventPeriodId = ep.id
            INNER JOIN {$events_table} AS ev ON ep.eventId = ev.id
            WHERE ev.id = %d 
            AND cb.status IN ({$status_placeholders})
            AND ev.status = %s", // Az esemény státusza is legyen 'approved'
            array_merge([$event_or_service_id], $possible_statuses, ['approved'])
        );

        error_log('Amelia Fluent Tagger (get_amelia_bookings_for_event DEBUG): SQL Lekérdezés (ESEMÉNYEKRE): ' . $event_query_sql);
        $event_results = $wpdb->get_results($event_query_sql);

        if ($wpdb->last_error) {
            error_log('Amelia Tagger DB Hiba (get_amelia_bookings_for_event DEBUG - event query): ' . $wpdb->last_error);
        } else {
            error_log('Amelia Fluent Tagger (get_amelia_bookings_for_event DEBUG): Talált ESEMÉNY foglalások száma: ' . count($event_results) . ' az eventId-hez: ' . $event_or_service_id);
            if (!empty($event_results)) {
                $results = array_merge($results, $event_results);
                 error_log('Amelia Fluent Tagger (get_amelia_bookings_for_event DEBUG): Első eseményfoglalás részletei: ' . print_r($event_results[0], true));
            }
        }

        // Ha nem találtunk eseményfoglalást, vagy ha a logika megengedi, hogy szolgáltatás is legyen,
        // akkor próbálkozunk a SZOLGÁLTATÁS (időpontos) foglalások lekérdezésével.
        // A jelenlegi hiba alapján az esemény lekérdezés a relevánsabb.
        // Ha a fenti lekérdezés ad eredményt, akkor valószínűleg az a helyes út.
        // Ha az esemény lekérdezés 0 sort ad, de a felhasználó biztos benne, hogy van foglalása,
        // akkor lehet, hogy mégis a serviceId-s lekérdezés kellene, de más serviceId-vel.

        if (empty($results)) {
             error_log('Amelia Fluent Tagger (get_amelia_bookings_for_event DEBUG): Nem található foglalás sem eseményként, sem szolgáltatásként (a szolgáltatás lekérdezés most kihagyva, ha az esemény lekérdezés volt a cél). Event ID: '.$event_or_service_id);
        }
        
        return $results;
    }

    public static function get_info_sheet_id_for_event($amelia_event_id) {
        if (empty($amelia_event_id)) {
            return null;
        }
        $args = [
            'post_type'      => self::INFO_SHEET_CPT_SLUG,
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => self::AMELIA_EVENT_ID_META_KEY,
                    'value'   => $amelia_event_id,
                    'compare' => '=',
                ],
            ],
            'fields'         => 'ids', 
        ];
        $query = new WP_Query($args);
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        return null;
    }
}
?>
