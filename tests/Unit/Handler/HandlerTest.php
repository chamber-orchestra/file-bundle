<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Handler;

use ChamberOrchestra\FileBundle\Events\PostRemoveEvent;
use ChamberOrchestra\FileBundle\Events\PostUploadEvent;
use ChamberOrchestra\FileBundle\Events\PreRemoveEvent;
use ChamberOrchestra\FileBundle\Events\PreUploadEvent;
use ChamberOrchestra\FileBundle\Exception\RuntimeException;
use ChamberOrchestra\FileBundle\Handler\Handler;
use ChamberOrchestra\FileBundle\Mapping\Attribute\Uploadable;
use ChamberOrchestra\FileBundle\Mapping\Configuration\UploadableConfiguration;
use ChamberOrchestra\FileBundle\Storage\StorageInterface;
use ChamberOrchestra\FileBundle\Storage\StorageResolver;
use ChamberOrchestra\MetadataBundle\Mapping\ExtensionMetadataInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class HandlerTest extends TestCase
{
    private StorageInterface&MockObject $storage;
    private EventDispatcherInterface&MockObject $dispatcher;
    private ExtensionMetadataInterface&MockObject $metadata;
    private Handler $handler;
    private UploadableConfiguration $config;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(StorageInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->metadata = $this->createMock(ExtensionMetadataInterface::class);

        $resolver = new StorageResolver();
        $resolver->add('default', $this->storage);

        $this->handler = new Handler($resolver, $this->dispatcher, \sys_get_temp_dir().'/file_bundle_archive_test');

        $this->config = new UploadableConfiguration(new Uploadable(prefix: 'test'));
        $this->config->mapField('file', ['upload' => true, 'mappedBy' => 'filePath']);
        $this->config->mapField('filePath', ['inversedBy' => 'file']);

        $this->metadata->method('getConfiguration')
            ->with(UploadableConfiguration::class)
            ->willReturn($this->config);
    }

    public function testNotifySetsRelativePath(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');
        $file = new File($tmpFile);
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'file')
            ->willReturn($file);

        $this->storage->method('resolveRelativePath')
            ->willReturn('/test/file.txt');

        $this->metadata->expects(self::once())
            ->method('setFieldValue')
            ->with($entity, 'filePath', '/test/file.txt');

        $this->handler->notify($this->metadata, $entity, 'file');

        \unlink($tmpFile);
    }

    public function testNotifySetsNullWhenNoFile(): void
    {
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'file')
            ->willReturn(null);

        $this->metadata->expects(self::once())
            ->method('setFieldValue')
            ->with($entity, 'filePath', null);

        $this->handler->notify($this->metadata, $entity, 'file');
    }

    public function testNotifyIgnoresNonFileValues(): void
    {
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'file')
            ->willReturn('string-value');

        $this->metadata->expects(self::never())
            ->method('setFieldValue');

        $this->handler->notify($this->metadata, $entity, 'file');
    }

    public function testUpdateSetsPathFromInversedBy(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');
        $file = new File($tmpFile);
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'file')
            ->willReturn($file);

        $this->storage->method('resolveRelativePath')
            ->willReturn('/test/file.txt');

        $this->metadata->expects(self::once())
            ->method('setFieldValue')
            ->with($entity, 'filePath', '/test/file.txt');

        $this->handler->update($this->metadata, $entity, 'filePath');

        \unlink($tmpFile);
    }

    public function testUpdateSetsNullWhenFileIsNull(): void
    {
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'file')
            ->willReturn(null);

        $this->metadata->expects(self::once())
            ->method('setFieldValue')
            ->with($entity, 'filePath', null);

        $this->handler->update($this->metadata, $entity, 'filePath');
    }

    public function testUploadDelegatesToStorageAndCreatesModelFile(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');
        $file = new UploadedFile($tmpFile, 'original.txt', 'text/plain', null, true);
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'file')
            ->willReturn($file);

        $this->storage->method('upload')
            ->willReturn('/test/hashed.txt');

        $this->storage->method('resolvePath')
            ->with('/test/hashed.txt')
            ->willReturn('/uploads/test/hashed.txt');

        $this->storage->method('resolveUri')
            ->with('/test/hashed.txt')
            ->willReturn('/uploads/test/hashed.txt');

        $this->metadata->expects(self::once())
            ->method('setFieldValue')
            ->with($entity, 'file', self::isInstanceOf(\ChamberOrchestra\FileBundle\Model\File::class));

        $this->handler->upload($this->metadata, $entity, 'filePath');

        @\unlink($tmpFile);
    }

    public function testUploadDispatchesPreAndPostUploadEvents(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');
        $file = new UploadedFile($tmpFile, 'nocturne.pdf', 'application/pdf', null, true);
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'file')
            ->willReturn($file);

        $this->storage->method('upload')->willReturn('/test/nocturne.pdf');
        $this->storage->method('resolvePath')->willReturn('/uploads/test/nocturne.pdf');
        $this->storage->method('resolveUri')->willReturn('/uploads/test/nocturne.pdf');

        $dispatched = [];
        $this->dispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $this->handler->upload($this->metadata, $entity, 'filePath');

        self::assertInstanceOf(PreUploadEvent::class, $dispatched[0]);
        self::assertSame(\stdClass::class, $dispatched[0]->entityClass);
        self::assertInstanceOf(PostUploadEvent::class, $dispatched[1]);
        self::assertSame(\stdClass::class, $dispatched[1]->entityClass);

        @\unlink($tmpFile);
    }

    public function testUploadThrowsForNonFileValue(): void
    {
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'file')
            ->willReturn('not-a-file');

        $this->expectException(RuntimeException::class);

        $this->handler->upload($this->metadata, $entity, 'filePath');
    }

    public function testInjectCreatesModelFile(): void
    {
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'filePath')
            ->willReturn('/test/file.txt');

        $this->storage->method('resolvePath')
            ->with('/test/file.txt')
            ->willReturn('/var/uploads/test/file.txt');

        $this->storage->method('resolveUri')
            ->with('/test/file.txt')
            ->willReturn('/uploads/test/file.txt');

        $this->metadata->expects(self::once())
            ->method('setFieldValue')
            ->with($entity, 'file', self::isInstanceOf(\ChamberOrchestra\FileBundle\Model\File::class));

        $this->handler->inject($this->metadata, $entity, 'file');
    }

    public function testInjectSetsNullWhenNoPath(): void
    {
        $entity = new \stdClass();

        $this->metadata->method('getFieldValue')
            ->with($entity, 'filePath')
            ->willReturn(null);

        $this->metadata->expects(self::once())
            ->method('setFieldValue')
            ->with($entity, 'file', null);

        $this->handler->inject($this->metadata, $entity, 'file');
    }

    public function testRemoveDispatchesEventsAndCallsStorage(): void
    {
        $this->storage->method('resolvePath')
            ->with('/test/file.txt')
            ->willReturn('/var/uploads/test/file.txt');

        $this->storage->method('resolveUri')
            ->with('/test/file.txt')
            ->willReturn('/uploads/test/file.txt');

        $dispatched = [];
        $this->dispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $this->storage->expects(self::once())
            ->method('remove')
            ->with('/var/uploads/test/file.txt');

        $this->handler->remove(\stdClass::class, 'default', '/test/file.txt');

        self::assertInstanceOf(PreRemoveEvent::class, $dispatched[0]);
        self::assertSame(\stdClass::class, $dispatched[0]->entityClass);
        self::assertInstanceOf(PostRemoveEvent::class, $dispatched[1]);
        self::assertSame(\stdClass::class, $dispatched[1]->entityClass);
    }

    public function testRemoveReturnsEarlyForNullPath(): void
    {
        $this->dispatcher->expects(self::never())
            ->method('dispatch');

        $this->storage->expects(self::never())
            ->method('remove');

        $this->handler->remove(\stdClass::class, 'default', null);
    }
}
