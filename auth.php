<?php
/**
 * @file auth.php
 * Callback handler for PaymentsJS v2 authorization tokens.
 *
 * @copyright Copyright 2003-2019 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @package paymentMethod
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Scott Morrison May 10, 2020 v157-payeezyjsv2-2.0-dev $
 *
 */
require_once './includes/application_top.php';

if (zen_is_logged_in() && isset($_SESSION['cart']->cartID)) {
  global $order, $insert_id, $customer, $order_totals;

  require_once 'includes/classes/order.php';
  require_once 'includes/classes/order_total.php';
  $payeezyjsv2_module = 'payeezyjsv2';
  require_once DIR_WS_CLASSES . 'payment.php';
  require_once 'includes/modules/payment/payeezyjsv2.php';
  $payment_modules = new payment($payeezyjsv2_module);
  $payeezyjsv2 = $payment_modules->paymentClass;
  $payeezyjsv2->authsess();
}
// Nothing left to do. The user will be redirected via javascript upon response.