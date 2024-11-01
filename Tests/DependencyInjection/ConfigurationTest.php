<?php

declare(strict_types=1);

namespace JMS\SerializerBundle\Tests\DependencyInjection;

use Doctrine\Common\Annotations\AnnotationReader;
use JMS\SerializerBundle\DependencyInjection\Configuration;
use JMS\SerializerBundle\JMSSerializerBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ConfigurationTest extends TestCase
{
    private function getContainer(array $configs = [])
    {
        $bundles = ['JMSSerializerBundle' => 'JMS\SerializerBundle\JMSSerializerBundle'];
        $container = new ContainerBuilder();

        if (class_exists(AnnotationReader::class)) {
            $container->set('annotation_reader', new AnnotationReader());
        }

        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir() . '/serializer');
        $container->setParameter('kernel.bundles', $bundles);
        $container->setParameter('kernel.bundles_metadata', array_map(static function (string $class): array {
            return [
                'path' => (new $class())->getPath(),
                'namespace' => (new \ReflectionClass($class))->getNamespaceName(),
            ];
        }, $bundles));

        $bundle = new JMSSerializerBundle();

        $extension = $bundle->getContainerExtension();
        $extension->load($configs, $container);

        return $container;
    }

    public function testConfig()
    {
        $ref = new JMSSerializerBundle();
        $container = $this->getContainer([
            [
                'metadata' => [
                    'directories' => [
                        'foo' => [
                            'namespace_prefix' => 'JMSSerializerBundleNs1',
                            'path' => '@JMSSerializerBundle',
                        ],
                        'bar' => [
                            'namespace_prefix' => 'JMSSerializerBundleNs2',
                            'path' => '@JMSSerializerBundle/Resources/config',
                        ],
                    ],
                ],
            ],
        ]);

        $directories = $container->findDefinition('jms_serializer.metadata.file_locator')->getArgument(0);

        $this->assertEquals($ref->getPath(), $directories['JMSSerializerBundleNs1']);
        $this->assertEquals($ref->getPath() . '/Resources/config', $directories['JMSSerializerBundleNs2']);
    }

    public function testCacheServicesRemovedWhenMetadataCachingIsDisabled(): void
    {
        $container = $this->getContainer([
            [
                'metadata' => ['cache' => 'none'],
            ],
        ]);

        self::assertFalse($container->has('jms_serializer.metadata.cache'));
        self::assertFalse($container->has('jms_serializer.cache.cache_clearer'));
    }

    public function testWrongObjectConstructorFallbackStrategyTriggersException()
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(true), [
            'jms_serializer' => [
                'object_constructors' => [
                    'doctrine' => ['fallback_strategy' => 'foo'],
                ],
            ],
        ]);
    }

    public function testConfigComposed()
    {
        $ref = new JMSSerializerBundle();
        $container = $this->getContainer([
            [
                'metadata' => [
                    'directories' => [
                        'foo' => [
                            'namespace_prefix' => 'JMSSerializerBundleNs1',
                            'path' => '@JMSSerializerBundle',
                        ],
                    ],
                ],
            ],
            [
                'metadata' => [
                    'directories' => [
                        [
                            'name' => 'foo',
                            'namespace_prefix' => 'JMSSerializerBundleNs2',
                            'path' => '@JMSSerializerBundle/Resources/config',
                        ],
                    ],
                ],
            ],
        ]);

        $directories = $container->findDefinition('jms_serializer.metadata.file_locator')->getArgument(0);

        $this->assertArrayNotHasKey('JMSSerializerBundleNs1', $directories);
        $this->assertEquals($ref->getPath() . '/Resources/config', $directories['JMSSerializerBundleNs2']);
    }

    public function testDebugWithoutCache()
    {
        $container = $this->getContainer([
            [
                'metadata' => [
                    'cache' => 'none',
                    'directories' => [
                        'foo' => [
                            'namespace_prefix' => 'JMSSerializerBundleNs1',
                            'path' => '@JMSSerializerBundle',
                        ],
                    ],
                ],
                'profiler' => true,
            ],
        ]);
        $container->compile();

        $this->assertNotEmpty($container->get('jms_serializer'));
    }

    public function testContextDefaults()
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), []);

        $this->assertArrayHasKey('default_context', $config);
        foreach (['serialization', 'deserialization'] as $item) {
            $this->assertArrayHasKey($item, $config['default_context']);

            $defaultContext = $config['default_context'][$item];

            $this->assertTrue(is_array($defaultContext['attributes']));
            $this->assertEmpty($defaultContext['attributes']);

            $this->assertTrue(is_array($defaultContext['groups']));
            $this->assertEmpty($defaultContext['groups']);

            $this->assertArrayNotHasKey('version', $defaultContext);
            $this->assertArrayNotHasKey('serialize_null', $defaultContext);
        }
    }

    public function testContextValues()
    {
        $configArray = [
            'serialization' => [
                'version' => 3,
                'serialize_null' => true,
                'attributes' => ['foo' => 'bar'],
                'groups' => ['Baz'],
                'enable_max_depth_checks' => false,
            ],
            'deserialization' => [
                'version' => '5.5',
                'serialize_null' => false,
                'attributes' => ['foo' => 'bar'],
                'groups' => ['Baz'],
                'enable_max_depth_checks' => true,
            ],
        ];

        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), [
            'jms_serializer' => ['default_context' => $configArray],
        ]);

        $this->assertArrayHasKey('default_context', $config);
        foreach (['serialization', 'deserialization'] as $configKey) {
            $this->assertArrayHasKey($configKey, $config['default_context']);

            $values = $config['default_context'][$configKey];
            $confArray = $configArray[$configKey];

            $this->assertSame($values['version'], $confArray['version']);
            $this->assertSame($values['serialize_null'], $confArray['serialize_null']);
            $this->assertSame($values['attributes'], $confArray['attributes']);
            $this->assertSame($values['groups'], $confArray['groups']);
            $this->assertSame($values['enable_max_depth_checks'], $confArray['enable_max_depth_checks']);
        }
    }

    public function testConfigNormalization()
    {
        $configArray = [
            'default_context' => [
                'serialization' => 'the.serialization.factory.context',
                'deserialization' => 'the.deserialization.factory.context',
            ],
            'property_naming' => 'property.mapping.service',
            'expression_evaluator' => 'expression_evaluator.service',
        ];

        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), ['jms_serializer' => $configArray]);

        $this->assertArrayHasKey('default_context', $config);
        $this->assertArrayHasKey('serialization', $config['default_context']);
        $this->assertArrayHasKey('deserialization', $config['default_context']);
        $this->assertArrayHasKey('id', $config['default_context']['serialization']);
        $this->assertArrayHasKey('id', $config['default_context']['deserialization']);

        $this->assertSame($configArray['default_context']['serialization'], $config['default_context']['serialization']['id']);
        $this->assertSame($configArray['default_context']['deserialization'], $config['default_context']['deserialization']['id']);

        $this->assertArrayHasKey('property_naming', $config);
        $this->assertArrayHasKey('expression_evaluator', $config);
        $this->assertArrayHasKey('id', $config['property_naming']);
        $this->assertArrayHasKey('id', $config['expression_evaluator']);
        $this->assertSame($configArray['property_naming'], $config['property_naming']['id']);
        $this->assertSame($configArray['expression_evaluator'], $config['expression_evaluator']['id']);
    }

    public function testContextNullValues()
    {
        $configArray = [
            'serialization' => [
                'version' => null,
                'serialize_null' => null,
                'attributes' => null,
                'groups' => null,
            ],
            'deserialization' => [
                'version' => null,
                'serialize_null' => null,
                'attributes' => null,
                'groups' => null,
            ],
        ];

        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), [
            'jms_serializer' => ['default_context' => $configArray],
        ]);

        $this->assertArrayHasKey('default_context', $config);
        foreach (['serialization', 'deserialization'] as $configKey) {
            $this->assertArrayHasKey($configKey, $config['default_context']);

            $defaultContext = $config['default_context'][$configKey];

            $this->assertTrue(is_array($defaultContext['attributes']));
            $this->assertEmpty($defaultContext['attributes']);

            $this->assertTrue(is_array($defaultContext['groups']));
            $this->assertEmpty($defaultContext['groups']);

            $this->assertArrayNotHasKey('version', $defaultContext);
            $this->assertArrayNotHasKey('serialize_null', $defaultContext);
        }
    }

    public function testDefaultDateFormat()
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), []);

        $this->assertEquals(\DateTime::ATOM, $config['handlers']['datetime']['default_format']);
    }

    public function testDefaultDateDeserializationFormats()
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), []);

        $this->assertEquals([], $config['handlers']['datetime']['default_deserialization_formats']);
    }

    public function testDefaultUidFormat()
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), []);

        // Same as JMS\Serializer\Handler\SymfonyUidHandler::FORMAT_CANONICAL
        $this->assertEquals('canonical', $config['handlers']['symfony_uid']['default_format']);
    }

    public function testJsonSerializationVisitorDefaultOptions()
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), []);

        $this->assertEquals(1024 /*JSON_PRESERVE_ZERO_FRACTION*/, $config['visitors']['json_serialization']['options']);
    }

    public function testDefaultProfiler()
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), []);

        $this->assertSame(true, $config['profiler']);

        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(false), []);

        $this->assertSame(false, $config['profiler']);
    }

    public function testEnableStrictJsonDeserializer(): void
    {
        $container = $this->getContainer([
            [
                'visitors' => [
                    'json_deserialization' => ['strict' => true],
                ],
            ],
        ]);

        $strict = $container->findDefinition('jms_serializer.json_deserialization_visitor')->getArgument(0);

        $this->assertTrue($strict);
    }
}
