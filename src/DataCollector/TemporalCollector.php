<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DataCollector;

use Symfony\Bundle\FrameworkBundle\DataCollector\TemplateAwareDataCollectorInterface as TemplateAwareDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class TemporalCollector implements TemplateAwareDataCollector
{
    /**
     * @param array<non-empty-string, array<non-empty-string, non-empty-string>>              $workers
     * @param list<array<non-empty-string, non-empty-string>>                                 $clients
     * @param list<array<non-empty-string, array{0: array{workers: list<non-empty-string>}}>> $workflows
     * @param list<array<non-empty-string, array{0: array{workers: list<non-empty-string>}}>> $activities
     * @param list<array<non-empty-string, non-empty-string>>                                 $scheduleClients
     */
    public function __construct(
        public array $workers,
        public array $clients,
        public array $workflows,
        public array $activities,
        public array $scheduleClients,
    ) {
    }


    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        return;
    }

    /**
     * @return non-empty-string
     */
    public function getName(): string
    {
        return 'Temporal';
    }

    public function reset(): void
    {
        // TODO: Implement reset() method.
    }

    public static function getTemplate(): string
    {
        return '@Temporal/data_collector/layout.html.twig';
    }
}
