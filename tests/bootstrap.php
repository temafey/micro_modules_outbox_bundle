<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for outbox-bundle's own test suite.
 *
 * Loads optional dependency stubs first (so they can be conditionally defined
 * before Composer autoload resolves classes), then registers the Composer autoloader.
 */

// 1. Load optional-dependency stubs (Enqueue, Saga) when packages are absent.
require_once __DIR__ . '/bootstrap-stubs.php';

// 2. Register Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';
