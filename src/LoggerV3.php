<?php
class CcvOnlinePaymentsPaymentPrestashopLoggerV3 implements \Psr\Log\LoggerInterface {

    const SEVERITY_DEBUG    = 1;
    const SEVERITY_INFO     = 2;
    const SEVERITY_WARNING  = 3;
    const SEVERITY_ERROR    = 4;

    public function emergency(\Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), self::SEVERITY_ERROR, null, null, null, true);
    }

    public function alert(\Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), self::SEVERITY_ERROR, null, null, null, true);
    }

    public function critical(\Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), self::SEVERITY_ERROR, null, null, null, true);
    }

    public function error(\Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), self::SEVERITY_ERROR, null, null, null, true);
    }

    public function warning(\Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), self::SEVERITY_WARNING, null, null, null, true);
    }

    public function notice(\Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), self::SEVERITY_INFO, null, null, null, true);
    }

    public function info(\Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), self::SEVERITY_INFO, null, null, null, true);
    }

    public function debug(\Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), self::SEVERITY_DEBUG, null, null, null, true);
    }

    public function log($level, \Stringable|string $message, array $context = array()) : void
    {
        PrestaShopLogger::addLog($this->contextToMessage($message, $context), $level, null, null, null, true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function contextToMessage(string $message, array $context) : string{
        foreach($context as $key => $value) {
            $message .= "\n$key: ".json_encode($value);
        }
        return $message;
    }
}
