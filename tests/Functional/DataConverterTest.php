<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\DataConverter\Bytes;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodingKeys;
use Temporal\DataConverter\Type;
use Vanta\Integration\Symfony\Temporal\DataConverter\DataConverterFactory;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\TemporalExtension;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[RunTestsInSeparateProcesses]
#[CoversClass(TemporalExtension::class)]
#[CoversClass(DataConverterFactory::class)]
final class DataConverterTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, string> $options
     */
    protected static function createKernel(array $options = []): Kernel
    {
        /**
         * @var TestKernel $kernel
         */
        $kernel = parent::createKernel($options);
        $kernel->addTestBundle(TemporalBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }


    public function testDefaultConverterIsUnchanged(): void
    {
        $converter = $this->dataConverter('temporal_without_pool.yaml');

        assertSame(EncodingKeys::METADATA_ENCODING_JSON, $this->encoding($converter, new Bytes('data')));
        assertSame(EncodingKeys::METADATA_ENCODING_JSON, $this->encoding($converter, null));
    }


    public function testSdkDefaultConverters(): void
    {
        $converter = $this->dataConverter('temporal_with_sdk_converters.yaml');

        assertSame(EncodingKeys::METADATA_ENCODING_NULL, $this->encoding($converter, null));
        assertSame(EncodingKeys::METADATA_ENCODING_RAW, $this->encoding($converter, new Bytes('data')));
        assertSame(EncodingKeys::METADATA_ENCODING_PROTOBUF_JSON, $this->encoding($converter, new WorkflowExecution(['workflow_id' => 'id'])));
        assertSame(EncodingKeys::METADATA_ENCODING_JSON, $this->encoding($converter, ['key' => 'value']));

        // temporal/sdk 2.20 types $type with a Psalm-only alias (TType)
        $bytes = $converter->fromPayload($converter->toPayload(new Bytes('data')), Type::create(Bytes::class)); // @phpstan-ignore argument.type

        assertInstanceOf(Bytes::class, $bytes);
        assertSame('data', $bytes->getData());

        $execution = $converter->fromPayload($converter->toPayload(new WorkflowExecution(['workflow_id' => 'id'])), Type::create(WorkflowExecution::class)); // @phpstan-ignore argument.type

        assertInstanceOf(WorkflowExecution::class, $execution);
        assertSame('id', $execution->getWorkflowId());
    }


    public function testPayloadConverterReplacesConverterWithSameEncoding(): void
    {
        $converter = $this->dataConverter('temporal_with_payload_converters.yaml');

        $payload = $converter->toPayload('value');

        assertSame('"custom"', $payload->getData());
        assertSame('custom', $converter->fromPayload($payload, Type::create(Type::TYPE_STRING))); // @phpstan-ignore argument.type
    }


    /**
     * @param non-empty-string $configFile
     */
    private function dataConverter(string $configFile): DataConverterInterface
    {
        $kernel = self::bootKernel(['config' => static function (TestKernel $kernel) use ($configFile): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/' . $configFile);
            $kernel->addTestCompilerPass(new class() implements CompilerPass {
                public function process(ContainerBuilder $container): void
                {
                    $container->getDefinition('temporal.data_converter')->setPublic(true);
                }
            });
        }]);

        $converter = $kernel->getContainer()->get('temporal.data_converter');

        assertInstanceOf(DataConverterInterface::class, $converter);

        return $converter;
    }


    private function encoding(DataConverterInterface $converter, mixed $value): string
    {
        /** @var string $encoding */
        $encoding = $converter->toPayload($value)->getMetadata()[EncodingKeys::METADATA_ENCODING_KEY];

        return $encoding;
    }
}
