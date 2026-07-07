<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Przelewy24
 */

declare(strict_types=1);

class Maho_Przelewy24_Model_Cron
{
    /**
     * How long an unpaid (P24 status 0) order may stay in pending_payment
     * before the cron cancels it. Asynchronous P24 methods can confirm well
     * after the customer returns to the store, so this must be generous
     * enough to cover them; payments confirmed even later still hit the
     * webhook, which logs a reconciliation warning for cancelled orders.
     */
    public const PAYMENT_EXPIRY_HOURS = 4;

    /**
     * Check pending P24 payments and update their status.
     *
     * Runs every 5 minutes. Catches orders stuck in pending_payment (e.g. webhook
     * failed to arrive) and verifies them against the P24 API. The scan window is
     * twice PAYMENT_EXPIRY_HOURS so every order gets cancelled once it expires
     * before ageing out of the window.
     */
    #[Maho\Config\CronJob('maho_przelewy24_check_pending_payments', schedule: '*/5 * * * *')]
    public function checkPendingPayments(): void
    {
        $windowHours = self::PAYMENT_EXPIRY_HOURS * 2;
        $orders = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('created_at', ['gteq' => date('Y-m-d H:i:s', strtotime("-{$windowHours} hours"))])
            ->setPageSize(50);

        $orders->getSelect()->join(
            ['payment' => Mage::getSingleton('core/resource')->getTableName('sales/order_payment')],
            'payment.parent_id = main_table.entity_id',
            [],
        );
        $orders->getSelect()->where('payment.method = ?', 'przelewy24');

        foreach ($orders as $order) {
            try {
                // Quiet: a still-unpaid order is the expected case on every
                // 5-minute pass — logging it would flood przelewy24.log.
                $this->processPaymentStatus($order, false);
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
     *
     * $logNoAction controls whether a still-unpaid order (status 0, not yet
     * expired) writes a log line: useful on the customer-return flow where it
     * documents *why* the customer was bounced back to the cart, pure noise
     * on the recurring cron passes.
     */
    public function processPaymentStatus(Mage_Sales_Model_Order $order, bool $logNoAction = true): void
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
            // No quote handling here: if the customer came back to the store,
            // successAction already gave them their cart back; if they never
            // came back, their session is gone and reactivating the quote
            // hours later could even resurrect a cart they since re-ordered.
            $order->cancel()->save();

            Mage::log(
                "Przelewy24: cancelled order #{$order->getIncrementId()} (payment returned)",
                Mage::LOG_INFO,
                'przelewy24.log',
            );
        } else {
            // Status 0 means P24 hasn't seen the money *yet* — not that the
            // customer abandoned payment. Asynchronous methods can confirm
            // minutes (or longer) after the customer returns to the store, so
            // the order must stay in pending_payment until either the webhook/
            // cron captures it or the payment window has clearly expired.
            $createdAt = $order->getCreatedAt();
            if ($status === 0
                && $createdAt !== null
                && strtotime($createdAt) < strtotime('-' . self::PAYMENT_EXPIRY_HOURS . ' hours')
            ) {
                $order->cancel()->save();

                Mage::log(
                    "Przelewy24: cancelled order #{$order->getIncrementId()} "
                    . '(payment never arrived within ' . self::PAYMENT_EXPIRY_HOURS . ' hours)',
                    Mage::LOG_INFO,
                    'przelewy24.log',
                );
            } elseif ($logNoAction) {
                Mage::log(
                    "Przelewy24: no action for order #{$order->getIncrementId()} (P24 status={$status})",
                    Mage::LOG_INFO,
                    'przelewy24.log',
                );
            }
        }
    }
}
