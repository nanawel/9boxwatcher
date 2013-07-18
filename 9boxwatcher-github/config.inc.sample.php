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

/**
 * Administrator GUI login
 * Default: admin
 * @var string
 */
define('NEUFBOX_LOGIN', 'admin'); 

/**
 * Administrator GUI password
 * Default: look on your Neufbox
 * @var string
 */
define('NEUFBOX_PASSWORD', '');

/**
 * Neufbox IP from LAN
 * Default: 192.168.1.1
 * @var string
 */
define('NEUFBOX_HOST', '192.168.1.1');

/**
 * 120s = 2mn
 * Delay after sending the reboot order before checking connection again.
 * @var int
 */
define('REBOOT_WAIT_DELAY', 120);
