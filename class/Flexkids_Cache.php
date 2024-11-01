<?php
/**
 * Created by PhpStorm.
 * User: Stephan
 * Date: 13-2-2019
 * Time: 18:28
 */

class Flexkids_Cache extends Flexkids_Abstract
{
    /**
     * Flexkids_Cache constructor.
     * @param Flexkids_Client|null $client
     * @param Flexkids_Cache|null $cache
     */
    public function __construct(Flexkids_Client $client = null, Flexkids_Cache $cache = null)
    {
        parent::__construct($client, $cache);


    }

    /**
     * @param $key
     * @return bool|mixed
     */
    public function getCacheLeadValues($key)
    {
        $result = wp_cache_get($key);
        if (false === $result)
        {
            $result = $this->cacheLeadValues($key);
        }
        return $result;
    }

    /**
     * @param $key
     * @return bool|mixed
     */
    private function cacheLeadValues($key)
    {
        $this->client->authenticate();
        $results = $this->client->doRequest('GET', 'leads/values');

        if ($results instanceof WP_Error)
        {
        	return false;
        }

        // cache location information
        $demo = $this->cacheLocations('flexkids_lead_locations', false);

        // cache other lead values
        wp_cache_set( 'flexkids_lead_countries', $results['data']['countries'] );
        wp_cache_set( 'flexkids_lead_languages', $results['data']['languages'] );
        wp_cache_set( 'flexkids_lead_care_types', $results['data']['care_types'] );
        wp_cache_set( 'flexkids_lead_statuses', $results['data']['statuses'] );
        wp_cache_set( 'flexkids_lead_sources', $results['data']['sources'] );
	    wp_cache_set( 'flexkids_lead_sources_hidden', $results['data']['sources'] );
        return wp_cache_get($key);
    }

	/**
	 * clearCachedLeadValues
	 */
	public function clearCachedLeadValues()
	{
		wp_cache_delete( 'flexkids_lead_locations' );
		wp_cache_delete( 'flexkids_lead_countries' );
		wp_cache_delete( 'flexkids_lead_languages' );
		wp_cache_delete( 'flexkids_lead_care_types' );
		wp_cache_delete( 'flexkids_lead_statuses' );
		wp_cache_delete( 'flexkids_lead_sources' );
		wp_cache_delete( 'flexkids_lead_sources_hidden' );
	}

	/**
	 * getLocations
	 *
	 * @param $key
	 * @return bool|mixed
	 */
	public function getLocations()
	{
		$result = wp_cache_get('flexkids_locations');
		if (false === $result)
		{
			$result = $this->cacheLocations('flexkids_locations');
		}
		return $result;
	}

	/**
	 * cacheLocations
	 *
	 * @param $key
	 * @return bool|mixed
	 */
	private function cacheLocations($key, $expand = true)
	{
		$this->client->authenticate();
		$results = $this->client->doRequest('GET', 'locations?' . urlencode('search[on_website]') . '=true' . ($expand ? '&expand=addresses,website_properties,registrations' : '') . '&limit=1000');

		wp_cache_set( $key, $results['data'] );
		return wp_cache_get($key);

	}

	/**
	 * getChildminders
	 *
	 * @param $key
	 * @return bool|mixed
	 */
	public function getChildminders()
	{
		$result = wp_cache_get('flexkids_childminders');
		if (false === $result)
		{
			$result = $this->cacheChildminders('flexkids_childminders');
		}
		return $result;
	}

	/**
	 * cacheAllEndpoints
	 */
	public function cacheAllEndpoints()
	{
		$this->getLocations();
		$this->getChildminders();
		$this->cacheLeadValues('flexkids_lead_locations');
	}

	/**
	 * cacheChildminders
	 *
	 * @param $key
	 * @return bool|mixed
	 */
	private function cacheChildminders($key)
	{
		$this->client->authenticate();
		$results = $this->client->doRequest('GET', 'childminders?expand=addresses,website_properties&limit=1000');

		wp_cache_set( 'flexkids_childminders', $results['data'] );
		return wp_cache_get($key);

	}

	/**
	 * clearChildmindersAvatars
	 */
	public function clearChildmindersAvatars()
	{
		$childMinders = $this->cacheChildminders('flexkids_childminders');
		foreach($childMinders as $childminder) {
			if ( isset( $childminder['actions'] ) && isset( $childminder['actions']['avatar'] ) && isset( $childminder['actions']['avatar']['href'] ) ) {
				$this->getMediaUrl( 'childminder', $childminder['id'], $childminder['actions']['avatar']['href'], true );
			}
		}
	}

	/**
	 * wp_get_attachment_by_post_name
	 *
	 * @param $post_name
	 *
	 * @return bool
	 */
	private function wp_get_attachment_by_post_name( $post_name ) {
		$args = array(
			'posts_per_page' => 1,
			'post_type'      => 'attachment',
			'name'           => trim( $post_name ),
		);

		$get_attachment = new WP_Query( $args );

		if ( ! $get_attachment || ! isset( $get_attachment->posts, $get_attachment->posts[0] ) ) {
			return false;
		}

		return $get_attachment->posts[0];
	}

	/**
	 * getMediaUrl
	 *
	 * @param $type
	 * @param $typeId
	 * @param $url
	 *
	 * @return bool|false|string
	 */
	public function getMediaUrl( $type, $typeId, $url, $forceDownload = false ) {
		$postName   = sprintf( '%s-%s', $type, $typeId );
		$localImage = $this->wp_get_attachment_by_post_name( $postName );
		if ( $localImage !== false ) {
			// if image older then 1 day, delete from media lib and download fresh image
			if ( $forceDownload === true || strtotime( $localImage->post_modified ) < strtotime( '-1 days' ) ) {
				if ( wp_delete_attachment( $localImage->ID, true ) ) {
					$imageUrl = $this->downloadImage( $type, $typeId, $url );

					return $imageUrl;
				}
			}

			return wp_get_attachment_url( $localImage->ID );
		}
		// Image not found, download image and add to media lib
		$imageUrl = $this->downloadImage( $type, $typeId, $url );

		return $imageUrl;
	}

	/**
	 * downloadImage
	 *
	 * @param $type
	 * @param $typeId
	 * @param $url
	 *
	 * @return bool
	 */
	private function downloadImage( $type, $typeId, $url ) {
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		$timeout_seconds = 5;

		$postName = sprintf( '%s-%s', $type, $typeId );

		// Download file to temp dir
		$temp_file = download_url( $url, $timeout_seconds );

		if ( ! is_wp_error( $temp_file ) ) {

			$fileExt = ( mime_content_type( $temp_file ) == 'image/png' ? 'png' : 'jpg' );
			// Array based on $_FILE as seen in PHP file uploads
			$file = array(
				'name'     => sprintf( '%s-%s.%s', $type, $typeId, $fileExt ),
				'type'     => mime_content_type( $temp_file ),
				'tmp_name' => $temp_file,
				'error'    => 0,
				'size'     => filesize( $temp_file ),
			);

			$overrides = array(
				'test_form' => false,
				'test_size' => true,
			);

			// Move the temporary file into the uploads directory
			$results = wp_handle_sideload( $file, $overrides );

			if ( ! empty( $results['error'] ) ) {
				return false;
			} else {

				$filename = $results['file']; // Full path to the file

				// Prepare an array of post data for the attachment.
				$attachment = array(
					'guid'           => $file['file'],
					'post_mime_type' => $file['type'],
					'post_title'     => $postName,
					'post_content'   => '',
					'post_status'    => 'inherit'
				);

				$attach_id   = wp_insert_attachment( $attachment, $filename, 0 );
				$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
				wp_update_attachment_metadata( $attach_id, $attach_data );

				return $results['url'];
			}
		}
	}

}