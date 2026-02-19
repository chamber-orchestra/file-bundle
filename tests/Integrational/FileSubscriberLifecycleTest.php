<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integrational;

use ChamberOrchestra\FileBundle\Model\File;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Fixtures\Entity\NonUploadableEntity;
use Tests\Fixtures\Entity\UploadableEntity;
use Tests\Fixtures\Entity\UploadableKeepEntity;

class FileSubscriberLifecycleTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $uploadPath;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->uploadPath = \realpath(\sys_get_temp_dir()).'/file_bundle_test';

        if (!\is_dir($this->uploadPath)) {
            \mkdir($this->uploadPath, 0777, true);
        }

        $schemaTool = new SchemaTool($this->em);
        $classes = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($classes);
        $schemaTool->createSchema($classes);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->uploadPath);
    }

    public function testPersistUploadsFileAndSetsPath(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'upload');
        \file_put_contents($tmpFile, 'file content');

        $entity = new UploadableEntity();
        $entity->setFile(new UploadedFile($tmpFile, 'document.txt', 'text/plain', null, true));

        $this->em->persist($entity);
        $this->em->flush();

        self::assertNotNull($entity->getFilePath());
        self::assertStringStartsWith('/test/', $entity->getFilePath());

        $uploadedFilePath = $this->uploadPath.$entity->getFilePath();
        self::assertFileExists($uploadedFilePath);
    }

    public function testPostLoadInjectsFileObject(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'upload');
        \file_put_contents($tmpFile, 'file content');

        $entity = new UploadableEntity();
        $entity->setFile(new UploadedFile($tmpFile, 'document.txt', 'text/plain', null, true));

        $this->em->persist($entity);
        $this->em->flush();

        $id = $entity->getId();

        $this->em->clear();

        $loaded = $this->em->find(UploadableEntity::class, $id);
        self::assertNotNull($loaded);

        $file = $loaded->getFile();
        self::assertInstanceOf(File::class, $file);
        self::assertNotNull($file->getUri());
        self::assertStringStartsWith('/uploads/', $file->getUri());
    }

    public function testUpdateReplacesFileAndRemovesOld(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'upload');
        \file_put_contents($tmpFile, 'original content');

        $entity = new UploadableEntity();
        $entity->setFile(new UploadedFile($tmpFile, 'original.txt', 'text/plain', null, true));

        $this->em->persist($entity);
        $this->em->flush();

        $oldPath = $entity->getFilePath();
        $oldFullPath = $this->uploadPath.$oldPath;
        self::assertFileExists($oldFullPath);

        $newTmpFile = \tempnam(\sys_get_temp_dir(), 'upload');
        \file_put_contents($newTmpFile, 'new content');

        $entity->setFile(new UploadedFile($newTmpFile, 'replacement.txt', 'text/plain', null, true));
        $this->em->flush();

        self::assertNotSame($oldPath, $entity->getFilePath());
        self::assertFileDoesNotExist($oldFullPath);

        $newFullPath = $this->uploadPath.$entity->getFilePath();
        self::assertFileExists($newFullPath);
    }

    public function testDeleteEntityRemovesFile(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'upload');
        \file_put_contents($tmpFile, 'content to delete');

        $entity = new UploadableEntity();
        $entity->setFile(new UploadedFile($tmpFile, 'delete-me.txt', 'text/plain', null, true));

        $this->em->persist($entity);
        $this->em->flush();

        $filePath = $this->uploadPath.$entity->getFilePath();
        self::assertFileExists($filePath);

        $this->em->remove($entity);
        $this->em->flush();

        self::assertFileDoesNotExist($filePath);
    }

    public function testDeleteWithBehaviourKeepPreservesFile(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'upload');
        \file_put_contents($tmpFile, 'keep me');

        $entity = new UploadableKeepEntity();
        $entity->setFile(new UploadedFile($tmpFile, 'keep-me.txt', 'text/plain', null, true));

        $this->em->persist($entity);
        $this->em->flush();

        $filePath = $this->uploadPath.$entity->getFilePath();
        self::assertFileExists($filePath);

        $this->em->remove($entity);
        $this->em->flush();

        self::assertFileExists($filePath);
    }

    public function testNonUploadableEntityIsIgnored(): void
    {
        $entity = new NonUploadableEntity();
        $entity->setName('test');

        $this->em->persist($entity);
        $this->em->flush();

        self::assertNotNull($entity->getId());

        $this->em->clear();

        $loaded = $this->em->find(NonUploadableEntity::class, $entity->getId());
        self::assertNotNull($loaded);
        self::assertSame('test', $loaded->getName());
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = \scandir($dir);
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            \is_dir($path) ? $this->removeDirectory($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
