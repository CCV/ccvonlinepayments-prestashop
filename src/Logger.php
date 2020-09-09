<?php
class CcvOnlinePaymentsPaymentPrestashopLogger implements \Psr\Log\LoggerInterface {

    const SEVERITY_DEBUG    = 1;
    const SEVERITY_INFO     = 2;
    const SEVERITY_WARNING  = 3;
    const SEVERITY_ERROR    = 4;

    public function emergency($message, array $context = array())
    {
        Logger::addLog($this->contextToMessage($message, $context), self::SEVERITY_ERROR);
    }

    public function alert($message, array $context = array())
    {
        Logger::addLog($this->contextToMessage($message, $context), self::SEVERITY_ERROR);
    }

    public function critical($message, array $context = array())
    {
        Logger::addLog($this->contextToMessage($message, $context), self::SEVERITY_ERROR);
    }

    public function error($message, array $context = array())
    {
        Logger::addLog($this->contextToMessage($message, $context), self::SEVERITY_ERROR);
    }

    public function warning($message, array $context = array())
    {
        Logger::addLog($this->contextToMessage($message, $context), self::SEVERITY_WARNING);
    }

    public function notice($message, array $context = array())
    {
        Logger::addLog($this->contextToMessage($message, $context), self::SEVERITY_INFO);
    }

    public function info($message, array $context = array())
    {
        Logger::addLog($this->contextToMessage($message, $context), self::SEVERITY_INFO);
    }

    public function debug($message, array $context = array())
    {
        Logger::addLog($this->contextToMessage($message, $context), self::SEVERITY_DEBUG);
    }

    public function log($level, $message, array $context = array())
    {

    }

    private function contextToMessage($message, $context) {
        foreach($context as $key => $value) {
            $message .= "\n$key: ".json_encode($value);
        }
        return $message;
    }
}
