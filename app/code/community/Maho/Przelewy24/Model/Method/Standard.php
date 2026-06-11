<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Przelewy24
 */

declare(strict_types=1);

class Maho_Przelewy24_Model_Method_Standard extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'przelewy24';

    protected $_formBlockType = 'maho_przelewy24/form';
    protected $_infoBlockType = 'maho_przelewy24/info';

    /**
     * P24 only settles in PLN. If the quote currency isn't PLN the method is
     * hidden from checkout — otherwise we'd silently convert and the customer
     * would see a different number on the P24 redirect page.
     */
    protected string $_requiredCurrency = 'PLN';

    protected $_isGateway = true;
    protected $_canAuthorize = false;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_isInitializeNeeded = true;
    protected $_canFetchTransactionInfo = true;

    protected ?Maho_Przelewy24_Model_Api $_api = null;

    #[\Override]
    public function isAvailable($quote = null): bool
    {
        if (!$this->_getP24Helper()->hasCredentials($quote?->getStoreId())) {
            return false;
        }
        if ($quote !== null
            && strtoupper((string) $quote->getQuoteCurrencyCode()) !== $this->_requiredCurrency
        ) {
            return false;
        }
        return parent::isAvailable($quote);
    }

    /**
     * Redirect URL returned to checkout JS after order placement.
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return Mage::getUrl('przelewy24/payment/redirect', ['_secure' => true]);
    }

    /**
     * Set order to the configured pending status on placement. The webhook will
     * move it to the configured processing status after P24 confirms the capture.
     */
    /**
     * @param \Maho\DataObject $stateObject
     */
    #[\Override]
    public function initialize($paymentAction, $stateObject): self
    {
        $storeId = null;
        try {
            $info = $this->getInfoInstance();
            if ($info !== null) {
                $source = $info->getOrder() ?: $info->getQuote();
                if ($source !== null && $source->getStoreId() !== null) {
                    $storeId = (int) $source->getStoreId();
                }
            }
        } catch (\Throwable) {
            $storeId = null;
        }

        $statusCode = $this->_getP24Helper()->getPendingStatus($storeId);

        // Resolve the state the configured status is attached to. Falls back
        // to STATE_PENDING_PAYMENT if the status row is missing or unassigned.
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        /** @var Mage_Sales_Model_Order_Config $orderConfig */
        $orderConfig = Mage::getSingleton('sales/order_config');
        foreach ($orderConfig->getStatusStates($statusCode) as $statusState) {
            $resolvedState = (string) $statusState->getState();
            if ($resolvedState !== '') {
                $state = $resolvedState;
                break;
            }
        }

        $stateObject->setData('state', $state);
        $stateObject->setData('status', $statusCode);
        $stateObject->setData('is_notified', false);
        return $this;
    }

    /**
     * Register transaction with P24 and return redirect URL + token.
     *
     * Called from PaymentController::redirectAction().
     *
     * @return array{token: string, redirectUrl: string}
     */
    public function registerTransaction(Mage_Sales_Model_Order $order): array
    {
        $helper = $this->_getP24Helper();
        $storeId = (int) $order->getStoreId();
        $payment = $order->getPayment();

        if (!$payment) {
            Mage::throwException($helper->__('No payment found for order.'));
        }

        $sessionId = $helper->generateSessionId($order->getIncrementId());
        $amount = $helper->toGrosze((float) $order->getGrandTotal());
        $currency = (string) $order->getOrderCurrencyCode();

        $billingAddress = $order->getBillingAddress();
        if (!$billingAddress) {
            Mage::throwException($helper->__('No billing address found for order.'));
        }

        /** @var array<string> $street */
        $street = $billingAddress->getStreet();

        $data = [
            'merchantId' => $helper->getMerchantId($storeId),
            'posId' => $helper->getPosId($storeId),
            'sessionId' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $helper->__('Order #%s', $order->getIncrementId()),
            'encoding' => 'UTF-8',
            'email' => $order->getCustomerEmail(),
            'client' => $billingAddress->getFirstname() . ' ' . $billingAddress->getLastname(),
            'address' => implode(' ', $street),
            'zip' => $billingAddress->getPostcode(),
            'city' => $billingAddress->getCity(),
            'country' => $billingAddress->getCountryId(),
            'language' => $helper->getLanguage(),
            'urlReturn' => Mage::getUrl('przelewy24/payment/success', ['_secure' => true]),
            'urlStatus' => Mage::getUrl('przelewy24/webhook/transaction', ['_secure' => true]),
        ];

        $result = $this->_getApi($storeId)->registerTransaction($data);

        $payment->setAdditionalInformation('p24_session_id', $sessionId);
        $payment->setAdditionalInformation('p24_token', $result['token']);
        $payment->setAdditionalInformation('p24_amount', $amount);
        $payment->setAdditionalInformation('p24_currency', $currency);
        $payment->save();

        $baseUrl = $helper->getApiBaseUrl($storeId);
        return [
            'token' => $result['token'],
            'redirectUrl' => $baseUrl . '/trnRequest/' . $result['token'],
        ];
    }

    /**
     * Finalize a pending order from a confirmed P24 transaction.
     *
     * Shared by the urlStatus webhook (push) and Cron::processPaymentStatus
     * (pull, also used on return from P24). Validates the reported amount and
     * currency against what we registered the transaction with — on mismatch
     * (e.g. the sandbox "Incorrect amount" action, tampering, or a P24-side
     * error) the order is left untouched in pending_payment and false is
     * returned. When the amount checks out it optionally verifies, captures
     * the payment, and applies the merchant-configured processing status.
     *
     * @param array{orderId?: int, amount?: int, currency?: string, methodId?: int, statement?: string} $transaction
     * @return bool true when the payment was captured, false when rejected
     */
    public function captureOrder(Mage_Sales_Model_Order $order, array $transaction, bool $verify): bool
    {
        $helper = $this->_getP24Helper();
        $storeId = (int) $order->getStoreId();

        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }

        $sessionId = (string) $payment->getAdditionalInformation('p24_session_id');
        $p24OrderId = (int) ($transaction['orderId'] ?? 0);
        $amount = (int) ($transaction['amount'] ?? 0);
        $currency = (string) ($transaction['currency'] ?? $order->getOrderCurrencyCode());

        // Guard against tampered / incorrect amounts (P24 sandbox "Incorrect
        // amount" action, MITM, or P24-side error). The amount and currency we
        // registered the transaction with are stored on the payment; a valid
        // signature/status only proves P24 sent the data, not that it matches
        // what we charged. On any mismatch leave the order in pending_payment
        // so the customer sees "Payment was not completed" instead of shipping.
        $expectedAmount = (int) $payment->getAdditionalInformation('p24_amount');
        $expectedCurrency = (string) $payment->getAdditionalInformation('p24_currency');
        if ($expectedAmount > 0
            && ($amount !== $expectedAmount
                || ($expectedCurrency !== '' && strcasecmp($currency, $expectedCurrency) !== 0))
        ) {
            Mage::log(
                "Przelewy24: amount/currency mismatch for order #{$order->getIncrementId()} "
                . "(expected {$expectedAmount} {$expectedCurrency}, got {$amount} {$currency}) — not capturing",
                Mage::LOG_ERROR,
                'przelewy24.log',
            );
            return false;
        }

        // Status 1 (advance payment) means the customer's money is at P24 but
        // we haven't called verify yet, which is what actually settles it to
        // the merchant. Status 2 is already verified, so skip it there.
        if ($verify) {
            $this->_getApi($storeId)->verifyTransaction([
                'merchantId' => $helper->getMerchantId($storeId),
                'posId' => $helper->getPosId($storeId),
                'sessionId' => $sessionId,
                'amount' => $amount,
                'currency' => $currency,
                'orderId' => $p24OrderId,
            ]);
        }

        $payment->setAdditionalInformation('p24_order_id', $p24OrderId);
        $methodId = (int) ($transaction['methodId'] ?? 0);
        if ($methodId > 0) {
            $payment->setAdditionalInformation('p24_method_id', $methodId);
            // Resolve the numeric id to a name (e.g. "mBank", "BLIK") for display.
            // Never let a name lookup abort the capture — the helper returns ''
            // (and the info block falls back to the id) on any failure.
            $methodName = $helper->getPaymentMethodName($methodId, $storeId);
            if ($methodName !== '') {
                $payment->setAdditionalInformation('p24_method_name', $methodName);
            }
        }
        $statement = (string) ($transaction['statement'] ?? '');
        if ($statement !== '') {
            $payment->setAdditionalInformation('p24_statement', $statement);
        }
        $payment->save();

        // P24 charges (and returns) the amount in the order currency, but
        // registerCaptureNotification() expects a base-currency amount: it
        // compares against getBaseGrandTotal() in _isCaptureFinal() and stores
        // it as base_amount_paid_online. When base currency != order currency
        // (e.g. base EUR, order PLN) the order-currency amount never matches,
        // so the invoice is skipped and the order is wrongly flagged as fraud.
        // P24 always settles the full order, so register the full base total.
        $payment->registerCaptureNotification((float) $order->getBaseGrandTotal());
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

        return true;
    }

    /**
     * Capture payment — called by registerCaptureNotification() from webhook.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    #[\Override]
    public function capture(\Maho\DataObject $payment, $amount): self
    {
        $p24OrderId = $payment->getAdditionalInformation('p24_order_id');
        if ($p24OrderId) {
            $payment->setTransactionId((string) $p24OrderId);
            $payment->setIsTransactionClosed(true);
        }
        return $this;
    }

    /**
     * Refund a captured payment via P24 API.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    #[\Override]
    public function refund(\Maho\DataObject $payment, $amount): self
    {
        $helper = $this->_getP24Helper();
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();

        $sessionId = $payment->getAdditionalInformation('p24_session_id');
        $p24OrderId = (int) $payment->getAdditionalInformation('p24_order_id');

        if (!$sessionId || !$p24OrderId) {
            Mage::throwException($helper->__('Cannot refund: missing Przelewy24 transaction data.'));
        }

        $requestId = bin2hex(random_bytes(16));
        $refundsUuid = bin2hex(random_bytes(16));

        $data = [
            'requestId' => $requestId,
            'refundsUuid' => $refundsUuid,
            'refunds' => [
                [
                    'orderId' => $p24OrderId,
                    'sessionId' => $sessionId,
                    'amount' => $helper->toGrosze($amount),
                    'description' => $helper->__('Refund for order #%s', $order->getIncrementId()),
                ],
            ],
            'urlStatus' => Mage::getUrl('przelewy24/webhook/refund', ['_secure' => true]),
        ];

        $this->_getApi($storeId)->refundTransaction($data);

        $payment->setTransactionId($refundsUuid);
        $payment->setIsTransactionClosed(false);
        $payment->setAdditionalInformation('p24_refund_uuid', $refundsUuid);

        return $this;
    }

    /**
     * Fetch transaction info from P24 for the admin panel.
     */
    #[\Override]
    public function fetchTransactionInfo(\Mage_Payment_Model_Info $payment, $transactionId): array
    {
        $sessionId = $payment->getAdditionalInformation('p24_session_id');
        if (!$sessionId) {
            return [];
        }

        $storeId = (int) $payment->getOrder()->getStoreId();
        try {
            $result = $this->_getApi($storeId)->getTransactionBySessionId($sessionId);
            return $result['data'] ?? [];
        } catch (\Throwable $e) {
            Mage::logException($e);
            return [];
        }
    }

    protected function _getP24Helper(): Maho_Przelewy24_Helper_Data
    {
        /** @var Maho_Przelewy24_Helper_Data */
        return Mage::helper('maho_przelewy24');
    }

    protected function _getApi(int $storeId): Maho_Przelewy24_Model_Api
    {
        if ($this->_api === null) {
            /** @var Maho_Przelewy24_Model_Api $api */
            $api = Mage::getModel('maho_przelewy24/api', ['store_id' => $storeId]);
            $this->_api = $api;
        }
        return $this->_api;
    }
}
