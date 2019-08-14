<?php
class Tritac_ChannelEngine_Block_Head extends Mage_Core_Block_Template
{
    public function getAccountName() {

        $storeId = Mage::app()->getStore()->getId();
        $config = Mage::helper('channelengine')->getGeneralConfig();

        return $config[$storeId]['tenant'];
    }

    public function getEnvironment()
    {
    	return Mage::helper('channelengine')->isDevelopment() ? 'development' : 'production';
    }
}