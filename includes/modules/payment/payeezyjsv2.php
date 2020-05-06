<?php
/**
 * Payeezy v2 payment module for Zen Cart
 * Updated from the ZC 1.5.6 version to use PaymentsJS v2 API for processing payments through Firstdata bank.
 * @see https://developer.payeezy.com/user/login to signup a Payeezy developer account
 * Payeezy example provided here: https://github.com/GBSEcom/paymentJS_php_integration
 *
 * Add the following line in your template footer for pages that have the credit card form:
 * require DIR_WS_MODULES . 'payment/payeezyjsv2/footer.inc';
 *
 * Requires PHP > 7
 * @package paymentMethod
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Scott Morrison May 10, 2020 v157-payeezyjsv2-2.0-dev $
 */

if (!defined('DB_PREFIX')) {
  define('DB_PREFIX', '');
}
define('TABLE_PAYEEZY_TRANSACTIONS', DB_PREFIX . 'payeezy_transactions');

/**
 * Payeezy Payment module class
 */
class payeezyjsv2 extends base
{
  /**
   * $code Zen Cart internal machine name label for this payment module.
   *
   * @var string
   */
  var $code = 'payeezyjsv2';

  /**
   * $moduleVersion Plugin version number.
   */
  var $moduleVersion = '2.0';

  /**
   * @var bool $enabled Whether the module is enabled.
   * @var string $title Display name for this payment method.
   * @var string $desription Internal description of the module.
   * @var string $commError Captures Communication error messages.
   * @var int $commErrNo Captures curl errors from curl attempts.
   * @global int $sort_order This module's internal sort order weight when delivering the list of payment modules.
   */
  var $enabled, $title, $description, $commError, $commErrNo, $sort_order = 0;

  /**
   * $commError and $commErrNo are communication details used for debugging.
   */
  var $response, $response_code, $card_type, $expy, $last4, $cc_name, $token, $gatewayRefId, $zeroDollarAuth, $customer_id;

  /**
   * Various transaction variables to hold the IDs of things.
   */
  var $transaction_id, $transaction_messages, $auth_code, $clientToken;

  var $session_vars = [
    'customer_id' => '',
    'billto' => '',
    'sendto' => '',
    'languages_id' => '',
    'cartID' => '',
    'shipping' => []
  ];

  /**
   * Internal variables.
   *
   * @var array $cvv_codes Canned responses for various CVV results.
   * @var array $avs_codes Canned responses for various AVS responses.
   * @var string $mode Mode is either "development" or "production".
   */
  private $avs_codes, $cvv_codes, $mode;

  /**
   * Advanced setting to enable expedited processing.
   */
  private $etppid = 'a77026b9457c8cf77ac73268ce5873cb02f41c0413f1f43d';

  /**
   * @global null|object $order A Zen Cart order or order_id.
   * @global null|string $insert_id Will exist if it's a new order.
   */
  function __construct()
  {
    global $order, $insert_id;
    $this->enabled = defined('MODULE_PAYMENT_PAYEEZYJSV2_STATUS') && MODULE_PAYMENT_PAYEEZYJSV2_STATUS == 'True';
    $this->title = MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CATALOG_TITLE;
    $this->description = MODULE_PAYMENT_PAYEEZYJSV2_TEXT_DESCRIPTION;
    $this->sort_order = defined('MODULE_PAYMENT_PAYEEZYJSV2_SORT_ORDER') ? MODULE_PAYMENT_PAYEEZYJSV2_SORT_ORDER : null;
    $this->mode = MODULE_PAYMENT_PAYEEZYJSV2_TESTING_MODE;

    if (!isset($this->order_status)) {
      /*
       * MODULE_PAYMENT_PAYEEZYJSV2_TOKEN_STATUS_ID
       * Represents the first pre-authorization order status (and can also be used for pre-auths).
      */
      if (MODULE_PAYMENT_PAYEEZYJSV2_ORDER_STATUS_ID > 0) {
        $this->order_status = MODULE_PAYMENT_PAYEEZYJSV2_TOKEN_STATUS_ID;
      }
      // Reset order status to pending if capture pending:
      if (MODULE_PAYMENT_PAYEEZYJSV2_TRANSACTION_TYPE == 'authorize') {
        $this->order_status = MODULE_PAYMENT_PAYEEZYJSV2_TOKEN_STATUS_ID;
      }
    }

    if (IS_ADMIN_FLAG === true) {
      $this->title = MODULE_PAYMENT_PAYEEZYJSV2_TEXT_ADMIN_TITLE;
      if (defined('MODULE_PAYMENT_PAYEEZYJSV2_STATUS')) {
        if (MODULE_PAYMENT_PAYEEZYJSV2_API_SECRET == '' && MODULE_PAYMENT_PAYEEZYJSV2_API_SECRET_SANDBOX == '') {
          $this->title .= '<span class="alert"> (not configured - API details needed)</span>';
        }
        if ($this->mode == 'Testing') {
          $this->title .= '<span class="alert"> (Sandbox mode)</span>';
        }
        //$this->title .= '<script src="' . DIR_WS_ADMIN . 'includes/javascript/payeezyjsv2.js"></script>';
        $new_version_details = false; //plugin_version_check_for_updates(2050, $this->moduleVersion);
        if ($new_version_details !== false) {
          $this->title .= '<span class="alert">' . ' - NOTE: A NEW VERSION OF THIS PLUGIN IS AVAILABLE. <a href="' . $new_version_details['link'] . '" target="_blank">[Details]</a>' . '</span>';
        }
      }
    }

    if (!empty($insert_id)) {
      $this->getTrans($insert_id);
      $this->customer_id = $this->order['customer_id'];
      $order = $this->order;
    }

    $this->_logDir = DIR_FS_LOGS;

    // Check for zone compliance and other conditions.
    if (is_object($order)) {
      $this->update_status();
      $this->shipping = isset($_SESSION["shipping"]) ? $_SESSION["shipping"] : '';
    }
    $this->setAvsCvvMeanings();
    $this->setSystemErrorCodes();

    return $this;
  }

  /**
   * Gives the ability to disable this payment module before any possible usage.
   * Set $this->enabled to false to disable.
   */
  function update_status()
  {
    global $order, $db;
    if ($this->enabled == false) {
      return;
    }
    elseif ((int)MODULE_PAYMENT_PAYEEZYJSV2_ZONE != 0) {
      if (isset($order->billing['country']) && isset($order->billing['country']['id'])) {
        $check_flag = false;
        $sql = "SELECT zone_id FROM " . TABLE_ZONES_TO_GEO_ZONES . " WHERE geo_zone_id = '" . (int)MODULE_PAYMENT_PAYEEZYJSV2_ZONE . "' AND zone_country_id = '" . (int)$order->billing['country']['id'] . "' ORDER BY zone_id";
        $checks = $db->Execute($sql);
        foreach ($checks as $check) {
          if ($check['zone_id'] < 1) {
            $check_flag = true;
            break;
          }
          elseif ($check['zone_id'] == $order->billing['zone_id']) {
            $check_flag = true;
            break;
          }
        }
        if ($check_flag == false) {
          $this->enabled = false;
        }
      }
    }
  }

  /**
   * This can put js in the header of pages with the card form.
   *
   * @return string
   */
  function javascript_validation()
  {
    return '';
  }

  /**
   * Display Credit Card Information Submission Fields for checkout.
   *
   * @return array
   */
  function selection()
  {
    global $order;

    return array(
      'id' => $this->code,
      'module' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CATALOG_TITLE,
      'fields' => [
        array(
          'title' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_OWNER,
          'field' => '<div class="form-controls payment-fields disabled" id="cc-name" data-cc-name></div>',
          'tag' => 'cc-name',
        ),
        array(
          'title' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_NUMBER,
          'field' => '<div class="form-controls payment-fields disabled empty" id="cc-card" data-cc-card></div>',
          'tag' => 'cc-card',
        ),
        array(
          'title' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_EXPIRES,
          'field' => '<div class="form-controls payment-fields disabled empty" id="cc-exp" data-cc-exp></div>',
          'tag' => 'cc-exp',
        ),
        array(
          'title' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CVV,
          'field' => '<div class="form-controls payment-fields disabled empty" id="cc-cvv" data-cc-cvv></div>',
          'tag' => 'cc-cvv',
        ),
        array(
          'title' => '',
          'field' => zen_draw_hidden_field(
              $this->code . '_fdtoken',
              '',
              'id="' . $this->code . '_fdtoken"'
            ) . '<div id="payeezy-payment-errors"></div>' .
            zen_draw_hidden_field($this->code . '_cc_number', '', 'id="' . $this->code . '_cc_number') .
            zen_draw_hidden_field($this->code . '_currency', $order->info['currency'], 'payeezy-data="currency"') .
            zen_draw_hidden_field(
              $this->code . '_billing_street',
              $order->billing['street_address'],
              'payeezy-data="billing.street"'
            ) .
            zen_draw_hidden_field(
              $this->code . '_billing_city',
              $order->billing['city'],
              'payeezy-data="billing.city"'
            ) .
            zen_draw_hidden_field(
              $this->code . '_billing_state',
              zen_get_zone_code(
                $order->billing['country']['id'],
                $order->billing['zone_id'],
                $order->billing['state']
              ),
              'payeezy-data="billing.state"'
            ) .
            zen_draw_hidden_field(
              $this->code . '_billing_country',
              $order->billing['country']['iso_code_2'],
              'payeezy-data="billing.country"'
            ) .
            zen_draw_hidden_field(
              $this->code . '_billing_zip',
              $order->billing['postcode'],
              'payeezy-data="billing.zip"'
            ) .
            zen_draw_hidden_field(
              $this->code . '_billing_email',
              $order->customer['email_address'],
              'payeezy-data="billing.email"'
            ) .
            zen_draw_hidden_field(
              $this->code . '_billing_phone',
              $order->customer['telephone'],
              'payeezy-data="billing.phone"'
            ),
          'tag' => '',
        )
      ]
    );
  }

  /**
   * Returning true here moves the card input process to the final/confirmation page.
   * @todo Configure as an admin switch. Returning false puts the form on the checkout_payment page.
   *
   * @return bool
   */
  public function in_special_checkout()
  {
    return true;
  }

  /**
   * Display Credit Card Information on the Checkout Confirmation Page.
   *
   * @return array
   */
  public function confirmation()
  {
    return array(
      'id' => $this->code,
      'module' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CATALOG_TITLE,
      'fields' => array(
        array(
          'title' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_OWNER,
          'field' => '<div class="form-controls payment-fields disabled" id="cc-name" data-cc-name></div>',
          'tag' => 'cc-name',
        ),
        array(
          'title' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_NUMBER,
          'field' => '<div class="form-controls payment-fields disabled empty" id="cc-card" data-cc-card></div>',
          'tag' => 'cc-card',
        ),
        array(
          'title' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CREDIT_CARD_EXPIRES,
          'field' => '<div class="form-controls payment-fields disabled empty" id="cc-exp" data-cc-exp></div>',
          'tag' => 'cc-exp',
        ),
        array(
          'title' => MODULE_PAYMENT_PAYEEZYJSV2_TEXT_CVV,
          'field' => '<div class="form-controls payment-fields disabled empty" id="cc-cvv" data-cc-cvv></div>',
          'tag' => 'cc-cvv',
        ),
      ),
    );
  }

  public function process_button()
  {
    return '<div class="buttonRow forward"><button id="submit" class="btn--primary disabled-bkg pull-right" data-submit-btn disabled>
                <span class="btn__loader" style="display:none;">loading...</span>Pay <span data-card-type></span>
            </button>
            <button class="btn--secondary" data-reset-btn>Reset</button></div>';
  }

  /**
   * A global $order here is a new unsaved one.
   */
  public function before_process()
  {
    global $db, $messageStack, $order, $order_totals;
    if (empty($this->customer_id)) {
      $this->customer_id = $customer_id = $_SESSION['customer_id'];
    }
    sleep(1);
    $sql = "SELECT * FROM " . TABLE_PAYEEZY_TRANSACTIONS . " WHERE session_id = '" . $_SESSION['securityToken'] . "' ORDER BY updated desc LIMIT 1";
    $result = $db->Execute($sql);
    if (!empty($result->fields)) {
      foreach ($result->fields as $field => $value) {
        $this->{$field} = $value;
      }
    }
    else {
      $messageStack->add_session('checkout_payment', MODULE_PAYMENT_PAYEEZYJSV2_TEXT_COMM_ERROR, 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT, '', 'SSL', true, false));
    }
    if (empty($this->clientToken)) {
      $messageStack->add_session('checkout_payment', MODULE_PAYMENT_PAYEEZYJSV2_TEXT_COMM_ERROR, 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    $order->info['cc_owner'] = $this->cc_name;
    $order->info['cc_type'] = $this->card_type;
    $order->info['cc_number'] = $this->token;
    $order->info['cc_expires'] = $this->expy;

    // lookup shipping and discount amounts
    $args['x_freight'] = $args['x_tax'] = $args['discount_amount'] = 0;
    if (count($order_totals)) {
      for ($i = 0, $n = count($order_totals); $i < $n; $i++) {
        if ($order_totals[$i]['code'] == '') {
          continue;
        }
        if (in_array(
          $order_totals[$i]['code'],
          array('ot_total', 'ot_subtotal', 'ot_tax', 'ot_shipping', 'insurance')
        )) {
          if ($order_totals[$i]['code'] == 'ot_shipping') {
            $args['x_freight'] = round($order_totals[$i]['value'], 2);
          }
          if ($order_totals[$i]['code'] == 'ot_tax') {
            $args['x_tax'] = round($order_totals[$i]['value'], 2);
          }
        }
        else {
          // Handle credits.
          global ${$order_totals[$i]['code']};
          if ((substr($order_totals[$i]['text'], 0, 1) == '-') ||
            (isset(${$order_totals[$i]['code']}->credit_class) &&
              ${$order_totals[$i]['code']}->credit_class == true)) {
            $args['discount_amount'] += round($order_totals[$i]['value'], 2);
          }
        }
      }

      $merch_label = '';
      foreach ($order->products as $product)
        $merch_label .= $product['model'] . ', ';
      $merch_label = rtrim($merch_label, ', ');

      $payload = array();
      $payload['merchant_ref'] = $merch_label;
      $payload['transaction_type'] = MODULE_PAYMENT_PAYEEZYJSV2_TRANSACTION_TYPE;
      $payload['method'] = 'token';
      $payload['amount'] = (int)$this->format_amount_for_payeezyv2($order->info['total']);
      $payload['currency_code'] = strtoupper($order->info['currency']);
      $payload['token']['token_type'] = 'FDToken';
      $payload['token']['token_data'] = [
        'type' => $this->card_type,
        'value' => $this->token,
        'cardholder_name' => $this->cc_name,
        'exp_date' => $this->expy,
        'special_payment' => $this->card_type === 'visa' ? 'B' : ''
      ];

      $payload['billing_address'] = array(
        'city' => $order->customer["city"],
        'country' => $order->customer["country"]["title"],
        'phone' => array('type' => 'D', 'number' => $order->customer["telephone"]),
        'street' => $order->customer["street_address"],
        'state_province' => $order->customer['state'],
        'zip_postal_code' => $order->customer['postcode'],
        'email' => $order->customer['email_address'],
      );

      $order->info['cc_cvv'] = '***';

      // @TODO - consider converting currencies if the gateway requires
      $exchange_factor = 1;

      // https://support.payeezy.com/hc/en-us/articles/204504175-How-to-generate-unsuccessful-transactions-during-testing-
      // $payment_amount = 500042;

      if (MODULE_PAYMENT_PAYEEZYJSV2_SEND_SOFT_DESCRIPTORS == 'Yes') {
        $payload['soft_descriptors'] = array(
          'dba_name' => STORE_NAME,
          'merchant_contact_info' => STORE_TELEPHONE_CUSTSERVICE,
          'city' => preg_replace('~https?://~', '', HTTP_SERVER),
          'postal_code' => SHIPPING_ORIGIN_ZIP,
          // Other options include:
          // 'Merchant Contact Info' <-- not defined in the API, perhaps 'merchant_contact_info'
          // 'street' => '',
          // 'region' => '',
          // 'country_code' => '',
          // 'mid' => '',
          // 'mcc' => '',
        );
      }

      if (MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL2 == 'Yes') {
        $payload['level2'] = array(
          'tax1_amount' => $this->format_amount_for_payeezyv2($args['x_tax']),
          'customer_ref' => $_SESSION['customer_id'],
          // 'tax1_number'=> '',  // number of the tax type, per the API chart
          // 'tax2_amount'=> $this->format_amount_for_payeezyv2($args['x_tax2']),
          // 'tax2_number'=> '',  // number of the tax type, per the API chart
          // customer number, or PO number, or invoice number, or order number
        );
      }

      if (MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL3 == 'Yes') {
        $payload['level3'] = array(
          'alt_tax_amount' => 0,
          'alt_tax_id' => 0,
          'discount_amount' => $this->format_amount_for_payeezyv2($args['discount_amount']),
          'freight_amount' => $this->format_amount_for_payeezyv2($args['x_freight']),
          'ship_from_zip' => SHIPPING_ORIGIN_ZIP,
          'ship_to_address' => array(
            'customer_number' => $_SESSION['customer_id'],
            'address_1' => $order->delivery['street_address'],
            'city' => $order->delivery['city'],
            'state' => $order->delivery['state'],
            'zip' => $order->delivery['postcode'],
            'country' => $order->delivery['country']['title'],
            'email' => $order->customer['email_address'],
            'name' => $order->delivery['firstname'] . ' ' . $order->delivery['lastname'],
            'phone' => $order->customer['telephone'],
          ),
        );

        // Add line-item data to transaction payload
        if (count($order->products) < 100) {
          $product_code = $commodity_code = ''; // not submitted

          $payload['level3']['line_items'] = array();
          foreach ($order->products as $p) {
            $payload['level3']['line_items'][] = (object)array(
              'description' => $p['name'],
              'quantity' => $p['qty'],
              'line_item_total' => $this->format_amount_for_payeezyv2(
                round(zen_add_tax($p['final_price'] * $exchange_factor, $p['tax']) * $p['qty'], 2)
              ),
              'tax_amount' => $this->format_amount_for_payeezyv2(
                round(zen_calculate_tax($p['final_price'] * $exchange_factor, $p['tax']), 2)
              ),
              'tax_rate' => round($p['tax'], 8),
              'unit_cost' => $this->format_amount_for_payeezyv2(round($p['final_price'] * $exchange_factor, 2)),
              // 'commodity_code'=> $commodity_code,
              // 'discount_amount'=> '',
              // 'discount_indicator'=> '',
              // 'gross_net_indicator'=> '',
              // 'tax_type'=> '',
              // 'unit_of_measure'=> '',
              // 'product_code'=> $product_code,
            );
          }
        }
      }

// FOR TROUBLESHOOTING ONLY
// TO TEMPORARILY DISABLE TRANSMISSION OF soft_descriptors OR level 2/3 data, UNCOMMENT the following lines:
      //    unset($payload['soft_descriptors']);
      //    unset($payload['level2']);
      //    unset($payload['level3']);
      //    unset($payload['billing_address']);

      $payload_logged = $payload;
      $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      // submit transaction
      $response = $this->postTransaction($payload, $this->hmacAuthorizationToken($payload));

      // log the response data
      $this->logTransactionData($payload_logged, $response);

      $result = $this->process_response($response, $payload_logged);
    }
    if ($result['error'])
      trigger_error($result['error_msg'], E_USER_ERROR);
  }

  /**
   * This is the second half of the above function.
   *
   * @param array $response
   * @param array $payload
   *
   * @return array Returns the response on success; does a redirect on failure.
   *
   * To test different response codes, use transaction amounts between $5,000 and $6,000 to trigger specific responses.
   * @see https://support.payeezy.com/hc/en-us/articles/203730499-eCommerce-Response-Codes-ETG-e4-Transaction-Gateway-Codes-
   * @see https://support.payeezy.com/hc/en-us/articles/204504175-How-to-generate-unsuccessful-transactions-during-testing
   *
   * $5000.00 = approved
   * $5000.22 = invalid card
   * $5202.00 = Invalid amount, or amount too large
   * $5303.00 = Processor Decline (ie: refused by bank)
   * $5500.00 = Fraud - card has been flagged
   * $5000.25 = Invalid Expiry Date
   * $5000.26 = Invalid Amount
   * $5234.00 = Duplicate Order Number
   * $5238.00 = Invalid Currency Code
   * $5353.00 = The FDToken was invalid or spoofed
   * $5000.72 = Invalid data submitted
   * $5000.12 = account configuration problem or timeout at gateway
   * $5000.37 = payment type not accepted by merchant account
   * $5000.43 = invalid merchant account login
   * $5000.44 = Address not Verified
   * $5245.00 = Missing 3D Secure data
   * $5246.00 = Merchant doesn't support MasterCard SecureCode
   *
   * analyze the response
   * http_codes:
   * 200, 201, 202 - OK
   * 400 = bad request, therefore did not complete
   * 401 = unauthorized = invalid API key and token
   * 403 = unauthorized = bad hmac verification
   * 404 = requested resource did not exist
   * 500, 502, 503, 504 = server error on Payeezy end
   *
   * transaction_id and transaction_tag (auth code) -- can be used for post-processing such as recurring billing,
   * void/capture/refund, etc.
   */
  function process_response($response = [], $payload = [])
  {
    global $messageStack;
    // The first clause deals with a successful response.
    if (in_array($response['http_code'], array(200, 201, 202))) {
      // This clause now deals with an approved transaction.
      if ($response['transaction_status'] === 'approved') {
        $this->auth_code = $response['transaction_tag'];
        $this->transaction_id = $response['transaction_id'] . ' Auth/Tag: ' . $response['transaction_tag'] . ' Amount: ' . number_format(
            $response['amount'] / 100,
            2,
            '.',
            ''
          );
        $this->transaction_messages = $response['bank_resp_code'] . ' ' . $response['bank_message'] . ' ' . $response['gateway_resp_code'] . ' ' . $response['gateway_message'];
        if (isset($response['avs']) && isset($this->avs_codes[$response['avs']])) {
          $this->transaction_messages .= "\n" . 'AVS: ' . $this->avs_codes[$response['avs']];
        }
        if (isset($response['cvv2']) && isset($this->cvv_codes[$response['cvv2']])) {
          $this->transaction_messages .= "\n" . 'CVV: ' . $this->cvv_codes[$response['cvv2']];
        }
        $this->order_status = MODULE_PAYMENT_PAYEEZYJSV2_ORDER_STATUS_ID;

        return $response; // For successful responses, we return the response.
      }

      elseif ($response['transaction_status'] === 'declined') {
        // 238 = invalid currency
        // 243 = invalid Level 3 data, or card not suited for Level 3
        // 258 = soft_descriptors not allowed/configured on this merchant account
        // 260 = AVS - Authorization network could not reach the bank which issued the card
        // 264 = Duplicate transaction; rejected.
        // 301 = Issuer Unavailable. Try again.
        // 303 = (Generic) Processor Decline: no other explanation offered
        // 351, 353, 354 = Transarmor errors

        // 301 means timeout, try again, because Authorization network could not reach the bank which issued the card
        // if ($response['bank_resp_code'] == 301) {
        //     $response = $this->postTransaction($payload, $this->hmacAuthorizationToken($payload));
        //     $this->logTransactionData($response, $payload_logged);
        // }

        // Check for soft-descriptor failure, and resubmit without it.
        if ($response['bank_resp_code'] == 258 && MODULE_PAYMENT_PAYEEZYJSV2_SEND_SOFT_DESCRIPTORS == 'Yes') {
          unset($payload['soft_descriptors']);
          $payload_logged = $payload;
          $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          $response = $this->postTransaction($payload, $this->hmacAuthorizationToken($payload));
          $this->logTransactionData($response, $payload_logged);
          if (in_array($response['http_code'], array(200, 201, 202)) && $response['transaction_status'] == 'approved') {
            $this->auth_code = $response['transaction_tag'];
            $this->transaction_id = $response['transaction_id'] . ' Auth/Tag: ' . $response['transaction_tag'] . ' Amount: ' . number_format(
                $response['amount'] / 100,
                2,
                '.',
                ''
              );
            $this->transaction_messages = $response['bank_resp_code'] . ' ' . $response['bank_message'] . ' ' . $response['gateway_resp_code'] . ' ' . $response['gateway_message'];

            if (isset($response['avs']) && isset($this->avs_codes[$response['avs']]))
              $this->transaction_messages .= "\n" . 'AVS: ' . $this->avs_codes[$response['avs']];

            if (isset($response['cvv2']) && isset($this->cvv_codes[$response['cvv2']]))
              $this->transaction_messages .= "\n" . 'CVV: ' . $this->cvv_codes[$response['cvv2']];

            $this->order_status = MODULE_PAYMENT_PAYEEZYJSV2_ORDER_STATUS_ID;

            return $response;
          }
        }

        // Check for level 3 failure, and resubmit without it.
        if ($response['bank_resp_code'] == 243 && MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL3 == 'Yes') {
          unset($payload['level3']);
          $payload_logged = $payload;
          $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          $response = $this->postTransaction($payload, $this->hmacAuthorizationToken($payload));
          $this->logTransactionData($response, $payload_logged);
          if (in_array($response['http_code'], array(200, 201, 202)) &&
            $response['transaction_status'] == 'approved') {
            $this->auth_code = $response['transaction_tag'];
            $this->transaction_id = $response['transaction_id'] . ' Auth/Tag: ' . $response['transaction_tag'] . ' Amount: ' . number_format(
                $response['amount'] / 100,
                2,
                '.',
                ''
              );
            $this->transaction_messages = $response['bank_resp_code'] . ' ' . $response['bank_message'] . ' ' . $response['gateway_resp_code'] . ' ' . $response['gateway_message'];
            if (isset($response['avs']) && isset($this->avs_codes[$response['avs']]))
              $this->transaction_messages .= "\n" . 'AVS: ' . $this->avs_codes[$response['avs']];

            if (isset($response['cvv2']) && isset($this->cvv_codes[$response['cvv2']]))
              $this->transaction_messages .= "\n" . 'CVV: ' . $this->cvv_codes[$response['cvv2']];

            $this->order_status = MODULE_PAYMENT_PAYEEZYJSV2_ORDER_STATUS_ID;

            return $response;
          }
        }

        // check if card is flagged for fraud
        if (in_array($response['bank_resp_code'], array(500, 501, 502, 503, 596, 534, 524, 519))) {
          global $zco_notifier;
          $_SESSION['payment_attempt'] = 500;
          $zco_notifier->notify('NOTIFY_CHECKOUT_SLAMMING_LOCKOUT', $response);
          $_SESSION['cart']->reset(true);
          zen_session_destroy();
          $messageStack->add_session('checkout_payment', MODULE_PAYMENT_PAYEEZYJSV2_ERROR_DECLINED, 'error');
        }

        // generic "declined" message response
        $messageStack->add_session('checkout_payment', MODULE_PAYMENT_PAYEEZYJSV2_ERROR_DECLINED, 'error');
      }

      // Should never get here if we have a 200-204 response; if we get here, the transaction could not be processed for some other reason
      $messageStack->add_session(
        'checkout_payment',
        MODULE_PAYMENT_PAYEEZYJSV2_TEXT_ERROR . zen_output_string_protected( //$response['bank_resp_code'] . ': ' .
          $response['bank_message']
        ),
        'error'
      );
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }
    elseif ($response['http_code'] == 400) {
      foreach ($response['Error']['messages'] as $resp) {
        $messageStack->add_session(
          'checkout_payment',
          MODULE_PAYMENT_PAYEEZYJSV2_TEXT_ERROR . zen_output_string_protected($resp[0]['description']),
          'error'
        );
      }
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    // invalid API key and token
    elseif ($response['http_code'] == 401) {
      $messageStack->add_session(
        'checkout_payment',
        MODULE_PAYMENT_PAYEEZYJSV2_TEXT_MISCONFIGURATION . '(401 Bad Token)',
        'error'
      );
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    elseif ($response['http_code'] == 403) {
      $messageStack->add_session(
        'checkout_payment',
        MODULE_PAYMENT_PAYEEZYJSV2_TEXT_MISCONFIGURATION . '(403 Bad HMAC)',
        'error'
      );
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    // bad transaction call
    elseif ($response['http_code'] == 404) {
      $messageStack->add_session(
        'checkout_payment',
        MODULE_PAYMENT_PAYEEZYJSV2_TEXT_MISCONFIGURATION . '(404 Failed)',
        'error'
      );
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    // error at Payeezy. Call tech support
    elseif (in_array($response['http_code'], array(500, 502, 503, 504))) {
      $messageStack->add_session(
        'checkout_payment',
        MODULE_PAYMENT_PAYEEZYJSV2_TEXT_MISCONFIGURATION . '(500 Processor Error)',
        'error'
      );
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    return [];
  }

  /**
   * @param $zf_order_id integer Order Id.
   *
   * @return mixed
   */
  public function after_order_create($zf_order_id)
  {
    global $db, $order, $insert_id;

    $order = $this->getTrans($insert_id);
    if (empty($this->order_id) && !empty($insert_id))
      $this->order_id = $insert_id;

    if (!empty($this->customer_id)) {
      $sql = "DELETE FROM " . TABLE_CUSTOMERS_BASKET . "
              WHERE customers_id = '" . $this->customer_id . "';";
      $db->Execute($sql);

      $sql = "DELETE FROM " . TABLE_CUSTOMERS_BASKET_ATTRIBUTES . "
              WHERE customers_id = '" . $this->customer_id . "';";
      $db->Execute($sql);
    }
  }

  /**
   * Post-process activities. Updates order details, status, and history
   * data in Zen Cart with the auth code and other details returned.
   *
   * @return boolean
   */
  public function after_process()
  {
    global $insert_id, $db;
    $sql = "UPDATE " . TABLE_ORDERS . " 
      SET cc_type ='" . $this->card_type . "',
      cc_owner = '" . $this->cc_name . "',
      cc_number = '" . $this->masked . "',
      cc_expires = '" . $this->expy . "',
      orders_status = '" . $this->order_status . "',
      paypal_ipn_id = '" . $this->gatewayRefId . "',
      last_modified = '" . date('Y-m-d H:i:s') . "'
      where orders_id = '" . $insert_id . "';";
    $db->Execute($sql);

    $trans_msg = 'Credit Card payment.  TransID: ' . $this->transaction_id . ' Tag: ' . $this->transaction_tag;

    $sql = "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY .
      " (comments, orders_id, orders_status_id, customer_notified, date_added)
       VALUES (:orderComments, :orderID, :orderStatus, 1, now())";
    $sql = $db->bindVars($sql, ':orderComments', $trans_msg, 'string');
    $sql = $db->bindVars($sql, ':orderID', $insert_id, 'integer');
    $sql = $db->bindVars($sql, ':orderStatus', $this->order_status, 'integer');
    $db->Execute($sql);

    $this->saveTrans();
  }

  /**
   * @return int Verifies that the payment method is enabled and installed.
   */
  public function check()
  {
    global $db;
    if (!isset($this->_check)) {
      $check_query = $db->Execute(
        "select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PAYEEZYJSV2_STATUS'"
      );
      $this->_check = $check_query->RecordCount();
    }

    if ($this->_check)
      $this->install();

    return $this->_check;
  }

  /**
   * Install/update configuration keys for this module.
   */
  public function install()
  {
    global $db, $messageStack;

    // Configuration items.
    $keys = $this->keyinfo();
    $fields = $this->keys(true);
    foreach ($fields as $key => $field) {
      $sql = '';
      if (!defined($key)) {
        $row = array_combine(array_intersect_key($keys, $field), $field);
        $sql .= $tsql = 'INSERT INTO ' . TABLE_CONFIGURATION . '(' . implode(
            ',',
            array_keys($row)
          ) . ') VALUES ("' . implode('","', array_values($row)) . "\");\n\n";
        $db->Execute($sql);
      }
    }

    // Database table.
    $sql = "SHOW TABLES LIKE '" . TABLE_PAYEEZY_TRANSACTIONS . "';";
    $result = $db->Execute($sql);

    // If already present.
    if ($result->count())
      return;

    $sql = "CREATE TABLE `" . TABLE_PAYEEZY_TRANSACTIONS . "` (
    `payeezy_id` int(11) UNSIGNED NOT NULL,
    `customer_id` int(11) NOT NULL COMMENT 'The Zen Cart Customer ID.',
    `order_id` int(11) NOT NULL COMMENT 'The Zen Cart Order ID.',
    `clientToken` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `response_code` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `card_type` varchar(64) NOT NULL COMMENT 'These values are in the response received back from Payeezy.',
    `cc_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `expy` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `last4` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `masked` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `zeroDollarAuth` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `transaction_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `token` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `gatewayRefId` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `session_vars` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    constraint payeezy_id unique (`payeezy_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
    $db->Execute($sql);

    $sql = "ALTER TABLE " . TABLE_PAYEEZY_TRANSACTIONS . "
           ADD PRIMARY KEY (`payeezy_id`),
           ADD KEY customer_id (customer_id) COMMENT 'Customer ID index.',
           ADD KEY order_id (order_id) COMMENT 'Order ID index.'";
    $db->Execute($sql);

    $sql = "ALTER TABLE " . TABLE_PAYEEZY_TRANSACTIONS . "
      MODIFY `payeezy_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT";
    $db->Execute($sql);

    if (!$result) {
      $messageStack->add_session('PayeezyJS V2 table installation error.', 'caution');
      $messageStack->add_session('Query didn\'t run:<br /><pre>' . $result->sql_query . '</pre>', 'caution');
      $messageStack->add_session('PHP Errors (if any):<br /><pre>' . error_get_last() . '</pre>', 'caution');
    }

    else {
      // Update the Zen Cart internal record of available payment methods.
      $tableData = [
        [
          'fieldName' => 'configuration_value',
          'value' => "CONCAT(configuration_value, ';payeezyjsv2.php')",
          'type' => 'passthru'
        ]
      ];
      $performFilter = "configuration_key = 'MODULE_PAYMENT_INSTALLED'";
      $db->perform(TABLE_CONFIGURATION, $tableData, 'UPDATE', $performFilter);
      $messageStack->reset();
      $messageStack->add_session('PayeezyJS V2 successfully installed.', 'success');
    }
  }

  /**
   * Uninstall the module configuration and table.
   */
  public function remove()
  {
    global $db, $messageStack;
    $db->Execute(
      "DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')"
    );
    // Update the Zen Cart internal record of available payment methods.
    $tableData = [
      [
        'fieldName' => 'configuration_value',
        'value' => "REPLACE(configuration_value, ';payeezyjsv2.php', '')",
        'type' => 'passthru'
      ]
    ];
    $performFilter = "configuration_key = 'MODULE_PAYMENT_INSTALLED'";
    $db->perform(TABLE_CONFIGURATION, $tableData, 'UPDATE', $performFilter);

    $db->Execute("DROP TABLE " . TABLE_PAYEEZY_TRANSACTIONS);
    $messageStack->add_session('PayeezyJS V2 module has been uninstalled.', 'warning');
  }

  /**
   * A public method to start the authorization based on production or testing mode.
   */
  public function authsess()
  {
    if (defined('MODULE_PAYMENT_PAYEEZYJSV2_TESTING_MODE') &&
      MODULE_PAYMENT_PAYEEZYJSV2_TESTING_MODE !== 'Testing') {
      $post = array(
        'hostname' => 'prod.api.firstdata.com',
        'credentials' =>
          array(
            'apiKey' => MODULE_PAYMENT_PAYEEZYJSV2_API_KEY,
            'apiSecret' => MODULE_PAYMENT_PAYEEZYJSV2_GW_APISEC,
          ),
        'gatewayConfig' =>
          array(
            'gateway' => 'PAYEEZY',
            'apiKey' => MODULE_PAYMENT_PAYEEZYJSV2_API_KEY,
            'apiSecret' => MODULE_PAYMENT_PAYEEZYJSV2_API_SECRET,
            'authToken' => MODULE_PAYMENT_PAYEEZYJSV2_GW_AUTH_TOKEN,
            'transarmorToken' => MODULE_PAYMENT_PAYEEZYJSV2_GW_TA_TOKEN,
            'zeroDollarAuth' => false
          )
      );
    }
    else {
      $post = array(
        'hostname' => 'cert.api.firstdata.com',
        'credentials' =>
          array(
            'apiKey' => MODULE_PAYMENT_PAYEEZYJSV2_API_KEY_SANDBOX,
            'apiSecret' => MODULE_PAYMENT_PAYEEZYJSV2_GW_APISEC_SANDBOX,
          ),
        'gatewayConfig' =>
          array(
            'gateway' => 'PAYEEZY',
            'apiKey' => MODULE_PAYMENT_PAYEEZYJSV2_API_KEY_SANDBOX,
            'apiSecret' => MODULE_PAYMENT_PAYEEZYJSV2_API_SECRET_SANDBOX,
            'authToken' => MODULE_PAYMENT_PAYEEZYJSV2_GW_AUTH_TOKEN_SANDBOX,
            'transarmorToken' => 'NOIW',
            'currency' => 'USD',
            'zeroDollarAuth' => false
          )
      );
    }

    return $this->authorizeSession($post);
  }

  /**
   * This is an attempt to reorganize default settings so they're easier to review.
   * I wouldn't say it worked out.
   *
   * @return array Either an array of all the available configuration keys for this module,
   * OR an array of arrays keyed by those configuration keys, and containing arrays with these keys:
   * (which represent the TABLE_CONFIGURATION table):
   *
   * 'configuration_title',
   * 'configuration_key',
   * 'configuration_value',
   * 'configuration_description',
   * 'configuration_group_id',
   * 'sort_order',
   * 'date_added',
   * 'use_function',
   * 'set_function',
   */
  public function keys($config = false)
  {
    $return = [
      'MODULE_PAYMENT_PAYEEZYJSV2_STATUS' => [
        'Enable PayeezyJS V2 Payments',
        'MODULE_PAYMENT_PAYEEZYJSV2_STATUS',
        'True',
        'Do you want to enable PayeezyJS V2 (First Data) payment module?',
        '6',
        '0',
        'now()',
        '',
        'zen_cfg_select_option(array(\'True\', \'False\'), ',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_SORT_ORDER' => [
        'Sort order of display.',
        'MODULE_PAYMENT_PAYEEZYJSV2_SORT_ORDER',
        '0',
        'Sort order of this module when displaying the list payment modules.',
        '6',
        '0',
        'now()',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_ZONE' => [
        'Payment Zone',
        'MODULE_PAYMENT_PAYEEZYJSV2_ZONE',
        '0',
        'If a zone is selected, only enable this payment method for that zone.',
        '6',
        '2',
        'now()',
        'zen_get_zone_class_title',
        'zen_cfg_pull_down_zone_classes(',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_TRANSACTION_TYPE' => [
        'Transaction Type',
        'MODULE_PAYMENT_PAYEEZYJSV2_TRANSACTION_TYPE',
        'purchase',
        'Should payments be authorized first, or be completed immediately:',
        '6',
        '0',
        'now()',
        '',
        'zen_cfg_select_option(array(\'authorize\', \'purchase\'), ',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_ORDER_STATUS_ID' => [
        'Set Completed Order Status',
        'MODULE_PAYMENT_PAYEEZYJSV2_ORDER_STATUS_ID',
        '2',
        'Set the status to use for orders made with this payment module.',
        '6',
        '0',
        'now()',
        'zen_get_order_status_name',
        'zen_cfg_pull_down_order_statuses(',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_TOKEN_STATUS_ID' => [
        'Set an Authorization Status <span style=\"color:red\">*required</span>',
        'MODULE_PAYMENT_PAYEEZYJSV2_TOKEN_STATUS_ID',
        '1',
        'This order status is used during authorization and before the actual transaction. Once Zen Cart receives authorization for the transaction to occur, it will proceed as normal (to the status set above). You may need to create a new order status to use, eg. Authorizing',
        '6',
        '0',
        'now()',
        'zen_get_order_status_name',
        'zen_cfg_pull_down_order_statuses(',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_API_KEY' => [
        'Payeezy API Key',
        'MODULE_PAYMENT_PAYEEZYJSV2_API_KEY',
        '',
        'Enter the API Key from \"My APIs\" in your developer.payeezy.com account',
        '6',
        '0',
        'now()',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_API_SECRET' => [
        'Payeezy API Secret',
        'MODULE_PAYMENT_PAYEEZYJSV2_API_SECRET',
        '',
        'Enter the API Secret from developer.payeezy.com',
        '6',
        '0',
        'now()',
        'zen_cfg_password_display',
        '',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_GW_APISEC' => [
        'Payment.JS Secret',
        'MODULE_PAYMENT_PAYEEZYJSV2_GW_APISEC',
        '',
        'Enter the Payment.JS Secret',
        '6',
        '0',
        'now()',
        'zen_cfg_password_display',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_GW_AUTH_TOKEN' => [
        'Merchant Auth Token',
        'MODULE_PAYMENT_PAYEEZYJSV2_GW_AUTH_TOKEN',
        '',
        'The Merchant Token is in the <strong>Merchants</strong> section of your Payeezy Developer Account (it should start with <strong>fdoa-</strong>)',
        '6',
        '0',
        'now()',
        'zen_cfg_password_display',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_GW_TA_TOKEN' => [
        'Transarmor Token',
        'MODULE_PAYMENT_PAYEEZYJSV2_GW_TA_TOKEN',
        '',
        'Enter the Transarmor Token',
        '6',
        '0',
        'now()',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_SEND_SOFT_DESCRIPTORS' => [
        'Send Soft Descriptor Data',
        'MODULE_PAYMENT_PAYEEZYJSV2_SEND_SOFT_DESCRIPTORS',
        'No',
        'Soft-Descriptor data is typically used to differentiate between multiple stores in one merchant account, by sending the store-name and other data in each transaction. The feature must be enabled in your Merchant Account.',
        '6',
        '0',
        'now()',
        '',
        'zen_cfg_select_option(array(\'Yes\', \'No\'), ',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL2' => [
        'Send Level II Card Data',
        'MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL2',
        'No',
        'Level II data includes extra tax information, and is usually related to government-issued cards. The feature must be enabled in your Merchant Account.',
        '6',
        '0',
        'now()',
        '',
        'zen_cfg_select_option(array(\'Yes\', \'No\'), ',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL3' => [
        'Send Level III Card Data',
        'MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL3',
        'No',
        'Level III data includes detailed transaction line-items, and is usually related to government-issued cards. Using the feature can often result in reduced fees. The feature must be enabled in your Merchant Account.',
        '6',
        '0',
        'now()',
        '',
        'zen_cfg_select_option(array(\'Yes\', \'No\'), ',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_TESTING_MODE' => [
        'Sandbox/Live Mode',
        'MODULE_PAYMENT_PAYEEZYJSV2_TESTING_MODE',
        '',
        'By using sandbox mode, the sandbox credentials below and Firstdata\'s development API will be used.',
        '6',
        '0',
        'now()',
        '',
        'zen_cfg_select_option(array(\'Live\', \'Testing\'),',
      ]
    ];

    $return_sandbox = [];
    foreach ($return as $key => $result) {
      if (!in_array(
        $key,
        [
          'MODULE_PAYMENT_PAYEEZYJSV2_STATUS',
          'MODULE_PAYMENT_PAYEEZYJSV2_SORT_ORDER',
          'MODULE_PAYMENT_PAYEEZYJSV2_ORDER_STATUS_ID',
          'MODULE_PAYMENT_PAYEEZYJSV2_TOKEN_STATUS_ID',
          'MODULE_PAYMENT_PAYEEZYJSV2_ZONE',
          'MODULE_PAYMENT_PAYEEZYJSV2_TRANSACTION_TYPE',
          'MODULE_PAYMENT_PAYEEZYJSV2_SEND_SOFT_DESCRIPTORS',
          'MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL2',
          'MODULE_PAYMENT_PAYEEZYJSV2_SEND_LEVEL3',
          'MODULE_PAYMENT_PAYEEZYJSV2_TESTING_MODE',
          'MODULE_PAYMENT_PAYEEZYJSV2_LOGGING'
        ]
      )) {
        $result[1] .= '_SANDBOX';
        $result[0] = 'Sandbox - ' . $result[0];
        $return_sandbox[$key . '_SANDBOX'] = $result;
        if (strpos($key, 'PAYEEZYJSV2_GW_TA_TOKEN'))
          $return_sandbox[$key . '_SANDBOX'][2] = 'NOIW';
      }
    }
    $logging = [
      'MODULE_PAYMENT_PAYEEZYJSV2_LOGGING' => [
        'Log Mode',
        'MODULE_PAYMENT_PAYEEZYJSV2_LOGGING',
        'Log on Failures and Email on Failures',
        'Would you like to enable debug mode?  A complete detailed log of failed transactions may be emailed to the store owner.',
        '6',
        '0',
        'now()',
        '',
        'zen_cfg_select_option(array(\'Off\', \'Log Always\', \'Log on Failures\', \'Log Always and Email on Failures\', \'Log on Failures and Email on Failures\', \'Email Always\', \'Email on Failures\'), ',
      ],
      'MODULE_PAYMENT_PAYEEZYJSV2_DEBUG' => [
        'Debug Endpoint',
        'MODULE_PAYMENT_PAYEEZYJSV2_DEBUG',
        'Off',
        'Would you like to enable debug mode? Construct your own responses in webhook.php. Includes more details in logs.',
        '6',
        '0',
        'now()',
        '',
        'zen_cfg_select_option(array(\'On\', \'Off\'), ',
      ]
    ];

    return $config ? $return + $return_sandbox + $logging : array_keys($return + $return_sandbox + $logging);
  }

  /**
   * Loads the object with its database row data.
   *
   * @param string $clientToken What's given back in the authorization to
   * identify the transaction it's for.
   *
   * @return bool|string
   */
  public function getByClientToken($clientToken = '1234567890')
  {
    global $db;
    $sql = "SELECT * FROM " . TABLE_PAYEEZY_TRANSACTIONS . " WHERE clientToken = '" . $clientToken . "'";
    $result = $db->Execute($sql);
    if (!empty($clientToken) && count($result->fields)) {
      foreach ($result->fields as $key => $value)
        $this->{$key} = $value;

      if (!empty($result->fields['order_id']))
        $this->getTrans($result->fields['order_id']);

      return $clientToken;
    }

    return false;
  }

  public function getCheckoutArgs()
  {
    foreach ($this->session_vars as $var_name => &$value)
      if (isset($_SESSION[$var_name]) && $_SESSION[$var_name] != $value && !empty($_SESSION[$var_name]))
        $value = $_SESSION[$var_name];

    $this->session_vars = !empty(array_filter($this->session_vars)) ? serialize($this->session_vars) : '';
  }

  /**
   * Pull up the Zen Cart order for the given id.
   * Set it as a property with the same and return it.
   *
   * @param null|int $insert_id The order ID.
   *
   * @return $order A Zen Cart order object.
   */
  public function getTrans($insert_id = null)
  {
    global $db;
    $sql = '';
    if (!empty($insert_id))
      $sql = "SELECT orders_id FROM " . TABLE_ORDERS . " zo WHERE zo.orders_id = " . $insert_id;

    elseif (isset($_SESSION["customer_id"]))
      $sql = "SELECT orders_id FROM " . TABLE_ORDERS . " zo WHERE zo.customers_id = '" . $_SESSION["customer_id"] . "' ORDER BY orders_id DESC LIMIT 1";

    if (!empty($sql)) {
      $result = $db->Execute($sql);
      if (!empty($result->fields)) {
        $this->order = new order($result->fields["orders_id"]);

        return $this->order;
      }
    }

    return false;
  }

  /**
   * Update the active transaction. Fields with names that match column names in the payeezy table, get updated.
   *
   */
  public function saveTrans()
  {
    global $db;

    $query = $clientToken = $order_id = $customer_id = $session_id = '';
    $tableData = [];
    $sql = "SELECT * FROM " . TABLE_PAYEEZY_TRANSACTIONS . " WHERE ";

    if (isset($this->clientToken)) {
      $sql .= $query = $clientToken = "clientToken = '" . $this->clientToken . "' ";
    }
    else {
      if (!empty($this->order_id)) {
        $order_id = "order_id = '" . $this->order_id . "'";
      }

      if (!empty($this->customer_id)) {
        $customer_id = "customer_id = '" . $this->customer_id . "'";
      }

      if (!empty($_SESSION['securityToken'])) {
        $session_id = "session_id = '" . $_SESSION['securityToken'] . "'";
      }

      if (count(array_filter([$clientToken, $order_id, $customer_id, $session_id])) > 1) {
        foreach (array_filter([$clientToken, $order_id, $customer_id, $session_id]) as $clause) {
          $query .= empty($query) ? $clause : ' AND ' . $clause;
        }
      }
      $sql .= $query;
    }

    if ($query !== $sql) {
      $sql .= ' LIMIT 1';
      $result = $db->Execute($sql);
      if ($result->count()) {
        foreach ($result->fields as $field_name => $field) {
          if ($this->{$field_name} != $field && !empty($this->{$field_name})) {
            $tableData[] = [
              'fieldName' => $field_name,
              'value' => $this->{$field_name},
              'type' => $this->columnTypes($field_name)
            ];
          }
        }

        // Update the record.
        if (!empty($tableData)) {
          $db->perform(
            TABLE_PAYEEZY_TRANSACTIONS,
            $tableData,
            'UPDATE',
            $query
          );
        }
      }
      elseif (isset($_SESSION["customer_id"])) {
        // This is the initial record insert.
        $customer_id = $_SESSION["customer_id"];
        //   var response, response_code, ccard, expy, last4, cc_name, token, gatewayRefId, zeroDollarAuth;
        //$tableData[] = ['fieldName' => 'order_id', 'value' => $order_id, 'type' => $this->columnTypes('order_id')];
        $tableData[] = [
          'fieldName' => 'customer_id',
          'value' => $customer_id,
          'type' => $this->columnTypes('customer_id')
        ];
        $tableData[] = [
          'fieldName' => 'clientToken',
          'value' => $this->clientToken,
          'type' => $this->columnTypes('clientToken')
        ];
        $tableData[] = [
          'fieldName' => 'response_code',
          'value' => "'" . $this->response_code . "'",
          'type' => 'passthru'
        ];
        $tableData[] = [
          'fieldName' => 'gatewayRefId',
          'value' => "'" . $this->gatewayRefId . "'",
          'type' => 'passthru'
        ];
        $tableData[] = [
          'fieldName' => 'session_vars',
          'value' => "'" . serialize($this->session_vars) . "'",
          'type' => 'passthru'
        ];
        $tableData[] = [
          'fieldName' => 'session_id',
          'value' => $_SESSION['securityToken'],
          'type' => $this->columnTypes('clientToken')
        ];

        // Insert the payment.
        $db->perform(TABLE_PAYEEZY_TRANSACTIONS, $tableData, 'INSERT');
      }
    }
  }

  /**
   * This runs on the page with the cc form. At the top of the card entry page.
   */
  public function pre_confirmation_check()
  {
    global $order;
    $this->order = $order;
    echo '<link rel="stylesheet" type="text/css" href="/includes/modules/payment/payeezyjsv2/fields.css">';
  }

  private function keyinfo()
  {
    return [
      'configuration_title',
      'configuration_key',
      'configuration_value',
      'configuration_description',
      'configuration_group_id',
      'sort_order',
      'date_added',
      'use_function',
      'set_function',
    ];
  }

  private function format_amount_for_payeezyv2($amount)
  {
    global $currencies, $order;
    $decimal_places = 2;
    if (isset($order) && isset($order->info['currency']))
      $decimal_places = $currencies->get_decimal_places($order->info['currency']);

    if ((int)$decimal_places === 0)
      return (int)$amount;

    return (int)(string)(round($amount, $decimal_places) * pow(10, $decimal_places));
  }

  /**
   *  Does the tokenization.
   */
  private function authorizeSession($post = [])
  {
    if (empty($post))
      trigger_error(MODULE_PAYMENT_PAYEEZYJSV2_TEXT_COMM_ERROR, E_USER_ERROR);

    $nonce = time() * 1000 + rand();
    $timestamp = time() * 1000;
    $apiKey = $post['credentials']['apiKey'];
    $secretKey = $post['credentials']['apiSecret'];
    $contentType = 'application/json';
    $data = $post['gatewayConfig'];
    $url = $post["hostname"];
    $url = 'https://' . $url . '/paymentjs/v2/merchant/authorize-session';
    $jsonPayload = json_encode($data);
    $msg = $apiKey . $nonce . $timestamp . $jsonPayload;
    $messageSignature = base64_encode(hash_hmac('sha256', $msg, $secretKey));

    // Header array for auth request.
    $headers = array(
      'Api-Key: ' . $apiKey,
      'Content-Type: ' . $contentType,
      'Content-Length: ' . strlen($jsonPayload),
      'Message-Signature: ' . $messageSignature,
      'Nonce: ' . $nonce,
      'Timestamp: ' . $timestamp
    );

    // cURL function to get the response.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HEADER, 1);

    $response = curl_exec($ch);

    if (false === $response) {
      $commError = curl_error($ch);
      $commErrNo = curl_errno($ch);
      trigger_error('Payeezy communications failure. ' . $commErrNo . ' - ' . $commError, E_USER_NOTICE);
    }
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $header = [];
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    foreach (explode("\r\n", trim(substr($response, 0, $header_size))) as $row)
      if (preg_match('/(.*?): (.*)/', $row, $matches))
        $header[$matches[1]] = $matches[2];

    // Response headers.
    $this->clientToken = $header['Client-Token'];
    $responseNonce = $header['Nonce'];

    // Response body.
    $body = substr($response, $header_size);
    $publicKeyBase64 = substr($body, 20, -2); //extract publicKeyBase64 from the response body

    if ($http_status === 200) {
      if ($responseNonce == $nonce) {
        if (isset($_SESSION['cartID']) && !empty($_SESSION['cartID'])) {
          $this->getCheckoutArgs();
          $this->logTransactionData($post, $response);

          // Save the extras somewhere somehow.
          $this->saveTrans();
        }
        $data = ['clientToken' => $header['Client-Token'], 'publicKeyBase64' => $publicKeyBase64];
        $this->myCallback($data);
      }
      else {
        trigger_error('Validation failed for the transaction. You may try again or contact support.', E_USER_ERROR);
      }
    }

    else {
      trigger_error($body, E_USER_ERROR);
    }
  }

  /**
   * @param null $choose Specify a single column type.
   *
   * @return array|mixed All or one column type.
   */
  private function columnTypes($choose = null)
  {
    $return = [
      'payeezyv2_id' => 'integer',
      'customer_id' => 'integer',
      'order_id' => 'integer',
      'clientToken' => 'stringIgnoreNull',
      'response_code' => 'stringIgnoreNull',
      'card_type' => 'stringIgnoreNull',
      'cc_name' => 'stringIgnoreNull',
      'expy' => 'stringIgnoreNull',
      'last4' => 'integer',
      'masked' => 'stringIgnoreNull',
      'zeroDollarAuth' => 'stringIgnoreNull',
      'transaction_id' => 'stringIgnoreNull',
      'token' => 'stringIgnoreNull',
      'gatewayRefId' => 'stringIgnoreNull',
      'session_vars' => 'passthru',
      'updated' => 'passthru',
      'session_id' => 'stringIgnoreNull'
    ];

    return empty($choose) ? $return : $return[$choose];
  }

  /**
   * Callback function to send clientToken for tokenization.
   */
  private function myCallback($data)
  {
    if ($data) {
      header('Content-Type: application/json');
      $resp = json_encode($data);
      echo $resp;
    }
  }

  private function hmacAuthorizationToken($payload)
  {
    $nonce = (string)(hexdec(bin2hex(openssl_random_pseudo_bytes(4, $cstrong))));
    $timestamp = sprintf('%s', (string)(time()) . '000'); //time stamp in milli seconds as string
    $data = (string)(constant(
        'MODULE_PAYMENT_PAYEEZYJSV2_API_KEY' . ($this->mode == 'Testing' ? '_SANDBOX' : '')
      )) . $nonce . $timestamp . (string)(constant(
        'MODULE_PAYMENT_PAYEEZYJSV2_GW_AUTH_TOKEN' . ($this->mode == 'Testing' ? '_SANDBOX' : '')
      )) . $this->etppid . $payload;
    $hashAlgorithm = "sha256";
    $hmac = hash_hmac(
      $hashAlgorithm,
      $data,
      (string)(constant(
        'MODULE_PAYMENT_PAYEEZYJSV2_API_SECRET' . ($this->mode == 'Testing' ? '_SANDBOX' : '')
      )),
      false
    );    // HMAC Hash in hex
    $authorization = base64_encode($hmac);

    return array(
      'authorization' => $authorization,
      'nonce' => $nonce,
      'timestamp' => $timestamp,
    );
  }

  /**
   * Log transaction errors if enabled
   *
   * @param array $payload A collection of required credentials.
   * @param string $response Result of the curl request to be recorded.
   */
  private function logTransactionData($payload, $response = '')
  {
    $servervars = [
      'remote addr' => $_SERVER['REMOTE_ADDR'],
      'request uri' => $_SERVER['REQUEST_URI'],
      'SELF' => $_SERVER['PHP_SELF']
    ];

    $logMessage = date('M-d-Y h:i:s') . ' ' . $this->code;
    if (defined('MODULE_PAYMENT_PAYEEZYJSV2_DEBUG') && MODULE_PAYMENT_PAYEEZYJSV2_DEBUG) {
      $logMessage .= print_r($servervars, 1);
    }

    $logMessage .= "\n=====================================\n";
    if (!empty($this->commError)) {
      $logMessage .= 'Comm results: ' . $this->commErrNo . ' ' . $this->commError . "\n\n";
    }

    if (!empty($response)) {
      $logMessage .= 'Results received back from Payeezy: ' . print_r($response, 1) . "\n\n";
    }

    if (!empty($this->commInfo)) {
      $logMessage .= 'CURL communication info: ' . print_r($this->commInfo, 1) . "\n";
    }

    $logMessage .= "\r\n";
    if (strstr(
        MODULE_PAYMENT_PAYEEZYJSV2_LOGGING,
        'Log Always'
      ) || ($response['transaction_status'] != 'approved' && strstr(
          MODULE_PAYMENT_PAYEEZYJSV2_LOGGING,
          'Log on Failures'
        ))) {
      $file = $this->_logDir . '/' . 'PayeezyV2-' . date('m-d-y') . '.log';
      if ($fp = @fopen($file, 'a')) {
        fwrite($fp, $logMessage);
        fclose($fp);
      }
    }
    if (stristr(MODULE_PAYMENT_PAYEEZYJSV2_LOGGING, 'Email on Failures') ||
      strstr(MODULE_PAYMENT_PAYEEZYJSV2_LOGGING, 'Email Always')) {
      zen_mail(
        STORE_NAME,
        STORE_OWNER_EMAIL_ADDRESS,
        'Payeezy V2 Alert ' . $response['transaction_status'] . ' ' . date('M-d-Y h:i:s'),
        $logMessage,
        STORE_OWNER,
        STORE_OWNER_EMAIL_ADDRESS,
        array('EMAIL_MESSAGE_HTML' => nl2br($logMessage)),
        'debug'
      );
    }
  }

  private function postTransaction($payload, $headers)
  {
    $endpoint = $this->mode == 'Testing' ? 'api-cert.payeezy.com' : 'api.payeezy.com';
    $curlHeaders = array(
      'Content-Type:application/json',
      'apikey:' . (string)(constant(
        'MODULE_PAYMENT_PAYEEZYJSV2_API_KEY' . ($this->mode == 'Testing' ? '_SANDBOX' : '')
      )),
      'token:' . (string)(constant(
        'MODULE_PAYMENT_PAYEEZYJSV2_GW_AUTH_TOKEN' . ($this->mode == 'Testing' ? '_SANDBOX' : '')
      )),
      'Authorization:' . $headers['authorization'],
      'nonce:' . $headers['nonce'],
      'timestamp:' . (string)($headers['timestamp']),
      'ext_tppid:' . $this->etppid,
    );

    $request = curl_init();
    curl_setopt($request, CURLOPT_URL, "https://" . $endpoint . "/v1/transactions");
    curl_setopt($request, CURLOPT_POST, true);
    curl_setopt($request, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($request, CURLOPT_HEADER, false);
    curl_setopt($request, CURLOPT_HTTPHEADER, $curlHeaders);
    $response = curl_exec($request);
    $commErrNo = curl_errno($request);
    if ($commErrNo == 35) {
      trigger_error(
        'ALERT: Could not process Payeezy transaction via normal CURL communications. Your server is encountering connection problems using TLS 1.2 ... because your hosting company cannot autonegotiate a secure protocol with modern security protocols. We will try the transaction again, but this is resulting in a very long delay for your customers, and could result in them attempting duplicate purchases. Get your hosting company to update their TLS capabilities ASAP.',
        E_USER_NOTICE
      );
      // Reset CURL to TLS 1.2 using the defined value of 6 instead of CURL_SSLVERSION_TLSv1_2 since these outdated hosts also don't properly implement this constant either.
      curl_setopt($request, CURLOPT_SSLVERSION, 6);
      // and attempt resubmit
      $response = curl_exec($request);
    }

    if (false === $response) {
      $this->commError = curl_error($request);
      $this->commErrNo = curl_errno($request);
      trigger_error('Payeezy V2 communications failure. ' . $this->commErrNo . ' - ' . $this->commError, E_USER_NOTICE);
    }
    $httpcode = curl_getinfo($request, CURLINFO_HTTP_CODE);
    $this->commInfo = curl_getinfo($request);
    curl_close($request);

    if (!in_array($httpcode, array(200, 201, 202))) {
      $this->logTransactionData($payload, $response);
    }

    $response = json_decode($response, true);
    $response['http_code'] = $httpcode;
    $response['curlHeaders'] = $curlHeaders;

    return $response;
  }

  private function setAvsCvvMeanings()
  {
    $this->cvv_codes['M'] = 'CVV2/CVC2 Match - Indicates that the card is authentic. Complete the transaction if the authorization request was approved.';
    $this->cvv_codes['N'] = 'CVV2 / CVC2 No Match – May indicate a problem with the card. Contact the cardholder to verify the CVV2 code before completing the transaction, even if the authorization request was approved.';
    $this->cvv_codes['P'] = 'Not Processed - Indicates that the expiration date was not provided with the request, or that the card does not have a valid CVV2 code. If the expiration date was not included with the request, resubmit the request with the expiration date.';
    $this->cvv_codes['S'] = 'Merchant Has Indicated that CVV2 / CVC2 is not present on card - May indicate a problem with the card. Contact the cardholder to verify the CVV2 code before completing the transaction.';
    $this->cvv_codes['U'] = 'Issuer is not certified and/or has not provided visa encryption keys';
    $this->cvv_codes['I'] = 'CVV2 code is invalid or empty';

    $this->avs_codes['X'] = 'Exact match, 9 digit zip - Street Address, and 9 digit ZIP Code match';
    $this->avs_codes['Y'] = 'Exact match, 5 digit zip - Street Address, and 5 digit ZIP Code match';
    $this->avs_codes['A'] = 'Partial match - Street Address matches, ZIP Code does not';
    $this->avs_codes['W'] = 'Partial match - ZIP Code matches, Street Address does not';
    $this->avs_codes['Z'] = 'Partial match - 5 digit ZIP Code match only';
    $this->avs_codes['N'] = 'No match - No Address or ZIP Code match';
    $this->avs_codes['U'] = 'Unavailable - Address information is unavailable for that account number, or the card issuer does not support';
    $this->avs_codes['G'] = 'Service Not supported, non-US Issuer does not participate';
    $this->avs_codes['R'] = 'Retry - Issuer system unavailable, retry later';
    $this->avs_codes['E'] = 'Not a mail or phone order';
    $this->avs_codes['S'] = 'Service not supported';
    $this->avs_codes['Q'] = 'Bill to address did not pass edit checks/Card Association cannot verify the authentication of an address';
    $this->avs_codes['D'] = 'International street address and postal code match';
    $this->avs_codes['B'] = 'International street address match, postal code not verified due to incompatible formats';
    $this->avs_codes['C'] = 'International street address and postal code not verified due to incompatible formats';
    $this->avs_codes['P'] = 'International postal code match, street address not verified due to incompatible format';
    $this->avs_codes['1'] = 'Cardholder name matches';
    $this->avs_codes['2'] = 'Cardholder name, billing address, and postal code match';
    $this->avs_codes['3'] = 'Cardholder name and billing postal code match';
    $this->avs_codes['4'] = 'Cardholder name and billing address match';
    $this->avs_codes['5'] = 'Cardholder name incorrect, billing address and postal code match';
    $this->avs_codes['6'] = 'Cardholder name incorrect, billing postal code matches';
    $this->avs_codes['7'] = 'Cardholder name incorrect, billing address matches';
    $this->avs_codes['8'] = 'Cardholder name, billing address, and postal code are all incorrect';
    $this->avs_codes['F'] = 'Address and Postal Code match (UK only)';
    $this->avs_codes['I'] = 'Address information not verified for international transaction';
    $this->avs_codes['M'] = 'Address and Postal Code match';
  }

  /**
   * Just sets errors. Taken from https://docs.paymentjs.firstdata.com/#authorize-session
   */
  private function setSystemErrorCodes()
  {
    $this->cvv_codes['BAD_REQUEST'] = 'the request body is missing or incorrect for endpoint';
    $this->cvv_codes['DECRYPTION_ERROR'] = 'failed to decrypt card data';
    $this->cvv_codes['INVALID_GATEWAY_CREDENTIALS'] = 'gateway credentials failed';
    $this->cvv_codes['JSON_ERROR'] = 'the request body is either not valid JSON or larger than 2kb';
    $this->cvv_codes['KEY_NOT_FOUND'] = 'no available key found';
    $this->cvv_codes['MISSING_CVV'] = 'zero dollar auth requires cvv in form data';
    $this->avs_codes['NETWORK'] = 'gateway connection error';
    $this->avs_codes['REJECTED'] = 'the request was rejected by the gateway';
    $this->avs_codes['SESSION_CONSUMED'] = 'session completed in another request';
    $this->avs_codes['SESSION_INSERT'] = 'failed to store session data';
    $this->avs_codes['SESSION_INVALID'] = 'failed to match clientToken with valid record; can occur during deployment';
    $this->avs_codes['UNEXPECTED_RESPONSE'] = 'the gateway did not respond with the expected data';
    $this->avs_codes['UNKNOWN'] = 'unknown error';
  }

}
