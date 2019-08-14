<?php
/**
 * Test Controller
 */
class Tritac_ChannelEngine_TestController extends Mage_Core_Controller_Front_Action {

    /**
     * Index action
     */
    public function indexAction(){

        $apiKey = Mage::getStoreConfig('channelengine/general/api_key');
        $apiSecret = Mage::getStoreConfig('channelengine/general/api_secret');

        $this->client = new Tritac_ChannelEngineApiClient_Client($apiKey, $apiSecret, 'plugindev');

        $orders = $this->client->getOrders(array(Tritac_ChannelEngineApiClient_Enums_OrderStatus::IN_PROGRESS));

        if(!is_null($orders))
        {
            foreach($orders as $order)
            {
                $billingAddress = $order->getBillingAddress();
                if(empty($billingAddress)) continue;

                $quote = Mage::getModel('sales/quote')->setStoreId(Mage::app()->getStore()->getId());

                $lines = $order->getLines();
                if(!empty($lines)){
                    foreach($lines as $item){
                        // Load magento product
	                    $_product = Mage::getModel('catalog/product')
	                        ->setStoreId(Mage::app()->getStore()->getId());
	                    $productNo = $item->getMerchantProductNo();
	                    $ids = explode('_', $productNo);
	                    $productOptions = array();
	                    if(count($ids) == 3) {
	                        $productOptions = array($ids[1] => $ids[2]);
	                    }
	                    //$productId = $_product->getIdBySku();
	                    $_product->load($ids[0]);
	
	                    // Prepare product parameters for quote
	                    $params = new Varien_Object();
	                    $params->setQty($item->getQuantity());
	                    $params->setOptions($productOptions);
                        try {
                            $quote->addProduct($_product, $params);
                        } catch (Mage_Core_Exception $e) {
                            echo $e->getMessage();
                        } catch (Exception $e) {
                            echo $e->getMessage();
                            Mage::logException($e);
                        }

                    }
                }

                $billingData = array(
                    'firstname'     => $billingAddress->getFirstName(),
                    'lastname'      => $billingAddress->getLastName(),
                    'email'         => $order->getEmail(),
                    'telephone'     => '1234567890',
                    'country_id'    => $billingAddress->getCountryIso(),
                    'postcode'      => $billingAddress->getZipCode(),
                    'city'          => $billingAddress->getCity(),
                    'street'        => array(
                        $billingAddress->getStreetName().' '.
                        $billingAddress->getHouseNr().
                        $billingAddress->getHouseNrAddition()
                    ),
                    'save_in_address_book'  => 0,
                    'use_for_shipping'      => 1
                );

                $quote->getBillingAddress()
                    ->addData($billingData);
                $quote->getShippingAddress()
                    ->addData($billingData);

                $quote->setCustomerId(null)
                    ->setCustomerEmail($quote->getBillingAddress()->getEmail())
                    ->setCustomerIsGuest(true)
                    ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);

                $quote->getPayment()->importData(array('method' => 'checkmo'));
                $quote->getShippingAddress()
                    ->setShippingMethod('freeshipping_freeshipping')
                    ->setCollectShippingRates(true)
                    ->collectTotals();


                try {

                    $quote->save();

                    $service = Mage::getModel('sales/service_quote', $quote);
                    $service->submitAll();

                } catch (Mage_Core_Exception $e) {
                    echo $e->getMessage();
                } catch (Exception $e) {
                    echo $e->getMessage();
                    Mage::logException($e);
                }

                $_order = $service->getOrder();
                var_export($_order->getIncrementId());
            }
        }
    }
    
    public function fetchAction()
    {
		$apiKey = Mage::getStoreConfig('channelengine/general/api_key');
        $apiSecret = Mage::getStoreConfig('channelengine/general/api_secret');

        $this->client = new Tritac_ChannelEngineApiClient_Client($apiKey, $apiSecret, 'plugindev');

        /**
         * Retrieve new orders
         */
        $orders = $this->client->getOrders(array(
            Tritac_ChannelEngineApiClient_Enums_OrderStatus::NEW_ORDER
        ));

        /**
         * Check new orders existing
         */
        if(is_null($orders) || $orders->count() == 0)
            return false;

        foreach($orders as $order) {

            $billingAddress = $order->getBillingAddress();
            $shippingAddress = $order->getShippingAddress();
            if(empty($billingAddress)) continue;

            // Initialize new quote
            $quote = Mage::getModel('sales/quote')->setStoreId(Mage::app()->getDefaultStoreView()->getStoreId());
            $lines = $order->getLines();

            if(!empty($lines)) {

                foreach($lines as $item) {

                    // Load magento product
	                    $_product = Mage::getModel('catalog/product')
	                        ->setStoreId(Mage::app()->getStore()->getId());
	                    $productNo = $item->getMerchantProductNo();
	                    $ids = explode('_', $productNo);
	                    $productOptions = array();
	                    if(count($ids) == 3) {
	                        $productOptions = array($ids[1] => $ids[2]);
	                    }
	                    //$productId = $_product->getIdBySku();
	                    $_product->load($ids[0]);
	
	                    // Prepare product parameters for quote
	                    $params = new Varien_Object();
	                    $params->setQty($item->getQuantity());
	                    $params->setOptions($productOptions);

                    // Add product to quote
                    try {
                        $_quoteItem = $quote->addProduct($_product, $params);
                        $_quoteItem->setChannelengineOrderLineId($item->getId());

                    } catch (Exception $e) {

                        Mage::getModel('adminnotification/inbox')->addCritical(
                            "An order (#{$order->getId()}) could not be imported",
                            "Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                        );
                        Mage::logException($e);
                        continue 2;
                    }
                }
            }
            $phone = $order->getPhone();
            if(empty($phone))
                $phone = '-';
            // Prepare billing and shipping addresses
            $billingData = array(
                'firstname'     => $billingAddress->getFirstName(),
                'lastname'      => $billingAddress->getLastName(),
                'email'         => $order->getEmail(),
                'telephone'     => $phone,
                'country_id'    => $billingAddress->getCountryIso(),
                'postcode'      => $billingAddress->getZipCode(),
                'city'          => $billingAddress->getCity(),
                'street'        =>
                    $billingAddress->getStreetName().' '.
                    $billingAddress->getHouseNr().
                    $billingAddress->getHouseNrAddition()
            );
            $shippingData = array(
                'firstname'     => $shippingAddress->getFirstName(),
                'lastname'      => $shippingAddress->getLastName(),
                'email'         => $order->getEmail(),
                'telephone'     => $phone,
                'country_id'    => $shippingAddress->getCountryIso(),
                'postcode'      => $shippingAddress->getZipCode(),
                'city'          => $shippingAddress->getCity(),
                'street'        =>
                    $shippingAddress->getStreetName().' '.
                    $shippingAddress->getHouseNr().
                    $shippingAddress->getHouseNrAddition()
            );

            // Register shipping cost. See Tritac_ChannelEngine_Model_Carrier_Channelengine::collectrates();
            if($order->getShippingCostsInclVat() && floatval($order->getShippingCostsInclVat()) > 0) {
                Mage::register('channelengine_shipping_amount', floatval($order->getShippingCostsInclVat()));
            }

            $quote->getBillingAddress()
                ->addData($billingData);
            $quote->getShippingAddress()
                ->addData($shippingData)
                ->setSaveInAddressBook(0)
                ->setCollectShippingRates(true)
                ->setShippingMethod('channelengine_channelengine');

            $quote->collectTotals();

            // Set guest customer
            $quote->setCustomerId(null)
                ->setCustomerEmail($quote->getBillingAddress()->getEmail())
                ->setCustomerIsGuest(true)
                ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);

            // Set custom payment method
            $quote->getPayment()->importData(array('method' => 'channelengine'));

            // Save quote and convert it to new order
            try {

                $quote->save();

                $service = Mage::getModel('sales/service_quote', $quote);

                $service->submitAll();

            } catch (Exception $e) {
                Mage::getModel('adminnotification/inbox')->addCritical(
                    "An order (#{$order->getId()}) could not be imported",
                    "Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                );
                Mage::logException($e);
                continue;
            }

            $_order = $service->getOrder();

            if($_order->getIncrementId()) {

                /**
                 * Create new invoice and save channel order
                 */
                try {
                    // Initialize new invoice model
                    $invoice = Mage::getModel('sales/service_order', $_order)->prepareInvoice();
                    // Add comment to invoice
                    $invoice->addComment(
                        "Order paid on the marketplace.",
                        false,
                        true
                    );

                    // Register invoice. Register invoice items. Collect invoice totals.
                    $invoice->register();
                    $invoice->getOrder()->setIsInProcess(true);

                    // Initialize new channel order
                    $_channelOrder = Mage::getModel('channelengine/order');
                    $_channelOrder->setOrderId($_order->getId())
                        ->setChannelOrderId($order->getId())
                        ->setChannelName($order->getChannelName())
                        ->setDoSendMails($order->getDoSendMails())
                        ->setCanShipPartial($order->getCanShipPartialOrderLines());

                    $invoice->getOrder()
                        ->setCanShipPartiallyItem($order->getCanShipPartialOrderLines())
                        ->setCanShipPartially($order->getCanShipPartialOrderLines());

                    // Start new transaction
                    $transactionSave = Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->addObject($_channelOrder);
                    $transactionSave->save();

                } catch (Exception $e) {
                    Mage::getModel('adminnotification/inbox')->addCritical(
                        "An invoice could not be created (order #{$_order->getIncrementId()}, channel order #{$order->getId()})",
                        "Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                    );
                    Mage::logException($e);
                    continue;
                }
                Mage::log("Order #{$_order->getIncrementId()} was imported successfully.");
            } else {
                Mage::log("An order (#{$order->getId()}) could not be imported");
            }
        }

        return true;
    }
    
    public function returnAction() {

        $apiKey = Mage::getStoreConfig('channelengine/general/api_key');
        $apiSecret = Mage::getStoreConfig('channelengine/general/api_secret');

        $this->client = new Tritac_ChannelEngineApiClient_Client($apiKey, $apiSecret, 'plugindev');

        /**
         * Retrieve returns
         */
        $returns = $this->client->getReturns(array(
            Tritac_ChannelEngineApiClient_Enums_ReturnStatus::DECLARED
        ));

        /**
         * Check declared returns
         */
        if(is_null($returns) || $returns->count() == 0)
            return false;

        foreach($returns as $return) {
            $_channelOrder = Mage::getModel('channelengine/order')->loadByChannelOrderId($return->getOrderId());
            $_order = Mage::getModel('sales/order')->load($_channelOrder->getOrderId());

            if(!$_order->getIncrementId()) {
                continue;
            }

            $status     = $return->getStatus(); // Get return status
            $reason     = $return->getReason(); // Get return reason
            $title      = "You have new return from ChannelEngine (ChannelEngine Order #{$return->getOrderId()})";
            $message    = "Magento Order #: <a href='".
                Mage::helper('adminhtml')->getUrl('adminhtml/sales_order/view', array('order_id'=>$_order->getOrderId())).
                "'>".
                $_order->getIncrementId().
                "</a><br />";
            $message   .= "Status: {$status}<br />";
            $message   .= "Reason: {$reason}<br />";
            $message   .= "For more details visit your ChannelEngine <a href='http://www.channelengine.com' target='_blank'>account</a>";

            // Check if notification is already exist
            $_resource  = Mage::getSingleton('core/resource');
            $_connectionRead = $_resource->getConnection('core_read');
            $select = $_connectionRead->select()
                ->from($_resource->getTableName('adminnotification/inbox'))
                ->where('title = ?', $title)
                ->where('is_remove != 1')
                ->limit(1);
            $data = $_connectionRead->fetchRow($select);

            if ($data) {
                continue;
            }

            // Add new notification
            Mage::getModel('adminnotification/inbox')->addCritical(
                $title,
                $message,
                'http://www.channelengine.com'
            );
        }
    }
}