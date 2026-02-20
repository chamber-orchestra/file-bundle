<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\DependencyInjection;

use Aws\S3\S3Client;
use ChamberOrchestra\FileBundle\Form\Type\FileType;
use ChamberOrchestra\FileBundle\Handler\Handler;
use ChamberOrchestra\FileBundle\Serializer\Normalizer\FileNormalizer;
use ChamberOrchestra\FileBundle\Storage\FileSystemStorage;
use ChamberOrchestra\FileBundle\Storage\S3Storage;
use ChamberOrchestra\FileBundle\Storage\StorageResolver;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

final class ChamberOrchestraFileExtension extends ConfigurableExtension
{
    /**
     * @param array<string, mixed> $configs
     */
    public function loadInternal(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');

        /** @var array<string, array{driver: string, path: string, uri_prefix: string|null, enabled?: bool, bucket?: string|null, region?: string|null, endpoint?: string|null}> $storages */
        $storages = $configs['storages'] ?? [];
        $enabledStorages = \array_filter($storages, static fn (array $s): bool => $s['enabled'] ?? true);

        if ([] === $enabledStorages) {
            throw new \LogicException('At least one storage must be defined under "chamber_orchestra_file.storages".');
        }

        /** @var string $defaultName */
        $defaultName = $configs['default_storage'] ?? \array_key_first($enabledStorages);

        if (!isset($enabledStorages[$defaultName])) {
            throw new \LogicException(\sprintf('Default storage "%s" is not defined or not enabled. Available storages: %s.', $defaultName, \implode(', ', \array_keys($enabledStorages))));
        }

        $resolver = $container->getDefinition(StorageResolver::class);

        foreach ($enabledStorages as $name => $storage) {
            $serviceId = 'chamber_orchestra_file.storage.'.$name;

            match ($storage['driver']) {
                'file_system' => $this->registerFileSystemStorage($container, $serviceId, $storage),
                's3' => $this->registerS3Storage($container, $serviceId, $name, $storage),
                default => throw new \LogicException(\sprintf('Unsupported storage driver "%s".', $storage['driver'])),
            };

            $resolver->addMethodCall('add', [$name, new Reference($serviceId)]);
        }

        // Register the default storage as 'default' if it has a different name
        if ('default' !== $defaultName) {
            $resolver->addMethodCall('add', ['default', new Reference('chamber_orchestra_file.storage.'.$defaultName)]);
        }

        /** @var string $archivePath */
        $archivePath = $configs['archive_path'] ?? '%kernel.project_dir%/var/archive';

        $container->getDefinition(Handler::class)
            ->setArgument('$archivePath', $archivePath);

        if ($container->hasDefinition(FileNormalizer::class)) {
            $container->getDefinition(FileNormalizer::class)
                ->setArgument('$baseUrl', '%env(APP_URL)%');
        }

        if (\class_exists(AbstractType::class)) {
            $container->register(FileType::class, FileType::class)
                ->setAutoconfigured(true)
                ->setPublic(false)
                ->addTag('form.type');
        }
    }

    /**
     * @param array{path: string, uri_prefix: string|null} $storage
     */
    private function registerFileSystemStorage(ContainerBuilder $container, string $serviceId, array $storage): void
    {
        $definition = new Definition(FileSystemStorage::class);
        $definition->setArguments([
            $storage['path'],
            $storage['uri_prefix'],
        ]);
        $container->setDefinition($serviceId, $definition);
    }

    /**
     * @param array{driver: string, path: string, uri_prefix: string|null, enabled?: bool, bucket?: string|null, region?: string|null, endpoint?: string|null} $storage
     */
    private function registerS3Storage(ContainerBuilder $container, string $serviceId, string $name, array $storage): void
    {
        if (!\class_exists(S3Client::class)) {
            throw new \LogicException('The "aws/aws-sdk-php" package is required for S3 storage. Install it with "composer require aws/aws-sdk-php".');
        }

        /** @var string $region */
        $region = $storage['region'] ?? 'us-east-1';

        $clientArgs = [
            'region' => $region,
            'version' => 'latest',
        ];

        if (null !== ($storage['endpoint'] ?? null)) {
            $clientArgs['endpoint'] = $storage['endpoint'];
        }

        $clientDefinition = new Definition(S3Client::class, [$clientArgs]);
        $container->setDefinition($serviceId.'.client', $clientDefinition);

        $s3Definition = new Definition(S3Storage::class);
        $s3Definition->setArguments([
            $clientDefinition,
            $storage['bucket'] ?? '',
            $storage['uri_prefix'],
        ]);
        $container->setDefinition($serviceId, $s3Definition);
    }
}
