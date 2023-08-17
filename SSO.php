<?php

/**
 * User: jlawrence
 * Date: 5/27/16
 * Time: 10:04 AM.
 */

namespace LGL\Clever;

use Exception;
use Illuminate\Support\Facades\Redis;

class SSO
{
    public static $base = 'https://clever.com/oauth';
    public static $oauthTokenUrl = 'https://clever.com/oauth/tokens';
    public static $oauthAuthorizeUrl = 'https://clever.com/oauth/authorize';
    public static $apiMe = 'https://api.clever.com/v2.1';

    protected $_auth;

    public function __construct($auth = null)
    {
        $this->_auth = $auth;
    }


    /**
     * @param array|null $override_options
     *
     *
     */
    public function setOptions(array $override_options = null)
    {
        $options = array(
            'client_id' => $override_options['clientId'],
            'client_secret' => $override_options['clientSecret'],
            'clever_redirect_url' => $override_options['clientRedirect'],
            'clever_oauth_tokens_url' => self::$oauthTokenUrl,
            'clever_oauth_authorize_url' => self::$oauthAuthorizeUrl,
            'clever_api_me_url' => self::$apiMe . '/me',
        );
        if (isset($override_options)) {
            array_merge($options, $override_options);
        }

        // Clever redirect URIs must be preregistered on your developer dashboard.
        if (!empty($options['client_id']) && !empty($options['client_secret']) && !empty($options['clever_redirect_url'])) {
            $this->_auth = $options;
        } else {
            $optionsString = json_encode($options);
            $message = "Cannot communicate with Clever without configuration. Options: $optionsString";
            throw new Exception($message);

        }
    }


    /**
     * Exchanges a $code value received in a $client_redirect for a bearer token.
     *
     * @param string $code OAuth 2.0 exchange code received when our OAuth redirect was triggered
     * @param array $options Options used for Clever API requests
     *
     * @return string $bearer_token  The string value of a user's OAuth 2.0 access token
     * @throws Exception if the bearer token cannot be retrieved
     *
     */

    /**
     * @param string $code
     *
     * @return string $bearer_token  The string value of a user's OAuth 2.0 access token
     */
    public function exchangeCodeForBearerToken($code)
    {

        $bearerToken = $this->checkCodeForBearerToken($code);

        if (!$bearerToken) {
            $data = array(
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->_auth['clever_redirect_url'],
            );

            $request_options = array('method' => 'POST', 'data' => $data);
            $response = $this->requestFromClever($this->_auth['clever_oauth_tokens_url'], $request_options);
            // Evaluate if the response is successful
            if ($response && $response['response_code'] && $response['response_code'] == '200') {
                $bearer_token = $response['response']['access_token'];

                $this->_auth['bearer_token'] = $bearer_token;
                return $this;
            } else {
                // Handle condition when $code cannot be exchanged for bearer token from Clever
                $dataString = json_encode($data);
                $responseString = json_encode($response);
                $message = "Cannot exchange code for bearer token. \n";
                $message .= "Request data: $dataString \n";
                $message .= "Response data: $responseString \n";
                throw new Exception($message);
            }
        }
        //  basic_auth_header = "Authorization: Basic " + Base64.encode(client_id + ":" client_secret)


    }

    private function checkCodeForBearerToken($code)
    {
        // Redis stored bearer token or get another one
        $key = 'clever:sso:code:'.$code;
        $redis = Redis::connection('clever')->get($key);
        if ($redis) {
            $this->_auth['bearer_token'] = $redis;
            return true;
        }
        return false;
    }

    /**
     * Uses the specified bearer token to retrieve the /me response for the user.
     *
     *
     * @return array $oauth_response  Hash of Clever's response when identifying a bearer token's owner
     * @throws Exception if the /me API response cannot be retrieved
     *
     */
    public function retrieveMeResponse()
    {
        $request_options = array('method' => 'GET', 'bearer_token' => $this->_auth['bearer_token']);
        $response = $this->requestFromClever($this->_auth['clever_api_me_url'], $request_options);
        // Evaluate if the response is successful
        if ($response && $response['response_code'] && $response['response_code'] == '200') {
            $oauth_response = $response['response'];

            return $oauth_response;
        } else {
            // Handle condition when /me response cannot be retrieved for bearer token
            $responseString = json_encode($response);
            $message = "Cannot retrieve /me response for bearer token. \n";
            $message .= "Response data: $responseString \n";
            throw new Exception($message);
        }
    }


    /**
     * General-purpose HTTP wrapper for working with the Clever API.
     *
     * @param string $url The fully-qualified URL that the request will be issued to
     * @param array $request_options Hash of options pertinent to the specific request
     * @param array $clever_options Hash of options more generally associated with Clever API requests
     *
     * @return array $normalized_response  A structured hash with pertinent response & request details
     * @throws Exception when the HTTP library, cURL, cannot issue the request
     *
     */
    public function requestFromClever($url, array $request_options)
    {
        $ch = curl_init($url);
        $request_headers = array('Accept: application/json');
        if (array_key_exists('bearer_token', $this->_auth)) {
            $authHeader = 'Authorization: Bearer ' . $this->_auth['bearer_token'];
            $request_headers = array_merge($request_headers, array($authHeader));
        } else {
            // When we don't have a bearer token, assume we're performing client auth.
            $encodedAuth = base64_encode($this->_auth['client_id'] . ':' . $this->_auth['client_secret']);
            $tokenHeader = "Authorization: Basic ".$encodedAuth;
            $request_headers = array_merge($request_headers, array($tokenHeader));
        }
        if ($request_options && array_key_exists('method', $request_options) && $request_options['method'] == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($request_options['data']) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request_options['data']);
            }
        }
        // Set prepared HTTP headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $raw_response = curl_exec($ch);
        $parsed_response = json_decode($raw_response, true);
        $curl_info = curl_getinfo($ch);

        // Provide the HTTP response code for easy error handling.
        $response_code = $curl_info['http_code'];

        if ($curl_error = curl_errno($ch)) {
            $error_message = curl_strerror($curl_error);
            die("cURL failure #{$curl_error}: {$error_message}");
        }

        // Prepare the parsed and raw response for further use.
        $normalized_response = array(
            'response_code' => $response_code,
            'response' => $parsed_response,
            'raw_response' => $raw_response,
            'curl_info' => $curl_info
        );

        return $normalized_response;
    }
}