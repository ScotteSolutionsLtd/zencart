<?php
/**
 * Payeezy v2 payment module language defines
 *
 * @package paymentMethod
 * @copyright Copyright 2003-2012 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Scott Morrison May 10, 2020 v157-payeezyjs-2.0-dev $
 */

define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_DESCRIPTION', 'PayeezyJS v2<br><br>Uses version 2 of the Payeezy PaymentsJS V2 API.<br>Run PCI Compliant credit card payments using your Zen Cart store.<br><br><a href="https://docs.paymentjs.firstdata.com/" target="_blank">Click for more information or to sign up an account</a><br><br><a href="https://developer.payeezy.com/" target="_blank">Log In To Your Payeezy Developer Account</a>');
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_ADMIN_TITLE', 'Payeezy PaymentsJS V2'); // Payment option title as displayed in the admin
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CATALOG_TITLE', 'Credit Card');  // Payment option title as displayed to the customer
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_OWNER', 'Card Owner: ');
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_NUMBER', 'Card Number: ');
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_EXPIRES', 'Expiry Date: ');
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CVV', 'CVV Number: ');
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_TYPE', 'Credit Card Type: ');

define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_ERROR', "Your transaction could not be completed because of an error: ");
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_MISCONFIGURATION', "Your transaction could not be completed due to a misconfiguration. Please contact us for assistance.");
define('MODULE_PAYMENT_PAYEEZYJSV2_TEXT_COMM_ERROR', 'Unable to process payment due to a communications error. You may try again or contact us for assistance.');
define('MODULE_PAYMENT_PAYEEZYJSV2_ERROR_MISSING_FDTOKEN', "We could not initiate your transaction because of a system scripting error. Please report this error to the Store Owner: PAYEEZY-FDTOKEN-MISSING");
define('MODULE_PAYMENT_PAYEEZYJSV2_ERROR_DECLINED', 'Sorry, your payment could not be authorized. Please use an alternate method of payment.');
