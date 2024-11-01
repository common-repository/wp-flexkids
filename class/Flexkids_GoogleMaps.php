<?php

/**
 * Class Flexkids_GoogleMaps
 */
class Flexkids_GoogleMaps extends Flexkids_Abstract {
	/**
	 * Flexkids_GoogleMaps constructor.
	 *
	 * @param Flexkids_Client|null $client
	 * @param Flexkids_Cache|null $cache
	 * @param Flexkids_Settings|null $settings
	 */
	public function __construct( Flexkids_Client $client = null, Flexkids_Cache $cache = null, Flexkids_Settings $settings = null ) {
		parent::__construct( $client, $cache, $settings );
		add_filter( 'wpgmp_marker_source', [ $this, 'wpgmp_marker_source' ], 1, 2 );
	}

	/**
	 * wpgmp_marker_source
	 *
	 * @param $markers
	 * @param $map_id
	 *
	 * @return array
	 */
	public function wpgmp_marker_source( $markers, $map_id ) {
		if ( $this->getGoogleMapsType( $map_id ) == 2 ) {
			$childMinders = $this->getChildminders();
			$markers      = array_merge( $markers, $childMinders );
		} elseif ( $this->getGoogleMapsType( $map_id ) == 3 ) {
			$locations = $this->getLocations( true );
			$markers   = array_merge( $markers, $locations );
		} elseif ( $this->getGoogleMapsType( $map_id ) == 4 ) {
			$locations    = $this->getLocations();
			$childMinders = $this->getChildminders();
			$markers      = array_merge( $markers, $locations, $childMinders );
		} elseif ( $this->getGoogleMapsType( $map_id ) == 5 ) {
			$locations    = $this->getLocations( true );
			$childMinders = $this->getChildminders();
			$markers      = array_merge( $markers, $locations, $childMinders );
		} else {
			$locations = $this->getLocations();
			$markers   = array_merge( $markers, $locations );
		}

		return $markers;
	}

	/**
	 * getLocations
	 *
	 * @return array
	 */
	private function getLocations( $groupBy = false ) {
		$markers   = [];
		$locations = $this->cache->getLocations();

		foreach ( $locations as $location ) {
			// if location is not enabled for website we skip this
			if ( $location['on_website'] !== true ) {
				continue;
			}

			if ( $groupBy ) {
				$marker    = $this->createLocationMarker( $location, $groupBy );
				$markers[] = $marker;
			} else {
				// get all lrkp registrations and get the caretype from it
				foreach ( $location['registrations'] as $registration ) {
					$marker    = $this->createLocationMarker( $location, $groupBy, $registration );
					$markers[] = $marker;
				}
			}
		}

		return $markers;
	}

	private function createLocationMarker( $location, $groupBy, $registration = [] ) {
		$marker = [];
		// default information about location
		$marker['id']           = $location['id'];
		$marker['title']        = $location['name'];
		$marker['extra_fields'] = [
			'fax'                 => (!empty($location['fax']) ? $location['fax'] : '')
			,
			'email'               => (!empty($location['email']) ? $location['email'] : '')
			,
			'phone'               => (!empty($location['first_phone_number']) ? $location['first_phone_number'] : '')
			,
			'phone2'              => (!empty($location['secondary_phone_number']) ? $location['secondary_phone_number'] : '')
			,
			'website'             => (!empty($location['website']) ? $location['website'] : '')
			,
			'free_field_1'        => (!empty($location['free_field_1']) ? $location['free_field_1'] : '')
			,
			'free_field_2'        => (!empty($location['free_field_2']) ? $location['free_field_2'] : '')
			,
			'free_field_3'        => (!empty($location['free_field_3']) ? $location['free_field_3'] : '')
			,
			'registration_number' => (!empty($location['registration_number']) ? $location['registration_number'] : '')
		];
		$marker['message']      = $location['free_field_1'];
		$marker['category']     = 'test';

		// information about address
		if (count($location['addresses']) > 0) {
			$marker['address']   = sprintf( '%s %s, %s %s', $location['addresses'][0]['street_name'], $location['addresses'][0]['house_number'], $location['addresses'][0]['zip_code'], $location['addresses'][0]['city'] );
			$marker['latitude']  = $location['addresses'][0]['latitude'];
			$marker['longitude'] = $location['addresses'][0]['longitude'];
		}

		// set all websites properties as extra fields to use them in the infobox!
		foreach ( $location['website_properties'] as $property ) {
			// when website property category is set, we will use that as category
			if ( $property['name'] == 'category' ) {
				$marker['category'] = $property['explanation'];
			}

			// when website property category is set, we will use that as category
			if ( $property['name'] == 'message' ) {
				$marker['message'] = $property['explanation'];
			}

			// when website property pointer_icon is set, we will use that as marker icon
			if ( $property['name'] == 'pointer_icon' ) {
				$marker['icon'] = $property['explanation'];
			}

			$marker['extra_fields'][ $property['name'] ] = $property['explanation'];
		}

		if ( $groupBy ) {
			$careTypes = null;
			foreach ( $location['registrations'] as $registration ) {
				$careType = $this->getCareTypeName( $registration['care_type_id'] );
				if ( $careType !== null ) {
					if ( $careTypes !== null ) {
						$careTypes .= ', ' . $careType;
					} else {
						$careTypes = $careType;
					}
				}
			}
			$marker['extra_fields']['caretype'] = $careTypes;
		} else {
			$careType = $this->getCareTypeName( $registration['care_type_id'] );
			if ( $careType !== null ) {
				$marker['extra_fields']['registration_number']  = $registration['registration_number'];
				$marker['extra_fields']['caretype']             = $careType;
				$marker['title']                                = sprintf( '%s (%s)', $location['name'], $careType );
			}
		}

		return $marker;
	}

	/**
	 * getChildminders
	 *
	 * @return array
	 */
	private function getChildminders() {
		$markers      = [];
		$childminders = $this->cache->getChildminders();
		foreach ( $childminders as $childminder ) {
			$marker = [];
			// default information about location
			$marker['id']           = (!empty($childminder['id']) ? $childminder['id'] : rand());
			$marker['title']        = (!empty($childminder['full_name']) ? $childminder['full_name'] : '');
			$marker['extra_fields'] = [
				'born_on'      => (!empty($childminder['born_on']) ? $childminder['born_on'] : '')
				,
				'initials'     => (!empty($childminder['born_on']) ? $childminder['initials'] : '')
				,
				'first_name'   => (!empty($childminder['born_on']) ? $childminder['first_name'] : '')
				,
				'prefix'       => (!empty($childminder['born_on']) ? $childminder['prefix'] : '')
				,
				'last_name'    => (!empty($childminder['born_on']) ? $childminder['last_name'] : '')
				,
				'free_field_1' => (!empty($childminder['born_on']) ? $childminder['free_field_1'] : '')
				,
				'free_field_2' => (!empty($childminder['born_on']) ? $childminder['free_field_2'] : '')
				,
				'free_field_3' => (!empty($childminder['born_on']) ? $childminder['free_field_3'] : '')
			];
			$marker['message']      = $childminder['free_field_1'];
			$marker['category']      = $childminder['status'];

			// information about address
			if (count($childminder['addresses']) > 0) {
				$marker['address']   = sprintf( '%s %s, %s %s', $childminder['addresses'][0]['street'], $childminder['addresses'][0]['house_number'], $childminder['addresses'][0]['zip_code'], $childminder['addresses'][0]['city'] );
				$marker['latitude']  = $childminder['addresses'][0]['latitude'];
				$marker['longitude'] = $childminder['addresses'][0]['longitude'];
			}

			// set all websites properties as extra fields to use them in the infobox!
			foreach ( $childminder['website_properties'] as $property ) {
				// when website property category is set, we will use that as category
				if ( $property['name'] == 'category' ) {
					$marker['category'] = $property['explanation'];
				}

				// when website property pointer_icon is set, we will use that as marker icon
				if ( $property['name'] == 'pointer_icon' ) {
					$marker['icon'] = $property['explanation'];
				}

				$marker['extra_fields'][ $property['name'] ] = $property['explanation'];
			}

			/*
			 * Set Avatar as marker image
			 */
			if ( isset( $childminder['actions'] ) && isset( $childminder['actions']['avatar'] ) && isset( $childminder['actions']['avatar']['href'] ) ) {
				// get wordpress setting cache childminders avatars
				$cacheAvatar = esc_attr( get_option( 'flexkids_cache_childminders' ) );
				// get cache avatar url of get direct url for avatar
				$imageUrl = ( $cacheAvatar == "1" ? $this->cache->getMediaUrl( 'childminder', $childminder['id'], $childminder['actions']['avatar']['href'] ) : $childminder['actions']['avatar']['href'] );
				// when you use marker_image we must add the image tag because the preview will use an placeholder.
				$image                  = sprintf( '<img class="fc-avatar-img" src="%s" />', $imageUrl );
				$marker['marker_image'] = $image;
				// custom extra field with image for your own image field with custom class.
				$marker['extra_fields']['image'] = $imageUrl;
			}

			// add marker to markers array
			$markers[] = $marker;
		}

		return $markers;
	}

	/**
	 * getGoogleMapsType
	 *
	 * @param $mapId
	 *
	 * @return bool|mixed
	 */
	private function getGoogleMapsType( $mapId ) {
		$json = get_option( 'locations_maps' );
		$data = json_decode( $json, true );

		if ( is_array( $data ) && isset( $data[ $mapId ] ) ) {
			return $data[ $mapId ];
		}

		return false;
	}

	/**
	 * getCareTypeName
	 *
	 * @param $careTypeId
	 *
	 * @return |null
	 */
	private function getCareTypeName( $careTypeId ) {
		$careTypes = $this->cache->getCacheLeadValues( 'flexkids_lead_care_types' );
		foreach ( $careTypes as $care_type ) {
			if ( $care_type['id'] == $careTypeId ) {
				return $care_type['name'];
			}
		}

		return null;
	}
}