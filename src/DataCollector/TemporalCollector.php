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

final readonly class TemporalCollector implements TemplateAwareDataCollector
{
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // TODO: Implement collect() method.
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

    public static function getTemplate(): ?string
    {
        return '@Temporal/data_collector/layout.html.twig';
    }
}