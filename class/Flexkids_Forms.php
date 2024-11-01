<?php

/**
 * Class Flexkids_Forms
 */
class Flexkids_Forms extends Flexkids_Abstract
{
    /**
     * Flexkids_Forms constructor.
     * @param Flexkids_Client|null $client
     * @param Flexkids_Cache|null $cache
     * @param Flexkids_Settings|null $settings
     */
    public function __construct(Flexkids_Client $client = null, Flexkids_Cache $cache = null, Flexkids_Settings $settings = null)
    {
        parent::__construct($client, $cache, $settings);
        // add_action('caldera_forms_submit_post_process_end', [$this, 'ca_savemessage'], 10, 3);

        /**
         * Register custom fields
         */
        add_filter('caldera_forms_get_field_types', [$this, 'ca_register_custom_fields']);

        /**
         * Register processor
         */
        add_filter('caldera_forms_get_form_processors', function ($processors) {
            $processors['flexkids_processor_send_to_api'] = array(
                'name' => 'Flexkids Salesfunnel API',
                'description' => 'Send Caldera Forms Data To Flexkids Salesfunnel',
                'pre_processor' => [$this, 'flexkids_processor_send_to_api_pre_process']
            );
            return $processors;
        });

        /**
         * Set default country to NL
         */
        add_filter('caldera_forms_phone_js_options', function ($options) {
            $options['initialCountry'] = 'NL';
            return $options;
        });
    }

    /**
     * Process submission
     *
     * @param array $config Processor config
     * @param array $form Form config
     * @param string $process_id Unique process ID for this submission
     *
     * @return void|array
     */
    public function flexkids_processor_send_to_api_pre_process($config, $form, $process_id)
    {
        //get all form data
        $formData = Caldera_Forms::get_submission_data($form);

        $data = new stdClass();

        // non child fields can we add directly to the object.
        foreach ($form['fields'] as $field) {
            if ($field['type'] == 'button') continue;
            if (empty($formData[$field['ID']])) continue;
            if (strpos($field['slug'], '_child_') !== false) continue;
            $data->{$field['slug']} = $formData[$field['ID']];
        }

        // get days from checkboxes and calculate total days for number_of_days
        $this->calculateDays($form['fields'], $formData, $data);

        // add child data to main data object
        $this->addChildToDataIfFound($form['fields'], $formData, 1, $data);
        $this->addChildToDataIfFound($form['fields'], $formData, 2, $data);
        $this->addChildToDataIfFound($form['fields'], $formData, 3, $data);
        $this->calculateChildren($data);

        // authenticate to flexkids hub
        $this->client->authenticate();
        // send lead data to flexkids hub
        $response = $this->client->doRequest('POST', 'leads', $data);

        if (!is_wp_error($response) && isset($response['status']) && $response['status'] == 201) {
            return;
        }

        //find and return error
        if (is_wp_error($response)) {
            $code = $response->get_error_code();
            $error = $response->get_error_message();
            if ($code == 400 && is_array($error)) {
                $errorMessages = "";
                foreach ($error as $errorMessage) {
                    $errorMessages .= $errorMessage['message'] . "<br>";
                }

                $error = $errorMessages;
            }
        } elseif (isset($response['error'])) {
            $error = $response['error'];
        } else {
            $error = 'Something bad happened';
        }
        //returning in pre-precess stops submission processing.
        return array(
            'note' => $error,
            'type' => 'error'
        );
    }

	/**
	 * calculateChildren
	 *
	 * @param $data
	 */
	private function calculateChildren($data)
	{
		// when no number_of_days is filed, we fill it with calculated days.
		if (empty($data->number_of_children) && isset($data->children) && is_array($data->children))
		{
			$data->number_of_children = count($data->children);
		}
	}

	/**
	 * calculateDays
	 *
	 * @param $fields
	 * @param $formData
	 * @param $data
	 */
	private function calculateDays($fields, $formData, $data)
    {
	    $daysFieldId = $this->getFieldID($fields, 'days');
	    if ($daysFieldId !== false)
	    {
	    	$calculateDays = 0;
	    	foreach($formData[$daysFieldId] as $key => $value)
		    {
		    	if ($value == 'monday' || $value == 'tuesday' || $value == 'wednesday' || $value == 'thursday' || $value == 'friday' || $value == 'saturday' || $value == 'sunday')
			    {
			    	$data->{$value} = true;
			    	$calculateDays++;
			    }
		    }

	    	// when no number_of_days is filed, we fill it with calculated days.
	    	if (empty($data->number_of_days))
		    {
			    $data->number_of_days = $calculateDays;
		    }
	    }
    }

	/**
	 * getFieldID
	 *
	 * @param $fields
	 * @param $fieldSlug
	 *
	 * @return bool|string
	 */
	private function getFieldID($fields, $fieldSlug)
    {
	    foreach($fields as $field)
	    {
	    	if ($field['slug'] == $fieldSlug)
		    {
		    	return $field['ID'];
		    }
	    }
	    return false;
    }

	/**
	 * addChildToDataIfFound
	 *
	 * @param $fields
	 * @param $formData
	 * @param $childNr
	 * @param $data
	 *
	 * @return mixed
	 */
	private function addChildToDataIfFound($fields, $formData, $childNr, $data)
    {
        // search if we have child 1 fields.
        $foundChildName = $this->getFieldID($fields, 'name_child_' . $childNr);
        if ($foundChildName !== false) {
            $child = new stdClass();
            // set child name
            $child->name = $formData[$foundChildName];
            // get and set care type id
            $foundChildCareType = $this->getFieldID($fields,'care_type_id_child_' . $childNr);
            if ($foundChildCareType !== false) {
                $child->care_type_id = $formData[$fields[$foundChildCareType]];
            }
            // get and set gender
            $foundChildGender = $this->getFieldID($fields,'gender_child_' . $childNr);
            if ($foundChildGender !== false) {
                $child->gender = $formData[$foundChildGender];
                if ($formData[$foundChildGender] == "na") {
                    $child->is_born = false;
                }
            }
            // get and set care type id
            $foundChildDayOfBirth = $this->getFieldID($fields,'date_of_birth_child_' . $childNr);
            if ($foundChildDayOfBirth !== false) {
                $child->date_of_birth = $formData[$foundChildDayOfBirth];
            }

            $data->children[] = $child;
        }
        return $data;
    }

    /**
     * @param $form
     */
    public function ca_savemessage($form)
    {
        // get global procceed_data variable
        global $processed_data;
        // get current form id
        $form_id = $form['ID'];
        // get values from submitted form
        $values = $processed_data[$form_id];

        $data = new stdClass();

        foreach ($form['fields'] as $field) {
            if ($field['type'] == 'button') continue;
            if (empty($values[$field['ID']])) continue;
            $data->{$field['slug']} = $values[$field['ID']];
        }

        $this->client->authenticate();
        $message = $this->client->doRequest('POST', 'leads', $data);

        if ($message instanceof WP_Error) {

        }
    }

    /**
     * @param $field
     * @return mixed
     */
    public function ca_fill_custom_fields($field)
    {
        $cacheItems = $this->cache->getCacheLeadValues($field['type']);
        foreach ($cacheItems as $item) {
            $field['config']['option'][] = [
                'value' => $item['id'],
                'label' => $item['name']
            ];
        }

        return $field;
    }

    /**
     * @param $fieldtypes
     * @return mixed
     */
    public function ca_register_custom_fields($fieldtypes)
    {
        // Flexkids Lead Sources
        $fieldtypes['flexkids_lead_sources'] = [
            "field" => __('Flexkids Lead Sources', 'flexkids'),
            "description" => __('Flexkids Lead Sources', 'flexkids'),
            'icon' => WPF_URL . '/assets/img/flexkids_logo.png',
            "file" => CFCORE_PATH . "fields/dropdown/field.php",
            "category" => __('Special', 'caldera-forms'),
            "options" => "single",
            //"static" => true,
            "viewer" => [Caldera_Forms::get_instance(), 'filter_options_calculator'],
            "setup" => [
                "template" => CFCORE_PATH . "fields/dropdown/config_template.php",
                "preview" => CFCORE_PATH . "fields/dropdown/preview.php",
                "default" => [],
            ]
        ];
        add_filter('caldera_forms_render_get_field_type-flexkids_lead_sources', [$this, 'ca_fill_custom_fields']);

        // Flexkids Lead Statuses
        $fieldtypes['flexkids_lead_statuses'] = [
            "field" => __('Flexkids Lead Statuses', 'flexkids'),
            "description" => __('Flexkids Lead Statuses', 'flexkids'),
            'icon' => WPF_URL . '/assets/img/flexkids_logo.png',
            "file" => CFCORE_PATH . "fields/dropdown/field.php",
            "category" => __('Special', 'caldera-forms'),
            "options" => "single",
            //"static" => true,
            "viewer" => [Caldera_Forms::get_instance(), 'filter_options_calculator'],
            "setup" => [
                "template" => CFCORE_PATH . "fields/dropdown/config_template.php",
                "preview" => CFCORE_PATH . "fields/dropdown/preview.php",
                "default" => [],
            ]
        ];
        add_filter('caldera_forms_render_get_field_type-flexkids_lead_statuses', [$this, 'ca_fill_custom_fields']);

        // Flexkids Locations
        $fieldtypes['flexkids_lead_locations'] = [
            "field" => __('Flexkids Lead Locations', 'flexkids'),
            "description" => __('Flexkids Lead Locations', 'flexkids'),
            'icon' => WPF_URL . '/assets/img/flexkids_logo.png',
            "file" => CFCORE_PATH . "fields/dropdown/field.php",
            "category" => __('Special', 'caldera-forms'),
            "options" => "single",
            //"static" => true,
            "viewer" => [Caldera_Forms::get_instance(), 'filter_options_calculator'],
            "setup" => [
                "template" => CFCORE_PATH . "fields/dropdown/config_template.php",
                "preview" => CFCORE_PATH . "fields/dropdown/preview.php",
                "default" => [],
            ]
        ];
        add_filter('caldera_forms_render_get_field_type-flexkids_lead_locations', [$this, 'ca_fill_custom_fields']);

        // Flexkids Caretypes
        $fieldtypes['flexkids_lead_care_types'] = [
            "field" => __('Flexkids Lead Care Types', 'flexkids'),
            "description" => __('Flexkids Lead Care Types', 'flexkids'),
            'icon' => WPF_URL . '/assets/img/flexkids_logo.png',
            "file" => CFCORE_PATH . "fields/dropdown/field.php",
            "category" => __('Special', 'caldera-forms'),
            "options" => "single",
            //"static" => true,
            "viewer" => [Caldera_Forms::get_instance(), 'filter_options_calculator'],
            "setup" => [
                "template" => CFCORE_PATH . "fields/dropdown/config_template.php",
                "preview" => CFCORE_PATH . "fields/dropdown/preview.php",
                "default" => [],
            ]
        ];
        add_filter('caldera_forms_render_get_field_type-flexkids_lead_care_types', [$this, 'ca_fill_custom_fields']);

        // Flexkids Languages
        $fieldtypes['flexkids_lead_languages'] = [
            "field" => __('Flexkids Lead Languages', 'flexkids'),
            "description" => __('Flexkids Lead Languages', 'flexkids'),
            'icon' => WPF_URL . '/assets/img/flexkids_logo.png',
            "file" => CFCORE_PATH . "fields/dropdown/field.php",
            "category" => __('Special', 'caldera-forms'),
            "options" => "single",
            //"static" => true,
            "viewer" => [Caldera_Forms::get_instance(), 'filter_options_calculator'],
            "setup" => [
                "template" => CFCORE_PATH . "fields/dropdown/config_template.php",
                "preview" => CFCORE_PATH . "fields/dropdown/preview.php",
                "default" => [],
            ]
        ];
        add_filter('caldera_forms_render_get_field_type-flexkids_lead_languages', [$this, 'ca_fill_custom_fields']);

        // Flexkids Countries
        $fieldtypes['flexkids_lead_countries'] = [
            "field" => __('Flexkids Lead Countries', 'flexkids'),
            "description" => __('Flexkids Lead Countries', 'flexkids'),
            'icon' => WPF_URL . '/assets/img/flexkids_logo.png',
            "file" => CFCORE_PATH . "fields/dropdown/field.php",
            "category" => __('Special', 'caldera-forms'),
            "options" => "single",
            //"static" => true,
            "viewer" => [Caldera_Forms::get_instance(), 'filter_options_calculator'],
            "setup" => [
                "template" => CFCORE_PATH . "fields/dropdown/config_template.php",
                "preview" => CFCORE_PATH . "fields/dropdown/preview.php",
                "default" => [],
            ]
        ];
        add_filter('caldera_forms_render_get_field_type-flexkids_lead_countries', [$this, 'ca_fill_custom_fields']);


        return $fieldtypes;
    }
}