<?php

class Dig {

	/**
	 * Query DS records for a domain from the parent zone.
	 *
	 * @param string $domain Domain name to query.
	 * @return array Array of ['keytag' => int, 'algorithm' => int, 'digesttype' => int, 'digest' => string]
	 */
	public static function queryDS(string $domain): array {
		$cmd = findCommandPath(['dig']) . ' DS ' . escapeshellarg($domain) . ' @8.8.8.8 +short +cd 2>/dev/null';
		$output = [];
		$return = 0;
		exec($cmd, $output, $return);

		$results = [];
		foreach ($output as $line) {
			$line = trim($line);
			if (empty($line)) { continue; }

			// DS format: keytag algorithm digesttype digest
			$bits = preg_split('/\s+/', $line, 4);
			if (count($bits) < 4) { continue; }

			$results[] = [
				'keytag' => intval($bits[0]),
				'algorithm' => intval($bits[1]),
				'digesttype' => intval($bits[2]),
				'digest' => strtolower(str_replace(' ', '', $bits[3])),
			];
		}

		return $results;
	}

	/**
	 * Query DNSKEY records for a domain.
	 *
	 * @param string $domain Domain name to query.
	 * @return array Array of ['flags' => int, 'protocol' => int, 'algorithm' => int, 'publickey' => string, 'keytag' => int]
	 */
	public static function queryDNSKEY(string $domain): array {
		$cmd = findCommandPath(['dig']) . ' DNSKEY ' . escapeshellarg($domain) . ' @8.8.8.8 +short +cd 2>/dev/null';
		$output = [];
		$return = 0;
		exec($cmd, $output, $return);

		$results = [];
		foreach ($output as $line) {
			$line = trim($line);
			if (empty($line)) { continue; }

			// DNSKEY format: flags protocol algorithm publickey
			$bits = preg_split('/\s+/', $line, 4);
			if (count($bits) < 4) { continue; }

			$flags = intval($bits[0]);
			$protocol = intval($bits[1]);
			$algorithm = intval($bits[2]);
			$publickey = $bits[3];

			$results[] = [
				'flags' => $flags,
				'protocol' => $protocol,
				'algorithm' => $algorithm,
				'publickey' => $publickey,
				'keytag' => self::computeKeytag($flags, $protocol, $algorithm, $publickey),
			];
		}

		return $results;
	}

	/**
	 * Compute key tag from DNSKEY rdata per RFC 4034 Appendix B.
	 *
	 * @param int $flags DNSKEY flags (256=ZSK, 257=KSK)
	 * @param int $protocol DNSKEY protocol (always 3)
	 * @param int $algorithm DNSKEY algorithm number
	 * @param string $publicKeyBase64 Base64-encoded public key
	 * @return int Key tag value
	 */
	public static function computeKeytag(int $flags, int $protocol, int $algorithm, string $publicKeyBase64): int {
		// From https://robin.waarts.eu/2012/07/14/get-the-keytag-from-dnskey-data-in-php/
		$rdata = base64_decode($publicKeyBase64);
		$wire = pack("ncc", $flags, $protocol, $algorithm) . $rdata;

		if ($algorithm == 1) {
			$keytag = 0xffff & unpack("n", substr($wire, -3, 2));
		} else {
			$sum = 0;
			for ($i = 0; $i < strlen($wire); $i++) {
				$a = unpack("C", substr($wire, $i, 1));
				$sum += ($i & 1) ? $a[1] : $a[1] << 8;
			}
			$keytag = 0xffff & ($sum + ($sum >> 16));
		}

		return $keytag;
	}
}
