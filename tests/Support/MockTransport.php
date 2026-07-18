<?php

declare(strict_types=1);

namespace Paylod\Tests\Support;

use Paylod\Exceptions\PaylodConnectionError;
use Paylod\Http\Transport;

/**
 * A mocked transport that replays canned steps in order and records every call. No network.
 *
 * Each step is an array:
 *   ['status' => int, 'json' => mixed, 'headers' => array, 'throw' => bool]
 * A `throw` step simulates a transport-layer connection failure.
 */
final class MockTransport implements Transport
{
    /** @var list<array{url:string,method:string,headers:array<string,string>,body:mixed,timeoutMs:int}> */
    public array $calls = [];

    private int $i = 0;

    /**
     * @param list<array<string,mixed>> $steps
     */
    public function __construct(private array $steps = [])
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $lowered = [];
        foreach ($headers as $k => $v) {
            $lowered[strtolower($k)] = $v;
        }
        $this->calls[] = [
            'url' => $url,
            'method' => $method,
            'headers' => $lowered,
            'body' => $body === null ? null : json_decode($body, true),
            // Recorded so a test can prove the operation deadline actually caps each in-flight request.
            'timeoutMs' => $timeoutMs,
        ];

        $step = $this->steps[min($this->i, count($this->steps) - 1)] ?? null;
        $this->i++;
        if ($step === null) {
            throw new \RuntimeException('MockTransport: no step configured');
        }
        if (($step['throw'] ?? false) === true) {
            throw new PaylodConnectionError('simulated network failure');
        }

        return [
            'status' => (int) ($step['status'] ?? 200),
            'headers' => $step['headers'] ?? [],
            'body' => json_encode($step['json'] ?? new \stdClass()),
        ];
    }

    public function count(): int
    {
        return $this->i;
    }
}
