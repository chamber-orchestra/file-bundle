<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Events;

use ChamberOrchestra\FileBundle\Events\PostRemoveEvent;
use ChamberOrchestra\FileBundle\Events\PostUploadEvent;
use ChamberOrchestra\FileBundle\Events\PreRemoveEvent;
use ChamberOrchestra\FileBundle\Events\PreUploadEvent;
use ChamberOrchestra\FileBundle\Model\File;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\EventDispatcher\Event;

class EventsTest extends TestCase
{
    public function testPreRemoveEventProperties(): void
    {
        $event = new PreRemoveEvent('App\\Entity\\Document', '/relative/path.txt', '/resolved/path.txt', '/uploads/path.txt');

        self::assertSame('App\\Entity\\Document', $event->entityClass);
        self::assertSame('/relative/path.txt', $event->relativePath);
        self::assertSame('/resolved/path.txt', $event->resolvedPath);
        self::assertSame('/uploads/path.txt', $event->resolvedUri);
    }

    public function testPostRemoveEventProperties(): void
    {
        $event = new PostRemoveEvent('App\\Entity\\Document', '/relative/path.txt', '/resolved/path.txt', '/uploads/path.txt');

        self::assertSame('App\\Entity\\Document', $event->entityClass);
        self::assertSame('/relative/path.txt', $event->relativePath);
        self::assertSame('/resolved/path.txt', $event->resolvedPath);
        self::assertSame('/uploads/path.txt', $event->resolvedUri);
    }

    public function testEventsExtendSymfonyEvent(): void
    {
        $pre = new PreRemoveEvent('App\\Entity\\Doc', '/a', '/b', '/c');
        $post = new PostRemoveEvent('App\\Entity\\Doc', '/a', '/b', '/c');

        self::assertInstanceOf(Event::class, $pre);
        self::assertInstanceOf(Event::class, $post);
    }

    public function testPreUploadEventProperties(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');
        $file = new UploadedFile($tmpFile, 'requiem.pdf', 'application/pdf', null, true);
        $entity = new \stdClass();

        $event = new PreUploadEvent(\stdClass::class, $entity, $file, 'score');

        self::assertSame(\stdClass::class, $event->entityClass);
        self::assertSame($entity, $event->entity);
        self::assertSame($file, $event->file);
        self::assertSame('score', $event->fieldName);
        self::assertInstanceOf(Event::class, $event);

        @\unlink($tmpFile);
    }

    public function testPostUploadEventProperties(): void
    {
        $file = new File('/uploads/scores/requiem.pdf', '/uploads/scores/requiem.pdf');
        $entity = new \stdClass();

        $event = new PostUploadEvent(\stdClass::class, $entity, $file, 'score');

        self::assertSame(\stdClass::class, $event->entityClass);
        self::assertSame($entity, $event->entity);
        self::assertSame($file, $event->file);
        self::assertSame('score', $event->fieldName);
        self::assertInstanceOf(Event::class, $event);
    }
}
