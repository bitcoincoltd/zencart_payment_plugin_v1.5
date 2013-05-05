<?php
// Bitcoin.in.th IPN module
$data = $_POST;

chdir('../../../..');
include('includes/application_top.php');

include_once(DIR_WS_CLASSES.'bitcointhai.php');

$api = new bitcointhaiAPI;


global $db;

if($ipn = $api->verifyIPN($data)){
	$order = $db->Execute("SELECT orders_id, orders_status FROM ".TABLE_ORDERS." WHERE orders_id='".zen_db_input($data['reference_id'])."'");
	
	if ($order->RecordCount()){
		$order_status = ($data['success'] == 1 ? MODULE_PAYMENT_BITCOINTHAI_CONFIRMED_STATUS_ID : $order->fields['orders_status']);
		if($order_status == 0){
			$order_status = DEFAULT_ORDERS_STATUS_ID;
		}
		
		$db->Execute("UPDATE ".TABLE_ORDERS." SET orders_status='".$order_status."' WHERE orders_id='".$order->fields['orders_id']."'");
		
		$sql_data_array = array('orders_id' => $order->fields['orders_id'],
							    'orders_status_id' => $order_status,
							    'date_added' => 'now()',
							    'customer_notified' => '0',
							    'comments' => '[Bitcoin IPN: '.$data['message'].']');
		
		zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
	}else{
		header("HTTP/1.0 403 Forbidden");
		echo 'IPN Failed: Order Not Found';
	}
}else{
	header("HTTP/1.0 403 Forbidden");
	echo 'IPN Failed';
}
?>