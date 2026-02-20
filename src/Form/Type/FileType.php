<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\NotBlank;

class FileType extends AbstractType
{
    private const string ATTR_HOLDER = 'file_holder';

    /** @var array<string, mixed> */
    private array $originalFiles = [];

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'error_bubbling' => false,
            'required' => false,
            'multiple' => false,
            'mime_types' => [],
            'allow_delete' => true,
            'attr' => static fn (Options $options): array => [
                'accept' => \implode(',', (array) $options['mime_types']),
            ],
            'constraints' => static function (Options $options): array {
                /** @var array<string> $mimeTypes */
                $mimeTypes = $options['mime_types'];

                $constraints = [];

                if ([] !== $mimeTypes) {
                    $constraints[] = new \Symfony\Component\Validator\Constraints\File(
                        mimeTypes: $mimeTypes,
                    );
                }

                if ($options['multiple'] && [] !== $constraints) {
                    $constraints = [new All($constraints)];
                }

                if ($options['required']) {
                    $constraints[] = new NotBlank();
                }

                return $constraints;
            },
            'delete_options' => static function (OptionsResolver $resolver): void {
                $resolver->setDefaults([
                    'required' => false,
                    'error_bubbling' => false,
                    'label' => 'file.field.delete',
                ]);
            },
            'entry_options' => static function (OptionsResolver $resolver, Options $parent): void {
                $keys = [
                    'attr',
                    'label',
                    'translation_domain',
                    'multiple',
                ];
                $map = [];
                foreach ($keys as $key) {
                    $map[$key] = $parent[$key];
                }

                $resolver->setDefaults(\array_merge([
                    'error_bubbling' => false,
                    'required' => false,
                ], $map));
            },
        ]);

        $resolver->setNormalizer('allow_delete', static function (Options $options, mixed $value): bool {
            if ($value && $options['required']) {
                throw new \LogicException('The "allow_delete" option cannot be enabled when "required" is true.');
            }

            return (bool) $value;
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        /** @var string $attr */
        $attr = $form->getConfig()->getAttribute(self::ATTR_HOLDER);

        $view->vars = \array_replace_recursive($view->vars, [
            'original_file' => $this->originalFiles[$attr] ?? null,
            'allow_delete' => $options['allow_delete'],
            'multiple' => $options['multiple'],
        ]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->setAttribute(self::ATTR_HOLDER, \uniqid());

        /** @var array<string, mixed> $entryOptions */
        $entryOptions = $options['entry_options'];

        $builder->add(
            'file',
            \Symfony\Component\Form\Extension\Core\Type\FileType::class,
            $entryOptions,
        );

        $multiple = $options['multiple'];

        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            function (FormEvent $event): void {
                $form = $event->getForm();
                /** @var string $attr */
                $attr = $form->getConfig()->getAttribute(self::ATTR_HOLDER);
                $this->originalFiles[$attr] = $form->getData();
            },
            10,
        );

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event) use ($multiple): void {
                $form = $event->getForm();
                /** @var array<string, mixed> $data */
                $data = $event->getData();
                /** @var string $attr */
                $attr = $form->getConfig()->getAttribute(self::ATTR_HOLDER);

                if ($multiple) {
                    if (!isset($data['file']) || (\is_array($data['file']) && [] === $data['file'])) {
                        $data['file'] = $this->originalFiles[$attr];
                    }
                } else {
                    $data['file'] ??= $this->originalFiles[$attr];
                }

                $event->setData($data);
            },
        );

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event): void {
                $form = $event->getForm();
                /** @var string $attr */
                $attr = $form->getConfig()->getAttribute(self::ATTR_HOLDER);
                unset($this->originalFiles[$attr]);
            },
        );

        if ($options['allow_delete']) {
            $builder->addEventListener(
                FormEvents::POST_SET_DATA,
                static function (FormEvent $event) use ($options): void {
                    $form = $event->getForm();
                    $file = $form->getData();

                    /** @var array<string, mixed> $deleteOptions */
                    $deleteOptions = $options['delete_options'];

                    $form->add('delete', CheckboxType::class, \array_replace_recursive([
                        'required' => false,
                        'disabled' => null === $file,
                    ], $deleteOptions));
                },
            );

            $reverseCallback = static function (array $data): mixed {
                if (isset($data['delete']) && $data['delete']) {
                    return null;
                }

                return $data['file'];
            };
        } else {
            $reverseCallback = static function (array $data): mixed {
                return $data['file'];
            };
        }

        $builder->addViewTransformer(new CallbackTransformer(
            static function (mixed $value = null): array {
                if (null === $value || $value instanceof UploadedFile || \is_array($value)) {
                    return ['file' => null];
                }

                return ['file' => $value];
            },
            $reverseCallback,
        ));
    }

    public function getBlockPrefix(): string
    {
        return 'chamber_orchestra_file';
    }
}
