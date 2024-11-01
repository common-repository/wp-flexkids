<?php
/**
 * Plugin Name:   WP Flexkids
 * Plugin URI:    https://kc.flexkids.nl
 * Description:   Flexkids Intergratie met formulieren en Google Maps om dichtbijzijnde locatie of gastouder te zoeken
 * Version:       1.0.4
 * Author:        Stèphan Eizinga
 * Author URI:    https://flexkids.nl
 */

if (!class_exists('WP_Flexkids')) {

    class WP_Flexkids
    {
        protected $client = null;
        protected $cache = null;
        protected $settings = null;
        protected $forms = null;
	    protected $googleMaps = null;

        /**
         * Class constructor
         */
        function __construct()
        {
            // Execute local functions
            $this->define_constants();
            $this->includes();

            // Load main classes
            $this->client = new Flexkids_Client();
	        $this->cache = new Flexkids_Cache($this->client);
            $this->settings = new Flexkids_Settings($this->client, $this->cache);

            // check and set settings, when no settings is set we show an error message
            $this->checkAndSetSettings();

            // Load other classes
            $this->forms = new Flexkids_Forms($this->client, $this->cache, $this->settings);
            $this->googleMaps = new Flexkids_GoogleMaps($this->client, $this->cache, $this->settings);

            // Create schedule cron jobs
	        add_action('wp', [$this, 'create_schedule_cron']);
	        add_action('wpflexkids_cron', [$this->cache, 'cacheAllEndpoints']);
        }

        /**
         * Setup plugin constants.
         *
         * @return void
         */
        private function define_constants()
        {
            if (!defined('WPF_VERSION'))
                define('WPF_VERSION', '1.0.2');

            if (!defined('WPF_URL'))
                define('WPF_URL', plugin_dir_url(__FILE__));

            if (!defined('WPF_BASENAME'))
                define('WPF_BASENAME', plugin_basename(__FILE__));

            if (!defined('WPF_PLUGIN_DIR'))
                define('WPF_PLUGIN_DIR', plugin_dir_path(__FILE__));
        }

        /**
         * Include the required files.
         *
         * @return void
         */
	    private function includes()
        {
            require_once(WPF_PLUGIN_DIR . 'class/Flexkids_Abstract.php');
            require_once(WPF_PLUGIN_DIR . 'class/Flexkids_Settings.php');
            require_once(WPF_PLUGIN_DIR . 'class/Flexkids_Cache.php');
	        require_once(WPF_PLUGIN_DIR . 'class/Flexkids_Client.php');
            require_once(WPF_PLUGIN_DIR . 'class/Flexkids_Forms.php');
	        require_once(WPF_PLUGIN_DIR . 'class/Flexkids_GoogleMaps.php');

            load_plugin_textdomain('flexkids', FALSE, basename(WPF_PLUGIN_DIR) . '/languages');
	        load_plugin_textdomain( 'wpgmp_google_map', false, basename( WPF_PLUGIN_DIR ) . '/languages' );
        }

        private function checkAndSetSettings()
        {
        	if (empty(esc_attr(get_option('user_api_token'))) || empty(esc_attr(get_option('application_user_name'))) || empty(esc_attr(get_option('environment'))) || empty(get_option('application_user_password')))
	        {
		        add_action( 'admin_notices', [$this, 'error_notice_no_settings_found'] );
	        }

	        $this->client->setApiToken(esc_attr(get_option('user_api_token')))
	                     ->setApiUsername(esc_attr(get_option('application_user_name')))
	                     ->setApiPassword(esc_attr($this->settings->decryptPassword(get_option('application_user_password'))))
	                     ->setEnvironment(esc_attr(get_option('environment')));
        }

	    public function error_notice_no_settings_found() {
		    ?>
		    <div class="error notice is-dismissable">
			    <p><?php _e( 'You must set first Flexkids Hub Settings before you can use our plugin!', 'flexkids' ); ?></p>
		    </div>
		    <?php
	    }

	    public function create_schedule_cron() {
		    if ( ! wp_next_scheduled( 'wpflexkids_cron' ) )
            {
	            wp_schedule_event(time(), 'daily', 'wpflexkids_cron');
            }
        }
    }

    $GLOBALS['wpfk'] = new WP_Flexkids();
}