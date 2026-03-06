<?php

use shanemcc\phpdb\DBObject;

class AuditEntry extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'time' => NULL,
	                             'actor' => NULL,
	                             'type' => NULL,
	                             'args' => NULL,
	                             'summary' => NULL,
	                             'extendedsummary' => NULL,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'audit_log';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setTime($value) {
		return $this->setData('time', $value);
	}

	public function setActor($value) {
		return $this->setData('actor', $value);
	}

	public function setType($value) {
		return $this->setData('type', $value);
	}

	public function setArgs($value) {
		return $this->setData('args', is_array($value) ? json_encode($value) : $value);
	}

	public function setSummary($value) {
		return $this->setData('summary', $value);
	}

	public function setExtendedSummary($value) {
		return $this->setData('extendedsummary', $value);
	}

	public function getID() {
		return intvalOrNull($this->getData('id'));
	}

	public function getTime() {
		return intvalOrNull($this->getData('time'));
	}

	public function getActor() {
		return $this->getData('actor');
	}

	public function getType() {
		return $this->getData('type');
	}

	public function getArgs() {
		return json_decode($this->getData('args'), true);
	}

	public function getRawArgs() {
		return $this->getData('args');
	}

	public function getSummary() {
		return $this->getData('summary');
	}

	public function getExtendedSummary() {
		return $this->getData('extendedsummary');
	}

	public function validate() {
		return true;
	}

	public function toArray() {
		$result = parent::toArray();
		$result['id'] = intvalOrNull($this->getData('id'));
		$result['time'] = intvalOrNull($this->getData('time'));
		return $result;
	}
}
