<?php

$dir = dirname(__FILE__);
include_once($dir."/bxcoinpay/coinpay_api_client.php");
class bxcoinpay {
  var $code, $title, $description, $callback, $enabled, $api, $cryptocurrencies, $ipn, $order_id;

  function bxcoinpay()
  {
    global $order;

    $this->code = 'bxcoinpay';
    $this->api_id = MODULE_PAYMENT_BXCOINPAY_API_ID;
    $this->title = MODULE_PAYMENT_BXCOINPAY_TEXT_TITLE;
    $this->description = MODULE_PAYMENT_BXCOINPAY_TEXT_DESCRIPTION;
    $this->sort_order = MODULE_PAYMENT_BXCOINPAY_SORT_ORDER;
    $this->enabled = ((MODULE_PAYMENT_BXCOINPAY_STATUS == 'True') ? true : false);
    $this->cryptocurrencies = MODULE_PAYMENT_BXCOINPAY_CRYPTOCURRENCIES;

    $this->api = new CoinpayApiClient($this->api_id);
    $this->callback = zen_href_link('ext/modules/payment/bxcoinpay/bxcoinpay.php','','SSL',false,false,true);

    if ((int)MODULE_PAYMENT_BXCOINPAY_ORDER_STATUS_ID > 0) {
      $this->order_status = MODULE_PAYMENT_BXCOINPAY_ORDER_STATUS_ID;
    }

    //if (is_object($order) && IS_ADMIN_FLAG !== true) $this->update_status();
  }

    function update_status() {
      global $order, $db;

      if ( ($this->enabled == true) && ((int)MODULE_PAYMENT_BXCOINPAY_ZONE > 0) ) {
        $check_flag = false;
        $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_BXCOINPAY_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
        while (!$check->EOF) {
          if ($check->fields['zone_id'] < 1) {
            $check_flag = true;
            break;
          } elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
            $check_flag = true;
            break;
          }
          $check->MoveNext();
        }


        if ($check_flag == false) {
          $this->enabled = false;
        }
      }


//		$this->api = new bitcointhaiAPI;
//		if(!$this->api->init(MODULE_PAYMENT_BXCOINPAY_API_ID, MODULE_PAYMENT_BXCOINPAY_API_KEY)){
//			$this->enabled = false;
//		}
  } // #update_status()

  function javascript_validation()
  {
    return false;
  }

  function selection()
  {
      return array('id' => $this->code,
                   'module' => $this->title);
  }

  function pre_confirmation_check()
  {
    return false;
  }

  function confirmation() {
		global $order, $paybox;


    $request = new PaymentDetailsRequest(
      $this->callback,
      $order->info['total'],
      $order->info['currency'],
      $this->cryptocurrencies,
      "Payment for order in ". STORE_NAME
    );

    // Refresh session if hash not match
    if( $this->payment_details_must_be_refreshed($request)) {
      $payment_details = $this->api->getPaymentDetails($request);
      $_SESSION['payment_details'] = $payment_details;
      $_SESSION['payment_details_hash'] = $request->hash();
    }else{
      $payment_details = $_SESSION['payment_details'];
    }

    if(!$payment_details ) {
      $this->get_payment_details_failed();
    }

    // loop through all addresses
    $addresses_arr = array();
    foreach( $payment_details as $key => $value ) {
      foreach( $value as $key => $item ) {
        array_push( $addresses_arr, $item->address);
      }
    }

    $_SESSION['bx_payment_addresses'] = $addresses_arr;

    include_once('bxcoinpay/payment_fields.php');
    echo "<input type='hidden' name='order_id' value='".(int)$this->order_id."'>";
  } // #confirmation()

  function process_button()
  {
    return false;
  }

  function before_process()
  {
    global $order, $messageStack;

    $bx_payment_addresses = $_SESSION['bx_payment_addresses'];

    $result = $this->api->checkPaymentReceived(
      $bx_payment_addresses
    );

    if( !$result ) {
      $error = "Payment error! ".$result->error;
		  $messageStack->add_session('checkout_payment', $error, 'error');
		  zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT,'', 'SSL'));
    }

    if( $result->payment_received === false ) {
      $error = "Did you already pay it? We still did not see your payment! <br> It can take a few seconds for your payment to appear. If you already paid - press button again.";
		  $messageStack->add_session('checkout_payment', $error, 'error');
		  zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT,'', 'SSL'));
    }

    $_SESSION['payment_received'] = $result;
    return true;
  }

  function after_process()
  {
    global $insert_id, $order, $messageStack;

    // Check if order already in session
    if( isset($_SESSION['order_id']) ) {
      $order_id = $_SESSION['order_id'];
      $this->order_remove($insert_id, false);
    }else{
      $_SESSION['order_id'] = $insert_id;
      $order_id = $insert_id;
      $this->order_comment_update($order_id, $order->info['order_status'], $this->expected_amount());
    }

    $this->not_enough_error($order_id, $order->info['order_status']);

    $order_saved = $this->api->saveOrderId(
      $_SESSION['bx_payment_addresses'],
      $order_id
    );

    if( $order_saved === false ) {
      $error = "Something went wrong! Order ID did not saved";
		  $messageStack->add_session('checkout_payment', $error, 'error');
		  zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT,'', 'SSL'));
    }

    $awaiting_confirmation = "[Coinpay: Payment awaiting coinfirmation] ";
    $this->order_comment_update($order_id, $order->info['order_status'], $awaiting_confirmation);


    unset($_SESSION['bx_payment_addresses']);
    unset($_SESSION['payment_details_hash']);
    unset($_SESSION['payment_details']);
    unset($_SESSION['order_id']);
    unset($_SESSION['payment_received']);
    return false;
  }

    function get_error() {
      return;
    }

    function check() {
      global $db;
      if (!isset($this->_check)) {
        $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_BXCOINPAY_STATUS'");
        $this->_check = $check_query->RecordCount();
      }
      return $this->_check;
    }

  function install()
  {
	  global $db;
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Cash On Delivery Module', 'MODULE_PAYMENT_BXCOINPAY_STATUS', 'True', 'Do you want to accept Bitcoin payments?', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_BXCOINPAY_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('API Access ID', 'MODULE_PAYMENT_BXCOINPAY_API_ID', '0', 'API Access ID from https://coinpay.in.th/', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Cryptocurrencies', 'MODULE_PAYMENT_BXCOINPAY_CRYPTOCURRENCIES', '0', 'Example: BTC, BCH, LTC', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_BXCOINPAY_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Before Confirmation Order Status', 'MODULE_PAYMENT_BXCOINPAY_ORDER_STATUS_ID', '0', 'Set the status of orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('After Confirmation Order Status', 'MODULE_PAYMENT_BXCOINPAY_CONFIRMED_STATUS_ID', '0', 'Set the status of orders after payment confirmation is received', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
  }
    function remove() {
      global $db;
      $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }
    function keys() {
      return array('MODULE_PAYMENT_BXCOINPAY_STATUS', 'MODULE_PAYMENT_BXCOINPAY_ZONE', 'MODULE_PAYMENT_BXCOINPAY_API_ID','MODULE_PAYMENT_BXCOINPAY_CRYPTOCURRENCIES','MODULE_PAYMENT_BXCOINPAY_ORDER_STATUS_ID', 'MODULE_PAYMENT_BXCOINPAY_SORT_ORDER','MODULE_PAYMENT_BXCOINPAY_CONFIRMED_STATUS_ID');
    }

    protected function payment_details_must_be_refreshed($request)
    {
      // Hash vill be change if cart changed
      return $_SESSION['payment_details_hash'] != $request->hash();
    }

    protected function get_payment_details_failed()
    {
      echo MODULE_PAYMENT_BXCOINPAY_TEXT_ERROR;
    }

    protected function order_remove($order_id, $restock = false)
    {
      global $db;
      // TODO implement restock

      $db->Execute("delete from ".TABLE_ORDERS." where orders_id = '".(int)$order_id."'");
      $db->Execute("delete from ".TABLE_ORDERS_PRODUCTS." where orders_id = '".(int)$order_id."'");
      $db->Execute("delete from ".TABLE_ORDERS_STATUS_HISTORY." where orders_id = '".(int)$order_id."'");
      $db->Execute("delete from ".TABLE_ORDERS_TOTAL." where orders_id = '".(int)$order_id."'");
    }

    protected function order_comment_update($id, $status, $comment)
    {
      global $db;
    $db->Execute(
        "insert into ".TABLE_ORDERS_STATUS_HISTORY."
        (orders_id, orders_status_id, date_added, customer_notified, comments)
        values(
        ".(int)$id.",
        ".(int)$status.",
        now(),
        0,
        '".$comment."')
      ");
    }

  protected function not_enough_error($order_id, $order_status)
  {
    global $messageStack;

    if( $_SESSION['payment_received']->is_enough === false ) {
      $str = '';
      foreach( $_SESSION['payment_received']->paid as $key => $value ) {
        $str .= $value->amount." in ".$value->cryptocurrency."; ";
      }
      $error = "Payment amount is not enough. Got: ".$str;
      $this->order_comment_update($order_id, $order_status, $error);
		  $messageStack->add_session('checkout_payment', $error, 'error');
		  zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT,'', 'SSL'));
    }
  }

  protected function expected_amount()
  {
    $str = 'Expecting ';
    if( isset($_SESSION['payment_details']) ) {
      foreach( $_SESSION['payment_details']->addresses as $key => $value ) {
        $str .= " ".$value->amount." in ".$key." to ".$value->address."; ";
      }
    }
    return $str;
  }

}
