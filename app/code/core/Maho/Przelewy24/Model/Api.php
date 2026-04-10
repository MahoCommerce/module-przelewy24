<?php

/**
 * Maho
 *
 * @package    Maho_Przelewy24
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Przelewy24_Model_Api
{
    protected ?int $_storeId = null;

    public function __construct(array $args = [])
    {
        if (isset($args['store_id'])) {
            $this->_storeId = (int) $args['store_id'];
        }
    }

    public function setStoreId(int $storeId): self
    {
        $this->_storeId = $storeId;
        return $this;
    }

    protected function _getHelper(): Maho_Przelewy24_Helper_Data
    {
        return Mage::helper('maho_przelewy24');
    }

    /**
     * Test API credentials.
     *
     * @throws Mage_Core_Exception
     */
    public function testAccess(): bool
    {
        $response = $this->_request('GET', '/api/v1/testAccess');
        return ($response['data'] ?? false) === true;
    }

    /**
     * Register a new transaction with Przelewy24.
     *
     * @return array{token: string} Response data containing the transaction token
     * @throws Mage_Core_Exception
     */
    public function registerTransaction(array $data): array
    {
        $helper = $this->_getHelper();

        $signData = [
            'sessionId' => $data['sessionId'],
            'merchantId' => $data['merchantId'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
        ];
        $data['sign'] = $helper->sign($signData, $helper->getCrcKey($this->_storeId));

        $response = $this->_request('POST', '/api/v1/transaction/register', $data);

        if (!isset($response['data']['token'])) {
            Mage::throwException(
                $helper->__('Przelewy24: Failed to register transaction. Response: %s', json_encode($response)),
            );
        }

        return $response['data'];
    }

    /**
     * Verify a completed transaction.
     *
     * @throws Mage_Core_Exception
     */
    public function verifyTransaction(array $data): array
    {
        $helper = $this->_getHelper();

        $signData = [
            'sessionId' => $data['sessionId'],
            'orderId' => $data['orderId'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
        ];
        $data['sign'] = $helper->sign($signData, $helper->getCrcKey($this->_storeId));

        return $this->_request('PUT', '/api/v1/transaction/verify', $data);
    }

    /**
     * Request a refund.
     *
     * @throws Mage_Core_Exception
     */
    public function refundTransaction(array $data): array
    {
        return $this->_request('POST', '/api/v1/transaction/refund', $data);
    }

    /**
     * Get transaction status by session ID.
     *
     * @throws Mage_Core_Exception
     */
    public function getTransactionBySessionId(string $sessionId): array
    {
        return $this->_request('GET', '/api/v1/transaction/by/sessionId/' . urlencode($sessionId));
    }

    /**
     * Perform an HTTP request to the Przelewy24 API.
     *
     * @throws Mage_Core_Exception
     */
    protected function _request(string $method, string $endpoint, array $body = []): array
    {
        $helper = $this->_getHelper();
        $baseUrl = $helper->getApiBaseUrl($this->_storeId);
        $url = $baseUrl . $endpoint;

        $posId = (string) $helper->getPosId($this->_storeId);
        $apiKey = $helper->getApiKey($this->_storeId);

        $options = [
            'timeout' => 30,
            'auth_basic' => [$posId, $apiKey],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if ($body && $method !== 'GET') {
            $options['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $client = \Symfony\Component\HttpClient\HttpClient::create();
            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $result = Mage::helper('core')->jsonDecode($content);

            if ($statusCode >= 400) {
                $errorMsg = $result['error'] ?? $result['message'] ?? "HTTP {$statusCode}";
                Mage::log(
                    "Przelewy24 API error: {$method} {$endpoint} -> {$statusCode}: {$content}",
                    Mage::LOG_ERROR,
                    'przelewy24.log',
                );
                Mage::throwException($helper->__('Przelewy24 API error: %s', $errorMsg));
            }

            return $result;
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            Mage::log(
                "Przelewy24 API transport error: {$method} {$endpoint} -> {$e->getMessage()}",
                Mage::LOG_ERROR,
                'przelewy24.log',
            );
            Mage::throwException($helper->__('Przelewy24 connection error: %s', $e->getMessage()));
        }
    }
}
