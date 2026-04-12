<?php

declare(strict_types=1);

/**
 * Minimal Enqueue and Saga stubs for use in the outbox-bundle's own test suite
 * when the full micro-module/enqueue and micro-module/saga packages are absent.
 *
 * Loaded via phpunit.dist.xml bootstrap (before autoloader), so the stubs are
 * defined unconditionally — PHPUnit will not re-include this file twice.
 */

// phpcs:disable

namespace Enqueue\Rpc {
    if (! class_exists(\Enqueue\Rpc\Promise::class)) {
        class Promise
        {
        }
    }
}

namespace Enqueue\Client {
    if (! interface_exists(\Enqueue\Client\ProducerInterface::class)) {
        interface ProducerInterface
        {
            public function sendCommand(string $command, $message, bool $needReply = false): ?\Enqueue\Rpc\Promise;
            public function sendEvent(string $topic, $message): void;
        }
    }
}

namespace MicroModule\Saga\Application {
    if (! interface_exists(\MicroModule\Saga\Application\SagaCommandQueueInterface::class)) {
        interface SagaCommandQueueInterface
        {
            /** @param class-string $commandClass */
            public function enqueue(string $commandClass, array $payload): void;
        }
    }
}

// phpcs:enable
