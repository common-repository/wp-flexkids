<?php

/**
 * Class Flexkids_Client
 */
class Flexkids_Client {
	/**
	 * @var string the current access token
	 */
	private $accessToken;

	/**
	 * @var string the current refresh token
	 */
	private $refreshToken;

	/**
	 * @var \Closure function to call after new tokens have been
	 */
	protected $newTokenCallback;

	/**
	 * @var string $apiToken
	 */
	private $apiToken = null;

	/**
	 * @var string $apiUsername
	 */
	private $apiUsername = null;

	/**
	 * @var string $apiPassword
	 */
	private $apiPassword = null;

	/**
	 * Create a FlexKids\Hub\Client instance
	 *
	 * @param string $baseUrl the URL to the FlexKids Hub instance
	 */
	public function __construct( $baseUrl = 'https://hub.flexkids.nl/v2/' ) {
		$this->baseUrl          = $baseUrl;
		$this->newTokenCallback = function ( $tokens ) {
		};
	}

	/**
	 * Set a new callback to be called when new tokens have been acquired.
	 * Use this to update your credential store.
	 *
	 * @param \Closure $callback
	 */
	public function setNewTokensCallback( \Closure $callback ) {
		$this->newTokenCallback = $callback;
	}

	/**
	 * Set a new access and refresh token to be used by this client.
	 *
	 * @param array $tokenData
	 */
	public function setTokens( $tokenData ) {
		if ( ! isset( $tokenData['access_token'] ) || ! isset( $tokenData['refresh_token'] ) ) {
			throw new \InvalidArgumentException(
				"Provide setTokens with an array containing access_token and refresh_token"
			);
		}

		$this->accessToken  = $tokenData['access_token'];
		$this->refreshToken = $tokenData['refresh_token'];
	}

	/**
	 * Return the current set of tokens used by the client
	 * @return array|null
	 */
	public function getTokens() {

		if ( is_null( $this->accessToken ) || is_null( $this->refreshToken ) ) {
			return null;
		}

		return [
			'access_token'  => $this->accessToken,
			'refresh_token' => $this->refreshToken
		];
	}

	/**
	 * Perform a login attempt at FlexKids Hub
	 *
	 * @param string $appKey
	 * @param string $username
	 * @param string $password
	 *
	 * @return array
	 */
	public function authenticate( $appKey = null, $username = null, $password = null ) {
		if ( is_null( $username ) ) {
			$username = $this->apiUsername;
		}

		if ( is_null( $appKey ) ) {
			$appKey = $this->apiToken;
		}

		if ( is_null( $password ) ) {
			$password = $this->apiPassword;
		}

		$tokens = $this->getTokens();
		if ( isset( $tokens['access_token'] ) === false || $this->tokenStillValid( $tokens['access_token'] ) === false ) {
			$result = $this->doRawRequest(
				'POST',
				'auth/login',
				[
					'user_api_token'            => (string) $appKey,
					'application_user_name'     => (string) $username,
					'application_user_password' => (string) $password
				]
			);

			// When authricate error, send WP_Error
			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$tokens = [
				'access_token'  => $result['data']['access_token'],
				'refresh_token' => $result['data']['refresh_token']
			];

			$this->setTokens( $tokens );

			return $tokens;
		}

		return $this->getTokens();
	}

	/**
	 * Perform a request while also renewing the access_token if it expires.
	 *
	 * @param $method
	 * @param $uri
	 * @param array $data
	 *
	 * @return mixed
	 * @throws ClientException
	 */

	public function doRequest( $method, $uri, $data = [] ) {
		$tokens = $this->getTokens();
		if ( is_null( $tokens ) ) {
			throw new \Exception( "I don't have any tokens available. First authenticate." );
		}

		$accessToken = $tokens['access_token'];

		if ( ! $this->tokenStillValid( $accessToken ) ) {
			$result = $this->doRawRequest(
				'POST',
				'auth/refresh',
				[
					'refresh_token' => $tokens['refresh_token']
				]
			);

			$accessToken = $result['data']['access_token'];

			$this->setTokens( [
				'access_token'  => $result['data']['access_token'],
				'refresh_token' => $result['data']['refresh_token']
			] );

			$callback = $this->newTokenCallback;
			$callback( $tokens );
		}

		$headers                  = [];
		$headers['Authorization'] = 'Bearer ' . $accessToken;

		return $this->doRawRequest( $method, $uri, $data, $headers );
	}

	/**
	 * Verify if the given token is still valid by checking the expiration date.
	 *
	 * @param $token
	 *
	 * @return bool
	 */
	private function tokenStillValid( $token ) {
		list ( $header, $data, $signature ) = explode( '.', $token );

		$data = json_decode( base64_decode( $data ), true );

		return ( $data['exp'] >= time() );
	}

	/**
	 * Actually perform a HTTPS request to FlexKids Hub.
	 *
	 * @param string $method HTTP method to use
	 * @param string $uri URI to send
	 * @param array $data will be transformed in the JSON object sent to FlexKids Hub.
	 * @param array $headers headers to send with the request.
	 *
	 * @return array|WP_Error JSON object converted to associative array
	 *
	 * @throws ClientException
	 * @throws ServerException
	 * @throws \Exception
	 */
	private function doRawRequest( $method, $uri, $data = [], $headers = [] ) {
		$notCachedUri            = [ 'auth/login', 'auth/refresh' ];
		$headers['Content-Type'] = 'application/json';
		$url                     = $this->baseUrl . $uri;

		$args = [
			'headers' => $headers
		];

		switch ( strtoupper( $method ) ) {
			case 'POST':
				$args['body'] = json_encode( $data );
				$response     = wp_remote_post( $url, $args );
				break;
			case 'PUT':
			case 'PATCH':
				$args['method'] = 'PUT';
				$args['body']   = json_encode( $data );
				$response       = wp_remote_post( $url, $args );
				break;
			case 'DELETE':
				$args['method'] = 'DELETE';
				$args['body']   = json_encode( $data );
				$response       = wp_remote_post( $url, $args );
				break;
			default:
				// cache only get endpoints
				$response = get_transient( $uri );
				if ( false === $response ) {
					$response = wp_remote_get( $url, $args );
					set_transient( $uri, $response, DAY_IN_SECONDS );
				}
				break;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$result    = @json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code > 399 && $http_code < 499 ) {
			return new WP_Error( $http_code, ( ! empty( $result['errors'] ) ? $result['errors'] : $result['message'] ) );
		} else if ( $http_code > 499 ) {
			return new WP_Error( $http_code, $result['message'] );
		}

		return $result;
	}

	/**
	 * setApiToken
	 *
	 * @param $apiToken
	 *
	 * @return $this
	 */
	public function setApiToken( $apiToken ) {
		$this->apiToken = $apiToken;

		return $this;
	}

	/**
	 * setApiUsername
	 *
	 * @param $username
	 *
	 * @return $this
	 */
	public function setApiUsername( $username ) {
		$this->apiUsername = $username;

		return $this;
	}

	/**
	 * setApiPassword
	 *
	 * @param $password
	 *
	 * @return $this
	 */
	public function setApiPassword( $password ) {
		$this->apiPassword = $password;

		return $this;
	}

	/**
	 * setEnvironment
	 *
	 * @param $environment
	 *
	 * @return $this
	 */
	public function setEnvironment( $environment ) {
		$this->baseUrl = 'https://hub.flexkids.nl/v2/';
		// when acceptance we must go to the uat webservers
		if ( $environment == 'acceptance' ) {
			$this->baseUrl = 'https://hub.uat.flexkids.nl/v2/';

		}

		return $this;
	}
}
