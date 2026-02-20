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
        $entity = new \stdClass();
        $event = new PreRemoveEvent($entity, '/relative/path.txt', '/resolved/path.txt', '/uploads/path.txt');

        self::assertSame($entity, $event->entity);
        self::assertSame('/relative/path.txt', $event->relativePath);
        self::assertSame('/resolved/path.txt', $event->resolvedPath);
        self::assertSame('/uploads/path.txt', $event->resolvedUri);
    }

    public function testPostRemoveEventProperties(): void
    {
        $entity = new \stdClass();
        $event = new PostRemoveEvent($entity, '/relative/path.txt', '/resolved/path.txt', '/uploads/path.txt');

        self::assertSame($entity, $event->entity);
        self::assertSame('/relative/path.txt', $event->relativePath);
        self::assertSame('/resolved/path.txt', $event->resolvedPath);
        self::assertSame('/uploads/path.txt', $event->resolvedUri);
    }

    public function testEventsExtendSymfonyEvent(): void
    {
        $entity = new \stdClass();
        $pre = new PreRemoveEvent($entity, '/a', '/b', '/c');
        $post = new PostRemoveEvent($entity, '/a', '/b', '/c');

        self::assertInstanceOf(Event::class, $pre);
        self::assertInstanceOf(Event::class, $post);
    }

    public function testPreUploadEventProperties(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');
        $file = new UploadedFile($tmpFile, 'requiem.pdf', 'application/pdf', null, true);
        $entity = new \stdClass();

        $event = new PreUploadEvent($entity, $file, 'score');

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

        $event = new PostUploadEvent($entity, $file, 'score');

        self::assertSame($entity, $event->entity);
        self::assertSame($file, $event->file);
        self::assertSame('score', $event->fieldName);
        self::assertInstanceOf(Event::class, $event);
    }
}
