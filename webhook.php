<?php
/**
 * @file webhook.php
 * Where the Payeezy API data comes in for processing.
 *
 * @copyright Copyright 2003-2019 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @package paymentMethod
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Scott Morrison May 10, 2020 v157-payeezyjs-2.0-dev $
 */
$logfile = DIR_FS_LOGS . '/logs/PayeezyJSV2-API-' . date('m-d-y') . '.log';
$response = $msg = $logdata = '';
error_reporting(E_ALL);

require 'includes/application_top.php';
if (MODULE_PAYMENT_PAYEEZYJSV2_DEBUG === 'Off') {
  // Get the value of Client-Token from the headers.
  $headers = [];
  foreach (getallheaders() as $name => $value)
    $headers[$name] = $value;

  $logdata = "webhook.php Headers:\r\n";
  $logdata .= print_r($headers, 1);
  $clientToken = isset($headers['Client-Token']) ? $headers['Client-Token'] : false;

  if ($clientToken)
    $inputJSON = file_get_contents('php://input');

  if ($inputJSON)
    $response = json_decode($inputJSON, true);
}

else {
  // An example successful authorization response. Add your debugging here.
  $response = [
    'authCode' => '',
    'card' => [
      'bin' => '552639',
      'brand' => 'mastercard',
      'exp' => [
        'month' => '11',
        'year' => '2023',
      ],
      'last4' => '8568',
      'masked' => 'XXXXXXXXXXXX8568',
      'name' => 'El Slozzo',
      'token' => '2368514472798568',
    ],
    'error' => '',
    'gatewayRefId' => '228.8602817418565,',
    'zeroDollarAuth' => '',
  ];
  $clientToken = '1234567890';
}
$logdata .= "\r\nResponse: \r\n" . print_r($response, 1) . "\r\n";

if (isset($response['error']) && empty($response['error'])) {
  global $order_totals, $order, $insert_id, $order_total_modules;
  require_once './includes/application_top.php';
  require 'includes/classes/order.php';
  require 'includes/classes/order_total.php';
  $payeezyjsv2_module = 'payeezyjsv2';
  require(DIR_WS_CLASSES . 'payment.php');
  require('includes/modules/payment/payeezyjsv2.php');
  $payment_modules = new payment($payeezyjsv2_module);
  $payeezyjsv2 = $payment_modules->paymentClass;
  $payeezyjsv2->response = $response;

  if ($payeezyjsv2->getByClientToken($clientToken)) {
    // Values from the response are saved to construct the transaction later.
    // Some incoming values may need to be adjusted to work in the transaction call.
    if ($response['card']['brand'] === 'american-express')
      $response['card']['brand'] = 'American Express';

    $payeezyjsv2->response_code = $response['authCode'];
    $payeezyjsv2->card_type = $response['card']['brand'];
    $payeezyjsv2->cc_name = $response['card']['name'];
    $payeezyjsv2->expy = $response['card']['exp']['month'] . substr($response['card']['exp']['year'], -2);
    $payeezyjsv2->token = $response['card']['token'];
    $payeezyjsv2->gatewayRefId = rtrim($response['gatewayRefId'], ', ');
    $payeezyjsv2->last4 = $response['card']['last4'];
    $payeezyjsv2->masked = $response['card']['masked'];
    $payeezyjsv2->zeroDollarAuth = $response['zeroDollarAuth'];
    $payeezyjsv2->saveTrans();
  }
}

else {
  $auth_error_msg = isset($response['error']) ? $response['error'] : '';
  if (isset($response['reason']))
    $msg .= 'The transaction has been marked as ' . $response['reason'] . ".\r\n";

  if (isset($response["gatewayReason"]))
    foreach ($response["gatewayReason"] as $msgs)
      $msg .= $msgs['description'] . ".\r\n";

  $logdata .= $auth_error_msg . $msg;
  file_put_contents($logfile, $logdata . PHP_EOL, FILE_APPEND | LOCK_EX);
}
