<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Migration;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Temporal\Client\GRPC\ContextInterface as Context;
use Temporal\Client\GRPC\OperatorClientInterface as OperatorClient;
use Temporal\Client\GRPC\ServiceClientInterface as ServiceClient;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;

/** @internal */
final class MigrationRpcContext
{
    private readonly DateTimeImmutable $deadline;
    private readonly float $monotonicDeadline;
    private readonly Closure $clock;
    private readonly Closure $sleep;
    private readonly Context $serviceContext;
    private readonly Context $operatorContext;
    /** @var array<string, true> */
    private array $ensuredNamespaces;

    /**
     * @param array<string, true> $ensuredNamespaces
     * @param Closure(): float|null $clock
     * @param Closure(float): void|null $sleep
     */
    public function __construct(
        private readonly ServiceClient $service,
        private readonly OperatorClient $operator,
        int $timeout = 60,
        private readonly Logger $logger = new NullLogger(),
        ?Closure $clock = null,
        ?Closure $sleep = null,
        array $ensuredNamespaces = [],
    ) {
        if ($timeout < 1) {
            throw MigrationException::invalidTimeout();
        }

        $this->ensuredNamespaces = $ensuredNamespaces;
        $this->clock             = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep             = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1e6));
        };
        $this->deadline          = (new DateTimeImmutable())->modify('+' . $timeout . ' seconds');
        $this->monotonicDeadline = ($this->clock)() + $timeout;
        $this->serviceContext    = $service->getContext();
        $this->operatorContext   = $operator->getContext();
    }

    public function operatorClient(): OperatorClient
    {
        return $this->operator->withContext($this->context());
    }

    public function serviceClient(): ServiceClient
    {
        return $this->service->withContext($this->context(true));
    }

    public function context(bool $service = false): Context
    {
        $this->assertRemaining();
        $context            = $service ? $this->serviceContext : $this->operatorContext;
        $configuredDeadline = $context->getDeadline();
        $deadline           = $configuredDeadline !== null && $configuredDeadline < $this->deadline ? $configuredDeadline : $this->deadline;

        return $context->withDeadline($deadline);
    }

    /** @param Closure(): bool $condition */
    public function observe(Closure $condition, string $description, int $attempts, float $interval): void
    {
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $this->assertRemaining();
            $this->logger->debug('Observing Temporal management state', [
                'target'           => $description,
                'attempt'          => $attempt,
                'remainingSeconds' => max(0.0, $this->monotonicDeadline - ($this->clock)()),
            ]);

            if ($condition()) {
                $this->assertRemaining();

                return;
            }

            if ($attempt < $attempts) {
                ($this->sleep)(min($interval, max(0.0, $this->monotonicDeadline - ($this->clock)())));
            }
        }

        $this->logger->error('Temporal state verification exhausted', ['target' => $description]);
        throw MigrationException::verificationFailed($description, $attempts);
    }

    public function markEnsured(string $namespace): void
    {
        $this->ensuredNamespaces[$namespace] = true;
    }

    public function isEnsured(string $namespace): bool
    {
        return isset($this->ensuredNamespaces[$namespace]);
    }

    /** @return array<string, true> */
    public function ensuredNamespaces(): array
    {
        return $this->ensuredNamespaces;
    }

    private function assertRemaining(): void
    {
        if (($this->clock)() >= $this->monotonicDeadline) {
            throw MigrationException::deadlineExceeded();
        }
    }
}
