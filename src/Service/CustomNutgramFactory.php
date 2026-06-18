<?php

namespace App\Service;

use SergiX44\NutgramBundle\DependencyInjection\Factory\NutgramFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;

class CustomNutgramFactory extends NutgramFactory
{
    public function __construct(
        private string $telegramProxy
    ) {}

    public function createNutgram(
        array $config,
        ContainerInterface $container,
        RequestStack $requestStack,
        KernelInterface $kernel,
        ?CacheItemPoolInterface $nutgramCache,
        ?LoggerInterface $nutgramLogger,
        ?LoggerInterface $nutgramConsoleLogger
    ): Nutgram {
        // Intercept and modify config array before creation
        $config['config']['clientTimeout'] = 30;
        
        if (!empty($this->telegramProxy)) {
            if (!isset($config['config']['clientOptions'])) {
                $config['config']['clientOptions'] = [];
            }
            $config['config']['clientOptions']['proxy'] = $this->telegramProxy;
        }

        return parent::createNutgram(
            $config,
            $container,
            $requestStack,
            $kernel,
            $nutgramCache,
            $nutgramLogger,
            $nutgramConsoleLogger
        );
    }
}
