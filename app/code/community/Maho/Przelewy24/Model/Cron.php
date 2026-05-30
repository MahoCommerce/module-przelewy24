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
            Mage::log(
                "Przelewy24: no p24_session_id on order #{$order->getIncrementId()}, skipping",
                Mage::LOG_WARNING,
                'przelewy24.log',
            );
            return;
        }

        $storeId = (int) $order->getStoreId();
        /** @var Maho_Przelewy24_Model_Api $api */
        $api = Mage::getModel('maho_przelewy24/api', ['store_id' => $storeId]);

        $result = $api->getTransactionBySessionId($sessionId);
        $status = (int) ($result['data']['status'] ?? 0);

        // P24 transaction status (per the REST API spec at developers.przelewy24.pl):
        //   0 - no payment        (customer hasn't paid)
        //   1 - advance payment   (customer paid; merchant verify still pending)
        //   2 - payment made      (merchant verify completed)
        //   3 - payment returned  (rejected / cancelled by P24)
        //
        // Both 1 and 2 mean the customer's money is at P24. The difference is
        // whether we've already called verify (which is what actually triggers
        // settlement to the merchant). In sandbox/local-dev setups the urlStatus
        // webhook often doesn't reach the merchant, so we may need to call verify
        // ourselves here on status 1.
        if ($status === 1 || $status === 2) {
            // Status 2 is already verified by P24; status 1 still needs verify
            // (the webhook that normally does it may never reach us in sandbox /
            // local-dev setups). captureOrder validates the amount before acting.
            /** @var Maho_Przelewy24_Model_Method_Standard $method */
            $method = $payment->getMethodInstance();
            $captured = $method->captureOrder($order, [
                'orderId' => (int) ($result['data']['orderId'] ?? 0),
                'amount' => (int) ($result['data']['amount'] ?? 0),
                'currency' => $result['data']['currency'] ?? $order->getOrderCurrencyCode(),
                // The by-sessionId response calls the chosen method "paymentMethod"
                // (the webhook calls it "methodId"). Capture it here too so the
                // method is recorded even when the webhook never reaches us.
                'methodId' => (int) ($result['data']['paymentMethod'] ?? 0),
                'statement' => $result['data']['statement'] ?? null,
            ], $status === 1);

            if ($captured) {
                Mage::log(
                    "Przelewy24: captured payment for order #{$order->getIncrementId()} "
                    . '(p24 orderId=' . (int) ($result['data']['orderId'] ?? 0) . ')',
                    Mage::LOG_INFO,
                    'przelewy24.log',
                );
            }
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
        } else {
            Mage::log(
                "Przelewy24: no action for order #{$order->getIncrementId()} (P24 status={$status})",
                Mage::LOG_INFO,
                'przelewy24.log',
            );
        }
    }
}
