#!/usr/bin/env php
<?php
	require_once(dirname(__FILE__) . '/init_functions.php');

	use shanemcc\phpdb\DB;

	// This will delete the database and seed it with test data.
	$pdo = DB::get()->getPDO();
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
	$pdo->exec('DROP TABLE IF EXISTS users;');
	$pdo->exec('DROP TABLE IF EXISTS domains;');
	$pdo->exec('DROP TABLE IF EXISTS domain_access;');
	$pdo->exec('DROP TABLE IF EXISTS records;');
	$pdo->exec('DROP TABLE IF EXISTS apikeys;');
	$pdo->exec('DROP TABLE IF EXISTS hooks;');
	$pdo->exec('DROP TABLE IF EXISTS permissions;');
	$pdo->exec('DROP TABLE IF EXISTS twofactorkeys;');
	$pdo->exec('DROP TABLE IF EXISTS twofactordevices;');
	$pdo->exec('DROP TABLE IF EXISTS domainkeys;');
	$pdo->exec('DROP TABLE IF EXISTS domainhooks;');
	$pdo->exec('DROP TABLE IF EXISTS zonekeys;');
	$pdo->exec('DROP TABLE IF EXISTS articles;');
	$pdo->exec('DROP TABLE IF EXISTS job_depends;');
	$pdo->exec('DROP TABLE IF EXISTS joblogs;');
	$pdo->exec('DROP TABLE IF EXISTS jobs;');
	$pdo->exec('DROP TABLE IF EXISTS usercustomdata;');
	$pdo->exec('DROP TABLE IF EXISTS userdomaincustomdata;');
	$pdo->exec('DROP TABLE IF EXISTS __MetaData;');
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
	initDataServer(DB::get());

	$domains = array();

	$admin = new User(DB::get());
	$admin->setEmail('admin@example.org')->setRealName('Admin User')->setPassword('password')->setPermission('all', true)->setAcceptTerms(time())->save();

	$adminKey = new APIKey(DB::get());
	$adminKey->setKey('69299C29-5BED-447D-B2F9-840DD01FE0B5')->setDescription('Test Key')->setUserID($admin->getID())->setCreated(time());
	$adminKey->setDomainRead(true)->setDomainWrite(true);
	$adminKey->setUserRead(true)->setUserWrite(true);
	$adminKey->save();

	for ($i = 1; $i <= 5; $i++) {
		$domain = new Domain(DB::get());
		$domain->setDomain('test' . $i . '.com')->setAccess($admin->getID(), 'Owner')->save();
		$domains[] = $domain;
		EventQueue::get()->publish('domain.delete', [$domain->getID(), $domain->getDomainRaw()]);
		EventQueue::get()->publish('domain.add', [$domain->getID()]);

		$key = new DomainKey(DB::get());
		$key->setKey('9426F536-2559-4FA0-BA50-644C90B5FAE4')->setDescription('Write Key')->setDomainID($domain->getID())->setCreated(time());
		$key->setDomainWrite(true);
		$key->save();

		$key = new DomainKey(DB::get());
		$key->setKey('586DAB85-9DCE-4FA2-AA9D-877AA7011190')->setDescription('Read Key')->setDomainID($domain->getID())->setCreated(time());
		$key->save();
	}

	for ($i = 1; $i <= 5; $i++) {
		$user = new User(DB::get());
		$user->setEmail('user' . $i . '@example.org')->setRealName('Normal User ' . $i)->setPassword('password')->setPermission('all', false)->setAcceptTerms($i < 4 ? time() : 4 - $i)->save();

		$domain = new Domain(DB::get());
		$domain->setDomain('example' . $i . '.org')->setAccess($user->getID(), 'Owner')->save();
		if ($i % 2 == 0) {
			$domain->setAccess($admin->getID(), 'Write')->save();
		}
		$domains[] = $domain;
		EventQueue::get()->publish('domain.delete', [$domain->getID(), $domain->getDomainRaw()]);
		EventQueue::get()->publish('domain.add', [$domain->getID()]);
	}

	$user->setRealName('2FA User')->save();

	$ga = new PHPGangsta_GoogleAuthenticator();

	$secretKey1 = new TwoFactorKey(DB::get());
	$secretKey1->setKey('TESTTESTTESTTEST')->setDescription('Test Key [TESTTESTTESTTEST]')->setUserID($user->getID())->setCreated(time())->setActive(true);
	$secretKey1->save();

	$secretKey2 = new TwoFactorKey(DB::get());
	$s = $ga->createSecret();
	$secretKey2->setKey($s)->setDescription('Test Device [' . $s . ']')->setUserID($user->getID())->setCreated(time());
	$secretKey2->save();

	for ($i = 1; $i <= 5; $i++) {
		$domain = new Domain(DB::get());
		$domain->setDomain('unowned' . $i . '.com')->save();
		if ($i % 2 == 0) {
			$domain->setAccess($admin->getID(), 'Read')->save();
		}
		$domains[] = $domain;
		EventQueue::get()->publish('domain.delete', [$domain->getID(), $domain->getDomainRaw()]);
		EventQueue::get()->publish('domain.add', [$domain->getID()]);
	}

	foreach ($domains as $domain) {

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('SOA');
		$record->setContent('ns1.mydnshost.co.uk. dnsadmin.dataforce.org.uk. 2017030500 86400 7200 2419200 60');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('NS');
		$record->setContent('dev.mydnshost.co.uk');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('A');
		$record->setContent('127.0.0.1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('www.' . $domain->getDomain());
		$record->setType('A');
		$record->setContent('127.0.0.1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('AAAA');
		$record->setContent('::1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('www.' . $domain->getDomain());
		$record->setType('AAAA');
		$record->setContent('::1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('test.' . $domain->getDomain());
		$record->setType('CNAME');
		$record->setContent('www.' . $domain->getDomain());
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('txt.' . $domain->getDomain());
		$record->setType('TXT');
		$record->setContent('Some Text Record');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('MX');
		$record->setPriority('10');
		$record->setContent($domain->getDomain());
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('MX');
		$record->setPriority('50');
		$record->setContent('www.' .  $domain->getDomain());
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);

		EventQueue::get()->publish('domain.records.changed', [$domain->getID()]);
	}

	exit(0);
