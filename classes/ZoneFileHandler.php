<?php

	abstract class ZoneFileHandler {
		/**
		 * Take a zone file, and return parsed data.
		 *
		 * @param  $domainName Domain name this data represents.
		 * @param  $data zone data.
		 * @return Array representing parsed data.
		 *         The array will contain an 'soa' and 'records' section.
		 *         'soa' contains the details from the SOA record, Keys: ['Nameserver', 'Email', 'Serial', 'Refresh', 'Retry', 'Expire', 'MinTTL']
		 *         'records' is an array of rrtypes and records, eg:
		 *         'A' => ['www' => [['Address' => '10.0.0.1', 'TTL' => '3600'], ['Address' => '10.0.0.2', 'TTL' => '3600']]],
		 *         'MX' => ['' => [['Address' => 'mail', 'TTL' => '3600', 'Priority' => 10], ['Address' => 'mail2', 'TTL' => '3600', 'Priority' => 20]]],
		 *         etc.
		 *         A record rrname or Address may or may not be an FQDN. FQDNs should end with a .
		 */
		abstract public function parseZoneFile($domainName, $data);

		/**
		 * Take parsed zone data and produce a zone file.
		 *
		 * @param  $domainName Domain name this data represents.
		 * @param  $data zone data (Generally in the same format as
		 *               parseZoneFile outputs, except 'records' can optionally
		 *               be a RecordsInfo object)
		 * @return String representing a zone file.
		 */
		abstract public function generateZoneFile($domainName, $data);

		public final static function get($type = 'bind') {
			if (strtolower($type) == 'bind') {
				return new BindZoneFileHandler();
			} else if (strtolower($type) == 'tinydns') {
				return new TinyDNSZoneFileHandler();
			} else {
				throw new Exception('Unknown zone file type: ' . $type);
			}
		}
	}


	class RecordsInfo {
		private $records = [];

		public function removeRecords($rrname, $rrtype) {
			if (!isset($this->records[$rrtype])) { return; }
			if (!isset($this->records[$rrtype][$rrname])) { return; }

			unset($this->records[$rrtype][$rrname]);
		}

		public function addRecord($rrname, $rrtype, $content, $ttl, $priority = null) {
			if (!isset($this->records[$rrtype])) { $this->records[$rrtype] = []; }
			if (!isset($this->records[$rrtype][$rrname])) { $this->records[$rrtype][$rrname] = []; }

			$value = ['Address' => $content, 'TTL' => $ttl];
			if ($priority !== null) { $value['Priority'] = $priority; }

			$this->records[$rrtype][$rrname][] = $value;
		}

		public function mergeFrom(RecordsInfo $recordsInfo) {
			foreach ($recordsInfo->records as $rrtype => $entries) {
				foreach ($entries as $rrname => $records) {
					if (!isset($this->records[$rrtype])) { $this->records[$rrtype] = []; }
					if (!isset($this->records[$rrtype][$rrname])) { $this->records[$rrtype][$rrname] = []; }

					foreach ($records as $record) {
						$this->records[$rrtype][$rrname][] = $record;
					}
				}
			}
		}

		public function getByName($name) {
			$result = [];
			foreach ($this->records as $type => $entries) {
				foreach ($entries as $rname => $records) {
					if ($rname != $name) { continue; }
					foreach ($records as $record) {
						$result[] = ['Name' => $rname,
						             'Type' => $type,
						             'Address' => $record['Address'],
						             'TTL' => $record['TTL'],
						             'Priority' => isset($record['Priority']) ? $record['Priority'] : null,
						            ];
					}
				}
			}
			return $result;
		}

		public function get() {
			return $this->records;
		}

		public function clear() {
			$this->records = [];
		}
	}
