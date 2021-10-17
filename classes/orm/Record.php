<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;
use shanemcc\phpdb\DB;

class Record extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain_id' => NULL,
	                             'remote_domain_id' => NULL,
	                             'name' => NULL,
	                             'type' => NULL,
	                             'content' => NULL,
	                             'ttl' => '86400',
	                             'priority' => NULL,
	                             'changed_at' => 0,
	                             'changed_by' => NULL,
	                             'disabled' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'records';

	protected static $VALID_RRs = ['A', 'AAAA', 'TXT', 'SRV', 'SOA', 'MX', 'TXT', 'PTR', 'CNAME', 'NS', 'CAA', 'DS', 'SSHFP', 'TLSA', 'RRCLONE'];

	public static function getValidRecordTypes() {
		return Record::$VALID_RRs;
	}

	public static function findDomainForRecord($context, $record) {
		// Remove trailing .
		$record = rtrim($record, '.');

		// Convert the requested domain into an array (eg foo.bar.baz.example.com => [foo, bar, baz, example, com])
		$bits = explode('.', $record);

		// Domains can have at most 255 characters,
		// subdomains require a . between them leaving a maximum sub-domain count of ~128 levels deep.
		// If someone tries to look for more than this then just ignore them.
		$limit = 128;
		do {
			// Get the domain to look for ([foo, bar, baz, example, com] => foo.bar.baz.example.com)
			$dom = implode('.', $bits);

			// If we have an exact match for this domain, then only return it in the output.
			// There may be a nicer way to do this than asking the DB every time.
			if ($context instanceof DB) {
				$domain = Domain::loadFromDomain($context, $dom);
			} else if ($context instanceof User) {
				$domain = $context->getDomainByName($dom);
			} else {
				$domain = FALSE;
			}
			if ($domain !== FALSE) {
				return $domain;
			}

			// Remove the first entry from the array so that the next time we check the parent domain.
			// eg [foo, bar, baz, example, com] => [bar, baz, example, com] and the next check is bar.baz.example.com
			array_shift($bits);
		} while (!empty($bits) && $limit-- > 0);

		return FALSE;
	}

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setDomainID($value) {
		return $this->setData('domain_id', $value);
	}

	public function setRemoteDomainID($value) {
		return $this->setData('remote_domain_id', $value);
	}

	public function setName($value) {
		return $this->setData('name', do_idn_to_ascii($value));
	}

	public function setType($value) {
		return $this->setData('type', strtoupper($value));
	}

	public function setContent($value) {
		return $this->setData('content', trim($value));
	}

	public function setTTL($value) {
		if (preg_match('#^([0-9]+)([smhdw])$#i', $value, $m)) {
			$value = $m[1];

			if ($m[2] == 'm') {
				$value *= 60;
			} else if ($m[2] == 'h') {
				$value *= 3600;
			} else if ($m[2] == 'd') {
				$value = 86400;
			} else if ($m[2] == 'w') {
				$value *= 604800;
			}
		}

		return $this->setData('ttl', $value);
	}

	public function setPriority($value) {
		if (empty($value) && $value !== 0 && $value !== '0') { $value = NULL; }
		return $this->setData('priority', $value);
	}

	public function setChangedAt($value) {
		return $this->setData('changed_at', $value);
	}

	public function setChangedBy($value) {
		return $this->setData('changed_by', $value);
	}

	public function setDisabled($value) {
		return $this->setData('disabled', parseBool($value) ? 'true' : 'false');
	}

	public function getID() {
		return intvalOrNull($this->getData('id'));
	}

	public function getDomainID() {
		return intvalOrNull($this->getData('domain_id'));
	}

	public function getRemoteDomainID() {
		return intvalOrNull($this->getData('remote_domain_id'));
	}

	public function getName() {
		return do_idn_to_utf8($this->getData('name'));
	}

	public function getNameRaw() {
		return $this->getData('name');
	}

	public function getType() {
		return $this->getData('type');
	}

	public function getContent() {
		return $this->getData('content');
	}

	public function getTTL() {
		return intval($this->getData('ttl'));
	}

	public function getPriority() {
		return intvalOrNull($this->getData('priority'));
	}

	public function getChangedAt() {
		return intval($this->getData('changed_at'));
	}

	public function getChangedBy() {
		return intvalOrNull($this->getData('changed_by'));
	}

	public function isDisabled() {
		return parseBool($this->getData('disabled'));
	}

	public function parseSOA() {
		if ($this->getType() != 'SOA') { return FALSE; }

		$bits = explode(' ', $this->getContent());
		$result = array();

		$result['primaryNS'] = do_idn_to_utf8($bits[0]);
		$result['adminAddress'] = do_idn_to_utf8($bits[1]);
		$result['serial'] = intval($bits[2]);
		$result['refresh'] = intval($bits[3]);
		$result['retry'] = intval($bits[4]);
		$result['expire'] = intval($bits[5]);
		$result['minttl'] = intval($bits[6]);

		return $result;
	}

	public function postLoad() {
		$type = $this->getType();
		$content = $this->getContent();
		if ($type == 'MX' || $type == 'CNAME' || $type == 'PTR' || $type == 'NS') {
			$this->setContent(do_idn_to_utf8($content));
		} else if ($type == 'SRV' || $type == 'RRCLONE') {
			$content = explode(' ', $content);
			$content[count($content) - 1] = do_idn_to_utf8($content[count($content) - 1]);
			$this->setContent(implode(' ', $content));
		}
	}

	public function toArray() {
		$result = parent::toArray();
		foreach (['disabled'] as $k) { if (!isset($result[$k])) { continue; }; $result[$k] = parseBool($this->getData($k)); }
		foreach (['id', 'domain_id', 'remote_domain_id', 'ttl', 'priority', 'changed_at', 'changed_by'] as $k) { if (!isset($result[$k])) { continue; }; $result[$k] = intvalOrNull($this->getData($k)); }
		return $result;
	}

	public function preSave() {
		$type = $this->getType();
		$content = $this->getContent();
		if ($type == 'MX' || $type == 'CNAME' || $type == 'PTR' || $type == 'NS') {
			$this->setContent(do_idn_to_ascii($content));
		} else if ($type == 'SRV' || $type == 'RRCLONE') {
			$content = explode(' ', $content);
			$content[count($content) - 1] = do_idn_to_ascii($content[count($content) - 1]);
			$this->setContent(implode(' ', $content));
		}
	}

	public function updateSOAContent($parsed) {
		if ($this->getType() != 'SOA') { return FALSE; }

		$content = sprintf('%s %s %s %s %s %s %s', do_idn_to_ascii($parsed['primaryNS']), do_idn_to_ascii($parsed['adminAddress']), $parsed['serial'], $parsed['refresh'], $parsed['retry'], $parsed['expire'], $parsed['minttl']);

		$this->setContent($content);
	}

	public function validate() {
		$type = $this->getType();
		$content = trim($this->getContent());

		$testName = $this->getName();
		$testName = preg_replace('#^\*\.#', 'WILDCARD.', $testName);
		$testName = preg_replace('#^\$#', 'VAR', $testName);

		if (!empty($testName) && !Domain::validDomainName($testName)) {
			throw new ValidationFailed('Invalid name: "' . $this->getName() . '"');
		}

		if (!in_array($type, Record::$VALID_RRs)) {
			throw new ValidationFailed('Unknown record type: '. $type);
		}

		if (empty($content)) {
			throw new ValidationFailed('Content must not be empty.');
		}

		if ($type == 'SOA') {
			if (preg_match('#^[^\s]+ [^\s]+ [0-9]+ [0-9]+ [0-9]+ [0-9]+ [0-9]+$#', $content, $m)) {
				$soa = $this->parseSOA();

				$testAddress = substr($soa['primaryNS'], 0, -1);
				if (!Domain::validDomainName($testAddress) || substr($soa['primaryNS'], -1) != '.') {
					throw new ValidationFailed('Primary Nameserver in SOA (' . $soa['primaryNS'] . ') does not look valid.');
				}

				$testAddress = substr($soa['adminAddress'], 0, -1);
				if (!Domain::validDomainName($testAddress) || substr($soa['adminAddress'], -1) != '.') {
					throw new ValidationFailed('Admin address in SOA (' . $soa['adminAddress'] . ') does not look valid.');
				}
			} else {
				throw new ValidationFailed('SOA is invalid.');
			}
		}

		if ($type == 'MX' || $type == 'SRV') {
			if ($this->getPriority() === NULL || $this->getPriority() === '') {
				throw new ValidationFailed('Records of '. $type . ' require a priority.');
			} else if (!preg_match('#^[0-9]+$#', $this->getPriority())) {
				throw new ValidationFailed('Priority must be numeric.');
			}
		} else if (empty($this->getPriority())) {
			$this->setPriority(NULL);
		} else if ($this->getPriority() !== NULL) {
			throw new ValidationFailed('Priority should not be set for records of type: ' . $type);
		}

		if (''.$this->getTTL() === '0' && $type == 'RRCLONE') {
			// Allow specifically as 0.
		} else if (empty($this->getTTL())) {
			$this->setTTL(NULL);
		} else if (!preg_match('#^[0-9]+$#', $this->getTTL())) {
			throw new ValidationFailed('TTL must be numeric.');
		}

		if ($type == 'A' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
			throw new ValidationFailed('Content must be a valid IPv4 Address.');
		}

		if ($type == 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === FALSE) {
			throw new ValidationFailed('Content must be a valid IPv4 Address.');
		}

		if ($type == 'MX' || $type == 'CNAME' || $type == 'PTR' || $type == 'NS') {
			$testName = $content;
			if (substr($testName, -1) == '.') {
				$testName = substr($testName, 0, -1);
			}

			if (filter_var($testName, FILTER_VALIDATE_IP) !== FALSE) {
				throw new ValidationFailed('Content must be a name not an IP.');
			} else if ($type != 'PTR' && !Domain::validDomainName($testName)) {
				throw new ValidationFailed('Content must be a valid FQDN.');
			} else if ($type == 'PTR' && !preg_match('#^[a-z0-9\-_.]*$#i', $testName)) {
				throw new ValidationFailed('Content must be a valid FQDN.');
			} else if ($testName != $content) {
				$this->setContent($testName);
			}
		}

		if ($type == 'RRCLONE') {
			if (preg_match('#^\(([A-Z,*]+)\) ([^\s]+)$#i', $content, $m)) {
				if ($m[2] != ".") {
					$testName = $m[2];
					if (substr($testName, -1) == '.') {
						$testName = substr($testName, 0, -1);
					}

					$checkName = $testName;
					$checkName = preg_replace('#^\*\.#', 'WILDCARD.', $checkName);
					$checkName = preg_replace('#^\$#', 'VAR', $checkName);

					if (!Domain::validDomainName($checkName)) {
						throw new ValidationFailed('Target must be a valid FQDN.');
					} else {
						$this->setContent('(' . $m[1] . ') ' . $testName);
					}
				}
			} else if (preg_match('#^([^\s]+)$#', $content, $m)) {
				if ($m[1] != ".") {
					$testName = $m[1];
					if (substr($testName, -1) == '.') {
						$testName = substr($testName, 0, -1);
					}

					$checkName = $testName;
					$checkName = preg_replace('#^\*\.#', 'WILDCARD.', $checkName);
					$checkName = preg_replace('#^\$#', 'VAR', $checkName);

					if (!Domain::validDomainName($checkName)) {
						throw new ValidationFailed('Target must be a valid FQDN.');
					} else {
						$this->setContent($testName);
					}
				}
			} else {
				throw new ValidationFailed('RRCLONE Record content should have the format: "(<types>) <target>" or "<target>"');
			}
		}

		if ($type == 'SRV') {
			if (preg_match('#^([0-9]+ [0-9]+) ([^\s]+)$#', $content, $m)) {
				if (filter_var($m[2], FILTER_VALIDATE_IP) !== FALSE) {
					throw new ValidationFailed('Target must be a name not an IP.');
				}

				if ($m[2] != ".") {
					$testName = $m[2];
					if (substr($testName, -1) == '.') {
						$testName = substr($testName, 0, -1);
					}

					if (!Domain::validDomainName($testName)) {
						throw new ValidationFailed('Target must be a valid FQDN.');
					} else {
						$this->setContent($m[1] . ' ' . $testName);
					}
				}
			} else {
				throw new ValidationFailed('SRV Record content should have the format: <weight> <port> <target>');
			}
		}

		if ($type == 'CAA') {
			// Test for cloudflare zone imports which suck.
			if (preg_match('#^([0-9])+ ([a-f0-9]+) ([a-f0-9]+)$#i', $content, $m)) {
				$content = $m[1] . ' ' . $this->hex2str($m[2]) . ' "' . $this->hex2str($m[3]) . '"';
				$this->setContent($content);
			}

			if (!preg_match('#^[0-9]+ [a-z0-9]+ "[^\s]+"$#i', $content, $m)) {
				throw new ValidationFailed('CAA Record content should have the format: <flag> <tag> "<value>"');
			}
		}

		if ($type == 'SSHFP') {
			if (!preg_match('#^[0-9]+ [0-9]+ [0-9A-F]+$#i', $content, $m)) {
				throw new ValidationFailed('SSHFP Record content should have the format: <algorithm> <fingerprint type> <fingerprint>');
			}
		}

		if ($type == 'TLSA') {
			if (!preg_match('#^[0-9]+ [0-9]+ [0-9]+ [0-9A-F]+$#i', $content, $m)) {
				throw new ValidationFailed('TLSA Record content should have the format: <usage> <selector> <matching type> <fingerprint>');
			}
		}

		if ($type == 'DS') {
			if (!preg_match('#^[0-9]+ [0-9]+ [0-9]+ [0-9A-F]+$#i', $content, $m)) {
				throw new ValidationFailed('DS Record content should have the format: <keytag> <algorithm> <digesttype> <digest>');
			}
		}

		$domain = $this->getDomainID() !== NULL ? Domain::load($this->getDB(), $this->getDomainID()) : FALSE;
		if ($domain !== FALSE) {
			$nameFilter = $this->getName();
			$nameFilter = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', $nameFilter);

			if ($this->getType() == 'CNAME') {
				if ($nameFilter == '') {
					throw new ValidationFailed('Can\'t have CNAME at domain root: ' . $this->getName());
				}

				// Look for any other records with the same name as us, and fail
				// if they exist.
				foreach ($domain->getRecords($nameFilter) as $r) {
					if ($r->isDisabled() || $this->isDisabled()) { continue; }

					if ($r->getID() != $this->getID()) {
						throw new ValidationFailed('Can\'t have CNAME and other records: ' . $this->getName());
					}
				}
			} else {
				foreach ($domain->getRecords($nameFilter, 'CNAME') as $r) {
					if ($r->isDisabled() || $this->isDisabled() || $r->getID() == $this->getID()) { continue; }

					throw new ValidationFailed('There already exists a CNAME for this record: ' . $nameFilter);
				}
			}

			// TODO: Until this can check for pending records, it's not a useful check.
			if (false && $this->getType() == 'RRCLONE') {
				// Check that the content we want exists.
				$contentFilter = explode(' ', $this->getContent());
				$contentFilter = $contentFilter[count($contentFilter) - 1];
				$contentFilter = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', $contentFilter);

				$exists = false;
				// Check known records.
				// TODO: This doesn't check pending records when adding new
				//       records to a domain
				foreach ($domain->getRecords($contentFilter) as $r) {
					if ($r->isDisabled() || $this->isDisabled() || $r->getID() == $this->getID()) { continue; }

					// TODO: Allow RRCLONE to reference other RRCLONE records eventually.
					if ($r->getType() == 'RRCLONE') { continue; }

					// TODO: This doesn't care if the imported types will
					//       actually import anything.
					$exists = true;
					break;
				}

				if (!$exists) {
					throw new ValidationFailed('Valid content record for RRCLONE does not exist: ' . $contentFilter);
				}
			}
		}

		return TRUE;
	}

	private function hex2str($hex) {
		$str = '';
		for ($i=0; $i<strlen($hex); $i += 2) {
			$str .= chr(hexdec(substr($hex,$i,2)));
		}
		return $str;
	}

	public function __toString() {
		$content = $this->getContent();
		if ($this->getType() == "TXT") {
			$content = '"' . $this->getContent() . '"';
		} else if (in_array($this->getType(), ['CNAME', 'NS', 'MX', 'PTR', 'RRCLONE'])) {
			$content = $this->getContent() . '.';
		} else if ($this->getType() == 'SRV') {
			if (preg_match('#^[0-9]+ [0-9]+ ([^\s]+)$#', $content, $m)) {
				if ($m[1] != ".") {
					$content = $this->getContent() . '.';
				}
			}
		}

		return sprintf('%-30s %7s    IN %7s   %-6s %s', $this->getNameRaw() . '.', $this->getTTL(), $this->getType(), $this->getPriority(), $content);
	}

	public function parseString($str, $domain = '') {
		$bits = preg_split('/\s+/', $str);

		$name = array_shift($bits);

		if ((empty($name) && $name !== "0") || $name == '@') {
			$name = $domain . '.';
		} else if ($name[strlen($name) - 1] != '.') {
			$name = $name . '.' . $domain . '.';
		}
		$len = strlen($domain) + 1;
		$end = substr($name, strlen($name) - $len);

		if ($end == $domain . '.') {
			if ($name != $end) {
				if ($domain == '') {
					$name = substr($name, 0,  strlen($name) - $len);
				} else {
					$name = substr($name, 0,  strlen($name) - $len - 1);
				}
			} else {
				$name = '';
			}
		}

		if ($domain != '') {
			if ($name != '') { $name .= '.'; }
			$name =  $domain;
		}

		$this->setName($name);

		$next = array_shift($bits);
		if (is_numeric($next)) {
			$this->setTTL($next);
			$next = array_shift($bits);
		}

		if (in_array(strtoupper($next), ['IN', 'CS', 'CH', 'HS'])) {
			if ($next != 'IN') { throw new Exception('Unsupported Class: ' . $next); }
			$next = array_shift($bits);
		}

		$type = $next;
		$this->setType($type);

		if ($this->getType() == "MX" || $this->getType() == "SRV") {
			$this->setPriority(array_shift($bits));
		}

		$content = implode(' ', $bits);

		if (in_array($type, ['CNAME', 'NS', 'MX', 'PTR', 'RRCLONE'])) {
			if (endsWith($content, '.')) {
				$content = rtrim($content, '.');
			} else {
				if (!empty($content)) { $content .= '.'; }
				$content .= $domain;
			}
		} else if ($type == 'SRV' && preg_match('#^([0-9]+ [0-9]+) ([^\s]+)$#', $content, $m)) {
			if ($m[2] != '.') {
				if (endsWith($content, '.')) {
					$content = rtrim($content, '.');
				} else {
					if (!empty($content)) { $content .= '.'; }
					$content .= $domain;
				}
			}
		} else if ($type == 'TXT' && preg_match('#^"(.*)"$#', $content, $m)) {
			$content = $m[1];
		}
		$this->setContent($content);

		return $this;
	}
}
