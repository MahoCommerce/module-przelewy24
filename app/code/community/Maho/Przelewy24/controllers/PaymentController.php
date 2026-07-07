<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Przelewy24
 */

declare(strict_types=1);

class Maho_Przelewy24_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Register the transaction with P24 and redirect the customer to their hosted payment page.
     */
    #[Maho\Config\Route('/przelewy24/payment/redirect')]
    public function redirectAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $orderIncrementId = $session->getLastRealOrderId();

        if (!$orderIncrementId) {
            $this->_redirect('checkout/cart');
            return;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        if (!$order->getId()) {
            $this->_redirect('checkout/cart');
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            /** @var Maho_Przelewy24_Model_Method_Standard $method */
            $method = $payment->getMethodInstance();
            $result = $method->registerTransaction($order);

            $session->setPrzelewy24QuoteId($session->getQuoteId());
            $session->unsQuoteId();

            $this->getResponse()->setRedirect($result['redirectUrl']);
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('core/session')->addError(
                Mage::helper('maho_przelewy24')->__('Unable to initialize payment. Please try again.'),
            );
            $this->_restoreCart($order);
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * Customer returns here after completing (or attempting) payment on P24.
     *
     * P24 redirects here for both successful and failed payments, so we can't
     * trust the URL alone. We query P24 for the actual transaction status and
     * finalize the order (capture or cancel) right here — instead of waiting
     * for the urlStatus webhook, which may be delayed or unreachable (sandbox,
     * local dev, firewalled servers).
     */
    #[Maho\Config\Route('/przelewy24/payment/success')]
    public function successAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getPrzelewy24QuoteId(true));

        $orderIncrementId = $session->getLastRealOrderId();
        $order = $orderIncrementId
            ? Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId)
            : null;

        if ($order && $order->getId()) {
            try {
                Mage::getModel('maho_przelewy24/cron')->processPaymentStatus($order);
            } catch (\Throwable $e) {
                Mage::logException($e);
            }

            // P24 reported the payment as returned (status 3): the order was
            // already cancelled by processPaymentStatus, so just give the
            // customer their cart back.
            if ($order->isCanceled()) {
                $this->_reactivateQuote($order);
                Mage::getSingleton('core/session')->addError(
                    Mage::helper('maho_przelewy24')->__('Payment was not completed.'),
                );
                $this->_redirect('checkout/cart');
                return;
            }

            // Status 0: P24 hasn't seen the money *yet*. This does NOT mean the
            // customer abandoned payment — several P24 methods are asynchronous
            // and the funds can land minutes after the redirect back, so the
            // order must stay in pending_payment for the webhook/cron to
            // finalize; cancelling it here would strand the payment at P24.
            // Reactivate the quote (without cancelling) so a customer who
            // really did abandon can retry checkout.
            if ($order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                $this->_reactivateQuote($order);
                Mage::getSingleton('core/session')->addNotice(
                    Mage::helper('maho_przelewy24')->__('Your payment has not been confirmed yet. If you completed the payment, your order will be processed automatically as soon as Przelewy24 confirms it.'),
                );
                $this->_redirect('checkout/cart');
                return;
            }
        }

        $session->getQuote()->setIsActive(0)->save();
        $this->_redirect('checkout/onepage/success', ['_secure' => true]);
    }

    /**
     * Customer cancelled payment on P24 side.
     */
    #[Maho\Config\Route('/przelewy24/payment/cancel')]
    public function cancelAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getPrzelewy24QuoteId(true));

        $orderIncrementId = $session->getLastRealOrderId();
        if ($orderIncrementId) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
            if ($order->getId()) {
                $this->_restoreCart($order);
            }
        }

        $this->_redirect('checkout/cart');
    }

    protected function _restoreCart(Mage_Sales_Model_Order $order): void
    {
        $order->cancel()->save();
        $this->_reactivateQuote($order);
    }

    protected function _reactivateQuote(Mage_Sales_Model_Order $order): void
    {
        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
        if ($quote->getId()) {
            $quote->setIsActive(1)->setReservedOrderId('')->save();
            Mage::getSingleton('checkout/session')->replaceQuote($quote);
        }
    }
}
