<?php
namespace Serdominus\Delivery;
/**
 * Nova Poshta API Class
 * 
 * @author lis-dev
 * @see https://my.novaposhta.ua/data/API2-200215-1622-28.pdf
 * @see https://github.com/serdominus/nova-poshta-api-2
 * @license MIT
 */
class NovaPoshtaApi2 {
	/**
	 * Key for API NovaPoshta
	 * 
	 * @var string $key
	 * @see https://new.novaposhta.ua/dashboard/settings/developers
	 */
	protected $key;
	
	/**
	 * @var bool $throwErrors Throw exceptions when in response is error
	 */
	protected $throwErrors = FALSE;
	
	/**
	 * @var string $format Format of returned data - array, json, xml
	 */
	protected $format = 'array';
	
	/**
	 * @var string $language Language of response
	 */
	protected $language = 'ru';
	
	/**
	 * @var string $connectionType Connection type (curl | file_get_contents)
	 */
	protected $connectionType = 'curl';
	
	/**
	 * @var string $areas Areas (loaded from file, because there is no so function in NovaPoshta API 2.0)
	 */
	protected $areas;
	
	/**
	 * @var string $model Set current model for methods save(), update(), delete()
	 */
	protected $model = 'Common';
	
	/**
	 * @var string $method Set method of current model
	 */
	protected $method;
	
	/**
	 * @var array $params Set params of current method of current model
	 */
	protected $params;

	/**
	 * Default constructor
	 * 
	 * @param string $key NovaPoshta API key
	 * @param string $language Default Language
	 * @param bool $throwErrors Throw request errors as Exceptions
	 * @param bool $connectionType Connection type (curl | file_get_contents)
	 * @return NovaPoshtaApi2 
	 */
	function __construct($key, $language = 'ru', $throwErrors = FALSE, $connectionType = 'curl') {
		$this->throwErrors = $throwErrors;
		$this
			->setKey($key)
			->setLanguage($language)
			->setConnectionType($connectionType)
			->model('Common');
	}
	
	/**
	 * Setter for key property
	 * 
	 * @param string $key NovaPoshta API key
	 * @return NovaPoshtaApi2
	 */
	function setKey($key) {
		$this->key = $key;
		return $this;
	}
	
	/**
	 * Getter for key property
	 * 
	 * @return string
	 */
	function getKey() {
		return $this->key;
	}
	
	/**
	 * Setter for $connectionType property
	 * 
	 * @param string $connectionType Connection type (curl | file_get_contents)
	 * @return this
	 */
	function setConnectionType($connectionType) {
		$this->connectionType = $connectionType;
		return $this;
	}
	
	/**
	 * Getter for $connectionType property
	 * 
	 * @return string
	 */
	function getConnectionType() {
		return $this->connectionType;
	}
	
	/**
	 * Setter for language property
	 * 
	 * @param string $language
	 * @return NovaPoshtaApi2
	 */
	function setLanguage($language) {
		$this->language = $language;
		return $this;
	}
	
	/**
	 * Getter for language property
	 * 
	 * @return string
	 */
	function getLanguage() {
		return $this->language;
	}
	
	/**
	 * Setter for format property
	 * 
	 * @param string $format Format of returned data by methods (json, xml, array)
	 * @return NovaPoshtaApi2 
	 */
	function setFormat($format) {
		$this->format = $format;
		return $this;
	}
	
	/**
	 * Getter for format property
	 * 
	 * @return string
	 */
	function getFormat() {
		return $this->format;
	}
	
	private function prepare(array $response) {
		$this->checkErrors($response['errors'] ?? array(), !empty($response['success']));
		return match ($this->format) {
			'json' => json_encode($response, JSON_THROW_ON_ERROR),
			'xml' => $this->array2xml($response),
			default => $response,
		};
	}

	private function checkErrors($errors, bool $success): void {
		if ($this->throwErrors && ($errors || !$success)) {
			$messages = array_filter((array) $errors, 'is_scalar');
			throw new \RuntimeException($messages ? implode("\n", $messages) : 'Nova Poshta rejected the request.');
		}
	}

	private function response(array $data = array(), string $error = ''): array {
		return array(
			'success' => $error === '',
			'data' => $data,
			'errors' => $error === '' ? array() : array($error),
			'warnings' => array(),
			'info' => array(),
		);
	}

	private function withArrayFormat(callable $operation): array {
		$format = $this->format;
		$this->format = 'array';
		try {
			return $operation();
		} finally {
			$this->format = $format;
		}
	}

	private function array2xml(array $array): string {
		$xml = new \SimpleXMLElement('<root/>');
		$this->appendXml($xml, $array);
		return $xml->asXML();
	}

	private function appendXml(\SimpleXMLElement $xml, array $array): void {
		foreach ($array as $key => $value) {
			$name = is_int($key) ? 'item' : $key;
			if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/D', $name)) {
				throw new \InvalidArgumentException('Invalid XML element name in Nova Poshta request.');
			}
			if (is_array($value)) {
				$this->appendXml($xml->addChild($name), $value);
			} else {
				$text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
				$xml->addChild($name, htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8'));
			}
		}
	}

	private function request($model, $method, $params = NULL) {
		$xml = $this->format === 'xml';
		$url = $xml ? 'https://api.novaposhta.ua/v2.0/xml/' : 'https://api.novaposhta.ua/v2.0/json/';
		$data = array(
			'apiKey' => $this->key,
			'modelName' => $model,
			'calledMethod' => $method,
			'language' => $this->language,
			'methodProperties' => $params,
		);

		try {
			$post = $xml ? $this->array2xml($data) : json_encode($data, JSON_THROW_ON_ERROR);
			$body = $this->sendRequest($url, $post, $xml ? 'text/xml' : 'application/json');
		} catch (\JsonException | \InvalidArgumentException) {
			return $this->prepare($this->response(error: 'Unable to encode Nova Poshta request.'));
		} catch (\RuntimeException $e) {
			return $this->prepare($this->response(error: $e->getMessage()));
		}

		if ($xml) {
			$result = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
			if ($result === false || !isset($result->success)) {
				return $this->prepare($this->response(error: 'Invalid XML response from Nova Poshta.'));
			}
			$errors = array();
			if (isset($result->errors)) {
				foreach ($result->errors->item as $error) {
					$errors[] = (string) $error;
				}
			}
			$this->checkErrors($errors, in_array((string) $result->success, array('true', '1'), true));
			return $body;
		}

		$result = json_decode($body, true);
		if (!is_array($result) || !isset($result['success'])) {
			return $this->prepare($this->response(error: 'Invalid JSON response from Nova Poshta.'));
		}
		$this->checkErrors($result['errors'] ?? array(), !empty($result['success']));
		return $this->format === 'json' ? $body : $result;
	}

	protected function sendRequest(string $url, string $post, string $contentType): string {
		if ($this->getConnectionType() === 'curl') {
			if (!function_exists('curl_init')) {
				throw new \RuntimeException('cURL is unavailable. Select the file_get_contents transport.');
			}
			$handle = curl_init($url);
			if ($handle === false) {
				throw new \RuntimeException('Unable to initialize Nova Poshta connection.');
			}
			curl_setopt_array($handle, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => array('Content-Type: ' . $contentType),
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $post,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 20,
			));
			$body = curl_exec($handle);
			if ($body === false) {
				throw new \RuntimeException('Nova Poshta connection failed: ' . curl_strerror(curl_errno($handle)));
			}
			$status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		} else {
			$context = stream_context_create(array('http' => array(
				'method' => 'POST',
				'header' => 'Content-Type: ' . $contentType,
				'content' => $post,
				'timeout' => 20,
				'follow_location' => 0,
				'ignore_errors' => true,
			), 'ssl' => array(
				'verify_peer' => true,
				'verify_peer_name' => true,
			)));
			$stream = @fopen($url, 'rb', false, $context);
			if ($stream === false) {
				throw new \RuntimeException('Unable to connect to Nova Poshta.');
			}
			$headers = stream_get_meta_data($stream)['wrapper_data'];
			$body = stream_get_contents($stream);
			$timedOut = stream_get_meta_data($stream)['timed_out'];
			fclose($stream);
			if ($body === false || $timedOut) {
				throw new \RuntimeException('Unable to read the Nova Poshta response.');
			}
			$status = 0;
			foreach ($headers as $header) {
				if (preg_match('/^HTTP\/\S+ (\d{3})\b/', $header, $matches)) {
					$status = (int) $matches[1];
				}
			}
		}
		if ($status < 200 || $status >= 300) {
			throw new \RuntimeException('Nova Poshta returned HTTP ' . $status . '.');
		}
		return $body;
	}

	/**
	 * Set current model and empties method and params properties
	 * 
	 * @param string $model
	 * @return mixed
	 */
	function model($model = '') {
		if ( ! $model) 
			return $this->model;

		$this->model = $model;
		$this->method = NULL;
		$this->params = NULL;
		return $this;
	}
	/**
	 * Set method of current model property and empties params properties
	 * 
	 * @param string $method
	 * @return mixed
	 */
	function method($method = '') {
		if ( ! $method) 
			return $this->method;

		$this->method = $method;
		$this->params = NULL;
		return $this;
	}
	
	/**
	 * Set params of current method/property property
	 * 
	 * @param array $params
	 * @return mixed
	 */
	function params($params) {
		$this->params = $params;
		return $this;
	}
	
	/**
	 * Execute request to NovaPoshta API
	 * 
	 * @return mixed
	 */
	function execute() {
		return $this->request($this->model, $this->method, $this->params);
	}
	
	/**
	 * Get tracking information by track number
	 * Change function documentsTracking new 
	 * @param string $track Track number
	 * @param string $phone Phone number
	 * @return mixed
	 */
	function documentsTracking($track, $phone = '') {
		$document = array('DocumentNumber' => $track);
		if ($phone !== '') {
			$document['Phone'] = $phone;
		}
		return $this->request('TrackingDocument', 'getStatusDocuments', array('Documents' => array($document)));
	}

	/**
	 * Track one shipment and add a Ukrainian pickup notice to each returned tracking record.
	 *
	 * Pass a confirmed storage cutoff for this shipment to enable the expiration notice.
	 * The optional recipient label is display text supplied by the integration.
	 */
	function documentsTrackingWithNotification(
		$track,
		$phone = '',
		?\DateTimeImmutable $storageEndsAt = null,
		?string $recipientLabel = null
	) {
		$result = $this->withArrayFormat(fn () => $this->documentsTracking($track, $phone));
		if (empty($result['success']) || empty($result['data'])) {
			return $this->prepare($result);
		}

		$timezone = new \DateTimeZone('Europe/Kyiv');
		$now = new \DateTimeImmutable('now', $timezone);
		$deadline = $storageEndsAt?->setTimezone($timezone);

		foreach ($result['data'] as &$shipment) {
			$shipment['notification'] = null;
			$status = (string) ($shipment['StatusCode'] ?? '');
			$shipmentDeadline = $deadline;
			if ($shipmentDeadline === null && !empty($shipment['DateReturnCargo'])) {
				try {
					$shipmentDeadline = new \DateTimeImmutable((string) $shipment['DateReturnCargo'], $timezone);
				} catch (\Exception) {
					$shipmentDeadline = null;
				}
			}
			$daysRemaining = $shipmentDeadline === null ? null : (int) $now->setTime(0, 0)
				->diff($shipmentDeadline->setTime(0, 0))->format('%r%a');
			if (!in_array($status, array('7', '8'), true) || ($shipmentDeadline !== null && $shipmentDeadline <= $now)) {
				continue;
			}

			$number = '*' . substr((string) ($shipment['Number'] ?? $track), -4);
			if ($daysRemaining !== null && $daysRemaining <= 3) {
				$when = match ($daysRemaining) {
					0 => 'сьогодні',
					1 => 'завтра',
					default => 'через ' . $daysRemaining . ' дні',
				};
				$shipment['notification'] = array(
					'type' => 'storage_ending',
					'title' => 'Добігає кінця зберігання посилки',
					'message' => 'Зберігання посилки ' . $number . ' завершується ' . $when
						. ' (' . $shipmentDeadline->format('d.m.Y H:i') . ').',
					'trackingNumberMasked' => $number,
					'storageEndsAt' => $shipmentDeadline->format(DATE_ATOM),
					'daysRemaining' => $daysRemaining,
				);
				continue;
			}

			$recipient = trim($recipientLabel ?? '');
			$recipient = $recipient === '' ? 'Отримувач' : 'Отримувач ' . $recipient;
			$shipment['notification'] = array(
				'type' => 'awaiting_pickup',
				'title' => 'Посилка ще чекає на отримувача',
				'message' => $recipient . ' досі не забрав посилку ' . $number . '. Нагадайте йому про неї.',
				'trackingNumberMasked' => $number,
			);
		}
		unset($shipment);
		return $this->prepare($result);
	}

	/**
	 * Get cities of company NovaPoshta
	 * 
	 * @param int $page Num of page
	 * @param string $findByString Find city by russian or ukrainian word
	 * @param string $ref ID of city
	 * @return mixed
	 */
	function getCities($page = 0, $findByString = '', $ref = '') {
		return $this->request('Address', 'getCities', array(
			'Page' => $page,
			'FindByString' => $findByString,
			'Ref' => $ref,
		));
	}
	
	/**
	 * Get warehouses by city
	 * 
	 * @param string $cityRef ID of city
	 * @param int $page
	 * @return mixed
	 */
	function getWarehouses($cityRef, $page = 0) {
		return $this->request('Address', 'getWarehouses', array(
			'CityRef' => $cityRef,
			'Page' => $page,
		));
	}
	
	/**
	 * Get 5 nearest warehouses by array of strings
	 * 
	 * @param array $searchStringArray
	 * @return mixed
	 */
	function findNearestWarehouse($searchStringArray) {
	    $searchStringArray = (array) $searchStringArray;
		return $this->request('Address', 'findNearestWarehouse', array(
			'SearchStringArray' => $searchStringArray,
		));
	}
	
	/**
	 * Get warehouse by city name and warehouse's description
	 * 
	 * @param string $cityRef ID of city
	 * @param string $description Description like in getted by getWarehouses()
	 * @return mixed
	 */
	function getWarehouse($cityRef, $description = '') {
		$result = $this->withArrayFormat(fn () => $this->getWarehouses($cityRef));
		if (empty($result['success'])) {
			return $this->prepare($result);
		}

		$warehouses = $result['data'] ?? array();
		$description = trim((string) $description);
		if ($description === '' && count($warehouses) === 1) {
			return $this->prepare($this->response($warehouses));
		}

		$matches = array();
		if ($description !== '') {
			$search = mb_strtolower($description);
			$suffix = preg_match('/[0-9]$/D', $description) ? '(?![0-9])' : '';
			$pattern = '/' . preg_quote($description, '/') . $suffix . '/iu';
			foreach ($warehouses as $warehouse) {
				$name = $warehouse['Description'] ?? '';
				$nameRu = $warehouse['DescriptionRu'] ?? '';
				if (mb_strtolower($name) === $search || mb_strtolower($nameRu) === $search) {
					return $this->prepare($this->response(array($warehouse)));
				}
				if (preg_match($pattern, $name) || preg_match($pattern, $nameRu)) {
					$matches[] = $warehouse;
				}
			}
		}
		if (count($matches) === 1) {
			return $this->prepare($this->response($matches));
		}

		$error = $matches ? 'Warehouse description is ambiguous' : 'Warehouse was not found';
		return $this->prepare($this->response(error: $error));
	}

	/**
	 * Get streets list by city and/or search string
	 * 
	 * @param string $cityRef ID of city
	 * @param string $findByString
	 * @param int $page
	 * @return mixed
	 */
	function getStreet($cityRef, $findByString = '', $page = 0) {
		return $this->request('Address', 'getStreet', array(
			'FindByString' => $findByString,
			'CityRef' => $cityRef,
			'Page' => $page,
		));
	}
	
	/**
	 * Find current area in list of areas
	 * 
	 * @param array $areas List of arias, getted from file
	 * @param string $findByString Area name
	 * @param string $ref Area Ref ID
	 * @return array
	 */
	protected function findArea(array $areas, $findByString = '', $ref = '') {
		$data = array();
		if ( ! $findByString AND ! $ref)
			return $data;
		// Try to find current region
		foreach ($areas as $key => $area) {
			// Is current area found by string or by key
			$found = $findByString 
				? ((mb_stripos($area['Description'], $findByString) !== FALSE) 
					OR (mb_stripos($area['DescriptionRu'], $findByString) !== FALSE)
					OR (mb_stripos($area['Area'], $findByString) !== FALSE)
					OR (mb_stripos($area['AreaRu'], $findByString) !== FALSE))
				: ($key == $ref);
			if ($found) {
				$area['Ref'] = $key;
				$data[] = $area;
				break;
			}
		}
		return $data;
	}
	
	/**
	 * Get area by name or by ID
	 * 
	 * @param string $findByString Find area by russian or ukrainian word
	 * @param string $ref Get area by ID
	 * @return array|string
	 */
	function getArea($findByString = '', $ref = '') {
		$this->areas ??= require __DIR__ . '/NovaPoshtaApi2Areas.php';
		$data = $this->findArea($this->areas, $findByString, $ref);
		return $this->prepare($this->response($data, $data ? '' : 'Area was not found'));
	}

	/**
	 * Get areas list by city and/or search string
	 *
	 * @param string $ref ID of area
	 * @param int $page
	 * @return mixed
	 */
	function getAreas($ref = '', $page = 0) {
	    return $this->request('Address', 'getAreas', array(
	        'Ref' => $ref,
	        'Page' => $page,
	    ));
	}
	
	/**
	 * Find city from list by name of region
	 * 
	 * @param array $cities Array from query getCities to NovaPoshta 
	 * @param string $areaName
	 * @return array 
	 */
	protected function findCityByRegion($cities, $areaName) {
		$data = array();
		$areaRef = '';
		// Get region id
		$area = $this->withArrayFormat(fn () => $this->getArea($areaName));
		$areaRef = $area['data'][0]['Ref'] ?? '';
		if ($areaRef !== '') {
			foreach ($cities['data'] as $city) {
				if (($city['Area'] ?? '') === $areaRef) {
					$data[] = $city;
				}
			}
		}
		return $data;
	}

	/**
	 * Get a city by name and, when needed, disambiguate by region.
	 *
	 * @return array|string
	 */
	function getCity($cityName, $areaName = '') {
		$result = $this->withArrayFormat(fn () => $this->getCities(0, $cityName));
		if (empty($result['success'])) {
			return $this->prepare($result);
		}

		$data = $result['data'] ?? array();
		if (count($data) > 1) {
			$data = $areaName === '' ? array() : $this->findCityByRegion($result, $areaName);
		}

		$error = $data ? '' : 'City was not found or is ambiguous';
		return $this->prepare($this->response($data, $error));
	}

	/**
	 * Magic method of calling functions (uses for calling Common Model of NovaPoshta API)
	 * 
	 * @param string $method Called method of Common Model
	 * @param array $arguments Array of params
	 */
	function __call($method, $arguments) {
		$common_model_method = array(
			'getTypesOfCounterparties',
			'getBackwardDeliveryCargoTypes',
			'getCargoDescriptionList',
			'getCargoTypes',
			'getDocumentStatuses',
			'getOwnershipFormsList',
			'getPalletsList',
			'getPaymentForms',
			'getTimeIntervals',
			'getServiceTypes',
			'getTiresWheelsList',
			'getTraysList',
			'getTypesOfAlternativePayers',
			'getTypesOfPayers',
			'getTypesOfPayersForRedelivery',
		);
		// Call method of Common model
		if (in_array($method, $common_model_method)) {
			return $this
				->model('Common')
				->method($method)
				->params(NULL)
				->execute();
		}
	}
	
	/**
	 * Delete method of current model
	 * 
	 * @param array $params
	 * @return mixed
	 */
	function delete($params) {
		return $this->request($this->model, 'delete', $params);
	}
	
	/**
	 * Update method of current model
	 * Required params: 
	 * For ContactPerson model: Ref, CounterpartyRef, FirstName (ukr), MiddleName, LastName, Phone (format 0xxxxxxxxx)
	 * For Counterparty model: Ref, CounterpartyProperty (Recipient|Sender), CityRef, CounterpartyType (Organization, PrivatePerson), 
	 * FirstName (or name of organization), MiddleName, LastName, Phone (0xxxxxxxxx), OwnershipForm (if Organization)
	 * 
	 * @param array $params
	 * @return mixed
	 */
	function update($params) {
		return $this->request($this->model, 'update', $params);
	}
	
	/**
	 * Save method of current model
	 * Required params: 
	 * For ContactPerson model (only for Organization API key, for PrivatePerson error will be returned): 
	 *	 CounterpartyRef, FirstName (ukr), MiddleName, LastName, Phone (format 0xxxxxxxxx)
	 * For Counterparty model:
	 *	 CounterpartyProperty (Recipient|Sender), CityRef, CounterpartyType (Organization, PrivatePerson), 
	 *	 FirstName (or name of organization), MiddleName, LastName, Phone (0xxxxxxxxx), OwnershipForm (if Organization)
	 * 
	 * @param array $params
	 * @return mixed
	 */
	function save($params) {
		return $this->request($this->model, 'save', $params);
	}
	
	/**
	 * getCounterparties() function of model Counterparty
	 * 
	 * @param string $counterpartyProperty Type of Counterparty (Sender|Recipient)
	 * @param int $page Page number
	 * @param string $findByString String to search
	 * @param string $cityRef City ID
	 * @return mixed
	 */
	function getCounterparties($counterpartyProperty = 'Recipient', $page = NULL, $findByString = NULL, $cityRef = NULL) {
		// Any param can be skipped
		$params = array();
		$params['CounterpartyProperty'] = $counterpartyProperty ? $counterpartyProperty : 'Recipient';
		$page AND $params['Page'] = $page;
		$findByString AND $params['FindByString'] = $findByString;
		$cityRef AND $params['CityRef'] = $cityRef;
		return $this->request('Counterparty', 'getCounterparties', $params);
	}
	
	/**
	 * cloneLoyaltyCounterpartySender() function of model Counterparty
	 * The counterparty will be not created immediately, you can wait a long time
	 * 
	 * @param string $cityRef City ID
	 * @return mixed
	 */
	function cloneLoyaltyCounterpartySender($cityRef) {
		return $this->request('Counterparty', 'cloneLoyaltyCounterpartySender', array('CityRef' => $cityRef));
	}
	
	/**
	 * getCounterpartyContactPersons() function of model Counterparty
	 * 
	 * @param string $ref Counterparty ref
	 * @return mixed
	 */
	function getCounterpartyContactPersons($ref) {
		return $this->request('Counterparty', 'getCounterpartyContactPersons', array('Ref' => $ref));
	}
	
	/**
	 * getCounterpartyAddresses() function of model Counterparty
	 * 
	 * @param string $ref Counterparty ref
	 * @param int $page
	 * @return mixed
	 */
	function getCounterpartyAddresses($ref, $page = 0) {
		return $this->request('Counterparty', 'getCounterpartyAddresses', array('Ref' => $ref, 'Page' => $page));
	}
	
	/**
	 * getCounterpartyOptions() function of model Counterparty
	 * 
	 * @param string $ref Counterparty ref
	 * @return mixed
	 */
	function getCounterpartyOptions($ref) {
		return $this->request('Counterparty', 'getCounterpartyOptions', array('Ref' => $ref));
	}
	
	/**
	 * getCounterpartyByEDRPOU() function of model Counterparty
	 * 
	 * @param string $edrpou EDRPOU code
	 * @param string $cityRef City ID
	 * @return mixed
	 */
	function getCounterpartyByEDRPOU($edrpou, $cityRef) {
		return $this->request('Counterparty', 'getCounterpartyByEDRPOU', array('EDRPOU' => $edrpou, 'cityRef' => $cityRef));
	}

	/**
	 * Get price of delivery between two cities
	 * 
	 * @param string $citySender City ID
	 * @param string $cityRecipient City ID
	 * @param string $serviceType (DoorsDoors|DoorsWarehouse|WarehouseWarehouse|WarehouseDoors)
	 * @param float $weight
	 * @param float $cost
	 * @return mixed
	 */
	function getDocumentPrice($citySender, $cityRecipient, $serviceType, $weight, $cost) {
		return $this->request('InternetDocument', 'getDocumentPrice', array(
			'CitySender' => $citySender,
			'CityRecipient' => $cityRecipient,
			'ServiceType' => $serviceType,
			'Weight' => $weight,
			'Cost' => $cost,
		));
	}

	/**
	 * Get approximately date of delivery between two cities
	 * 
	 * @param string $citySender City ID
	 * @param string $cityRecipient City ID
	 * @param string $serviceType (DoorsDoors|DoorsWarehouse|WarehouseWarehouse|WarehouseDoors)
	 * @param string $dateTime Date of shipping
	 * @return mixed
	 */
	function getDocumentDeliveryDate($citySender, $cityRecipient, $serviceType, $dateTime) {
		return $this->request('InternetDocument', 'getDocumentDeliveryDate', array(
			'CitySender' => $citySender,
			'CityRecipient' => $cityRecipient,
			'ServiceType' => $serviceType,
			'DateTime' => $dateTime,
		));
	}
	
	/**
	 * Get documents list
	 *
	 * @param array $params List of params
	 * Not required keys:
	 * 'Ref', 'IntDocNumber', 'InfoRegClientBarcodes', 'DeliveryDateTime', 'RecipientDateTime',
	 * 'CreateTime', 'SenderRef', 'RecipientRef', 'WeightFrom', 'WeightTo',
	 * 'CostFrom', 'CostTo', 'SeatsAmountFrom', 'SeatsAmountTo', 'CostOnSiteFrom',
	 * 'CostOnSiteTo', 'StateIds', 'ScanSheetRef', 'DateTime', 'DateTimeFrom',
	 * 'RecipientDateTime', 'isAfterpayment', 'Page', 'OrderField => 
	 *   [
	 *    IntDocNumber, DateTime, Weight, Cost, SeatsAmount, CostOnSite, 
	 *    CreateTime, EstimatedDeliveryDate, StateId, InfoRegClientBarcodes, RecipientDateTime
	 *   ],
	 * 'OrderDirection' => [DESC, ASC], 'ScanSheetRef'
	 * @return mixed
	 */
	function getDocumentList($params = NULL) {
	    return $this->request('InternetDocument', 'getDocumentList', $params ? $params : NULL);
	}
	
	/**
	 * Get document info by ID
	 * 
	 * @param string $ref Document ID
	 * @return mixed
	 */
	function getDocument($ref) {
		return $this->request('InternetDocument', 'getDocument', array(
			'Ref' => $ref,
		));
	}
	
	/**
	 * Generetes report by Document refs
	 * 
	 * @param array $params Params like getDocumentList with requiered keys 
	 *  'Type' => [xls, csv], 'DocumentRefs' => []
	 * @return mixed
	 */
	function generateReport($params) {
	    return $this->request('InternetDocument', 'generateReport', $params);
	}

	/**
	 * Check required fields for new InternetDocument and set defaults
	 * 
	 * @param array & $counterparty Recipient info array
	 * @return void
	 */
	protected function checkInternetDocumentRecipient(array & $counterparty) {
		foreach (array('FirstName', 'LastName', 'Phone') as $field) {
			if (empty($counterparty[$field])) {
				throw new \InvalidArgumentException($field . ' is required for recipient');
			}
		}
		if (empty($counterparty['CityRecipient']) && empty($counterparty['CityRef'])
			&& (empty($counterparty['City']) || empty($counterparty['Region']))) {
			throw new \InvalidArgumentException('City and region or city reference are required for recipient');
		}
		if (empty($counterparty['CounterpartyType'])) {
			$counterparty['CounterpartyType'] = 'PrivatePerson';
		}
	}

	protected function checkInternetDocumentParams(array & $params) {
		foreach (array('Description', 'Weight', 'Cost') as $field) {
			if (empty($params[$field])) {
				throw new \InvalidArgumentException($field . ' is required for new Internet document');
			}
		}
		$defaults = array(
			'DateTime' => date('d.m.Y'),
			'ServiceType' => 'WarehouseWarehouse',
			'PaymentMethod' => 'Cash',
			'PayerType' => 'Recipient',
			'SeatsAmount' => '1',
			'CargoType' => 'Cargo',
			'VolumeGeneral' => '0.0004',
		);
		foreach ($defaults as $field => $default) {
			if (empty($params[$field])) {
				$params[$field] = $default;
			}
		}
	}

	/**
	 * Create Internet Document by 
	 * 
	 * @param array $sender Sender info. 
	 * 	Required:
	 *  For existing sender:
	 *	  'Description' => String (Full name i.e.), 'City' => String (City name)
	 *  For creating: 
	 * 		'FirstName' => String, 'MiddleName' => String,
	 * 		'LastName' => String, 'Phone' => '000xxxxxxx', 'City' => String (City name), 'Region' => String (Region name), 
	 * 		'Warehouse' => String (Description from getWarehouses))
	 * @param array $recipient Recipient info, same like $sender param
	 * @param array $params Additional params of Internet Document
	 *  Required:
	 * 		'Description' => String, 'Weight' => Float, 'Cost' => Float
	 *  Recommended:
	 * 		'VolumeGeneral' => Float (default = 0.004), 'SeatsAmount' => Int (default = 1),
	 * 		'PayerType' => (Sender|Recipient - default), 'PaymentMethod' => (NonCash|Cash - default)
	 * 		'ServiceType' => (DoorsDoors|DoorsWarehouse|WarehouseDoors|WarehouseWarehouse - default) 
	 * 		'CargoType' => String
	 * @param mixed
	 */
	function newInternetDocument($sender, $recipient, $params) {
		$result = $this->withArrayFormat(fn () => $this->resolveInternetDocument($sender, $recipient, $params));
		if (!$result['success']) {
			return $this->prepare($result);
		}
		return $this->model('InternetDocument')->save($result['data']);
	}

	private function resolveInternetDocument(array $sender, array $recipient, array $params): array {
		$this->checkInternetDocumentRecipient($recipient);
		$this->checkInternetDocumentParams($params);

		$sender['CitySender'] = ($sender['CitySender'] ?? '') ?: ($sender['CityRef'] ?? '');
		if (!$sender['CitySender']) {
			if (empty($sender['City'])) {
				return $this->response(error: 'Sender city is required');
			}
			$result = $this->getCity($sender['City'], $sender['Region'] ?? '');
			if (!$result['success']) {
				return $result;
			}
			if (count($result['data']) !== 1 || empty($result['data'][0]['Ref'])) {
				return $this->response(error: 'Sender city could not be identified unambiguously');
			}
			$sender['CitySender'] = $result['data'][0]['Ref'];
		}
		$sender['CityRef'] = $sender['CitySender'];
		if (empty($sender['SenderAddress']) && !empty($sender['Warehouse'])) {
			$result = $this->getWarehouse($sender['CitySender'], $sender['Warehouse']);
			if (!$result['success']) {
				return $result;
			}
			$sender['SenderAddress'] = $result['data'][0]['Ref'] ?? '';
			if (!$sender['SenderAddress']) {
				return $this->response(error: 'Sender warehouse reference was not returned');
			}
		}
		if (empty($sender['Sender'])) {
			$sender['CounterpartyProperty'] = 'Sender';
			$sender['Description'] = ($sender['Description'] ?? '') ?: trim(
				($sender['LastName'] ?? '') . ' ' . ($sender['FirstName'] ?? '') . ' ' . ($sender['MiddleName'] ?? '')
			);
			$result = $this->getCounterparties('Sender', 1, $sender['Description'], $sender['CityRef']);
			if (!$result['success']) {
				return $result;
			}
			$sender['Sender'] = $result['data'][0]['Ref'] ?? '';
			if (!$sender['Sender']) {
				return $this->response(error: 'Sender was not found');
			}
			$result = $this->getCounterpartyContactPersons($sender['Sender']);
			if (!$result['success']) {
				return $result;
			}
			$sender['ContactSender'] = $result['data'][0]['Ref'] ?? '';
			$sender['SendersPhone'] = ($sender['Phone'] ?? '') ?: ($result['data'][0]['Phones'] ?? '');
			if (!$sender['ContactSender']) {
				return $this->response(error: 'Sender contact was not found');
			}
		}

		$recipient['CounterpartyProperty'] = 'Recipient';
		$recipient['RecipientsPhone'] = $recipient['Phone'];
		$recipient['CityRecipient'] = ($recipient['CityRecipient'] ?? '') ?: ($recipient['CityRef'] ?? '');
		if (!$recipient['CityRecipient']) {
			$result = $this->getCity($recipient['City'], $recipient['Region']);
			if (!$result['success']) {
				return $result;
			}
			if (count($result['data']) !== 1 || empty($result['data'][0]['Ref'])) {
				return $this->response(error: 'Recipient city could not be identified unambiguously');
			}
			$recipient['CityRecipient'] = $result['data'][0]['Ref'];
		}
		$recipient['CityRef'] = $recipient['CityRecipient'];
		if (empty($recipient['RecipientAddress'])) {
			$result = $this->getWarehouse($recipient['CityRecipient'], $recipient['Warehouse'] ?? '');
			if (!$result['success']) {
				return $result;
			}
			$recipient['RecipientAddress'] = $result['data'][0]['Ref'] ?? '';
			if (!$recipient['RecipientAddress']) {
				return $this->response(error: 'Recipient warehouse reference was not returned');
			}
		}
		if (empty($recipient['Recipient'])) {
			$result = $this->model('Counterparty')->save($recipient);
			if (!$result['success']) {
				return $result;
			}
			$recipient['Recipient'] = $result['data'][0]['Ref'] ?? '';
			$recipient['ContactRecipient'] = $result['data'][0]['ContactPerson']['data'][0]['Ref'] ?? '';
			if (!$recipient['Recipient'] || !$recipient['ContactRecipient']) {
				return $this->response(error: 'Recipient or recipient contact was not returned');
			}
		}

		return $this->response(array_merge($sender, $recipient, $params));
	}

	/**
	 * Get only link on internet document for printing
	 * 
	 * @param string $method Called method of NovaPoshta API
	 * @param array|string $documentRefs Array of Documents IDs
	 * @param string $type (html_link|pdf_link)
	 * @return mixed
	 */
	protected function printGetLink($method, $documentRefs, $type) {
		$data = 'https://my.novaposhta.ua/orders/' . $method . '/orders[]/'.implode(',', $documentRefs)
				.'/type/'.str_replace('_link', '', $type)
				.'/apiKey/'.$this->key;
		// Return data in same format like NovaPoshta API
		return $this->prepare(
			array(
				'success' => TRUE,
				'data' => array($data),
				'errors' => array(),
				'warnings' => array(),
				'info' => array(),
		));
	}

	/**
	 * printDocument method of InternetDocument model 
	 * 
	 * @param array|string $documentRefs Array of Documents IDs
	 * @param string $type (pdf|html|html_link|pdf_link)
	 * @return mixed
	 */
	function printDocument($documentRefs, $type = 'html') {
		$documentRefs = (array) $documentRefs;
		// If needs link
		if ($type == 'html_link' OR $type == 'pdf_link')
			return $this->printGetLink('printDocument', $documentRefs, $type);
		// If needs data
		return $this->request('InternetDocument', 'printDocument', array('DocumentRefs' => $documentRefs, 'Type' => $type));
	}

	/**
	 * printMarkings method of InternetDocument model 
	 * 
	 * @param array|string $documentRefs Array of Documents IDs
	 * @param string $type (pdf|new_pdf|new_html|old_html|html_link|pdf_link)
	 * @return mixed
	 */
	function printMarkings($documentRefs, $type = 'new_html') {
		$documentRefs = (array) $documentRefs;
		// If needs link
		if ($type == 'html_link' OR $type == 'pdf_link')
			return $this->printGetLink('printMarkings', $documentRefs, $type);
		// If needs data
		return $this->request('InternetDocument', 'printMarkings', array('DocumentRefs' => $documentRefs, 'Type' => $type));
	}
}

// Preserve the old class name for projects that include this file directly.
if (!class_exists('LisDev\Delivery\NovaPoshtaApi2', false)) {
	class_alias(NovaPoshtaApi2::class, 'LisDev\Delivery\NovaPoshtaApi2');
}
