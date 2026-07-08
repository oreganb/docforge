<?php

namespace DocForge\Core;

class Logger
{
    /** @var string */
    private $logDir;

    public function __construct($logDir)
    {
        $this->logDir = rtrim($logDir, '/');
    }

    public function info($message, array $context = array())
    {
        $this->write('INFO', $message, $context);
    }

    public function error($message, array $context = array())
    {
        $this->write('ERROR', $message, $context);
    }

    private function write($level, $message, array $context)
    {
        $line = date('c') . " [$level] $message";
        if (!empty($context)) {
            $line .= ' ' . json_encode($context);
        }
        $line .= "\n";
        file_put_contents($this->logDir . '/docforge.log', $line, FILE_APPEND | LOCK_EX);
    }
}
