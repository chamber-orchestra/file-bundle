<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Storage;

use ChamberOrchestra\FileBundle\Exception\InvalidArgumentException;

/**
 * Resolves storage instances by name.
 *
 * Storage instances are registered by the ChamberOrchestraFileExtension
 * based on the "chamber_orchestra_file.storages" configuration. A storage
 * named "default" is always available and points to either the explicitly
 * configured default storage or the first enabled storage.
 */
class StorageResolver
{
    /** @var array<string, StorageInterface> */
    private array $storages = [];

    public function add(string $name, StorageInterface $storage): void
    {
        if ('' === $name) {
            throw new InvalidArgumentException('Storage name must not be empty.');
        }

        $this->storages[$name] = $storage;
    }

    public function get(string $name): StorageInterface
    {
        if (!isset($this->storages[$name])) {
            throw new InvalidArgumentException(\sprintf('Storage "%s" is not registered. Available storages: %s.', $name, \implode(', ', \array_keys($this->storages))));
        }

        return $this->storages[$name];
    }
}
