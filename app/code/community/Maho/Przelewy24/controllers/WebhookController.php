<?php

/**
 * Maho
 *
 * @package    Maho_Przelewy24
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Przelewy24_WebhookController extends Mage_Core_Controller_Front_Action
{
    /**
     * Handle transaction status webhook from Przelewy24.
     *
     * P24 sends this after the customer completes payment. We verify the signature,
     * call the verify endpoint to confirm the transaction, then capture the payment.
     */
    public function transactionAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHttpResponseCode(405);
            return;
        }

        $body = $this->getRequest()->getRawBody();
        if ($body === false) {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        try {
            $data = Mage::helper('core')->jsonDecode($body);
        } catch (\Throwable $e) {
            Mage::log('Przelewy24 webhook: invalid JSON body', Mage::LOG_WARNING, 'przelewy24.log');
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        /** @var Maho_Przelewy24_Helper_Data $helper */
        $helper = Mage::helper('maho_przelewy24');

        $sessionId = $data['sessionId'] ?? '';
        $p24OrderId = (int) ($data['orderId'] ?? 0);
        $amount = (int) ($data['amount'] ?? 0);
        $currency = $data['currency'] ?? '';
        $receivedSign = $data['sign'] ?? '';

        $order = $this->_findOrderBySessionId($sessionId);
        if (!$order) {
            Mage::log("Przelewy24 webhook: no order found for sessionId={$sessionId}", Mage::LOG_WARNING, 'przelewy24.log');
            $this->getResponse()->setHttpResponseCode(404);
            return;
        }

        // Order may already have been finalized by successAction or an earlier
        // webhook delivery. Acknowledge with 200 so P24 doesn't keep retrying,
        // but don't re-process — registerCaptureNotification on an already-
        // captured order would flip it into PAYMENT_REVIEW with fraud flagged.
        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            Mage::log(
                "Przelewy24 webhook: order #{$order->getIncrementId()} already in state '{$order->getState()}', skipping",
                Mage::LOG_INFO,
                'przelewy24.log',
            );
            $this->getResponse()->setHttpResponseCode(200);
            return;
        }

        $storeId = (int) $order->getStoreId();
        $crcKey = $helper->getCrcKey($storeId);

        $signData = [
            'merchantId' => (int) ($data['merchantId'] ?? 0),
            'posId' => (int) ($data['posId'] ?? 0),
            'sessionId' => $sessionId,
            'amount' => $amount,
            'originAmount' => (int) ($data['originAmount'] ?? 0),
            'currency' => $currency,
            'orderId' => $p24OrderId,
            'methodId' => (int) ($data['methodId'] ?? 0),
            'statement' => $data['statement'] ?? '',
        ];

        if (!$helper->verifySign($signData, $receivedSign, $crcKey)) {
            Mage::log("Przelewy24 webhook: signature verification failed for sessionId={$sessionId}", Mage::LOG_WARNING, 'przelewy24.log');
            $this->getResponse()->setHttpResponseCode(401);
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            $this->getResponse()->setHttpResponseCode(500);
            return;
        }

        try {
            // captureOrder verifies the transaction and validates the amount
            // against what we charged before capturing — a valid signature only
            // proves P24 sent the data, not that the amount is correct. A
            // mismatch (e.g. the sandbox "Incorrect amount" action) returns
            // false: the order stays in pending_payment, but we still ack with
            // 200 since retrying won't fix a wrong amount.
            /** @var Maho_Przelewy24_Model_Method_Standard $method */
            $method = $payment->getMethodInstance();
            $captured = $method->captureOrder($order, [
                'orderId' => $p24OrderId,
                'amount' => $amount,
                'currency' => $currency,
                'methodId' => $data['methodId'] ?? null,
                'statement' => $data['statement'] ?? null,
            ], true);

            if ($captured) {
                Mage::log("Przelewy24 webhook: payment captured for order #{$order->getIncrementId()}", Mage::LOG_INFO, 'przelewy24.log');
            }
            $this->getResponse()->setHttpResponseCode(200);
        } catch (\Throwable $e) {
            Mage::logException($e);
            $this->getResponse()->setHttpResponseCode(500);
        }
    }

    /**
     * Handle refund webhook from Przelewy24.
     */
    public function refundAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHttpResponseCode(405);
            return;
        }

        $body = $this->getRequest()->getRawBody();
        if ($body === false) {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        try {
            $data = Mage::helper('core')->jsonDecode($body);
        } catch (\Throwable $e) {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        /** @var Maho_Przelewy24_Helper_Data $helper */
        $helper = Mage::helper('maho_przelewy24');

        $sessionId = $data['sessionId'] ?? '';
        $receivedSign = $data['sign'] ?? '';

        $order = $this->_findOrderBySessionId($sessionId);
        if (!$order) {
            $this->getResponse()->setHttpResponseCode(404);
            return;
        }

        $storeId = (int) $order->getStoreId();
        $crcKey = $helper->getCrcKey($storeId);

        $signData = [
            'orderId' => (int) ($data['orderId'] ?? 0),
            'sessionId' => $sessionId,
            'refundsUuid' => $data['refundsUuid'] ?? '',
            'merchantId' => (int) ($data['merchantId'] ?? 0),
            'amount' => (int) ($data['amount'] ?? 0),
            'currency' => $data['currency'] ?? '',
            'status' => (int) ($data['status'] ?? 0),
        ];

        if (!$helper->verifySign($signData, $receivedSign, $crcKey)) {
            Mage::log("Przelewy24 refund webhook: signature verification failed for sessionId={$sessionId}", Mage::LOG_WARNING, 'przelewy24.log');
            $this->getResponse()->setHttpResponseCode(401);
            return;
        }

        try {
            $payment = $order->getPayment();
            if (!$payment) {
                $this->getResponse()->setHttpResponseCode(500);
                return;
            }

            $refundUuid = $data['refundsUuid'] ?? '';

            $transaction = $payment->lookupTransaction($refundUuid, Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND);
            if ($transaction) {
                $transaction->setIsClosed(1)->save();
            }

            Mage::log("Przelewy24 refund webhook: refund confirmed for order #{$order->getIncrementId()}", Mage::LOG_INFO, 'przelewy24.log');
            $this->getResponse()->setHttpResponseCode(200);
        } catch (\Throwable $e) {
            Mage::logException($e);
            $this->getResponse()->setHttpResponseCode(500);
        }
    }

    /**
     * Find order by P24 session ID stored in payment additional_information.
     */
    protected function _findOrderBySessionId(string $sessionId): ?Mage_Sales_Model_Order
    {
        if ($sessionId === '') {
            return null;
        }

        $orderIncrementId = explode('_', $sessionId, 2)[0] ?? '';
        if ($orderIncrementId === '') {
            return null;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        if (!$order->getId()) {
            return null;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return null;
        }

        if ($payment->getAdditionalInformation('p24_session_id') !== $sessionId) {
            return null;
        }

        return $order;
    }
}
