<?php

declare(strict_types=1);

function testAssert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'PASS: ', $label, PHP_EOL;
}

function testSame(mixed $expected, mixed $actual, string $label): void
{
    testAssert($expected === $actual, $label . ($expected === $actual ? '' : ' (resultado inesperado)'));
}

function testThrows(callable $action, string $class, string $label, ?string $message = null): Throwable
{
    try {
        $action();
    } catch (Throwable $exception) {
        testAssert($exception instanceof $class && ($message === null || str_contains($exception->getMessage(), $message)), $label);
        return $exception;
    }
    throw new RuntimeException('FAIL: ' . $label . ' (nenhuma exceção)');
}
