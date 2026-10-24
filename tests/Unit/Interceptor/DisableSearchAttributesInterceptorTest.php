<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Interceptor;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\SearchAttributes\SearchAttributeKey;
use Temporal\Common\TypedSearchAttributes;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\Header;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Workflow\WorkflowExecution;
use Vanta\Integration\Symfony\Temporal\Interceptor\DisableSearchAttributesInterceptor;

#[CoversClass(DisableSearchAttributesInterceptor::class)]
final class DisableSearchAttributesInterceptorTest extends TestCase
{
    public function testStripsTypedSearchAttributes(): void
    {
        $options = WorkflowOptions::new()->withTypedSearchAttributes(
            TypedSearchAttributes::empty()->withValue(SearchAttributeKey::forKeyword('CustomerId'), 'c-1'),
        );

        $received = $this->start($options);

        assertNotNull($received->typedSearchAttributes);
        assertSame(0, $received->typedSearchAttributes->count());
        assertNull($received->searchAttributes);
    }

    public function testStripsUntypedSearchAttributes(): void
    {
        $received = $this->start(WorkflowOptions::new()->withSearchAttributes(['CustomerId' => 'c-1']));

        assertNull($received->searchAttributes);
        assertNull($received->typedSearchAttributes);
    }

    public function testKeepsOptionsWithoutSearchAttributes(): void
    {
        $received = $this->start(WorkflowOptions::new()->withTaskQueue('queue'));

        assertSame('queue', $received->taskQueue);
        assertNull($received->searchAttributes);
        assertNull($received->typedSearchAttributes);
    }

    public function testSignalWithStartStripsTypedSearchAttributes(): void
    {
        $options = WorkflowOptions::new()->withTypedSearchAttributes(
            TypedSearchAttributes::empty()->withValue(SearchAttributeKey::forKeyword('CustomerId'), 'c-1'),
        );

        $received = null;

        (new DisableSearchAttributesInterceptor())->signalWithStart(
            new SignalWithStartInput(self::startInput($options), 'signal', EncodedValues::empty()),
            static function (SignalWithStartInput $input) use (&$received): WorkflowExecution {
                $received = $input->workflowStartInput->options;

                return new WorkflowExecution('id', 'run');
            },
        );

        assertNotNull($received);
        assertSame(0, $received->typedSearchAttributes?->count());
    }

    private function start(WorkflowOptions $options): WorkflowOptions
    {
        $received = null;

        (new DisableSearchAttributesInterceptor())->start(
            self::startInput($options),
            static function (StartInput $input) use (&$received): WorkflowExecution {
                $received = $input->options;

                return new WorkflowExecution('id', 'run');
            },
        );

        assertNotNull($received);

        return $received;
    }

    private static function startInput(WorkflowOptions $options): StartInput
    {
        return new StartInput('id', 'Workflow', Header::empty(), EncodedValues::empty(), $options);
    }
}
