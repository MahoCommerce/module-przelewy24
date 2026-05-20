<?php

/**
 * Maho
 *
 * @package    Maho_Przelewy24
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Przelewy24_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Register the transaction with P24 and redirect the customer to their hosted payment page.
     */
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
     */
    public function successAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getPrzelewy24QuoteId(true));
        $session->getQuote()->setIsActive(0)->save();
        $this->_redirect('checkout/onepage/success', ['_secure' => true]);
    }

    /**
     * Customer cancelled payment on P24 side.
     */
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
        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
        if ($quote->getId()) {
            $quote->setIsActive(1)->setReservedOrderId('')->save();
            Mage::getSingleton('checkout/session')->replaceQuote($quote);
        }
    }
}
