<?php
/**
 * Plugin Name: Amelia FluentCRM Tagger
 * Description: Automatikusan FluentCRM tageket ad a felhasználókhoz az Amelia eseményfoglalásaik alapján, az esemény előtt vagy után meghatározott idővel.
 * Version: 1.2.8
 * Author: Teszáry Péter
 * Author URI: https://peterteszary.com
 * Text Domain: amelia-fluent-tagger
 * Domain Path: /languages
 * Requires PHP: 7.2
 * Requires at least: 5.2
 */

if (!defined('ABSPATH')) {
    exit; // Közvetlen hozzáférés tiltása
}

define('AFT_PLUGIN_VERSION', '1.2.8');
define('AFT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AFT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AFT_PLUGIN_FILE', __FILE__);
define('AFT_CRON_HOOK', 'aft_daily_tagging_event'); 
define('AFT_RULES_OPTION_NAME', 'aft_tagging_rules'); 

/**
 * Egyéni cron intervallumok hozzáadása.
 */
add_filter( 'cron_schedules', 'aft_add_custom_cron_intervals' );
function aft_add_custom_cron_intervals( $schedules ) {
    // Hozzáadja a 15 perces intervallumot, ha még nem létezik
    if (!isset($schedules['every_fifteen_minutes'])) {
        $schedules['every_fifteen_minutes'] = array(
            'interval' => 900, // 15 perc * 60 másodperc
            'display'  => esc_html__( 'Every Fifteen Minutes', 'amelia-fluent-tagger' ),
        );
    }
    return $schedules;
}

/**
 * A fő bővítményosztály.
 */
final class Amelia_Fluent_Tagger_Core {

    private static $instance;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
        $this->init_modules();
    }

    private function load_dependencies() {
        require_once AFT_PLUGIN_DIR . 'admin/class-aft-admin.php';
        require_once AFT_PLUGIN_DIR . 'includes/class-aft-integrations.php';
        require_once AFT_PLUGIN_DIR . 'includes/class-aft-cron.php'; 
        require_once AFT_PLUGIN_DIR . 'includes/class-aft-shortcodes.php';
    }

    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
    }

    private function init_modules() {
        Amelia_Fluent_Tagger_Admin::get_instance();
        Amelia_Fluent_Tagger_Cron::get_instance(); 
        Amelia_Fluent_Tagger_Shortcodes::get_instance();
    }

    public function load_textdomain() {
        load_plugin_textdomain('amelia-fluent-tagger', false, dirname(plugin_basename(AFT_PLUGIN_FILE)) . '/languages');
    }

    public static function activate_plugin() {
        error_log('Amelia Fluent Tagger: activate_plugin futtatása (v' . AFT_PLUGIN_VERSION . ').');
        
        $cleared_count = wp_clear_scheduled_hook(AFT_CRON_HOOK);
        if ($cleared_count > 0) {
             error_log('Amelia Fluent Tagger: wp_clear_scheduled_hook törölt ' . $cleared_count . ' db "' . AFT_CRON_HOOK . '" eseményt az aktiváláskor.');
        } else {
            error_log('Amelia Fluent Tagger: wp_clear_scheduled_hook nem talált törlendő "' . AFT_CRON_HOOK . '" eseményt az aktiváláskor.');
        }
        
        // Ütemezés 'every_fifteen_minutes' (15 percenkénti) intervallummal.
        $scheduled_15_min = wp_schedule_event(time(), 'every_fifteen_minutes', AFT_CRON_HOOK);

        if (false === $scheduled_15_min) {
            error_log('Amelia Fluent Tagger: HIBA - Cron esemény (' . AFT_CRON_HOOK . ') ütemezése SIKERTELEN a "every_fifteen_minutes" intervallummal. Megpróbálkozás "hourly" intervallummal (fallback).');
            // Fallback: Ha a 15 perces sikertelen, megpróbáljuk óránként
            $scheduled_hourly = wp_schedule_event(time(), 'hourly', AFT_CRON_HOOK);
            if (false === $scheduled_hourly) {
                error_log('Amelia Fluent Tagger: HIBA - Cron esemény (' . AFT_CRON_HOOK . ') ütemezése SIKERTELEN az "hourly" intervallummal is.');
            } else {
                error_log('Amelia Fluent Tagger: Cron esemény (' . AFT_CRON_HOOK . ') sikeresen ütemezve óránkénti (hourly) futásra (fallback).');
            }
        } else {
            error_log('Amelia Fluent Tagger: Cron esemény (' . AFT_CRON_HOOK . ') sikeresen ütemezve 15 percenkénti ("every_fifteen_minutes") futásra.');
        }
        
        if (false === get_option(AFT_RULES_OPTION_NAME)) {
            add_option(AFT_RULES_OPTION_NAME, [], '', 'no');
            error_log('Amelia Fluent Tagger: Alapértelmezett "' . AFT_RULES_OPTION_NAME . '" opció létrehozva.');
        } else {
            error_log('Amelia Fluent Tagger: Az "' . AFT_RULES_OPTION_NAME . '" opció már létezett.');
        }
    }

    public static function deactivate_plugin() {
        error_log('Amelia Fluent Tagger: deactivate_plugin futtatása (v' . AFT_PLUGIN_VERSION . ').');
        $cleared_count = wp_clear_scheduled_hook(AFT_CRON_HOOK); 
        if ($cleared_count > 0) {
             error_log('Amelia Fluent Tagger: deactivate_plugin - wp_clear_scheduled_hook törölt ' . $cleared_count . ' db "' . AFT_CRON_HOOK . '" eseményt.');
        } else {
            error_log('Amelia Fluent Tagger: deactivate_plugin - wp_clear_scheduled_hook nem talált törlendő "' . AFT_CRON_HOOK . '" eseményt.');
        }
    }
}

register_activation_hook(AFT_PLUGIN_FILE, ['Amelia_Fluent_Tagger_Core', 'activate_plugin']);
register_deactivation_hook(AFT_PLUGIN_FILE, ['Amelia_Fluent_Tagger_Core', 'deactivate_plugin']);

function aft_run_plugin() {
    return Amelia_Fluent_Tagger_Core::get_instance();
}
add_action('plugins_loaded', 'aft_run_plugin');

?>
