<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Przelewy24
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

        // Which method the customer actually paid with (bank transfer, BLIK, card...).
        // Prefer the resolved name; fall back to the raw id if it couldn't be resolved.
        $methodName = $payment->getAdditionalInformation('p24_method_name');
        $methodId = $payment->getAdditionalInformation('p24_method_id');
        if ($methodName) {
            $data[$helper->__('Payment Method')] = $methodName;
        } elseif ($methodId) {
            $data[$helper->__('Payment Method')] = $methodId;
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
