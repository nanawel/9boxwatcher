<?php
/**
 * 9BOXWATCHER
 * Small script to check ADSL connection on Neufbox devices
 * and reboot to force reconnecting if needed.
 * 
 * @author Anael Ollier <nanawel@gmail.com>
 * @version 0.1.2
 * @license GPLv3 <http://www.gnu.org/copyleft/gpl.html>
 * 
 * 
 * See config.inc.php for configuration
 */


/*
 * Check and load config & classes
 */
$thisDir = dirname(__FILE__);
set_include_path(get_include_path() . PATH_SEPARATOR . $thisDir . '/libs');

if (!is_file($thisDir . '/config.inc.php')) {
	die("Missing config file.\nRename config.inc.sample.php to config.inc.php and open it with a text editor to adapt the configuration.\n");
}
require 'config.inc.php';
require 'NeufboxWatcher.class.php';
require 'Mutex.class.php';
require 'DataFormatter.class.php';

/*
 * Process script arguments
 */
$usage = 'php -f ' . basename(__FILE__) . " -- \\\n\t"
	. "[ -a | --action fullreport|checkandreboot|reboot|exportuserconfig|\n"
	. "                 wifistatus|enablewifi|disablewifi|hotspotstatus|enablehotspot|\n"
	. "                 disablehotspot|adslinfo ] \\\n\t"
	. "[ -o | --output human(default)|script|csv ] \\\n\t"
	. "[ -s | --silent-success ] \\\n\t"
	. "[ -m | --mutex ] \\\n\t"
	. "[ -d | --debug ]";
$longopts  = array(
	'action:',
	'output:',
	'silent-success',
	'mutex',
	'debug',
);
$options = getopt('a:smd', $longopts);

// Process action argument
if (!isset($options['action']) && !isset($options['a'])) {
	die("Missing --action argument.\nUsage:\t$usage\n");
}
$action = isset($options['action']) ? $options['action'] : $options['a'];

// Process output argument
if (isset($options['output'])) {
	$output = $options['output'];
}
elseif (isset($options['o'])) {
	$output = $options['o'];
}
else {
	$output = 'human';
}
switch($output) {
	case 'human':
		$output = DataFormatter::OUTPUT_HUMAN;
		break;
		
	case 'script':
		$output = DataFormatter::OUTPUT_SCRIPT;
		break;
		
	case 'csv':
		$output = DataFormatter::OUTPUT_CSV;
		break;
		
	default:
		die("Unknown --output argument.\nUsage:\t$usage\n");
}

// Process silent-success argument
$silentSuccess = false;
if (isset($options['silent-success']) || isset($options['s'])) {
	$silentSuccess = true;
}

// Process mutex argument
$mutex = (isset($options['mutex']) || isset($options['m'])) ? true : false;

// Debug
$debug = (isset($options['debug']) || isset($options['d'])) ? true : false;


/*
 * Now do the real job
 */
Neufbox4::checkRequirements();

// Prevent simultaneous executions
if ($mutex) {
	$mutex = new Mutex();
	$mutexId = preg_replace('/[^0-9]*/', '', md5(__FILE__));		// path-dependant ID
	$mutex->init($mutexId);
	if (!$mutex->acquire()) {
		die('It seems like another instance of the script is already running. Exiting.');
	}
	function NeufboxWatcher_onExit() {
		global $mutex;
		$mutex->release();
	}
	register_shutdown_function('NeufboxWatcher_onExit');
}


/*
 * Initialize main helper class and set log level
 */
if ($silentSuccess) {
	$neufbox = new Neufbox4(NEUFBOX_HOST, Neufbox4::LOG_WARNING);
}
else {
	$neufbox = new Neufbox4(NEUFBOX_HOST);
}

if (!NEUFBOX_PASSWORD) {
	die("Missing password in configuration.\nPlease edit config.inc.php and fill the value NEUFBOX_PASSWORD.\n");
}

if ($debug) {
    $neufbox->debug = $debug;
    $neufbox->logLevel = Neufbox4::LOG_DEBUG;
}
$neufbox->login(NEUFBOX_LOGIN, NEUFBOX_PASSWORD);

/*
 * Run the watcher
 */
$formatter = new DataFormatter($output);
switch($action) {
	case 'fullreport':
		foreach($neufbox->getFullReport() as $key => $data) {
			if (substr($key, -6) == 'status') {
				$data = Neufbox4::getStatusAsString($data);
			}
			echo $formatter->format($data, $key) . "\n\n";
		}
		break;
		
	case 'checkandreboot':
		$watcher = new NeufboxWatcher($neufbox);
		$watcher->setRebootWaitDelay(REBOOT_WAIT_DELAY);
		$watcher->checkAdslAndReboot($silentSuccess);
		break;
		
	case 'reboot':
		$neufbox->reboot();
		break;
		
	case 'exportuserconfig':
		$neufbox->exportUserConfig('nb4_userconfig_' . time() . '.conf');
		break;
		
	case 'adslinfo':
		echo $formatter->format($neufbox->getAdslInfo()) . "\n";
		break;
		
	case 'wifistatus':
		echo Neufbox4::getStatusAsString($neufbox->getWifiStatus()) . "\n";
		break;
		
	case 'enablewifi':
		$neufbox->enableWifi();
		break;
		
	case 'disablewifi':
		$neufbox->disableWifi();
		break;
		
	case 'hotspotstatus':
		echo Neufbox4::getStatusAsString($neufbox->getHotspotStatus()) . "\n";
		break;
		
	case 'enablehotspot':
		$neufbox->enableHotspot();
		break;
		
	case 'disablehotspot':
		$neufbox->disableHotspot();
		break;
		
	default:
		die("Unknown --action argument.\nUsage:\t$usage\n");
}
