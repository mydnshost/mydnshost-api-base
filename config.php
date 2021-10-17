<?php
	// Database Details
	$database['server'] = getEnvOrDefault('DB_SERVER', 'localhost');
	$database['type'] = getEnvOrDefault('DB_SERVER_TYPE', 'mysql');
	$database['username'] = getEnvOrDefault('DB_SERVER_USERNAME', 'dnsapi');
	$database['password'] = getEnvOrDefault('DB_SERVER_PASSWORD', 'dnsapi');
	$database['database'] = getEnvOrDefault('DB_SERVER_DATABASE', 'dnsapi');

	// Secret for JWT Tokens
	// Must be at least 12 characters in length, contain upper and lower case
	// letters, a number, and a special character `*&!@%^#$``
	$config['jwtsecret'] = getEnvOrDefault('JWT_SECRET', 'S0M3SEcr3t!#');

	// Config for redis.
	//
	// This will be used for sessions and by job workers for locking.
	$config['redis'] = getEnvOrDefault('REDIS_HOST', '');
	$config['redisPort'] = getEnvOrDefault('REDIS_PORT', 6379);
	$config['redisSessionPrefix'] = getEnvOrDefault('REDIS_SESSION_PREFIX', 'MyDNSHost-API-Session');

	// Config for Site registration
	$config['register_enabled'] = parseBool(getEnvOrDefault('ALLOW_REGISTER', 'true'));
	$config['register_manual_verify'] = parseBool(getEnvOrDefault('REGISTER_MANUAL_VERIFY', 'false'));
	$config['register_permissions'] = explode(',', getEnvOrDefault('REGISTER_PERMISSIONS', 'domains_create'));
	$config['register_require_terms'] = parseBool(getEnvOrDefault('REGISTER_REQUIRE_TERMS', 'true'));

	// Configuration for YUBIKEY Authentication
	$config['twofactor']['yubikey']['clientid'] = '12345';
	$config['twofactor']['yubikey']['secret'] = 'FOOBAR=';
	$config['twofactor']['yubikey']['enabled'] = false;

	// Configuration for AUTHY Authentication
	$config['twofactor']['authy']['clientid'] = '12345';
	$config['twofactor']['authy']['secret'] = 'FOOBAR=';
	$config['twofactor']['authy']['enabled'] = false;

	// Minimum terms time required to be considered "accepted".
	//
	// If this is not met, /userdata responses will show `"acceptterms": false`
	// and the frontend will prompt the user to accept the terms before
	// continuing.
	$config['minimum_terms_time'] = (int)getEnvOrDefault('TERMS_TIME', 1528752008);

	// Minimum terms time required to be able to use all API functions.
	//
	// This should be earlier than `minimum_terms_time` to allow API functions
	// to still work for a limited time after a change of terms (Users who have
	// never accepted the terms have a time of -1)
	//
	// If the terms are not accepted after this time then only `user_read` and
	// `user_write` permissions will be granted to a user logging in.
	//
	// This does not impact DomainKeys
	$config['api_minimum_terms_time'] = (int)getEnvOrDefault('API_TERMS_TIME', -2);

	// Allow users to delete their own account.
	$config['self_delete'] = parseBool(getEnvOrDefault('ALLOW_SELF_DELETE', 'true'));

	// General details (used by emails)
	$config['sitename'] = getEnvOrDefault('SITE_NAME', 'MyDNSHost');
	$config['siteurl'] = getEnvOrDefault('SITE_URL', 'https://mydnshost.co.uk/');

	// Template details (used by emails)
	$config['templates']['dir'] = getEnvOrDefault('TEMPLATE_DIR', __DIR__ . '/templates');
	$config['templates']['theme'] = getEnvOrDefault('TEMPLATE_THEME', 'default');
	$config['templates']['cache'] = getEnvOrDefault('TEMPLATE_CACHE', __DIR__ . '/templates_c');

	// Config for email sending
	$config['email']['enabled'] = parseBool(getEnvOrDefault('EMAIL_ENABLED', 'false'));
	$config['email']['server'] = getEnvOrDefault('EMAIL_SERVER', '');
	$config['email']['username'] = getEnvOrDefault('EMAIL_USERNAME', '');
	$config['email']['password'] = getEnvOrDefault('EMAIL_PASSWORD', '');
	$config['email']['from'] = getEnvOrDefault('EMAIL_FROM', 'dns@example.org');
	$config['email']['from_name'] = getEnvOrDefault('EMAIL_FROM_NAME', $config['sitename']);

	// Config for RabbitMQ
	$config['rabbitmq']['host'] = getEnvOrDefault('RABBITMQ_HOST', '127.0.0.1');
	$config['rabbitmq']['port'] = getEnvOrDefault('RABBITMQ_PORT', 5672);
	$config['rabbitmq']['user'] = getEnvOrDefault('RABBITMQ_USER', 'guest');
	$config['rabbitmq']['pass'] = getEnvOrDefault('RABBITMQ_PASS', 'guest');

	// Config for MongoDB
	$config['mongodb']['server'] = getEnvOrDefault('MONGO_HOST', '127.0.0.1');
	$config['mongodb']['database'] = getEnvOrDefault('MONGO_DB', 'mydnshost');

	function getJobWorkerConfig($w) {
		$result = [];
		$result['processes'] = getEnvOrDefault('WORKER_' . $w . '_PROCESSES', getEnvOrDefault('WORKER_PROCESSES', 1));
		$result['maxJobs'] = getEnvOrDefault('WORKER_' . $w . '_MAXJOBS', getEnvOrDefault('WORKER_MAXJOBS', 250));

		return $result;
	}

	function getJobWorkers($workerList) {
		$result = [];

		foreach (explode(',', $workerList) as $w) {
			if (empty($w)) { continue; }

			$includeWorker = true;
			if ($w[0] == '-') { $w = substr($w, 1); $includeWorker = false; }

			if ($w == '*') {
				foreach (recursiveFindFiles(__DIR__ . '/workers/workers') as $file) {
					$w = pathinfo($file, PATHINFO_FILENAME);

					$result[$w] = getJobWorkerConfig($w);
					$result[$w]['include'] = $includeWorker;
				}
			} else {
				$result[$w] = getJobWorkerConfig($w);
				$result[$w]['include'] = $includeWorker;
			}
		}

		return $result;
	}

	$config['jobworkers'] = getJobWorkers(getEnvOrDefault('WORKER_WORKERS', ''));

	// Default DNS Records
	$config['defaultRecords'] = [];
	$config['defaultRecords'][] = ['name' => '', 'type' => 'NS', 'content' => 'ns1.example.com'];
	$config['defaultRecords'][] = ['name' => '', 'type' => 'NS', 'content' => 'ns2.example.com'];
	$config['defaultRecords'][] = ['name' => '', 'type' => 'NS', 'content' => 'ns3.example.com'];

	// Default SOA
	$config['defaultSOA'] = ['primaryNS' => 'ns1.example.com.'];

	// Influx DB
	$config['influx']['host'] = getEnvOrDefault('INFLUX_HOST', 'localhost');
	$config['influx']['port'] = getEnvOrDefault('INFLUX_PORT', '8086');
	$config['influx']['user'] = getEnvOrDefault('INFLUX_USER', '');
	$config['influx']['pass'] = getEnvOrDefault('INFLUX_PASS', '');
	$config['influx']['db'] = getEnvOrDefault('INFLUX_DB', 'MyDNSHost');

	// Domain Logs Source
	// $config['domainlogs']['source'] = getEnvOrDefault('DOMAINLOGS_SOURCE', 'file:/var/log/bind.log');
	$config['domainlogs']['source'] = getEnvOrDefault('DOMAINLOGS_SOURCE', 'docker:bind');

	// Local configuration.
	if (file_exists(dirname(__FILE__) . '/config.local.php')) {
		include(dirname(__FILE__) . '/config.local.php');
	}
