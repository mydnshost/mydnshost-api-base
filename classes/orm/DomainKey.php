<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class DomainKeyUser extends User {
	private $domainkey;

	public function __construct($db, $domainkey) {
		parent::__construct($db);
		$this->domainkey = $domainkey;
	}

	public function save() {
		/* Do Nothing */
	}

	/**
	 * Get a domain searcher that limits us to domains we have access to.
	 */
	protected function getDomainSearch() {
		$domainSearch = Domain::getSearch($this->getDB());
		$domainSearch->where('id', $this->domainkey->getDomainID());
		$domainSearch->order('domain');

		return $domainSearch;
	}

	public function getDomainKey() {
		return $this->domainkey;
	}
}

class DomainKey extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domainkey' => NULL,
	                             'domain_id' => NULL,
	                             'description' => NULL,
	                             'domains_write' => false,
	                             'recordregex' => NULL,
	                             'created' => 0,
	                             'lastused' => 0,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'domainkeys';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setKey($value) {
		if ($value === TRUE) {
			$value = genUUID();
		}
		return $this->setData('domainkey', $value);
	}

	public function setDomainID($value) {
		return $this->setData('domain_id', $value);
	}

	public function setDescription($value) {
		return $this->setData('description', $value);
	}

	public function setDomainWrite($value) {
		return $this->setData('domains_write', parseBool($value) ? 'true' : 'false');
	}

	public function setRecordRegex($value) {
		return $this->setData('recordregex', trim($value));
	}

	public function setLastUsed($value) {
		return $this->setData('lastused', $value);
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function getID() {
		return intvalOrNull($this->getData('id'));
	}

	public function getKey($masked = false) {
		$key = $this->getData('domainkey');
		if ($masked) {
			$bits = explode('-', $key);
			$key = [];
			foreach ($bits as $i => $bit) {
				if ($i === 0) { $key[] = $bit; }
				else if ($i === 4) { $key[] = preg_replace('#.#', '*', substr($bit, 0, 7)) . substr($bit, 7); }
				else { $key[] = preg_replace('#.#', '*', $bit); }
			}

			$key = implode('-', $key);
		}
		return $key;
	}

	public function getDomainID() {
		return intvalOrNull($this->getData('domain_id'));
	}

	public function getDescription() {
		return $this->getData('description');
	}

	public function getDomainWrite() {
		return parseBool($this->getData('domains_write'));
	}

	public function getRecordRegex() {
		return trim(ltrim(rtrim($this->getData('recordregex'), '$/'), '/^'));
	}

	public function hasRecordRegex() {
		$rr = $this->getRecordRegex();
		$allRecordsRegexes = ['', '.*', '.* .*'];
		return !empty($rr) && !in_array($rr, $allRecordsRegexes);
	}

	public function getLastUsed() {
		return intval($this->getData('lastused'));
	}

	public function getCreated() {
		return intval($this->getData('created'));
	}

	public function getDomainKeyUser() {
		 return new DomainKeyUser($this->getDB(), $this);
	}

	public function getDomain() {
		 return Domain::load($this->getDB(), $this->getDomainID());
	}

	public function canEditRecord($rrtype, $name) {
		if (!$this->hasRecordRegex()) { return TRUE; }

		$regex = '/^' . $this->getRecordRegex() . '$/i';
		$test = $rrtype . ' ' . $name;

		return preg_match($regex, $test);
	}

	/**
	 * Load an object from the database based on domain_id AND the key.
	 *
	 * @param $db Database object to load from.
	 * @param $domain domain id to look for
	 * @param $key key to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromDomainKey($db, $domain, $key) {
		$result = static::find($db, ['domain_id' => $domain, 'domainkey' => $key]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['domainkey', 'domain_id', 'description'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}

	public function toArray() {
		$result = parent::toArray();
		foreach (['domains_write'] as $k) { if (!isset($result[$k])) { continue; }; $result[$k] = parseBool($this->getData($k)); }
		foreach (['id', 'domain_id', 'created', 'lastused'] as $k) { if (!isset($result[$k])) { continue; }; $result[$k] = intvalOrNull($this->getData($k)); }
		return $result;
	}
}
