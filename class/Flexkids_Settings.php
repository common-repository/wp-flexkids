<?php

/**
 * Class Flexkids_Settings
 */
class Flexkids_Settings extends Flexkids_Abstract {
	/**
	 * Flexkids_Settings constructor.
	 *
	 * @param Flexkids_Client|null $client
	 * @param Flexkids_Cache|null $cache
	 */
	public function __construct( Flexkids_Client $client = null, Flexkids_Cache $cache = null ) {
		parent::__construct( $client, $cache );

		add_action( 'init', [ $this, 'Register_Flexkids_Settings' ] );
		if ( is_admin() || defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'admin_menu', [ $this, 'Add_Flexkids_Menu' ] );
		}
	}

	/**
	 * Register_Flexkids_Settings
	 */
	function Register_Flexkids_Settings() {
		// General settings
	    register_setting( 'flexkidshub-settings-group', 'environment' );
		register_setting( 'flexkidshub-settings-group', 'user_api_token' );
		register_setting( 'flexkidshub-settings-group', 'application_user_name' );
		register_setting( 'flexkidshub-settings-group', 'application_user_password', [
			$this,
			'application_user_password_validation'
		] );
		register_setting( 'flexkidshub-settings-group', 'flexkids_cache_leadvalues_delete', [
			$this,
			'flexkids_cache_leadvalues_delete'
		] );

		// Location settings
		register_setting( 'flexkidshub-settings-locations', 'flexkids_cache_childminders' );
		register_setting( 'flexkidshub-settings-locations', 'flexkids_cache_childminders_delete', [
			$this,
			'delete_childminders_cache'
		] );
		register_setting( 'flexkidshub-settings-locations', 'locations_maps', [
			$this,
			'maps_validation'
		] );
	}

	/**
     * Add_Flexkids_Menu
	 *
	 */
	function Add_Flexkids_Menu() {
		add_menu_page( __( 'Flexkids Settings', 'flexkids' ), __('Flexkids'), 'administrator', 'wp-flexkids', [
			$this,
			'Flexkids_Settings_Page'
		], WPF_URL . '/assets/img/icon.png' );

    	add_action( 'init', [ $this, 'Register_Flexkids_Settings' ] );
	}

	/**
     * delete_childminders_cache
     *
	 * @param $input
	 *
	 * @return int
	 */
	public function delete_childminders_cache( $input ) {
	    if ($input == "1") {
	        $this->cache->clearChildmindersAvatars();
        }
	    return 0;
    }

	/**
     * flexkids_cache_leadvalues_delete
     *
	 * @param $input
	 *
	 * @return int
	 */
	public function flexkids_cache_leadvalues_delete( $input ) {
	    if ($input == "1") {
		    delete_transient('leads/values');
		    $this->cache->clearCachedLeadValues();
	    }
	    return 0;
    }

	/**
     * maps_validation
     *
	 * @param $input
	 *
	 * @return false|mixed|string
	 */
	public function maps_validation( $input ) {
		if ( empty( $input ) ) {
			return get_option( 'locations_maps' );
		}

		return json_encode($input);
    }

	/**
	 * application_user_password_validation
	 *
	 * @param $input
	 *
	 * @return string
	 */
	public function application_user_password_validation( $input ) {
		if ( empty( $input ) ) {
			return get_option( 'application_user_password' );
		}

		$client      = new Flexkids_Client();
		$environment = esc_attr( get_option( 'environment' ) );
		$client->setEnvironment( $environment );
		$tokens = $client->authenticate(
			esc_attr( get_option( 'user_api_token' ) ),
			esc_attr( get_option( 'application_user_name' ) ),
			$input
		);

		if ( $tokens instanceof WP_Error ) {
			add_settings_error( 'application_user_password', 'application_user_password', __( 'API User, Username or Password are incorrect' ), 'error' );

			return get_option( 'application_user_password' );
		}
		$client = null;

		return $this->encryptPassword( $input );
	}

	/**
     * encryptPassword
     *
	 * @param $password
	 *
	 * @return string
	 */
	private function encryptPassword( $password ) {
		// Example from http://php.net/manual/en/function.openssl-encrypt.php
		$key            = AUTH_KEY;
		$ivlen          = openssl_cipher_iv_length( $cipher = "AES-128-CBC" );
		$iv             = openssl_random_pseudo_bytes( $ivlen );
		$ciphertext_raw = openssl_encrypt( $password, $cipher, $key, $options = OPENSSL_RAW_DATA, $iv );
		$hmac           = hash_hmac( 'sha256', $ciphertext_raw, $key, $as_binary = true );

		return base64_encode( $iv . $hmac . $ciphertext_raw );
	}

	/**
     * decryptPassword
     *
	 * @param $hashedPassword
     *
	 * @return bool|string
	 */
	public function decryptPassword( $hashedPassword ) {
		if ( empty( $hashedPassword ) ) {
			return '';
		}
		// Example from http://php.net/manual/en/function.openssl-encrypt.php
		$key            = AUTH_KEY;
		$c              = base64_decode( $hashedPassword );
		$ivlen          = openssl_cipher_iv_length( $cipher = "AES-128-CBC" );
		$iv             = substr( $c, 0, $ivlen );
		$hmac           = substr( $c, $ivlen, $sha2len = 32 );
		$ciphertext_raw = substr( $c, $ivlen + $sha2len );
		$password       = openssl_decrypt( $ciphertext_raw, $cipher, $key, $options = OPENSSL_RAW_DATA, $iv );
		$calcmac        = hash_hmac( 'sha256', $ciphertext_raw, $key, $as_binary = true );
		if ( hash_equals( $hmac, $calcmac ) )//PHP 5.6+ timing attack safe comparison
		{
			return $password;
		}

		return false;
	}

	/**
	 * @param $mapId
	 *
	 * @return bool|mixed
	 */
	public function getGoogleMapsType( $mapId ) {
	    $json = get_option( 'locations_maps' );
	    $data = json_decode($json, true);

	    if (is_array($data) && isset($data[$mapId]))
        {
            return $data[$mapId];
        }
	    return false;
    }

	/**
     * createSettingsTabs
     *
	 * @param string $current
	 */
	private function createSettingsTabs( $current = 'general' ) {
		$tabs = [
		        'general' => __( 'General', 'flexkids' ),
                'locations' => __( 'Locations', 'flexkids' ),
		        'documentation' => __( 'Documentation', 'flexkids' ),

        ];
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $tab => $name ) {
			$class = ( $tab == $current ) ? ' nav-tab-active' : '';
			echo "<a class='nav-tab$class' href='?page=wp-flexkids&tab=$tab'>$name</a>";

		}
		echo '</h2>';
	}

	/**
	 * Flexkids_Settings_Page
	 */
	function Flexkids_Settings_Page() {
		?>
        <div class="wrap">
            <h1><?php echo __( 'FlexKIDS Hub Intergration', 'flexkids' ); ?></h1>
			<?php
            if (!wp_using_ext_object_cache())
            {
                ?>
                <div class="notice notice-error">
                    <p><strong><?php echo __( 'Object Cache is not activated, please activate your wordpress cache plugin first!', 'flexkids' ); ?></strong></p>
                </div>
                <?php
            }
			settings_errors();
			if ( isset ( $_GET['tab'] ) ) {
				$this->createSettingsTabs( $_GET['tab'] );
			} else {
				$this->createSettingsTabs( 'general' );
			}
			?>
            <form method="post" action="options.php">
                <?php
                    if(isset ( $_GET['tab'] ) && $_GET['tab'] == 'locations') {
	                    settings_fields( 'flexkidshub-settings-locations' );
	                    do_settings_sections( 'flexkidshub-settings-locations' );
	                    if (is_plugin_active( 'wp-google-map-gold/wp-google-map-gold.php')) {
		                    ?>
                            <table class="form-table">
                                <tr valign="top">
                                    <th scope="row"><?php echo __( 'Cache childminders avatar', 'flexkids' ); ?></th>
                                    <td><input type="checkbox" name="flexkids_cache_childminders"
                                               value="1" <?php echo (esc_attr( get_option( 'flexkids_cache_childminders' ) ) == 1 ? "checked" : ""); ?>/>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php echo __( 'Delete childminders cache', 'flexkids' ); ?></th>
                                    <td><input type="checkbox" name="flexkids_cache_childminders_delete"
                                               value="1"/>
                                    </td>
                                </tr>
			                    <?php

			                    global $wpdb;
			                    $result = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'create_map LIMIT 10' );
			                    foreach ( $result as $map ) {
			                        ?>
                                    <tr valign="top">
                                        <th scope="row"><?php echo $map->map_title; ?></th>
                                        <td><select name="locations_maps[<?php echo $map->map_id; ?>]">
                                                <option value="0">Maak uw keuze</option>
                                                <option value="1" <?php echo( $this->getGoogleMapsType($map->map_id) == 1 ? "selected" : ""); ?>><?php echo __( 'Locations', 'flexkids' ); ?></option>
                                                <option value="3" <?php echo( $this->getGoogleMapsType($map->map_id) == 3 ? "selected" : ""); ?>><?php echo __( 'Locations (Group by caretype)', 'flexkids' ); ?></option>
                                                <option value="2" <?php echo( $this->getGoogleMapsType($map->map_id) == 2 ? "selected" : "" ); ?>><?php echo __( 'Childminders', 'flexkids' ); ?></option>
                                                <option value="4" <?php echo( $this->getGoogleMapsType($map->map_id) == 4 ? "selected" : ""); ?>><?php echo __( 'Childminders & Locations', 'flexkids' ); ?></option>
                                                <option value="5" <?php echo( $this->getGoogleMapsType($map->map_id) == 5 ? "selected" : ""); ?>><?php echo __( 'Childminders & Locations (Group by caretype)', 'flexkids' ); ?></option>
                                            </select></td>
                                    </tr>
                                    <?php

			                    }
			                    ?>
                            </table>
		                    <?php
	                    }
	                    submit_button();
                    }
                    elseif (isset ( $_GET['tab'] ) && $_GET['tab'] == 'documentation')
                    {

                    }
                    else {
	                    settings_fields( 'flexkidshub-settings-group' );
	                    do_settings_sections( 'flexkidshub-settings-group' );
	                    ?>
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php echo __( 'Flexkids API Environment', 'flexkids' ); ?></th>
                                <td><select name="environment">
                                        <option value="production" <?php echo( esc_attr( get_option( 'environment' ) ) == "production" || empty( esc_attr( get_option( 'environment' ) ) ) ? "selected" : "" ); ?>><?php echo __( 'Production', 'flexkids' ); ?></option>
                                        <option value="acceptance" <?php echo( esc_attr( get_option( 'environment' ) ) == "acceptance" ? "selected" : "" ); ?>><?php echo __( 'Acceptance', 'flexkids' ); ?></option>
                                    </select></td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><?php echo __( 'Flexkids API User', 'flexkids' ); ?></th>
                                <td><input type="text" name="user_api_token"
                                           value="<?php echo esc_attr( get_option( 'user_api_token' ) ); ?>"/></td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><?php echo __( 'Connector Username', 'flexkids' ); ?></th>
                                <td><input type="text" name="application_user_name"
                                           value="<?php echo esc_attr( get_option( 'application_user_name' ) ); ?>"/>
                                </td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><?php echo __( 'Connector Password', 'flexkids' ); ?></th>
                                <td><input type="password" name="application_user_password"/></td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><?php echo __( 'Delete sales funnel cache', 'flexkids' ); ?></th>
                                <td><input type="checkbox" name="flexkids_cache_leadvalues_delete"
                                           value="1"/>
                                </td>
                            </tr>
                        </table>
	                    <?php
	                    submit_button();
                    }
                    ?>
            </form>
        </div>
		<?php
	}
}