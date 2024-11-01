<?php

/**
 * Class Flexkids_Abstract
 */
class Flexkids_Abstract
{
	/**
	 * @var Flexkids_Client|null
	 */
	protected $client = null;

	/**
	 * @var Flexkids_Cache|null
	 */
	protected $cache = null;

	/**
	 * @var Flexkids_Settings|null
	 */
	protected $settings = null;

	/**
	 * Flexkids_Abstract constructor.
	 *
	 * @param Flexkids_Client|null $client
	 * @param Flexkids_Cache|null $cache
	 * @param Flexkids_Settings|null $settings
	 */
	public function __construct(Flexkids_Client $client = null, Flexkids_Cache $cache = null, Flexkids_Settings $settings = null)
    {
        $this->client = $client;
        $this->cache = $cache;
	    $this->settings = $settings;
    }

}