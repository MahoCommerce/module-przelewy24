<?php

/**
 * Maho
 *
 * @package    Maho_Przelewy24
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Przelewy24_Model_Method_Standard extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'przelewy24';

    protected $_formBlockType = 'maho_przelewy24/form';
    protected $_infoBlockType = 'maho_przelewy24/info';

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
     * Set order to pending_payment on placement. The webhook will move it to processing.
     */
    /**
     * @param \Maho\DataObject $stateObject
     */
    #[\Override]
    public function initialize($paymentAction, $stateObject): self
    {
        $stateObject->setData('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', 'pending_payment');
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
        $amount = $helper->toGrosze($order->getBaseGrandTotal());
        $currency = $order->getBaseCurrencyCode();

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
