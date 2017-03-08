<?php
namespace App\Models;


class PaypalIPN
{
	protected $user = '';
	protected $password = '';
	protected $signature = '';
	protected $certFile = '';
	protected $useSandbox = false;
	protected $useLocalCerts = true;

	const URL_VERIFY = 'https://ipnpb.paypal.com/cgi-bin/webscr';
	const URL_VERIFY_SANDBOX = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';
	const URL_NVP = 'https://api-3t.paypal.com/nvp';
	const URL_NVP_SANDBOX = 'https://api-3t.sandbox.paypal.com/nvp';

	const VALID = 'VERIFIED';
	const INVALID = 'INVALID';

	public function __construct($certFile = '')
	{
		if ($certFile) {
			$this->certFile = $certFile;
		}
	}

	public function useSandbox()
	{
		$this->useSandbox = true;
	}

	public function usePHPCerts()
	{
		$this->useLocalCerts = false;
	}

	public function getPaypalUri()
	{
		if ($this->useSandbox) {
			return self::URL_VERIFY_SANDBOX;
		} else {
			return self::URL_VERIFY;
		}
	}

	public function getPaypalNvpUri()
	{
		if ($this->useSandbox) {
			return self::URL_NVP_SANDBOX;
		} else {
			return self::URL_NVP;
		}
	}

	public function verifyIPN()
	{
		try {
			!count($_POST);
		} catch (Exception $e) {
			echo "missing post data";
		}

		$raw_post_data = file_get_contents('php://input');
		$raw_post_array = explode('&', $raw_post_data);
		$myPost = array();
		foreach ($raw_post_array as $keyval) {
			$keyval = explode('=', $keyval);
			if (count($keyval) == 2) {

				if ($keyval[0] === 'payment_date') {
					if (substr_count($keyval[1], '+') === 1) {
						$keyval[1] = str_replace('+', '%2B', $keyval[1]);
					}
				}
				$myPost[$keyval[0]] = urldecode($keyval[1]);
			}
		}

		$req = 'cmd=_notify-validate';
		$get_magic_quotes_exists = false;
		if (function_exists('get_magic_quotes_gpc')) {
			$get_magic_quotes_exists = true;
		}
		foreach ($myPost as $key => $value) {
			if ($get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1) {
				$value = urlencode(stripslashes($value));
			} else {
				$value = urlencode($value);
			}
			$req .= "&$key=$value";
		}

		$ch = curl_init($this->getPaypalUri());
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
		curl_setopt($ch, CURLOPT_SSLVERSION, 6);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		if ($this->useLocalCerts) {
			curl_setopt($ch, CURLOPT_CAINFO, $this->certFile);
		}
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));
		$res = curl_exec($ch);
		if ( ! ($res)) {
			$errno = curl_errno($ch);
			$errstr = curl_error($ch);
			curl_close($ch);

			echo "cURL error: [$errno] $errstr";
		}
		$info = curl_getinfo($ch);
		$http_code = $info['http_code'];
		if ($http_code != 200) {

			echo "PayPal responded with http code $http_code";
		}
		curl_close($ch);

		if ($res == self::VALID) {
			return true;
		} else {
			return false;
		}
	}

	public function cancelSubscription($subscriptionId)
	{
		$request = ''
			. 'USER=' . urlencode($this->user)
			. '&PWD=' . urlencode($this->password)
			. '&SIGNATURE=' . urlencode($this->signature)
			. '&VERSION=97.0'
			. '&METHOD=ManageRecurringPaymentsProfileStatus'
			. '&PROFILEID=' . urlencode($subscriptionId)
			. '&ACTION=Cancel'
			. '&NOTE=' . urlencode('Profile cancelled at store');

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->getPaypalNvpUri());
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CAINFO, $this->certFile);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

		if (!($response = curl_exec($ch))) {
			curl_close($ch);
			return false;
		}

		curl_close($ch);

		parse_str($response, $parsedResponse);
		return $parsedResponse;
	}

	public function setApiCredentials($user, $password, $signature)
	{
		$this->user = $user;
		$this->password = $password;
		$this->signature = $signature;
	}

	public function BlobPack($object) {
		if (function_exists('json_encode')) {
			$data = json_encode($object);
		} else {
			$data = serialize($object);
		}

		if (function_exists('gzdeflate')) {
			$data = gzdeflate($data);
		}

		return base64_encode($data);
	}

	public function BlobUnpack($data) {
		$data = base64_decode($data);

		if (!strlen($data)) {
			return $data;
		}

		if (function_exists('gzinflate')) {
			$data = gzinflate($data);
		}

		if (function_exists('json_decode')) {
			$object = json_decode($data);
		} else {
			$object = unserialize($data);
		}

		return $object;
	}
}
