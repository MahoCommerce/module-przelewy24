<?php

/**
 * Maho
 *
 * @package    Maho_Przelewy24
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Przelewy24_Block_Info extends Mage_Payment_Block_Info
{
    #[\Override]
    protected function _prepareSpecificInformation($transport = null): \Maho\DataObject
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $payment = $this->getInfo();
        $helper = Mage::helper('maho_przelewy24');

        $data = [];

        $p24OrderId = $payment->getAdditionalInformation('p24_order_id');
        if ($p24OrderId) {
            $data[$helper->__('Przelewy24 Order ID')] = $p24OrderId;
        }

        $sessionId = $payment->getAdditionalInformation('p24_session_id');
        if ($sessionId) {
            $data[$helper->__('Session ID')] = $sessionId;
        }

        $statement = $payment->getAdditionalInformation('p24_statement');
        if ($statement) {
            $data[$helper->__('Statement')] = $statement;
        }

        return $transport->addData($data);
    }
}
