<?php
App::uses('RSCAppModel','RSC.Model');
class RSCRecord extends RSCAppModel {
	public $name = 'RSCRecord';
	public $useTable = false;
	protected $_schema = array(
		'id' => array('type' => 'string', 'length' => '25', 'comment' => 'RSC identifier'),
		'zone' => array('type' => 'string', 'length' => '255', 'comment' => 'required to associate record with zone (aka domain name)'),
		'name' => array('type' => 'string', 'length' => '50', 'comment' => 'subdomain or record name'),
		'type' => array('type' => 'string', 'length' => '15', 'comment' => 'A, CNAME, MX, TXT, etc'),
		'data' => array('type' => 'string', 'length' => '100', 'comment' => 'The records value'),
		'ttl' => array('type' => 'integer', 'length' => '8', 'comment' => 'Time To Live'),
		'priority' => array('type' => 'integer', 'length' => '8', 'comment' => 'Priority field for MX records'),
	);
	public $validate = array(
		'name' => array(
			'rule' => ['notBlank'],
			'allowEmpty' => false
		),
		'type' => array(
			'rule' => ['notBlank'],
			'allowEmpty' => false,
		),
		'zone' => array(
			'rule' => ['notBlank'],
			'allowEmpty' => false,
		),
		'data' => array(
			'rule' => ['notBlank'],
			'allowEmpty' => false,
		),
		'priority' => array(
			'rule' => ['notBlank'],
		),
		'ttl' => array(
			'rule' => ['notBlank'],
		),
	);
	
	/**
	* Placeholder for DNS
	*/
	public $DNS = null;
	
	/**
	* Build the DNS object for me from RackSpace object.
	*/
	public function __construct($id = false, $table = null, $ds = null) {
		parent::__construct($id, $table, $ds);
		if ($this->connect()) {
			$this->DNS = $this->RackSpace->DNS();
		}
	}
	
	/**
	* Find function
	* @param string $type
	* @param array $options
	* @return array result
	* 
	* Examples
		$this->RSCRecord->find('all', array(
			'conditions' => array(
				'zone' => 'example.com'
			),
		));
	*/
	public function find($type = 'first', $options = array()){
		if (!$this->DNS) {
			$this->_error('Unable to connect to DNS.');
		}
		$filter = null;
		if (!empty($options['conditions'])) {
			$filter = $this->_stripOutModelAliasInConditions($options['conditions']);
		}
		if (empty($filter['zone'])) {
			$this->_error('Zone field not present. (aka example.com)');
		}
		$Domain = $this->__getDomainObjectByZone($filter['zone']);
		unset($filter['zone']);
		
		$retval = [];
		$records = $Domain->recordList($filter);
		while ($record = $records->next()) {
			$retval[] = [
				$this->alias => [
					'name' => $record->Name(),
					'id' => $record->id,
					'type' => $record->type,
					'data' => $record->data,
					'ttl' => $record->ttl,
					'created' => $record->created,
					'updated' => $record->updated,
					'zone' => $Domain->Name()
				]
			];
		}
		if ($type === 'first' && !empty($retval)) {
			return $retval[0];
		}
		return $retval;
	}
	
	/**
	* Checks to see if a name recordExists
	* @param string $name
	* @param string $zone
	* @param string $type (optional)
	* @return mixed boolean false if doesn't exist, returns array of Record if recordExists
	*/
	public function recordExists($name, $zone, $type = 'CNAME') {
		if (empty($name) || empty($zone)) {
			$this->_error('Zone and name required for exist search');
		}
		return $this->__getRecordByNameZoneType($name, $zone, $type);
	}
	
	/**
	* Find record ID by name and zone
	* @param string $name
	* @param string $zone
	* @param string $type (optional)
	* @return int id or false if not found.
	*/
	public function findIdByNameAndZone($name, $zone, $type = 'CNAME') {
		if (!$this->DNS) {
			$this->_error('Unable to connect to DNS.');
		}
		if (empty($name) || empty($zone)) {
			$this->_error('Zone and name required for exist search');
		}
		if ($record = $this->__getRecordByNameZoneType($name, $zone, $type)) {
			return $record->id;
		}
		return false;
	}
	
	/**
	* Saves and updates a domain
	* @param array $data
	* @param boolean $validate
	* @param $fieldList (ignored)
	* @return mixed array of domain or false if failure
	*/
	public function save($data = null, $validate = true, $fieldList = array()) {
		if (!$this->DNS) {
			$this->_error('Unable to connect to DNS.');
		}
		$this->set($data);
		if ($validate && !$this->validates()) {
			return false;
		}
		if (isset($data[$this->alias])) {
			$data = $data[$this->alias];
		}
		$zone = $data['zone'];
		unset($data['zone']);
		$type = empty($data['type']) ? 'CNAME' : $data['type'];
		$Record = $this->__getRecordByNameZoneType($data['name'], $zone, $type);
		if (!empty($Record)) {
			$Record->update($data);
		} else {  // Create it
			$async = $this->__getDomainObjectByZone($zone)->record($data)->create();
			$async->waitFor('COMPLETED');  // Wait for asyncresponse to complete or error out
			if ($async->status == 'ERROR') {
				$this->_error(!empty($async->error->details) ? $async->error->details : "Unable to create DNS record for {$data['name']} on zone {$zone}.");
			}
			$Record = !empty($async->response->records) ? $async->response->records[0] : null;
		}
		if ($Record) {
			return [
				$this->alias => [
					'id' => $Record->id,
					'name' => $Record->name,
					'zone' => $zone,
					'type' => $Record->type,
					'ttl' => $Record->ttl,
					'data' => $Record->data,
					'created' => $Record->created,
					'updated' => $Record->updated,
				]
			];
		}
		return false;
	}
	
	/**
	* Delete the Domain
	* @param string $name
	* @param string $zone
	* @param string $type (optional)
	* @return boolean success
	*/
	public function delete($name = null, $zone = true, $type = 'CNAME') {  // true is for strict compliance
		if (!$this->DNS) {
			$this->_error('Unable to connect to DNS.');
		}
		if (empty($name) || $zone === true || empty($zone)) {
			$this->_error('zone and name are required to delete a record');
		}
		$Record = $this->__getRecordByNameZoneType($name, $zone, $type);
		if (empty($Record)) {
			$this->_error("$name doesn't exist on $zone DNS.");
		}
		$result = $Record->delete();
		if (!empty($result)) {
			return true;
		}
		return false;
	}
	
	/**
	* Gives me the Domain object return because it's useful.
	* @param string $zone
	* @return RackSpace\Domain object
	*/
	private function __getDomainObjectByZone($zone = null) {
		if (!$this->DNS) {
			$this->_error('Unable to connect to DNS.');
		}
		$domains = $this->DNS->domainList(['name' => $zone]);
		if ($domains->size() == 0) {
			$this->_error("$zone does not exist.");
		}
		return $domains->next();
	}
	
	/**
	* Gives me the Record object return becasue it's useful
	* @param string $name
	* @param string $zone
	* @param string $type (optional) – necessary for filter to function correctly
	* @return RackSpace\Domain\Record object
	*/
	private function __getRecordByNameZoneType($name, $zone, $type = 'CNAME') {
		if (!$this->DNS) {
			$this->_error('Unable to connect to DNS.');
		}
		$records = $this->__getDomainObjectByZone($zone)->recordList(['type' => $type, 'name' => $name]);
		if (empty($records->size()) && $type != 'CNAME') {  // Fall back to look for a CNAME record instead
			$records = $this->__getDomainObjectByZone($zone)->recordList(['type' => 'CNAME', 'name' => $name]);
		}
		return $records->size() ? $records->next() : false;
	}
}
