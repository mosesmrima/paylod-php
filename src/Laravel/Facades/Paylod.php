<?php

declare(strict_types=1);

namespace Paylod\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * `Paylod` facade - a static front for the singleton {@see \Paylod\Paylod} client.
 *
 * ```php
 * use Paylod\Laravel\Facades\Paylod;
 *
 * $outcome = Paylod::collectAndWait(['amount' => 100, 'phone' => '0712345678']);
 * ```
 *
 * @method static array collect(array $params)
 * @method static \Paylod\PaymentOutcome collectAndWait(array $params, array $options = [])
 * @method static array status(string $paymentId)
 * @method static \Paylod\PaymentOutcome check(string $paymentId)
 * @method static \Paylod\PaymentOutcome wait(string $paymentId, array $options = [])
 * @method static array decodeError(int|string|null $resultCode, ?string $rawDesc = null)
 * @method static bool verifyWebhook(string $rawBody, ?string $signatureHeader, ?string $secret = null, int $toleranceSec = 300)
 * @method static array parseWebhook(string $rawBody, ?string $signatureHeader, ?string $secret = null, int $toleranceSec = 300)
 *
 * @see \Paylod\Paylod
 */
final class Paylod extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Paylod\Paylod::class;
    }
}
