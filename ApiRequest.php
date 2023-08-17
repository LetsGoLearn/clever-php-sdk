<?php

namespace LGL\Clever;

use \Psr\Log;
use LGL\Clever\Exceptions\CurlException;

abstract class ApiRequest {

	use Log\LoggerAwareTrait;

	const VERSION = '2.1.0';

	const APIBASE = "https://api.clever.com/v2.1";

	protected $token;

	function setToken($token){
		$this->token = $token;
	}

	function encodeQuery(array $query = []){
		if(isset($query["where"])){
			$query["where"] = json_encode($query["where"]);
		}
		return http_build_query($query, null, "&");
	}

	function utf8($value){
		return is_string($value) ? utf8_encode($value) : $value;
	}

	function getCurlOpts(array $opts = []){
		$options = [
			CURLOPT_HTTPGET        => 1, // default
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_CAINFO         => dirname(__FILE__) . '/data/ca-certificates.crt',
			CURLOPT_HTTPHEADER     => [
				"Authorization: Bearer {$this->token}",
				'X-Clever-Client-Info: {"ca":"using Clever-supplied CA bundle"}'
			],
		];
		return $options + $opts;
	}

	function ping($url, array $query = []){
		$curl = curl_init();

		$url = static::APIBASE . "/{$url}";


		if($query){
			$url = "{$url}?" . $this->encodeQuery($query);
		}
		
		curl_setopt_array($curl, $this->getCurlOpts([
			CURLOPT_URL => $this->utf8($url),
		]));

		$rbody = curl_exec($curl);
		$error = curl_error($curl);
		$errno = curl_errno($curl);

		if(CURLE_OK === $errno){
			$rcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			return [$rbody, $rcode];
		}

		// 77 CURLE_SSL_CACERT_BADFILE (constant not defined in PHP though)
		if(in_array($errno, [CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE, 77])){
			// do we even need to do this?
		}

		$this->relayCurlError(new CurlException($error, $errno));

	}

	function relayCurlError(CurlException $e){
		$longMessage = "Unexpected error communicating with Clever.  If this problem persists, let us know at support@clever.com.";
		$curle = [
			CURLE_COULDNT_CONNECT      => "CURLE_COULDNT_CONNECT :: Could not connect to Clever ().  Please check your internet connection and try again.",
			CURLE_COULDNT_RESOLVE_HOST => "CURLE_COULDNT_RESOLVE_HOST :: Could not connect to Clever ().  Please check your internet connection and try again.",
			CURLE_OPERATION_TIMEOUTED  => "CURLE_OPERATION_TIMEOUTED :: Could not connect to Clever ().  Please check your internet connection and try again.",
			CURLE_SSL_CACERT           => "CURLE_SSL_CACERT :: Could not verify Clever's SSL certificate.  Please make sure that your network is not intercepting certificates.  (Try going to in your browser.)  If this problem persists, let us know at support@clever.com.",
			CURLE_SSL_PEER_CERTIFICATE => "CURLE_SSL_PEER_CERTIFICATE :: Could not verify Clever's SSL certificate.  Please make sure that your network is not intercepting certificates.  (Try going to in your browser.)  If this problem persists, let us know at support@clever.com.",
		];

		$longMessage = array_key_exists($e->getCode(), $curle) ? $curle[$e->getCode()] : $longMessage;
		$longMessage = "{$longMessage}\n\n(Network error [errno {$e->getCode()}]: {$e->getMessage()})";

		if($this->logger instanceof Log\LoggerInterface){
			$this->logger->error($e->getMessage(), [
				"APIBASE"       => static::APIBASE,
				"VERSION"       => static::VERSION,
				"e.code"        => $e->getCode(),
				"e.message"     => $e->getMessage(),
				"e.longmessage" => $longMessage,
			]);
		}

		throw $e;

	}

}






