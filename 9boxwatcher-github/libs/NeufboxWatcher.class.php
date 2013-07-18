<?php
/**
 * 9BOXWATCHER
 * Small script to check ADSL connection on Neufbox devices
 * and reboot to force reconnecting if needed.
 * 
 * @author Anael Ollier <nanawel@gmail.com>
 * @version 0.1.0
 * @license GPLv3 <http://www.gnu.org/copyleft/gpl.html>
 */
require 'Neufbox4.class.php';

/**
 * NeufboxWatcher class.
 * 
 * @author Anael Ollier <nanawel@gmail.com>
 * @version 0.1.0
 * @since 2011-12-26
 */
class NeufboxWatcher {
	
	/** @var int */
	const DEFAULT_REBOOT_WAIT_DELAY = 120;
	/** @var int */
	const DEFAULT_FAIL_COUNT_BEFORE_REBOOT = 3;
	
	/** @var Neufbox4 */
	protected $_neufbox = null;
	
	/** @var int */
	protected $_rebootWaitDelay = self::DEFAULT_REBOOT_WAIT_DELAY;
	
	
	public function __construct($neufbox) {
		$this->_neufbox = $neufbox;
	}
	
	public function setRebootWaitDelay($sec) {
		if ((int) $sec) {
			$this->_rebootWaitDelay = (int) $sec;
		}
	}
	
	/**
	 * Check ADSL status and reboot Neufbox immediately if connection is down.
	 */
	public function checkAdslAndReboot() {
		$this->_neufbox->log("Checking ADSL status...", Neufbox4::LOG_NOTICE);
		if ($this->_neufbox->getIpv4Status() != Neufbox4::STATUS_CONNECTED) {
			$this->_neufbox->log("ADSL down, rebooting Neufbox...", Neufbox4::LOG_WARNING);
			$this->_neufbox->reboot();
		}
		else {
			$this->_neufbox->log("ADSL up, no action needed.", Neufbox4::LOG_NOTICE);
			return;
		}
		
		sleep($this->_rebootWaitDelay);
		
		if ($this->_neufbox->getIpv4Status() != Neufbox4::STATUS_CONNECTED) {
			$this->_neufbox->log("ADSL *still* down, aborting.", Neufbox4::LOG_ERROR);
		}
		else {
			$this->_neufbox->log("ADSL back up, retrieving system info...", Neufbox4::LOG_NOTICE);
			$this->_neufbox->log($this->_neufbox->getFullReport(), Neufbox4::LOG_NOTICE);
		}
	}
	
	/**
	 * Check ADSL status and reboot Neufbox after $failCountBeforeReboot consequent failures.
	 * 
	 * /!\ Warning: not tested yet.
	 * 
	 * @param int $failCountBeforeReboot
	 */
	public function checkAdslAndRebootOnMultipleFail($failCountBeforeReboot = self::DEFAULT_FAIL_COUNT_BEFORE_REBOOT) {
		$counterFilename = './' . $this->_neufbox->getHost() . '_fail_count';
		if ($this->_neufbox->getIpv4Status() != Neufbox4::STATUS_CONNECTED) {
			$currentFailCount = (int) file_get_contents($counterFilename);
			$currentFailCount++;
			file_put_contents('./fail_count', $currentFailCount);
			
			if ($currentFailCount >= $failCountBeforeReboot) {
				$this->_neufbox->log("ADSL down for $currentFailCount checks, rebooting Neufbox...", Neufbox4::LOG_WARNING);
				$this->_neufbox->reboot();
				
				sleep($this->_rebootWaitDelay);
			
				if ($this->_neufbox->getIpv4Status() != Neufbox4::STATUS_CONNECTED) {
					$this->_neufbox->log("ADSL *still* down, aborting.", Neufbox4::LOG_ERROR);

					// Keep counter, next execution will try to reboot again without delay
				}
				else {
					$this->_neufbox->log("ADSL back up, retrieving system info...", Neufbox4::LOG_NOTICE);
					$this->_neufbox->log($this->_neufbox->getFullReport(), Neufbox4::LOG_NOTICE);
					
					// Reset counter
					file_put_contents($counterFilename, 0);
				}
			}
			else {
				$this->_neufbox->log("ADSL down, postponing reboot after $failCountBeforeReboot fails.", Neufbox4::LOG_NOTICE);
			}
		}
		else {
			// Reset counter
			file_put_contents($counterFilename, 0);
		}
	}
}
