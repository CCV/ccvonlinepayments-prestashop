<?php
class CcvOnlinePaymentsPaymentPrestashopCache extends \CCVOnlinePayments\Lib\Cache {

    private $cache;

    public function __construct()
    {
        $this->cache = Cache::getInstance();
    }

    public function set(string $key, string $value, int $timeout): void
    {
        $this->cache->set($key, $value, $timeout);
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key);
        if($value === false) {
            return null;
        }else{
            return $value;
        }
    }

}
