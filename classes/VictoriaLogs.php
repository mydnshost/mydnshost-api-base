<?php

	class VictoriaLogs {
		// Instance of VictoriaLogs.
		private static $instance = null;

		private $config;

		/**
		 * Get the VictoriaLogs instance.
		 *
		 * @return VictoriaLogs instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new VictoriaLogs();
			}

			return self::$instance;
		}

		public function setConfig($config) {
			$this->config = $config;
		}

		/**
		 * Quote a string for use in a LogsQL query.
		 *
		 * @param $str String to quote.
		 * @return Quoted string.
		 */
		public static function quote($str) {
			return '"' . addcslashes($str, "\0..\37\"\\") . '"';
		}

		/**
		 * Convert a VictoriaLogs _time value to RFC 2822.
		 *
		 * @param $time Time from VictoriaLogs (RFC3339 with nanoseconds).
		 * @return Formatted time.
		 */
		public static function formatTime($time) {
			// DateTime only handles microseconds.
			$time = preg_replace('/(\.\d{6})\d+/', '$1', $time);
			return (new DateTime($time))->format('r');
		}

		/**
		 * Run a LogsQL query.
		 *
		 * @param $query LogsQL query.
		 * @return Array of matching log entries.
		 */
		public function query($query) {
			$result = [];
			foreach (explode("\n", $this->request('/select/logsql/query', ['query' => $query])) as $line) {
				if ($line !== '') {
					$result[] = json_decode($line, true);
				}
			}

			return $result;
		}

		/**
		 * Get the unique values of a stream field.
		 *
		 * @param $query LogsQL query to select logs.
		 * @param $field Stream field to get the values of.
		 * @return Array of values.
		 */
		public function streamFieldValues($query, $field) {
			$response = json_decode($this->request('/select/logsql/stream_field_values', ['query' => $query, 'field' => $field]), true);
			return array_column($response['values'] ?? [], 'value');
		}

		private function request($path, $params) {
			$context = stream_context_create(['http' => ['method' => 'POST',
			                                             'header' => 'Content-Type: application/x-www-form-urlencoded',
			                                             'content' => http_build_query($params),
			                                             'timeout' => 10,
			                                             'ignore_errors' => true,
			                                            ]]);

			$response = @file_get_contents(rtrim($this->config['url'], '/') . $path, false, $context);
			if ($response === false) {
				throw new Exception('Unable to query VictoriaLogs.');
			}

			if (!preg_match('#^HTTP/\S+ 200 #', $http_response_header[0] ?? '')) {
				throw new Exception('VictoriaLogs query failed: ' . trim($response));
			}

			return $response;
		}
	}
