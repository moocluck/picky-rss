<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        if ($cacheDir = $_SERVER['APP_CACHE_DIR'] ?? $_ENV['APP_CACHE_DIR'] ?? getenv('APP_CACHE_DIR')) {
            return $cacheDir . '/' . $this->getEnvironment();
        }

        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if ($logDir = $_SERVER['APP_LOG_DIR'] ?? $_ENV['APP_LOG_DIR'] ?? getenv('APP_LOG_DIR')) {
            return $logDir;
        }

        return parent::getLogDir();
    }
}
