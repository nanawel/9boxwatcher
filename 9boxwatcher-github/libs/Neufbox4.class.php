<?php
/**
 * Provides access and control over Neufbox4 devices.
 * Tested with firmware NB4-MAIN-R3.1.10.
 *
 * @require PHP > 5.1.2
 * @author Anael Ollier <nanawel@gmail.com>
 * @version 0.1.2
 * @since 2011-12-25
 */
class Neufbox4 {
	const CLASS_VERSION = '0.1.2';
	
	const DEFAULT_HOST = '192.168.1.1';
	
	const STATUS_CONNECTED = 0;
	const STATUS_CONNECTING = 1;
	const STATUS_UNUSED = 2;
	const STATUS_NOT_CONNECTED = 3;
	
	const REQUEST_TIMEOUT = 5;
	
	const LOG_DEBUG = 10;
	const LOG_NOTICE = 20;
	const LOG_WARNING = 30;
	const LOG_ERROR = 40;
	
	/** @var string */
	protected $_host;
	/** @var string */
	protected $_login;
	/** @var string */
	protected $_password;
	
	/** @var resource */
	protected $_curl;
	/** @var int */
	protected $_timeout = self::REQUEST_TIMEOUT;
	/** @var string */
	protected $_sessionId;
	
	/** @var boolean */
	public $debug = false;
	/** @var boolean */
	public $dumpResponse = false;
	/** @var int */
	public $logLevel = self::LOG_DEBUG;
	
	/**
	 *
	 * @param string $host The Neufbox4 IP to connect to.
	 */
	public function __construct($host = self::DEFAULT_HOST, $logLevel = self::LOG_NOTICE) {
		$this->_host = $host;
		$this->logLevel = $logLevel;
		$this->_resetCurl();
		$this->log("Initialized new connection to host $host", self::LOG_DEBUG);
	}
	
	public function __destruct() {
		curl_close($this->_curl);
	}
	
	protected function _getUrl($path) {
		return 'http://' . $this->_host . $path;
	}
	
	protected function _getSidCookie() {
	    return $this->_sessionId ? 'sid=' . $this->_sessionId : '';
	}
	
	/**
	 * Creates a new session with stored login/password if
	 * there's no current session.
	 *
	 * @param boolean $force
	 */
	protected function _login($force = false) {
		if ($force || !$this->_sessionId) {
			
			///////////////////
			// 1. Retrieve challenge (session ID)
			$res = $this->_sendRawRequest('/login', 'post', array('action' => 'challenge'),
				array(
					'X-Requested-With: XMLHttpRequest',
					'X-Requested-Handler: ajax',
				)
			);
			if ($res === false) {
				self::throwException('Cannot log in: challenge request failed');
			}
			if (200 != ($code = $res['info']['http_code'])) {
				self::throwException("Cannot log in: unexpected code HTTP $code returned");
			}
			elseif ('text/xml' != ($contentType = $res['info']['content_type'])) {
				self::throwException("Cannot log in: unexpected content type \"$contentType\" returned (text/xml expected)");
			}
			$xml = new SimpleXMLElement($res['body']);
			if (! $sid = (string) $xml->challenge) {
				self::throwException('Cannot log in: no challenge found in response body');
			}
			$this->_sessionId = self::_trim($sid);
			
			///////////////////
			// 2. Generate hash for authentication
			if (0 == strlen($this->_login) || 0 == strlen($this->_password)) {
				self::log("Missing or empty login/password", self::LOG_WARNING);
			}
			$hash = $this->_genLoginHash($this->_sessionId, $this->_login, $this->_password);
			
			///////////////////
			// 3. Log in with calculated hash
			try {
				$res = $this->_sendRawRequest('/login', 'post',
					array(
						'hash' => $hash,
						'login' => '',
						'method' => 'passwd',
						'password' => '',
						'zsid' => $this->_sessionId,
					),
					array('User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:30.0) Gecko/20100101 Firefox/30.0'),
					$this->_getSidCookie()
				);
			}
			catch (Exception $e) {
				self::throwException("Cannot log in: authentication request failed. ({$e->getMessage()})");
			}
			
			if ($res['info']['http_code'] == 401) {
				self::throwException("Cannot log in: invalid login/password?");
			}
			
			$this->log('Login successful! Session ID: ' . $this->_sessionId, self::LOG_DEBUG);
		}
	}
	
	/**
	 * Generates the authentication hash based on session ID,
	 * login and password.
	 *
	 * @param string $challenge
	 * @param string $login
	 * @param string $password
	 */
	protected function _genLoginHash($challenge, $login, $password) {
		return hash_hmac('sha256', hash('sha256', $login), $challenge)
			. hash_hmac('sha256', hash('sha256', $password), $challenge);
	}
	
	/**
	 * Helper for building raw cURL requests.
	 *
	 * @param string $path
	 * @param string $method
	 * @param array $data
	 * @return array
	 * @throws Exception if the request failed.
	 */
	protected function _sendRawRequest($path = '/', $method = 'get', $data = array(), $headers = null, $cookie = '') {
		$url = $this->_getUrl($path);
		$method = strtolower($method);
		
		// Common
		curl_setopt($this->_curl, CURLOPT_TIMEOUT, $this->_timeout);
		curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->_curl, CURLOPT_HEADER, true);
		
		// Cookie
		if ($cookie) {
			curl_setopt($this->_curl, CURLOPT_COOKIE, $cookie);
		}
		else {
			curl_setopt($this->_curl, CURLOPT_COOKIE, '');
		}
		
		// Headers
		if (is_array($headers)) {
		    $headers[] = 'Connection: keep-alive';
		}
		else {
			$headers = array('Connection: keep-alive');
		}
		curl_setopt($this->_curl, CURLOPT_HTTPHEADER, $headers);
		
		// Prepare GET/POST fields
		$dataFields = array();
		foreach($data as $key => $value) {
			$dataFields[] = $key . '=' . $value;
		}
		$dataFields = implode('&', $dataFields);
		$finalUrl = $url;
		if ($method == 'get') {
			self::log("Sending GET request to \"$url\" with data: " . ($dataFields ? $dataFields : '(none)'), self::LOG_DEBUG);
			$finalUrl .= '?' . $dataFields;
			curl_setopt($this->_curl, CURLOPT_HTTPGET, true);
		}
		elseif ($method == 'post') {
			self::log("Sending POST request to \"$url\" with data: $dataFields", self::LOG_DEBUG);
			curl_setopt($this->_curl, CURLOPT_POST, true);
			curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $dataFields);
		}
		else {
			self::throwException('Unsupported method: ' . $method);
		}
		
		// Debug
		curl_setopt($this->_curl, CURLOPT_VERBOSE, $this->debug ? true : false);
		
		// Set final URL and execute request
		curl_setopt($this->_curl, CURLOPT_URL, $finalUrl);
		$result = curl_exec($this->_curl);
		if ($result === false) {
			$err = curl_errno($this->_curl);
			
			// Couldn't connect || Operation timeout
			if (7 == $err || 28 == $err) {
				self::throwException('Cannot connect to host with IP "' . $this->_host . '". Is network up and device on?');
			}
			self::throwException('cURL error ' . $err);
		}
		
		// Extract headers
		list($rawHeaders, $body) = explode("\r\n\r\n", $result, 2);
		$headers = self::_parseHttpHeaders($rawHeaders);
		
		if ($this->dumpResponse) {
			$baseFilename = './' . str_replace('.', '', (string)microtime(true)) . '_' . preg_replace('/[^a-z0-9-_.]/i', '-', $url);
			$ext = $headers['Content-Type'] == 'text/html' ? '.html' : '';
			$ext = $headers['Content-Type'] == 'text/xml' ? '.xml' : $ext;
			file_put_contents($baseFilename . '.header', $rawHeaders);
			file_put_contents($baseFilename . '.body' . $ext, $body);
		}
		
		return array(
			'info'		=> curl_getinfo($this->_curl),
			'headers'	=> $headers,
			'body'		=> $body,
		);
	}
	
	/**
	 * Helper for building cURL requests after authentication with
	 * the Neufbox.
	 *
	 * @param string $path
	 * @param string $method
	 * @param array $data
	 * @return array
	 * @throws Exception if the request failed.
	 */
	protected function _sendRequest($path = '/', $method = 'get', $data = array(), $headers = null) {
		if (!$this->_sessionId) {
			$this->log("No session, initializing...", self::LOG_DEBUG);
			$this->_login();
		}
		$res = $this->_sendRawRequest($path, $method, $data, $headers, $this->_getSidCookie());
		
		// Redirect means that session is invalid
		if (in_array($res['info']['http_code'], array(302, 401)) || false !== strpos($res['body'], 'access_lock')) {
			$this->log("Session lost, attempting to renew...", self::LOG_DEBUG);
			
			// Force new login
			$this->_login(true);
			
			// Then try the original request again
			$res = $this->_sendRawRequest($path, $method, $data, $headers, $this->_getSidCookie());
			if ($res['info']['http_code'] != 200) {
				self::throwException('Cannot reconnect to Neufbox. Aborting');
			}
		}
		return $res;
	}
	
	/**
	 *
	 * @param string $html
	 * @param string $encoding
	 * @return DOMXPath
	 */
	protected function _htmlToDOMXPath($html, $encoding = 'iso-8859-1') {
		$dom = new DOMDocument('1.0', $encoding);
		@$dom->loadHTML($html);
		$xpathDom = new DOMXPath($dom);
		
		return $xpathDom;
	}
	
	public function login($login, $password) {
		$this->_login = $login;
		$this->_password = $password;
		//$this->_login(true);
	}
	
	public function logout() {
		$this->log('Logging out');
		$this->_sessionId = null;
	}
	
	protected function _resetCurl() {
		if ($this->_curl) {
			curl_close($this->_curl);
		}
		$this->_curl = curl_init($this->_getUrl('/'));
	}
	
	public function getHost() {
		return $this->_host;
	}
	
	/**
	 *
	 * @return int
	 */
	public function getIpv4Status() {
		$this->log("Retrieving IPv4 status...");
		$res = $this->_sendRequest('/state');
		return $this->_getStatusFromNodeCss($res['body'], '//td[@id="internet_status"]');
	}
	
	/**
	 *
	 * @return int
	 */
	public function getIpv6Status() {
		$this->log("Retrieving IPv6 status...");
		$res = $this->_sendRequest('/state');
		return $this->_getStatusFromNodeCss($res['body'], '//td[@id="internet_status_v6"]');
	}
	
	/**
	 *
	 * @return int
	 */
	public function getPhoneStatus() {
		$this->log("Retrieving phone status...");
		$res = $this->_sendRequest('/state');
		return $this->_getStatusFromNodeCss($res['body'], '//td[@id="voip_status"]');
	}
	
	/**
	 *
	 * @return int
	 */
	public function getWifiStatus() {
		$this->log("Retrieving Wifi status...");
		$res = $this->_sendRequest('/wifi');
		return $this->_getStatusFromNodeCss($res['body'], '//table[@id="wifi_info"]/*/td[1]');
	}
	
	/**
	 *
	 * @return int
	 */
	public function getHotspotStatus() {
		$this->log("Retrieving hotspot status...");
		$res = $this->_sendRequest('/hotspot');
		return $this->_getStatusFromNodeCss($res['body'], '//td[@id="hotspot_status"]');
	}
	
	/**
	 *
	 * @return int
	 */
	public function getTelevisionStatus() {
		$this->log("Retrieving TV status...");
		$res = $this->_sendRequest('/state');
		return $this->_getStatusFromNodeCss($res['body'], '//td[@id="tv_status"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getModemInfo() {
		$this->log("Retrieving modem info...");
		$res = $this->_sendRequest('/state');
		return $this->_getTableDataAsArray($res['body'], '//table[@id="modem_infos"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getIpv4ConnectionInfo() {
		$this->log("Retrieving IPv4 info...");
		$res = $this->_sendRequest('/state/wan');
		return $this->_getTableDataAsArray($res['body'], '//table[@id="wan_info"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getIpv6ConnectionInfo() {
		$this->log("Retrieving IPv6 info...");
		$res = $this->_sendRequest('/state/wan');
		return $this->_getTableDataAsArray($res['body'], '//table[@id="ipv6_info"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getAdslInfo() {
		$this->log("Retrieving ADSL info...");
		$res = $this->_sendRequest('/state/wan');
		return $this->_getTableDataAsArray($res['body'], '//table[@id="adsl_info"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getPppInfo() {
		$this->log("Retrieving PPP info...");
		$res = $this->_sendRequest('/state/wan');
		return $this->_getTableDataAsArray($res['body'], '//table[@id="ppp_info"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getConnectedHosts() {
		$this->log("Retrieving connected hosts list...");
		$res = $this->_sendRequest('/network');
		return $this->_getTableDataAsArrayWithHeaders($res['body'], '//table[@id="network_clients"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getDeviceInfo() {
		$this->log("Retrieving device info...");
		$res = $this->_sendRawRequest('/');
		$data = $this->_getTableDataAsArray($res['body'], '//div["id=infos"]//table');
		foreach($data as $i => $d) {
			$data[self::_trim($i)] = self::_trim(substr($d, 2));
		}
		return $data;
	}
	
	/**
	 *
	 * @return array
	 */
	public function getPortsInfo() {
		$this->log("Retrieving ports info...");
		$res = $this->_sendRequest('/network');
		return $this->_getTableDataAsArray($res['body'], '//table[@id="network_status"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getWifiInfo() {
		$this->log("Retrieving Wifi info...");
		$res = $this->_sendRequest('/wifi');
		return $this->_getTableDataAsArray($res['body'], '//table[@id="wifi_info"]');
	}
	
	/**
	 *
	 * @return array
	 */
	public function getLocalDnsInfo() {
		$this->log("Retrieving local DNS info...");
		$res = $this->_sendRequest('/network/dns');
		$data = $this->_getTableDataAsArrayWithHeaders($res['body'], '//table[@id="dnshosts_config"]');
		foreach($data as &$d) {
		    unset($d['']);    // Remove last column
		}
		return $data;
	}
	
	/**
	 *
	 * @return array
	 */
	public function getNatConfig() {
		$this->log("Retrieving NAT configuration...");
		$res = $this->_sendRequest('/network/nat');
		$return = $this->_getTableDataAsArrayWithHeaders($res['body'], '//table[@id="nat_config"]');
		
		//Remove last line (used to add a new NAT rule from the GUI)
		array_pop($return);
		
		// Remove the last two columns from rows (used to enable/disable and delete rules from the GUI)
		foreach($return as &$row) {
			array_pop($row);
			array_pop($row);
		}
		
		return $return;
	}
	
	/**
	 * Add a NAT rule
	 *
	 * All parameters can also be passed in an array as first argument:
	 * array(
	 * 		'name' 		=> name,
	 * 		'protocol'	=> protocol,
	 * 		...
	 *  )
	 *
	 * @param string|array $name
	 * @param string $protocol	"tcp" | "udp" | "both"
	 * @param int|array $externalPorts
	 * @param string $targetIp
	 * @param int|array $targetPorts
	 * @param boolean $active
	 */
	public function addNatRule($name, $protocol = null, $externalPorts = null, $targetIp = null, $targetPorts = null, $active = true) {
		// Prepare form values
		if (is_array($name)) {
			$ipParts = explode('.', $name['targetIp']);
			if (false === ip2long($targetIp) || count($ipParts) != 4) {
				self::throwException("Cannot add NAT rule, invalid IP: $targetIp");
			}
			$isRange = is_array($name['externalPorts']);
			$parameters = array(
				'nat_rulename'  	=> $name['name'],
				'nat_proto'			=> $name['protocol'],
				'nat_range' 		=> $isRange ? 'true' : 'false',
				'nat_extport'		=> $isRange ? '' : $name['externalPorts'],
				'nat_extrange_p0'	=> $isRange ? $name['externalPorts'][0] : '',
				'nat_extrange_p1'	=> $isRange ? $name['externalPorts'][1] : '',
				'nat_dstip_p0'		=> $ipParts[0],
				'nat_dstip_p1'		=> $ipParts[1],
				'nat_dstip_p2'		=> $ipParts[2],
				'nat_dstip_p3'		=> $ipParts[3],
				'col_nat_dstport'	=> $isRange ? '' : $name['targetPorts'],
				'nat_dstrange_p0'	=> $isRange ? $name['targetPorts'][0] : '',
				'nat_dstrange_p1'	=> $isRange ? $name['targetPorts'][1] : '',
				'nat_active'		=> $name['activate'] ? 'on' : '',
			);
		}
		else {
			$ipParts = explode('.', $targetIp);
			if (false === ip2long($targetIp) || count($ipParts) != 4) {
				self::throwException("Cannot add NAT rule, invalid IP: $targetIp");
			}
			$isRange = is_array($externalPorts);
			$parameters = array(
				'nat_rulename'  	=> $name,
				'nat_proto'			=> $protocol,
				'nat_range' 		=> $isRange ? 'true' : 'false',
				'nat_extport'		=> $isRange ? '' : $externalPorts,
				'nat_extrange_p0'	=> $isRange ? $externalPorts[0] : '',
				'nat_extrange_p1'	=> $isRange ? $externalPorts[1] : '',
				'nat_dstip_p0'		=> $ipParts[0],
				'nat_dstip_p1'		=> $ipParts[1],
				'nat_dstip_p2'		=> $ipParts[2],
				'nat_dstip_p3'		=> $ipParts[3],
				'nat_dstport'		=> $isRange ? '' : $targetPorts,
				'nat_dstrange_p0'	=> $isRange ? $targetPorts[0] : '',
				'nat_dstrange_p1'	=> $isRange ? $targetPorts[1] : '',
				'nat_active'		=> $active ? 'on' : '',
			);
		}
		
		// Create string representation of the rule
		$ruleSummary =  $parameters['nat_rulename'] . ' (' . ($parameters['nat_proto'] == 'both' ? 'tcp-udp' : $parameters['nat_proto']) . ') ';
		$ruleSummary .= ($isRange ? $parameters['nat_extrange_p0'] . '-' . $parameters['nat_extrange_p1'] : $parameters['nat_extport']);
		$ruleSummary .= ' => ' . join('.', $ipParts) . ':';
		$ruleSummary .= ($isRange ? $parameters['nat_dstrange_p0'] . '-' . $parameters['nat_dstrange_p1'] . ' ' : $parameters['nat_dstport']);
		$ruleSummary .= ($parameters['nat_active'] == 'on' ? 'ACTIVE' : 'INACTIVE');
		
		// Check rule
		if ($isRange) {
			if ($parameters['nat_dstrange_p1'] - $parameters['nat_dstrange_p0'] != $parameters['nat_extrange_p1'] - $parameters['nat_extrange_p0']) {
				self::throwException('Cannot add NAT rule, ranges do not match: ' . $ruleSummary);
			}
		}
		$this->log("Adding NAT rule: $ruleSummary");
		
		// Get original form values
		$res = $this->_sendRequest('/network/nat', 'get');
		$formData = $this->_getFormValues($res['body'], '//form[@id="form_nat"]');
		$parameters['action_add.x'] = 0;
		$parameters['action_add.y'] = 0;
		$parameters['port_list_tcp'] = urlencode($formData['port_list_tcp']);
		$parameters['port_list_udp'] = urlencode($formData['port_list_udp']);
		
		// Submit form values
		$res = $this->_sendRequest('/network/nat', 'post', $parameters);
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("NAT rule configuration may have failed: unexpected code HTTP $code returned");
		}
		
		//FIXME check *actual* success of the operation by reloading form values
		$this->log("Rule added successfully");
	}
	
	/**
	 * Remove a NAT rule
	 *
	 * TODO add remove-by-name ability
	 *
	 * @param int $id
	 */
	public function removeNatRule($id) {
		if (!(int) $id) {
			self::throwException("Cannot remove NAT rule, invalid ID: $id");
		}
		$id = (int) $id;
		$this->log("Removing NAT rule with ID: $id");
		
		// Get original form values
		$res = $this->_sendRequest('/network/nat', 'get');
		$formData = $this->_getFormValues($res['body'], '//form[@id="form_nat"]');
		if (!isset($formData["action_remove.$id"])) {
			self::throwException("Cannot remove NAT rule, no such ID: $id");
		}
		$parameters = array();
		$parameters["action_remove.$id.x"] = 0;
		$parameters["action_remove.$id.y"] = 0;
		$parameters['port_list_tcp'] = urlencode($formData['port_list_tcp']);
		$parameters['port_list_udp'] = urlencode($formData['port_list_udp']);
		
		// Submit form values
		$res = $this->_sendRequest('/network/nat', 'post', $parameters);
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("NAT rule configuration may have failed: unexpected code HTTP $code returned");
		}
		
		//FIXME check *actual* success of the operation by reloading form values
		$this->log("Rule removed successfully");
	}
	
	/**
	 *
	 * @return array
	 */
	public function getPhoneCallHistory() {
		$this->log("Retrieving phone call history...");
		$res = $this->_sendRequest('/state/voip');
		return $this->_getTableDataAsArrayWithHeaders($res['body'], '//table[@id="call_history_list"]');
	}
	
	public function getFullReport() {
	    $report = array();
        $myMethods = get_class_methods($this);
        $excludedMethods = array('getHost', 'getFullReport', 'getStatusAsString');
        sort($myMethods);
        
        foreach($myMethods as $methodName) {
            if (substr($methodName, 0, 3) == 'get' && !in_array($methodName, $excludedMethods)) {
                $key = self::_uncamelize(substr($methodName, 3));
                $report[$key] = call_user_func(array($this, $methodName));
            }
        }
	
		return $report;
	}
	
	public function reboot() {
		$this->log("Rebooting device...");
		$res = $this->_sendRequest('/reboot', 'post', array('submit' => ''));
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("Reboot may have failed: unexpected code HTTP $code returned");
		}
		$this->log("Reboot command sent successfully");
	}
	
	/**
	 * Enable or disable Wifi.
	 *
	 * @param boolean $enable
	 */
	public function enableWifi($enable = true) {
		$res = $this->_sendRequest('/wifi/config', 'get');
		$formData = $this->_getFormValues($res['body'], '//table[@id="access_point_config"]');
		
		// Override enable/disable value
		if ($enable) {
			$this->log("Enabling Wifi...");
			$formData['ap_active'] = 'on';
		}
		else {
			$this->log("Disabling Wifi...");
			$formData['ap_active'] = 'off';
		}
		
		$res = $this->_sendRequest('/wifi/config', 'post', $formData);
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("Wifi " . ($enable ? '' : 'de') . "activation may have failed: unexpected code HTTP $code returned");
		}
		$this->log("Wifi " . ($enable ? '' : 'de') . "activation command sent successfully");
	}
	
	public function disableWifi() {
		$this->enableWifi(false);
	}
	
	/**
	 * Set Wifi config.
	 *
	 * @param array $config Available values:
	 * 		ap_active	=> on/off			[You should use enableWifi() instead]
	 * 		ap_ssid 	=> (string)
	 * 		ap_closed 	=> 0/1
	 * 		ap_channel 	=> auto/0/1/2/.../13
	 * 		ap_mode		=> auto/11b/11g
	 */
	public function setWifiConfig($config) {
		$res = $this->_sendRequest('/wifi/config', 'get');
		$formData = $this->_getFormValues($res['body'], '//table[@id="access_point_config"]');
		
		$this->log("Setting Wifi configuration...");
		$formData = array_merge($formData, $config);
		
		$res = $this->_sendRequest('/wifi/config', 'post', $formData);
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("Wifi configuration may have failed: unexpected code HTTP $code returned");
		}
		$this->log("Wifi configuration set successfully");
	}
	
	/**
	 * Set Wifi security config.
	 *
	 * @param array $config Available values:
	 * 		wlan_encryptiontype	=> OPEN/WEP/WPA-PSK/WPA2-PSK/WPA-WPA2-PSK
	 * 		wlan_keytype		=> ascii/hexa
	 * 		wlan_wepkey			=> (string)
	 * 		wlan_wpakey			=> (string)
	 */
	public function setWifiSecurity($config) {
		$res = $this->_sendRequest('/wifi/security', 'get');
		$formData = $this->_getFormValues($res['body'], '//table[@id="wlan_encryption"]');
		
		$this->log("Setting Wifi configuration...");
		$formData = array_merge($formData, $config);
		
		$res = $this->_sendRequest('/wifi/security', 'post', $formData);
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("Wifi configuration may have failed: unexpected code HTTP $code returned");
		}
		$this->log("Wifi configuration set successfully");
	}

	/**
	 *
	 * @param boolean $enable
	 * @param string $mode "sfr" or "sfr_fon"
	 */
	public function enableHotspot($enable = true, $mode = 'sfr') {
		$res = $this->_sendRequest('/hotspot/config', 'get');
		$formData = $this->_getFormValues($res['body'], '//table[@id="hotspot_config"]');
		
		// Override enable/disable value
		if ($enable) {
			$this->log("Enabling hotspot...");
			$formData['hotspot_active'] = 'on';
			$formData['hotspot_mode'] = $mode;
			$formData['hotspot_conditions'] = 'accept';
		}
		else {
			$this->log("Disabling hotspot...");
			$formData['hotspot_active'] = 'off';
		}
		
		$res = $this->_sendRequest('/hotspot/config', 'post', $formData);
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("Hotspot " . ($enable ? '' : 'de') . "activation may have failed: unexpected code HTTP $code returned");
		}
		$this->log("Hotspot " . ($enable ? '' : 'de') . "activation command sent successfully");
		
		if ($enable && $this->getWifiStatus() != self::STATUS_CONNECTED) {
			$this->log('Hotspot cannot be active if Wifi is off', self::LOG_WARNING);
		}
	}
	
	public function disableHotspot() {
		$this->enableHotspot(false);
	}
	
	/**
	 * Exports the user configuration to the specified file.
	 *
	 * @param string $filename
	 */
	public function exportUserConfig($filename) {
		$this->log('Exporting user config...');
		$res = $this->_sendRequest('/maintenance/system', 'post', array(
			'action'	=> 'config_user_export',
		));
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("Cannot export user config: unexpected code HTTP $code returned");
		}
		if (false === file_put_contents($filename, $res['body'])) {
			self::throwException("Cannot export user config: unable to write data to $filename");
		}
		$this->log("User config exported successfully to $filename");
	}
	
	/**
	 * Ping a remote hostname.
	 *
	 * TODO Fix TTL timeout
	 *
	 * @param string $hostname
	 * @param int $count Default: 10
	 * @param float $timeout Max timeout for a request in seconds
	 * @return array(
	 * 		'hostname' 	=> (string),
	 * 		'status'	=> (string),
	 * 		'sent' 		=> (int),
	 * 		'received' 	=> (int),
	 * 		'avgrtt' 	=> (int)
	 * 	)
	 */
	public function ping($hostname, $count = 10, $timeout = 1.5) {
		static $lastPingTime;
		if (!isset($lastPingTime)) {
			$lastPingTime = microtime(true);
		}
		else {
			if (microtime(true) - $lastPingTime < 2) {
				$this->log("Last ping is too recent, delaying request...", self::LOG_DEBUG);
				usleep(1500000);
			}
		}
		$this->_resetCurl();
		
		$count = is_numeric($count) ? (int) $count : 10;
		$this->log("Sending $count ping requests to $hostname...");
		
		$ajaxHeaders = array(
			'X-Requested-With: XMLHttpRequest',
			'X-Requested-Handler: ajax',
		);
		
		// Start and retrieve ping ID
		$res = $this->_sendRequest('/maintenance/tests', 'post',
			array(
				'action'				=> 'ping',
				'ping_dest_hostname'	=> $hostname,
				'run'					=> 'start',
			),
			$ajaxHeaders
		);
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("Cannot start ping: unexpected code HTTP $code returned");
		}
		$startTime = microtime(true);
		$xml = new SimpleXMLElement($res['body']);
		if (! isset($xml->id)) {
			self::throwException('Cannot ping: no ID found in response body');
		}
		$id = (string) $xml->id;
		$this->log("Got ping id: " . $id, self::LOG_DEBUG);
		
		// Loop until count is reached
		$stopped = false;
		$finished = false;
		$maxEndTime = $startTime + ($count * $timeout);
		$stats = array();
		do {
			usleep(800000);
			$res = $this->_sendRequest('/maintenance/tests', 'post',
				array(
					'action'	=> 'ping',
					'id'		=> $id,
					'run'		=> 'status',
				),
				$ajaxHeaders
			);
			if (200 != ($code = $res['info']['http_code'])) {
				self::throwException("Cannot get ping status: unexpected code HTTP $code returned");
			}
			$xml = new SimpleXMLElement($res['body']);
			$sent = (int) (string) $xml->sent;
			$received = (int) (string) $xml->received;
			$status = (string) $xml->status['val'];
			
			// Update stats
			$stats['hostname'] = $hostname;
			$stats['status'] = $status;
			if (!$stopped) {
				$stats['sent'] = $sent;
			}
			$stats['received'] = $received;
			$stats['avgrtt'] = (int) (string) $xml->avgrtt;
			
			$this->log('Ping stats updated: ' . print_r($stats, true), self::LOG_DEBUG);
			
			if (!$stopped && $sent >= $count) {
				// Stop ping
				$res = $this->_sendRequest('/maintenance/tests', 'post',
					array(
						'action'	=> 'ping',
						'id'		=> $id,
						'run'		=> 'stop',
					),
					$ajaxHeaders
				);
				if (200 != ($code = $res['info']['http_code'])) {
					self::throwException("Failed tostop ping: unexpected code HTTP $code returned");
				}
				$stopped = true;
			}
			// Max ping reached
			elseif ('finished' === $status) {
				$stopped = true;
			}
			
			$now = microtime(true);
			//echo "START: $startTime | END: $maxEndTime | NOW: $now\n";
			if ($stopped && $status == 'finished' && $received >= $sent || $now >= $maxEndTime) {
				$finished = true;
			}
		}
		while(!$finished);
		$lastPingTime = microtime(true);
		
		return $stats;
	}
	
	/**
	 * Perform a traceroute to given hostname.
	 *
	 * @param string $hostname
	 * @return array
	 */
	public function traceroute($hostname) {
		$this->log("Performing traceroute to $hostname...");
		
		$ajaxHeaders = array(
			'X-Requested-With: XMLHttpRequest',
			'X-Requested-Handler: ajax',
		);
		
		// Start and retrieve ping ID
		$res = $this->_sendRequest('/maintenance/tests', 'post',
			array(
				'action'					=> 'traceroute',
				'traceroute_dest_hostname'	=> $hostname,
				'run'						=> 'start',
			),
			$ajaxHeaders
		);
		if (200 != ($code = $res['info']['http_code'])) {
			self::throwException("Cannot start traceroute: unexpected code HTTP $code returned");
		}
		$xml = new SimpleXMLElement($res['body']);
		if (! isset($xml->id)) {
			self::throwException('Cannot perform traceroute: no ID found in response body');
		}
		$id = (string) $xml->id;
		$this->log("Got traceroute id: " . $id, self::LOG_DEBUG);
		
		$stats = array();
		$finished = false;
		while(!$finished) {
			sleep(1);
			$res = $this->_sendRequest('/maintenance/tests', 'post',
				array(
					'action'	=> 'traceroute',
					'id'		=> $id,
					'run'		=> 'status',
				),
				$ajaxHeaders
			);
			if (200 != ($code = $res['info']['http_code'])) {
				self::throwException("Cannot get traceroute status: unexpected code HTTP $code returned");
			}
			$xml = new SimpleXMLElement($res['body']);
			if ('finished' === (string) $xml->status['val']) {
				$finished = true;
			}
		}
		
		// Retrieve hops data
		$stats = array();
		foreach($xml->hops as $hop) {
			if ($children = $hop->children()) {
				$hopDetails = array();
				foreach($children as $child) {
					foreach($child as $node) {
						$hopDetails[$node->getName()] = (string) $node;
					}
					$stats[] = $hopDetails;
				}
			}
		}
		return $stats;
	}
	
	
	///////////////////////////////////////////////////////////////////////////
	//			HELPERS
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 *
	 * @param string $html
	 * @param string $xpath
	 * @return int
	 */
	protected function _getStatusFromNodeCss($html, $xpath) {
		$dom = $this->_htmlToDOMXPath($html);
		$entries = $dom->query($xpath);
		if (null === $entries->item(0)) {
			self::throwException('Cannot find node at XPath "' . $xpath . '"');
		}
		$entry = $entries->item(0);
		
		$status = null;
		switch($entry->attributes->getNamedItem('class')->nodeValue) {
			case 'enabled':
				$status = self::STATUS_CONNECTED;
				break;
				
			case 'disabled':
				$status = self::STATUS_NOT_CONNECTED;
				break;
				
			case 'unused':
				$status = self::STATUS_UNUSED;
				break;
		}
		return $status;
	}
	
	/**
	 *
	 * @param string $html
	 * @param string $xpath XPath to the table holding data to be retrieved
	 * @return array
	 */
	protected function _getTableDataAsArray($html, $xpath) {
		$dom = $this->_htmlToDOMXPath($html);
		$entries = $dom->query($xpath);
		if (null === $entries->item(0) || $entries->item(0)->nodeName != 'table') {
			self::throwException('Cannot find <table> node at XPath "' . $xpath . '"');
		}
		$entry = $entries->item(0);
		
		$data = array();
		foreach($entry->childNodes as $childNode) {
			/** $childNode <tr> */
			$label = '';
			$value = '';
			foreach($childNode->childNodes as $node) {
				if ($node->nodeName == 'th') {
					$label = self::_normalizeText($node->textContent);
				}
				if ($node->nodeName == 'td') {
					$value = self::_normalizeText($node->textContent);
				}
			}
			if ($label && $value) {
				$data[$label] = $value;
			}
		}
		return $data;
	}
	
	/**
	 *
	 * @param string $html
	 * @param string $xpath XPath to the table holding data to be retrieved
	 * @return array
	 */
	protected function _getTableDataAsArrayWithHeaders($html, $xpath) {
		$dom = $this->_htmlToDOMXPath($html);
		$entries = $dom->query($xpath);
		if (null === $entries->item(0) || $entries->item(0)->nodeName != 'table') {
			self::throwException('Cannot find <table> node at XPath "' . $xpath . '"');
		}
		$entry = $entries->item(0);
		
		$data = array();
		
		// Cols
		$cols = array();
		$nodeList = $dom->query($xpath . '/thead/tr/th');
		foreach($nodeList as $node) {
			if ($node->nodeName == 'th') {
				$cols[] = self::_normalizeText($node->textContent);
			}
		}
		
		// Rows
		$rows = array();
		$rowNodeList = $dom->query($xpath . '/tbody/tr');
		$i = 0;
		foreach($rowNodeList as $rowNode) {
			$i++;
			$j = 0;
			foreach($rowNode->childNodes as $cellNode) {
				if ($cellNode->nodeName == 'td') {
					$colName = isset($cols[$j]) ? $cols[$j] : "{Column $j}";
					$j++;
					
					// Normal text node
					if ($value = self::_normalizeText($cellNode->textContent)) {
						$data[$i][$colName] = $value;
					}
					else {
						foreach($cellNode->childNodes as $subCellNode) {
							// Image node: retrieve "alt" attribute as text value
							if ($subCellNode->nodeName == 'img') {
								if ($value = $subCellNode->getAttribute('alt')) {
									$data[$i][$colName] = self::_normalizeText($value);
								}
							}
						}
					}
					
					// Fallback
					if (!isset($data[$i][$colName])) {
						$data[$i][$colName] = '';
					}
				}
			}
		}
		return $data;
	}
	
	/**
	 *
	 * @param string $html
	 * @param string $xpath XPath to the form
	 * @return DOMNodeList
	 */
	protected function _getFormValues($html, $xpath) {
		$dom = $this->_htmlToDOMXPath($html);
		$inputNodes = $dom->query($xpath . '//input | ' . $xpath . '//select');
		
		$formData = array();
		foreach($inputNodes as $inputNode) {
			$attributes = $inputNode->attributes;
			if ($inputNode->nodeName == 'input') {
				switch($attributes->getNamedItem('type')->nodeValue) {
					case 'radio':
						if ($attributes->getNamedItem('checked') && $attributes->getNamedItem('checked')->nodeValue == 'checked') {
							$formData[$attributes->getNamedItem('name')->nodeValue] = $attributes->getNamedItem('value')->nodeValue;
						}
						break;
						
					default:
						$formData[$attributes->getNamedItem('name')->nodeValue] = $attributes->getNamedItem('value') ? $attributes->getNamedItem('value')->nodeValue : '';
						break;
				}
			}
			elseif($inputNode->nodeName == 'select') {
				$optionNodes = $inputNode->getElementsByTagName('option');
				foreach($optionNodes as $optionNode) {
					if ($optionNode->attributes->getNamedItem('selected') && $optionNode->attributes->getNamedItem('selected')->nodeValue == 'selected') {
						$formData[$attributes->getNamedItem('name')->nodeValue] = $optionNode->attributes->getNamedItem('value')->nodeValue;
					}
				}
			}
		}
		return $formData;
	}
	
	public function log($msg, $level = self::LOG_NOTICE) {
		if ($level >= $this->logLevel) {
			echo self::formatLog($msg, $level);
		}
	}
	
	public static function formatLog($msg, $level = self::LOG_DEBUG) {
		switch($level) {
			case self::LOG_NOTICE:
				$level = 'NOTICE';
				break;
			
			case self::LOG_WARNING:
				$level = 'WARN';
				break;
			
			case self::LOG_ERROR:
				$level = 'ERROR';
				break;
				
			default:
				$level = 'DEBUG';
				break;
		}
		return date('Y-m-d H:i:s') . " [$level] " . print_r($msg, true) . "\n";
	}
	
	public function throwException($msg) {
		$this->log($msg, self::LOG_ERROR);
		throw new Exception($msg);
	}
	
	/**
	 * @see http://www.php.net/manual/en/function.http-parse-headers.php#77241
	 * @param string $rawHeaders
	 * @return array
	 */
	protected static function _parseHttpHeaders($rawHeaders) {
         $retVal = array();
         $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $rawHeaders));
         foreach( $fields as $field ) {
             if( preg_match('/([^:]+): (.+)/m', $field, $match) ) {
                 /* @deprecated after PHP 5.5.0 */
                 //$match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
                 $match[1] = preg_replace_callback(
                     '/(?<=^|[\x09\x20\x2D])./',
                     array('Neufbox4', '_headerReplaceCallback'),
                     strtolower(trim($match[1]))
                 );
                 if( isset($retVal[$match[1]]) ) {
                     if (!is_array($retVal[$match[1]])) {
                         $retVal[$match[1]] = array($retVal[$match[1]]);
                     }
                     $retVal[$match[1]][] = $match[2];
                 } else {
                     $retVal[$match[1]] = trim($match[2]);
                 }
             }
         }
         return $retVal;
	}
	
	protected static function _headerReplaceCallback($str) {
	    return strtoupper($str[0]);
	}
	
	/**
	 *
	 * @param int $status
	 * @return string
	 */
	public static function getStatusAsString($status) {
		$string = null;
		switch ($status) {
			case self::STATUS_CONNECTED:
				$string = 'Connected';
				break;
			case self::STATUS_CONNECTING:
				$string = 'Connecting';
				break;
			case self::STATUS_UNUSED:
				$string = 'Uused';
				break;
			case self::STATUS_NOT_CONNECTED:
				$string = 'Not Connected';
				break;
		}
		return $string;
	}
	
	protected static function _trim($text) {
		return trim($text, chr(32) . chr(160));		//32 = space / 160 = non-breakable space
	}
	
	protected static function _normalizeText($text) {
		return self::_trim(preg_replace('/\s+/', ' ', str_replace(array("\n", "\r\n"), '', $text)));
	}
	
	protected function _uncamelize($string) {
	   return strtolower(preg_replace('/(.)([A-Z])/', '$1_$2', $string));
	}
	
	public static function checkRequirements() {
		$classes = array(
			'DOMDocument'		=> 'DOMDocument',
			'DOMXPath'			=> 'DOMXPath',
			'SimpleXMLElement'	=> 'SimpleXMLElement',
		);
		$functions = array(
			'curl_init'	=> 'cURL',
		);
		
		if (-1 == version_compare(phpversion(), '5.1.2')) {
			echo "WARNING: PHP 5.1.2 or above is required.\n";
		}
		foreach($classes as $class => $lib) {
			if (!class_exists($class)) {
				throw new Exception("Missing required class/library: '$lib'. Please check your PHP configuration");
			}
		}
		foreach($functions as $function => $lib) {
			if (!function_exists($function)) {
				throw new Exception("Missing required function/library: '$lib'. Please check your PHP configuration");
			}
		}
	}
}
