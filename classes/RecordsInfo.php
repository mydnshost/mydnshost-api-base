<?php

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

		public function getByName($name, $types = []) {
			$result = [];
			foreach ($this->records as $type => $entries) {
				foreach ($entries as $rname => $records) {
					if ($rname != $name) { continue; }
					if (!empty($types) && !in_array($type, $types)) { continue; }
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
