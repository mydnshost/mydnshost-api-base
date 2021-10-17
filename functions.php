<?php
	use shanemcc\phpdb\DB;

	// Load Config
	if (!function_exists('getEnvOrDefault')) {
		function getEnvOrDefault($var, $default) {
			$result = getEnv($var);
			return $result === FALSE ? $default : $result;
		}
	}
	require_once(dirname(__FILE__) . '/config.php');

	// Load main classes
	require_once(dirname(__FILE__) . '/vendor/autoload.php');

	// Prep DB
	function checkDBAlive($wait = 0) {
		global $database;

		$errmode = NULL;
		if (DB::get()->getPDO() !== NULL) {
			$errmode = DB::get()->getPDO()->getAttribute(PDO::ATTR_ERRMODE);
			try {
				DB::get()->getPDO()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				DB::get()->getPDO()->query("SELECT 1");
				return TRUE;
			} catch (Exception $e) { }
		}

		$opts = [PDO::ATTR_TIMEOUT => 1, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
		while (true) {
			try {
				$pdo = new PDO(sprintf('%s:host=%s;dbname=%s', $database['type'], $database['server'], $database['database']), $database['username'], $database['password'], $opts);
				break;
			} catch (PDOException $ex) {
				if (stristr($ex->getMessage(), 'Connection timed out') !== FALSE) {
					if ($wait-- > 0) { echo 'Waiting for DB...', "\n"; continue; }
				}

				throw $ex;
			}
		}
		DB::get()->setPDO($pdo);

		if ($errmode !== NULL) { DB::get()->getPDO()->setAttribute(PDO::ATTR_ERRMODE, $errmode); }
	}
	if (!defined('NODB') || parseBool(NODB) !== TRUE) { checkDBAlive(); }

	// Template Engine
	TemplateEngine::get()->setConfig($config['templates'])->setVar('sitename', $config['sitename'])->setVar('siteurl', $config['siteurl']);

	// Mailer
	Mailer::get()->setConfig($config['email']);

	// RabbitMQ
	RabbitMQ::get()->setRabbitMQ($config['rabbitmq']);

	// Mongo
	Mongo::get()->setMongoConfig($config['mongodb']);

	// Event Queue.
	EventQueue::get();

	// Functions
	function recursiveFindFiles($dir) {
		if (!file_exists($dir)) { return; }

		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach($it as $file) {
			if (pathinfo($file, PATHINFO_EXTENSION) == "php") {
				yield $file;
			}
		}
	}

	function intvalOrNull($input) {
		return ($input === null) ? $input : intval($input);
	}

	function parseBool($input) {
		$in = strtolower($input);
		return ($in === true || $in == 'true' || $in == '1' || $in == 'on' || $in == 'yes');
	}

	function genUUID() {
		return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
	}

	function startsWith($haystack, $needle) {
		$length = strlen($needle);
		return (substr($haystack, 0, $length) === $needle);
	}

	function endsWith($haystack, $needle) {
		$length = strlen($needle);
		if ($length == 0) {
			return true;
		}

		return (substr($haystack, -$length) === $needle);
	}

	// From:
	// https://stackoverflow.com/questions/1707801/making-a-temporary-dir-for-unpacking-a-zipfile-into/30010928#30010928
	function tempdir($dir = null, $prefix = 'tmp_', $mode = 0700, $maxAttempts = 1000) {
		/* Use the system temp dir by default. */
		if (is_null($dir)) {
			$dir = sys_get_temp_dir();
		}

		/* Trim trailing slashes from $dir. */
		$dir = rtrim($dir, '/');

		/* If we don't have permission to create a directory, fail, otherwise we will
		 * be stuck in an endless loop.
		 */
		if (!is_dir($dir) || !is_writable($dir)) {
			return false;
		}

		/* Make sure characters in prefix are safe. */
		if (strpbrk($prefix, '\\/:*?"<>|') !== false) {
			return false;
		}

		/* Attempt to create a random directory until it works. Abort if we reach
		 * $maxAttempts. Something screwy could be happening with the filesystem
		 * and our loop could otherwise become endless.
		 */
		$attempts = 0;
		do {
			$path = sprintf('%s/%s%s', $dir, $prefix, mt_rand(100000, mt_getrandmax()));
		} while (!mkdir($path, $mode) && $attempts++ < $maxAttempts);

		return $path;
	}

	function deleteDir($dir) {
		if (empty($dir)) { return FALSE; }

		$files = array_diff(scandir($dir), ['.', '..']);

		foreach ($files as $file) {
			is_dir($dir . '/' . $file) ? delTree($dir . '/' . $file) : unlink($dir . '/' . $file);
		}

		return rmdir($dir);
	}

	function templateToMail($templateEngine, $template) {
		$subject = trim($templateEngine->renderBlock($template, 'subject'));
		$message = $templateEngine->renderBlock($template, 'body');
		$htmlmessage = $templateEngine->renderBlock($template, 'htmlbody');

		return [$subject, $message, $htmlmessage];
	}

	function updatePublicSuffixes($force = false) {
		// TODO: Put this in Redis or Mongo?
		$psl = '/tmp/public_suffix_list.dat';
		$maxAge = (7 * 86400);

		if ($force || !file_exists($psl) || (time() - filemtime($psl)) > $maxAge) {
			$data = file_get_contents('https://publicsuffix.org/list/public_suffix_list.dat');
			if (!empty($data)) {
				$r = Pdp\Rules::fromString($data);
				file_put_contents($psl, serialize($r));
				return TRUE;
			} else {
				// Make the cached file last another hour.
				touch($psl, $time - $maxAge + 3600);
			}
		}

		return FALSE;
	}

	function getPublicSuffixListRules() {
		global $_publicSuffixListRules;

		$psl = '/tmp/public_suffix_list.dat';

		if (updatePublicSuffixes() || !isset($_publicSuffixListRules)) {
			$_publicSuffixListRules = @unserialize(file_get_contents($psl));
			if ($_publicSuffixListRules == NULL) {
				updatePublicSuffixes(true);
				$_publicSuffixListRules = @unserialize(file_get_contents($psl));
			}
		}

		return $_publicSuffixListRules;
	}

	function isPublicSuffix($domain) {
		$publicSuffixList = getPublicSuffixListRules();
		$parser = $publicSuffixList->resolve('test-domain.' . $domain);

		return $parser->suffix()->isKnown() && $parser->suffix()->toString() == $domain;
	}

	function hasValidPublicSuffix($domain) {
		$publicSuffixList = getPublicSuffixListRules();
		$parser = $publicSuffixList->resolve($domain);

		return $parser->suffix()->isKnown();
	}

	function checkSessionHandler() {
		global $config;

		if (isset($config['redis']) && !empty($config['redis'])) {
			ini_set('session.save_handler', 'redis');
			ini_set('session.save_path', 'tcp://' . $config['redis'] . ':' . $config['redisPort'] . '/?prefix=' . urlencode($config['redisSessionPrefix'] . ':'));
		}
	}

	function getSystemRegisterRequireTerms() {
		global $config;
		return $config['register_require_terms'];
	}

	function getSystemMinimumTermsTime() {
		global $config;
		return $config['minimum_terms_time'];
	}

	function getSystemAPIMinimumTermsTime() {
		global $config;
		return $config['api_minimum_terms_time'];
	}

	function getSystemAllowSelfDelete() {
		global $config;
		return $config['self_delete'];
	}

	function getSystemRegisterPermissions() {
		global $config;
		return $config['register_permissions'];
	}

	function getSystemRegisterEnabled() {
		global $config;
		return $config['register_enabled'];
	}

	function getSystemRegisterManualVerify() {
		global $config;
		return $config['register_manual_verify'];
	}

	function getSystemDefaultSOA() {
		global $config;
		return $config['defaultSOA'];
	}

	function getSystemDefaultRecords() {
		global $config;
		return $config['defaultRecords'];
	}

	function getJWTSecret() {
		global $config;
		return $config['jwtsecret'];
	}

	function getSiteName() {
		global $config;
		return $config['sitename'];
	}

	/**
	 * Get header Authorization
	 */
	function getAuthorizationHeader(){
		$headers = null;
		if (isset($_SERVER['Authorization'])) {
			$headers = trim($_SERVER["Authorization"]);
		} else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Nginx or fast CGI
			$headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
		} else if (function_exists('apache_request_headers')) {
			$requestHeaders = apache_request_headers();
			// Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
			$requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
			//print_r($requestHeaders);
			if (isset($requestHeaders['Authorization'])) {
				$headers = trim($requestHeaders['Authorization']);
			}
		}

		return $headers;
	}

	/**
	 * Get access token from header
	 *
	 * https://stackoverflow.com/questions/40582161/how-to-properly-use-bearer-tokens
	 */
	function getBearerToken() {
		$headers = getAuthorizationHeader();
		// HEADER: Get the access token from the header
		if (!empty($headers)) {
			if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
				return $matches[1];
			}
		}

		return null;
	}

	function getDomainLogs($domain) {
		global $config;
		$source = explode(':', $config['domainlogs']['source'], 2);

		if ($source[0] == 'docker' && isset($source[1])) {

			$regex = '(\'| |\()' . preg_quote($domain->getDomain());

			$search = ['docker.hostname' => $source[1], 'message' => ['$regex' => $regex]];
			$options = ['projection' => ['_id' => 0], 'sort' => ['timestamp' => -1], 'limit' => 100];

			Mongo::get()->connect();
			$logs = Mongo::get()->getCollection('dockerlogs')->find($search, $options)->toArray();
			$logs = array_reverse($logs);

			$result = [];
			foreach ($logs as $log) {
				$result[] = ['timestamp' => $log['timestamp'], 'message' => $log['message']];
			}

			return $result;

		} else {
			return FALSE;
		}
	}

	function getInfluxClient() {
		global $config;
		$client = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);
		return $client;
	}

	function getInfluxDB() {
		global $config;
		$database = getInfluxClient()->selectDB($config['influx']['db']);
		if (!$database->exists()) { $database->create(); }
		return $database;
	}

	class bcrypt {
		public static function hash($password, $work_factor = 0) {
			if ($work_factor > 0) { $options = ['cost' => $work_factor]; }
			return password_hash($password, PASSWORD_DEFAULT);
		}

		public static function check($password, $stored_hash, $legacy_handler = NULL) {
			return password_verify($password, $stored_hash);
		}
	}

	function getGlobalQueriesPerServer($type = 'raw', $time = '3600') {
		try {
			$database = getInfluxDB();

			// executing a query will yield a resultset object
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(max("value")) AS value');
			} else {
				$result = $result->select('max("value") AS value');
			}

			$result = $result->from('opcode_query')
			                 ->where(["time > now() - " . $time . "s"])
			                 ->groupby("time(60s)")->groupby("host")
			                 ->getResultSet();

			$stats = [];
			foreach ($result->getSeries() AS $series) {
				$host = $series['tags']['host'];
				$stats[$host] = [];

				foreach ($series['values'] as $val) {
					if ($val[1] === NULL) { continue; }
					$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];

					$stats[$host][] = $stat;
				}
			}

			return ['stats' => $stats];
		} catch (Exception $ex) { }

		return false;
	}

	function getGlobalQueriesPerRRType($type = 'raw', $time = '3600') {
		try {
			$database = getInfluxDB();

			// executing a query will yield a resultset object
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(max("value")) AS value');
			} else {
				$result = $result->select('max("value") AS value');
			}

			$result = $result->from('qtype')
			                 ->where(["time > now() - " . $time . "s"])
			                 ->groupby("time(60s)")->groupby("qtype")
			                 ->getResultSet();

			$stats = [];
			foreach ($result->getSeries() AS $series) {
				$qtype = $series['tags']['qtype'];
				$stats[$qtype] = [];

				$total = 0;
				foreach ($series['values'] as $val) {
					if ($val[1] === NULL) { continue; }
					$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];
					$total += $stat['value'];

					$stats[$qtype][] = $stat;
				}
				if ($total == 0) { unset($stats[$qtype]); }
			}

			return ['stats' => $stats];
		} catch (Exception $ex) { }

		return false;
	}

	function getGlobalQueriesPerZone($type = 'raw', $time = '3600', $zones = []) {
		try {
			$database = getInfluxDB();

			// executing a query will yield a resultset object
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(max("value")) AS value');
			} else {
				$result = $result->select('max("value") AS value');
			}

			$where = ["time > now() - " . $time . "s"];
			if (!empty($zones)) {
				$zoneq = [];
				foreach ($zones as $z) {
					if (Domain::validDomainName($z)) {
						$zoneq[] = "\"zone\" = '" . $z ."'";
					}
				}
				$where[] = "(" . implode(" OR ", $zoneq) . ")";
			}

			$result = $result->from('zone_qtype')
			                 ->where($where)
			                 ->groupby("time(60s)")->groupby("zone")
			                 ->getResultSet();

			$stats = [];
			foreach ($result->getSeries() AS $series) {
				$zone = $series['tags']['zone'];
				$stats[$zone] = [];

				$total = 0;
				foreach ($series['values'] as $val) {
					if ($val[1] === NULL) { continue; }
					$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];
					$total += $stat['value'];

					$stats[$zone][] = $stat;
				}
				if ($total == 0) { unset($stats[$zone]); }
			}

			return ['stats' => $stats];
		} catch (Exception $ex) { }

		return false;
	}

	/**
	 * Get zone statistics.
	 *
	 * @param $domain Domain object.
	 * @return TRUE if we handled this method.
	 */
	function getDomainStats($domain, $type = 'raw', $time = '3600') {
		try {
			$database = getInfluxDB();

			// executing a query will yield a resultset object
//				SELECT max("value") FROM "zone_qtype" WHERE time > now() - 1h and "zone" = 'mydnshost.co.uk' GROUP BY time(60s),"zone","qtype";
//				SELECT max("value") FROM "zone_qtype" WHERE time > now() - 1h AND zone = 'mydnshost.co.uk' GROUP BY time(60s),zone,qtype"
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(max("value")) AS value');
			} else {
				$result = $result->select('max("value") AS value');
			}

			$result = $result->from('zone_qtype')
			                 ->where(["time > now() - " . $time . "s", "\"zone\" = '" . $domain->getDomain() . "'"])
			                 ->groupby("time(60s)")->groupby("zone")->groupby("qtype")
			                 ->getResultSet();

			// $results = json_decode($result->getRaw(), true);

			$stats = [];
			foreach ($result->getSeries() AS $series) {
				$type = $series['tags']['qtype'];
				$stats[$type] = [];

				foreach ($series['values'] as $val) {
					if ($val[1] === NULL) { continue; }
					$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];

					$stats[$type][] = $stat;
				}
			}

			return ['stats' => $stats];
		} catch (Exception $ex) { }

		return false;
	}

	function showTime() {
		return date('[Y-m-d H:i:s O]');
	}

	function do_idn_to_ascii($domain) {
		return ($domain == '.') ? $domain : idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
	}

	function do_idn_to_utf8($domain) {
		return ($domain == '.') ? $domain : idn_to_utf8($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
	}
