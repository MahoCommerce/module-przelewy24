<?php

/**
 * Maho
 *
 * @package    Maho_Przelewy24
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Przelewy24_Model_Cron
{
    /**
     * Check pending P24 payments and update their status.
     *
     * Runs every 5 minutes. Catches orders stuck in pending_payment (e.g. webhook
     * failed to arrive) and verifies them against the P24 API.
     */
    public function checkPendingPayments(): void
    {
        $orders = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('created_at', ['gteq' => date('Y-m-d H:i:s', strtotime('-24 hours'))])
            ->setPageSize(50);

        $orders->getSelect()->join(
            ['payment' => Mage::getSingleton('core/resource')->getTableName('sales/order_payment')],
            'payment.parent_id = main_table.entity_id',
            [],
        );
        $orders->getSelect()->where('payment.method = ?', 'przelewy24');

        foreach ($orders as $order) {
            try {
                $this->processPaymentStatus($order);
            } catch (\Throwable $e) {
                Mage::log(
                    "Przelewy24 cron: error checking order #{$order->getIncrementId()}: {$e->getMessage()}",
                    Mage::LOG_ERROR,
                    'przelewy24.log',
                );
            }
        }
    }

    /**
     * Poll P24 for the transaction status of a pending order and finalize it
     * (capture or cancel). Called from cron and from the return-from-P24 flow,
     * so it must be a no-op for orders already past pending_payment.
     */
    public function processPaymentStatus(Mage_Sales_Model_Order $order): void
    {
        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        $sessionId = $payment->getAdditionalInformation('p24_session_id');
        if (!$sessionId) {
            return;
        }

        $storeId = (int) $order->getStoreId();
        /** @var Maho_Przelewy24_Model_Api $api */
        $api = Mage::getModel('maho_przelewy24/api', ['store_id' => $storeId]);

        $result = $api->getTransactionBySessionId($sessionId);
        $status = (int) ($result['data']['status'] ?? 0);

        if ($status === 2) {
            /** @var Maho_Przelewy24_Helper_Data $helper */
            $helper = Mage::helper('maho_przelewy24');

            $p24OrderId = (int) ($result['data']['orderId'] ?? 0);
            $amount = (int) ($result['data']['amount'] ?? 0);
            $currency = $result['data']['currency'] ?? $order->getBaseCurrencyCode();

            $api->verifyTransaction([
                'merchantId' => $helper->getMerchantId($storeId),
                'posId' => $helper->getPosId($storeId),
                'sessionId' => $sessionId,
                'amount' => $amount,
                'currency' => $currency,
                'orderId' => $p24OrderId,
            ]);

            $payment->setAdditionalInformation('p24_order_id', $p24OrderId);
            $payment->save();
            $payment->registerCaptureNotification((float) $amount / 100);
            $order->save();

            // registerCaptureNotification puts the order in STATE_PROCESSING with
            // the default processing status. Apply the merchant-configured status
            // (which may differ) while leaving the state as-is.
            $processingStatus = $helper->getProcessingStatus($storeId);
            if ($processingStatus !== '' && $processingStatus !== (string) $order->getStatus()) {
                $order->setStatus($processingStatus);
                $order->addStatusHistoryComment(
                    $helper->__('Order status set to "%s" per Przelewy24 configuration.', $processingStatus),
                    $processingStatus,
                )->setIsCustomerNotified(false);
                $order->save();
            }

            Mage::log(
                "Przelewy24: captured payment for order #{$order->getIncrementId()} (p24 orderId={$p24OrderId})",
                Mage::LOG_INFO,
                'przelewy24.log',
            );
        } elseif ($status === 3) {
            $order->cancel()->save();

            // Reactivate the quote so the customer can resume checkout (possibly
            // with a different payment method) without having to rebuild their cart.
            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(1)->setReservedOrderId('')->save();
            }

            Mage::log(
                "Przelewy24: cancelled order #{$order->getIncrementId()} (payment returned)",
                Mage::LOG_INFO,
                'przelewy24.log',
            );
        }
    }
}
