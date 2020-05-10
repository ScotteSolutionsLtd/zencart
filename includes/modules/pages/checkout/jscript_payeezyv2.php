<?php
/**
 * Javascript to prep functionality for Payeezy payment module.
 *
 * @package payeezy
 * @copyright Copyright 2003-2020 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Scott Morrison May 10, 2020 v157-payeezyjs-2.0-dev $
 */
if (!defined(MODULE_PAYMENT_PAYEEZYJSV2_STATUS) || MODULE_PAYMENT_PAYEEZYJSV2_STATUS != 'True' || (!defined('MODULE_PAYMENT_PAYEEZYJSV2_JSSECURITY_KEY') && !defined('MODULE_PAYMENT_PAYEEZYJSV2_JSSECURITY_KEY_SANDBOX') )) {
	return false;
}
if ($payment_modules->in_special_checkout()) {
    return false;
}