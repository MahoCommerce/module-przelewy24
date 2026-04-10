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

    #[\Override]
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
}
