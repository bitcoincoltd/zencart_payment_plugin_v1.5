<?php

// BX CoinPay IPN module

chdir('../../../..');
include('includes/application_top.php');
include_once(DIR_WS_MODULES.'/payment/bxcoinpay/coinpay_api_client.php');

/**
 * Getting response from POST
 * @array
 */
$response = json_decode(file_get_contents('php://input'),true);

/**
 * Find bx ID to include API client
 */
global $db;
$api_query = $db->Execute("select configuration_value from ".TABLE_CONFIGURATION." where configuration_key='MODULE_PAYMENT_BXCOINPAY_API_ID'");

if( !$api_query->RecordCount() ) {
  header("HTTP/1.0 403 Forbiden");
  print_r( "IPN Failed. Can't find API ID in database." );
  exit();
}
$api_id =  $api_query->fields['configuration_value'];
$api = new CoinpayApiClient($api_id);

if( !$api->validIPN($response) ) {
  header("HTTP/1.0 403 Forbiden");
  print_r( "IPN Failed. Signature invalid." );
  exit();
}

$order = $db->Execute("select orders_id, orders_status from ".TABLE_ORDERS." where orders_id='".zen_db_input( $response['order_id'])."'");

if(!$order) {
  header("HTTP/1.0 403 Forbidden");
  print_r( "IPN Failed. Order not found." );
  exit();
}

$order_status = ($response['confirmed_in_full'] == true ? MODULE_PAYMENT_BXCOINPAY_CONFIRMED_STATUS_ID : $order->fields['order_status'] );
if( $order_status == 0 ) {
  $order_status = DEFAULT_ORDERS_STATUS_ID;
}

$db->Execute("UPDATE ".TABLE_ORDERS." SET orders_status='".$order_status."' where orders_id='".$orders->fields['orders_id']."'");

$sql_data_array = array(
  'orders_id' => $response['order_id'],
  'orders_status_id' => $order_status,
  'date_added' => 'now()',
  'customer_notified' => '0',
  'comments' => '[BX CoinPay IPN: '.$response['message'].']'
);

zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);

?>
