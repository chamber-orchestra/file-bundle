<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integrational\Form\Type;

use ChamberOrchestra\FileBundle\Form\Type\FileType;
use ChamberOrchestra\FileBundle\Model\File;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileTypeTest extends TypeTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();

        parent::setUp();
    }

    protected function getExtensions(): array
    {
        return \array_merge(parent::getExtensions(), [
            new HttpFoundationExtension(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (\file_exists($path)) {
                @\unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testSubmitSingleFile(): void
    {
        $form = $this->factory->create(FileType::class);
        $uploadedFile = $this->createTempUploadedFile();

        $form->submit(['file' => $uploadedFile]);

        self::assertTrue($form->isSynchronized());
        self::assertSame($uploadedFile, $form->getData());
    }

    public function testSubmitNullPreservesOriginalFile(): void
    {
        $originalFile = $this->createModelFile();

        $form = $this->factory->create(FileType::class);
        $form->setData($originalFile);
        $form->submit(['file' => null]);

        self::assertTrue($form->isSynchronized());
        self::assertSame($originalFile, $form->getData());
    }

    public function testSubmitNewFileReplacesOriginal(): void
    {
        $originalFile = $this->createModelFile();
        $newFile = $this->createTempUploadedFile('moonlight_sonata.jpg');

        $form = $this->factory->create(FileType::class);
        $form->setData($originalFile);
        $form->submit(['file' => $newFile]);

        self::assertTrue($form->isSynchronized());
        self::assertSame($newFile, $form->getData());
    }

    public function testSubmitDeleteCheckboxReturnsNull(): void
    {
        $originalFile = $this->createModelFile();

        $form = $this->factory->create(FileType::class);
        $form->setData($originalFile);
        $form->submit(['file' => null, 'delete' => '1']);

        self::assertTrue($form->isSynchronized());
        self::assertNull($form->getData());
    }

    public function testDeleteCheckboxDisabledWhenNoFile(): void
    {
        $form = $this->factory->create(FileType::class);
        $form->setData(null);

        self::assertTrue($form->has('delete'));
        self::assertTrue($form->get('delete')->getConfig()->getOption('disabled'));
    }

    public function testDeleteCheckboxEnabledWhenFileExists(): void
    {
        $originalFile = $this->createModelFile();

        $form = $this->factory->create(FileType::class);
        $form->setData($originalFile);

        self::assertTrue($form->has('delete'));
        self::assertFalse($form->get('delete')->getConfig()->getOption('disabled'));
    }

    public function testAllowDeleteFalseHidesCheckbox(): void
    {
        $form = $this->factory->create(FileType::class, null, [
            'allow_delete' => false,
        ]);

        self::assertFalse($form->has('delete'));
    }

    public function testAllowDeleteWithRequiredThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "allow_delete" option cannot be enabled when "required" is true.');

        $this->factory->create(FileType::class, null, [
            'allow_delete' => true,
            'required' => true,
        ]);
    }

    public function testRequiredFieldHasNoDeleteCheckbox(): void
    {
        $form = $this->factory->create(FileType::class, null, [
            'required' => true,
            'allow_delete' => false,
        ]);

        self::assertFalse($form->has('delete'));
    }

    public function testViewVarsExposeOriginalFile(): void
    {
        $originalFile = $this->createModelFile();

        $form = $this->factory->create(FileType::class);
        $form->setData($originalFile);

        $view = $form->createView();

        self::assertSame($originalFile, $view->vars['original_file']);
        self::assertTrue($view->vars['allow_delete']);
        self::assertFalse($view->vars['multiple']);
    }

    public function testViewVarsWithoutFile(): void
    {
        $form = $this->factory->create(FileType::class);
        $form->setData(null);

        $view = $form->createView();

        self::assertNull($view->vars['original_file']);
    }

    public function testBlockPrefix(): void
    {
        $type = new FileType();

        self::assertSame('chamber_orchestra_file', $type->getBlockPrefix());
    }

    public function testCollectionSubmitMultipleFiles(): void
    {
        $form = $this->factory->createBuilder()
            ->add('scores', CollectionType::class, [
                'entry_type' => FileType::class,
                'entry_options' => ['allow_delete' => false],
                'allow_add' => true,
            ])
            ->getForm();

        $file1 = $this->createTempUploadedFile('symphony_no_5.pdf');
        $file2 = $this->createTempUploadedFile('violin_concerto.mp3');

        $form->submit([
            'scores' => [
                ['file' => $file1],
                ['file' => $file2],
            ],
        ]);

        self::assertTrue($form->isSynchronized());

        $data = $form->get('scores')->getData();
        self::assertCount(2, $data);
        self::assertSame($file1, $data[0]);
        self::assertSame($file2, $data[1]);
    }

    public function testCollectionPreservesOriginalFiles(): void
    {
        $file1 = $this->createModelFile('symphony_no_5.pdf');
        $file2 = $this->createModelFile('moonlight_sonata.jpg');

        $form = $this->factory->createBuilder()
            ->add('scores', CollectionType::class, [
                'entry_type' => FileType::class,
            ])
            ->getForm();

        $form->setData(['scores' => [$file1, $file2]]);
        $form->submit([
            'scores' => [
                ['file' => null],
                ['file' => null],
            ],
        ]);

        self::assertTrue($form->isSynchronized());

        $data = $form->get('scores')->getData();
        self::assertCount(2, $data);
        self::assertSame($file1, $data[0]);
        self::assertSame($file2, $data[1]);
    }

    public function testCollectionDeleteOneEntry(): void
    {
        $file1 = $this->createModelFile('symphony_no_5.pdf');
        $file2 = $this->createModelFile('moonlight_sonata.jpg');

        $form = $this->factory->createBuilder()
            ->add('scores', CollectionType::class, [
                'entry_type' => FileType::class,
            ])
            ->getForm();

        $form->setData(['scores' => [$file1, $file2]]);
        $form->submit([
            'scores' => [
                ['file' => null, 'delete' => '1'],
                ['file' => null],
            ],
        ]);

        self::assertTrue($form->isSynchronized());

        $data = $form->get('scores')->getData();
        self::assertCount(2, $data);
        self::assertNull($data[0]);
        self::assertSame($file2, $data[1]);
    }

    public function testCollectionAddEntry(): void
    {
        $existingFile = $this->createModelFile('symphony_no_5.pdf');

        $form = $this->factory->createBuilder()
            ->add('scores', CollectionType::class, [
                'entry_type' => FileType::class,
                'entry_options' => ['allow_delete' => false],
                'allow_add' => true,
            ])
            ->getForm();

        $form->setData(['scores' => [$existingFile]]);

        $newFile = $this->createTempUploadedFile('violin_concerto.mp3');

        $form->submit([
            'scores' => [
                ['file' => null],
                ['file' => $newFile],
            ],
        ]);

        self::assertTrue($form->isSynchronized());

        $data = $form->get('scores')->getData();
        self::assertCount(2, $data);
        self::assertSame($existingFile, $data[0]);
        self::assertSame($newFile, $data[1]);
    }

    public function testPostSubmitCleansUpOriginalFiles(): void
    {
        $originalFile = $this->createModelFile();

        $form = $this->factory->create(FileType::class);
        $form->setData($originalFile);
        $form->submit(['file' => null]);

        $type = $form->getConfig()->getType()->getInnerType();

        $reflection = new \ReflectionClass($type);
        $property = $reflection->getProperty('originalFiles');

        self::assertSame([], $property->getValue($type));
    }

    private function createTempUploadedFile(string $name = 'symphony_no_5.pdf'): UploadedFile
    {
        $path = \sys_get_temp_dir().'/'.\uniqid('file_type_test_').'_'.$name;
        \file_put_contents($path, 'test content for '.$name);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function createModelFile(string $name = 'symphony_no_5.pdf'): File
    {
        $path = \sys_get_temp_dir().'/'.\uniqid('model_file_test_').'_'.$name;
        \file_put_contents($path, 'existing content for '.$name);
        $this->tempFiles[] = $path;

        return new File($path, '/uploads/'.$name);
    }
}
