#!/usr/bin/env php
<?php
	use shanemcc\phpdb\DB;

	require_once(dirname(__FILE__) . '/../functions.php');

	echo 'Creating new admin user.', "\n";

	$user = new User(DB::get());

	echo 'Email address: ';
	$email = trim(fgets(STDIN));

	echo 'Password: ';
	system('stty -echo');
	$password = trim(fgets(STDIN));
	system('stty echo');
	echo "\n";

	echo 'Confirm Password: ';
	system('stty -echo');
	$password2 = trim(fgets(STDIN));
	system('stty echo');
	echo "\n";

	if ($password != $password2) {
		echo 'Failed to add admin user: Passwords did not match.', "\n";
		exit(1);
	}

	echo 'User real name: ';
	$name = trim(fgets(STDIN));

	$user = new User(DB::get());
	$user->setEmail(strtolower($email));
	$user->setRealName($name);
	$user->setPassword($password);
	$user->setPermission('all', true);
	$user->setAcceptTerms(time());

	echo "\n";

	try {
		$user->validate();
	} catch (Exception $ex) {
		echo 'Failed to add admin user: ', $ex->getMEssage(), "\n";
		exit(1);
	}

	if ($user->save()) {
		echo 'Added admin user: ', $user->getEmail(), "\n";
		echo 'ID: ', $user->getID(), "\n";
		exit(0);
	} else {
		echo 'Failed to add admin user: ', (is_array($user->getLastError()) ? $user->getLastError()[2] : $user->getLastError()), "\n";
		exit(1);
	}
