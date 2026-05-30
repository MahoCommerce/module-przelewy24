<?php

/**
 * Maho
 *
 * @package    Maho_Przelewy24
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Przelewy24_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const API_URL_LIVE = 'https://secure.przelewy24.pl';
    public const API_URL_SANDBOX = 'https://sandbox.przelewy24.pl';

    protected $_moduleName = 'Maho_Przelewy24';

    public function getMerchantId(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig('maho_przelewy24/credentials/merchant_id', $storeId);
    }

    public function getPosId(?int $storeId = null): int
    {
        $posId = (int) Mage::getStoreConfig('maho_przelewy24/credentials/pos_id', $storeId);
        return $posId ?: $this->getMerchantId($storeId);
    }

    public function getCrcKey(?int $storeId = null): string
    {
        return (string) Mage::getStoreConfig('maho_przelewy24/credentials/crc_key', $storeId);
    }

    public function getApiKey(?int $storeId = null): string
    {
        return (string) Mage::getStoreConfig('maho_przelewy24/credentials/api_key', $storeId);
    }

    public function isSandbox(?int $storeId = null): bool
    {
        return Mage::getStoreConfigFlag('maho_przelewy24/credentials/sandbox', $storeId);
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        return $this->isSandbox($storeId) ? self::API_URL_SANDBOX : self::API_URL_LIVE;
    }

    public function hasCredentials(?int $storeId = null): bool
    {
        return $this->getMerchantId($storeId) > 0
            && $this->getCrcKey($storeId) !== ''
            && $this->getApiKey($storeId) !== '';
    }

    /**
     * Convert decimal amount to grosze (Polish cents).
     */
    public function toGrosze(float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Compute SHA384 HMAC signature used by P24 API v1.
     *
     * The signature is computed by JSON-encoding the data array with the CRC key
     * appended under the 'crc' key, then hashing with SHA384.
     *
     * @param array<string, mixed> $data Fields to sign (order matters for consistency but JSON handles it)
     */
    public function sign(array $data, string $crcKey): string
    {
        $data['crc'] = $crcKey;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }
        return hash('sha384', $json);
    }

    /**
     * Verify a signature received from Przelewy24.
     *
     * @param array<string, mixed> $data Fields that were signed
     */
    public function verifySign(array $data, string $receivedSign, string $crcKey): bool
    {
        return hash_equals($this->sign($data, $crcKey), $receivedSign);
    }

    /**
     * Get the P24-compatible language code from the current store locale.
     *
     * P24 supports: bg, cs, de, en, es, fr, hr, hu, it, nl, pl, pt, se, sk, ro.
     * Falls back to 'en' if the store locale is not supported.
     */
    public function getLanguage(): string
    {
        $supported = ['bg', 'cs', 'de', 'en', 'es', 'fr', 'hr', 'hu', 'it', 'nl', 'pl', 'pt', 'se', 'sk', 'ro'];
        $lang = substr(Mage::app()->getLocale()->getLocaleCode(), 0, 2);
        return in_array($lang, $supported, true) ? $lang : 'en';
    }

    /**
     * Generate a unique session ID for a transaction.
     */
    public function generateSessionId(string $orderIncrementId): string
    {
        return $orderIncrementId . '_' . bin2hex(random_bytes(4));
    }

    /**
     * Resolve the human-readable name of a P24 payment method from its numeric id.
     *
     * P24 reports only a numeric method id on a transaction; the names come from a
     * separate endpoint. That list rarely changes, so it's cached per store/language
     * for a day. Returns '' when the id can't be resolved (unknown id, API error) —
     * callers fall back to showing the raw id and must never let this break a capture.
     */
    public function getPaymentMethodName(int $methodId, ?int $storeId = null): string
    {
        if ($methodId <= 0) {
            return '';
        }

        $lang = $this->getLanguage();
        $cacheId = "przelewy24_payment_methods_{$storeId}_{$lang}";

        $methods = null;
        $cached = Mage::app()->loadCache($cacheId);
        if (is_string($cached) && $cached !== '') {
            $methods = json_decode($cached, true);
        }

        if (!is_array($methods)) {
            try {
                /** @var Maho_Przelewy24_Model_Api $api */
                $api = Mage::getModel('maho_przelewy24/api', ['store_id' => (int) $storeId]);
                $methods = $api->getPaymentMethods($lang);
                Mage::app()->saveCache(
                    (string) json_encode($methods),
                    $cacheId,
                    [Mage_Core_Model_Config::CACHE_TAG],
                    86400,
                );
            } catch (\Throwable $e) {
                Mage::logException($e);
                return '';
            }
        }

        foreach ($methods as $method) {
            if ((int) ($method['id'] ?? 0) === $methodId) {
                return (string) ($method['name'] ?? '');
            }
        }

        return '';
    }

    /**
     * Status code applied while the customer is at the P24 checkout.
     * Falls back to 'pending_payment' if the config is missing.
     */
    public function getPendingStatus(?int $storeId = null): string
    {
        $status = (string) Mage::getStoreConfig('payment/przelewy24/order_status_pending', $storeId);
        return $status !== '' ? $status : 'pending_payment';
    }

    /**
     * Status code applied after P24 confirms the payment.
     * Falls back to 'processing' if the config is missing.
     */
    public function getProcessingStatus(?int $storeId = null): string
    {
        $status = (string) Mage::getStoreConfig('payment/przelewy24/order_status_processing', $storeId);
        return $status !== '' ? $status : 'processing';
    }
}
