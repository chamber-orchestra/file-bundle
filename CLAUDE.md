# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ChamberOrchestra File Bundle is a Symfony bundle that automatically handles file uploads for Doctrine ORM entities. It uses PHP attributes to mark uploadable fields, hooks into Doctrine lifecycle events to upload/inject/remove files transparently, and supports pluggable naming strategies and storage backends.

## Build and Test Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/Unit/Handler/HandlerTest.php

# Run tests in specific directory
./vendor/bin/phpunit tests/Unit/Storage/

# Run single test method
./vendor/bin/phpunit --filter testMethodName

# Run static analysis (level max)
composer run-script analyse

# Check code style (dry-run)
composer run-script cs-check

# Auto-fix code style
./vendor/bin/php-cs-fixer fix
```

## Architecture

### Upload Attributes

**Uploadable** (`src/Mapping/Attribute/Uploadable.php`): PHP attribute applied to entity classes to mark them as uploadable. Implements Doctrine's `MappingAttribute`. Validates inputs in constructor (prefix must not contain `..`, namingStrategy must implement `NamingStrategyInterface`). Options: `prefix` (upload subdirectory path), `namingStrategy` (class implementing `NamingStrategyInterface`, defaults to `HashingNamingStrategy`), `behaviour` (file removal policy: `Behaviour::Remove`, `Behaviour::Keep`, or `Behaviour::Archive`), `storage` (named storage to use, defaults to `'default'`; must match a name defined under `storages` in bundle config).

**UploadableProperty** (`src/Mapping/Attribute/UploadableProperty.php`): PHP attribute applied to entity properties of type `File|null` to mark them as upload fields. Options: `mappedBy` (name of the string property that persists the relative file path).

### Mapping Layer

**UploadableDriver** (`src/Mapping/Driver/UploadableDriver.php`): Extends `AbstractMappingDriver` from metadata-bundle. Reads `#[Uploadable]` from the entity class and `#[UploadableProperty]` from properties, validates that the naming strategy implements `NamingStrategyInterface` and that `mappedBy` target properties exist, then builds bidirectional field mappings (`upload`/`mappedBy` on the file field, `inversedBy` on the path field). Supports Doctrine embeddables by recursively joining embedded configurations with dot-prefixed field names.

**UploadableConfiguration** (`src/Mapping/Configuration/UploadableConfiguration.php`): Extends `AbstractMetadataConfiguration`. Stores prefix, behaviour, naming strategy class, and storage name. Provides `getUploadableFieldNames()` (fields with `#[UploadableProperty]`) and `getMappedByFieldNames()` (the corresponding persisted path fields). Implements `__serialize`/`__unserialize` for metadata caching.

**Behaviour** (`src/Mapping/Helper/Behaviour.php`): Int-backed enum with cases `Keep` (0, leave orphan files on disk), `Remove` (1, delete file when entity is updated/deleted), and `Archive` (2, copy file to archive directory before removing from storage).

### Event Subscriber

**FileSubscriber** (`src/EventSubscriber/FileSubscriber.php`): Extends `AbstractDoctrineListener` from metadata-bundle, registered as a Doctrine listener for `postLoad`, `preFlush`, `onFlush`, and `postFlush` events.

- **postLoad**: Calls `Handler::inject()` on each uploadable field — converts stored relative paths into `Model\File` objects with resolved URI.
- **preFlush**: Iterates the identity map, calls `Handler::notify()` on each uploadable field — detects file changes and sets the `mappedBy` path field so Doctrine's UnitOfWork sees the change.
- **onFlush**: Processes scheduled insertions (upload + update path), updates (remove old file + upload new + update path), and deletions (queue file removal/archive). Uses `getScheduledEntityInsertions/Updates/Deletions` filtered by `UploadableConfiguration`. Calls `recomputeSingleEntityChangeSet()` after path updates.
- **postFlush**: Executes deferred file removals from `$pendingRemove` and archives from `$pendingArchive`. Both arrays store `[entityClass, storageName, paths]` tuples. Removals/archives are deferred to postFlush to ensure database changes succeed before files are affected.

### Handler

**Handler** (`src/Handler/Handler.php`): Registered as `lazy: true` in the service container. Depends on `StorageResolver`, `EventDispatcherInterface`, and `$archivePath`. Resolves the correct storage per entity via `UploadableConfiguration::getStorage()`. Implements six operations:
- `notify()`: Skips `Model\File` instances (already injected, mappedBy is correct). For other `File` instances, resolves its relative path via `Storage::resolveRelativePath()` and sets it on the `mappedBy` field. Sets `null` if no file.
- `update()`: After upload, reads the file from the `inversedBy` field and sets the relative path on the `mappedBy` field via `getPathname()`. Handles null/missing files.
- `upload()`: Derives entity class via `ClassUtils::getClass()`, dispatches `PreUploadEvent` (with `$entityClass`), creates a `NamingStrategyInterface` instance via `NamingStrategyFactory::create()`, delegates to `Storage::upload()`, wraps the result in a `Model\File` with resolved path and URI, then dispatches `PostUploadEvent` (with `$entityClass`).
- `remove(string $entityClass, string $storageName, ?string $relativePath)`: Resolves path/URI, dispatches `PreRemoveEvent` (with `$entityClass`), calls `Storage::remove()`, dispatches `PostRemoveEvent` (with `$entityClass`).
- `archive(string $storageName, ?string $relativePath)`: Downloads the file from storage to the archive directory via `Storage::download()`, then removes it from storage. Works with both filesystem and S3 backends.
- `inject()`: Reads the relative path from `mappedBy`, resolves to absolute path and URI via Storage, creates a `Model\File` instance and sets it on the file property.

### Storage

**StorageInterface** (`src/Storage/StorageInterface.php`): Contract for file operations: `upload`, `remove`, `resolvePath`, `resolveRelativePath`, `resolveUri`, `download`.

**FileSystemStorage** (`src/Storage/FileSystemStorage.php`): `readonly` local filesystem implementation. Constructed with `uploadPath` (absolute directory) and optional `uriPrefix` (web-accessible path prefix or CDN URL). `upload()` creates the target directory if needed, generates a filename via the naming strategy, validates the filename against path traversal (rejects `/`, `\`, `..`), moves the file to `{uploadPath}/{prefix}/`, and returns the relative path. `download()` copies the file from storage to a local target path. `resolvePath()` prepends the upload root. `resolveUri()` prepends the URI prefix (can be a CDN URL for absolute URIs).

**S3Storage** (`src/Storage/S3Storage.php`): `readonly` AWS S3 implementation. Constructed with `S3Client`, `bucket`, and optional `uriPrefix`. `upload()` puts the object to S3 and wraps `S3Exception` in a bundle `RuntimeException`. `remove()` catches `S3Exception` and returns `false` for `NoSuchKey`. `download()` uses `S3Client::getObject()` with `SaveAs` to download files to a local path. `resolveUri()` uses `S3Client::getObjectUrl()` when no URI prefix is configured.

**StorageResolver** (`src/Storage/StorageResolver.php`): Registry of named storages. Each storage defined in the `storages` config is registered by name. The `'default'` alias always points to the configured default storage (explicit via `default_storage` option, or the first enabled storage). Handler uses the resolver to select storage per entity based on `#[Uploadable(storage: '...')]`.

### Naming Strategies

**NamingStrategyInterface** (`src/NamingStrategy/NamingStrategyInterface.php`): Contract with a single `name(File $file): string` method.

**HashingNamingStrategy** (`src/NamingStrategy/HashingNamingStrategy.php`): Default strategy. Generates `md5(originalName + random_bytes) + guessedExtension`.

**OriginNamingStrategy** (`src/NamingStrategy/OriginNamingStrategy.php`): Preserves the original filename (client name for uploads, basename for regular files).

**NamingStrategyFactory** (`src/NamingStrategy/NamingStrategyFactory.php`): Static factory with singleton cache (`$factories` array). Validates that the class exists and implements `NamingStrategyInterface`. Provides `reset()` method for clearing the cache in tests.

### Model

**File** (`src/Model/File.php`): Extends `Symfony\Component\HttpFoundation\File\File` with a `readonly $uri` property. Implements `FileInterface`. Uses `ImageTrait` for image dimension support. Constructed with `(path, uri)` — passes `checkPath: false` to parent since the file may not exist at injection time.

**FileInterface** (`src/Model/FileInterface.php`): Contract requiring `getUri(): string|null`.

### Entity Traits

**FileTrait** (`src/Entity/FileTrait.php`): Provides `$file` (with `#[UploadableProperty(mappedBy: 'filePath')]`) and `$filePath` (`nullable: true` ORM column). Getter returns `File|null`.

**ImageTrait** (`src/Entity/ImageTrait.php`): Same pattern with `$image`/`$imagePath` fields mapped via `#[UploadableProperty(mappedBy: 'imagePath')]`.

**RequiredFileTrait** (`src/Entity/RequiredFileTrait.php`): Like `FileTrait` but `$filePath` column is `nullable: false`, defaults to `''`.

**RequiredImageTrait** (`src/Entity/RequiredImageTrait.php`): Like `ImageTrait` but `$imagePath` column is `nullable: false`, defaults to `''`.

### Events

All events carry `$entityClass` (the fully-qualified entity class name via `ClassUtils::getClass()`), enabling listeners to filter by entity type.

**PreUploadEvent** / **PostUploadEvent** (`src/Events/`): Dispatched before/after file upload. Both carry readonly `$entityClass`, `$entity`, `$file`, and `$fieldName`. `PreUploadEvent` provides the original source file (before storage move). `PostUploadEvent` provides the resolved `Model\File` (after storage). Use `PostUploadEvent` for post-processing such as image resizing, thumbnail generation, or metadata extraction.

**PreRemoveEvent** / **PostRemoveEvent** (`src/Events/`): Dispatched before/after file deletion. Both extend `AbstractEvent` which carries readonly `$entityClass`, `$relativePath`, `$resolvedPath`, and `$resolvedUri`. Subscribe to these to hook into file removal (e.g., clearing CDN cache, removing thumbnails).

### Serializer

**FileNormalizer** (`src/Serializer/Normalizer/FileNormalizer.php`): Symfony Serializer normalizer for `Model\File`. Constructed with `$baseUrl` (wired to `%env(APP_URL)%` by the bundle extension). Normalizes to an absolute URL by prepending `$baseUrl` to relative URIs. Absolute URIs (starting with `http://` or `https://`, e.g. from S3 or CDN storage) are returned as-is. Per-storage CDN is configured by setting the storage's `uri_prefix` to the CDN URL.

### Service Configuration

Services are autowired and autoconfigured via `src/Resources/config/services.php`. The `Handler` is registered as `lazy: true`. Directories excluded from autowiring: `DependencyInjection`, `Resources`, `ExceptionInterface`, `NamingStrategy`, `Model`, `Mapping`, `Entity`, `Events`, `Storage`.

Bundle configuration key is `chamber_orchestra_file`. Storages are defined under `storages` as a named map. Each storage has: `enabled` (default `true`), `driver` (`file_system` or `s3`), `path`, `uri_prefix` (null for private storage, or a CDN URL for absolute URIs), and S3-specific `bucket`, `region`, `endpoint`. S3 storages require `bucket` and `region` (validated at the Configuration tree level). The `default_storage` option selects which storage is the default (if omitted, the first enabled storage is used). The `archive_path` option (default `%kernel.project_dir%/var/archive`) sets the directory for `Behaviour::Archive`. The `FileNormalizer` receives `%env(APP_URL)%` as its base URL for resolving relative URIs. Entities select storage via `#[Uploadable(storage: 'name')]`.

## Code Style

- PHP 8.5+ with strict types (`declare(strict_types=1);`)
- PSR-4 autoloading: `ChamberOrchestra\FileBundle\` → `src/`
- `@PER-CS` + `@Symfony` PHP-CS-Fixer rulesets
- Native function invocations must be backslash-prefixed (e.g., `\array_merge()`, `\sprintf()`, `\count()`)
- No global namespace imports — never use `use function` or `use const`
- Nullable types use `?` prefix syntax (e.g., `?string` not `string|null`)
- Ordered imports (alpha), no unused imports, single quotes, trailing commas in multiline
- PHPStan level max

## Dependencies

- Requires PHP 8.5, Symfony 8.0 components (`dependency-injection`, `config`, `framework-bundle`, `runtime`, `options-resolver`), and `chamber-orchestra/metadata-bundle` 8.0
- Dev: PHPUnit 13, `symfony/test-pack`, `symfony/mime`, `symfony/serializer`, `aws/aws-sdk-php`, `friendsofphp/php-cs-fixer`, `phpstan/phpstan`
- Suggests: `aws/aws-sdk-php` (for S3 storage driver)
- Main branch is `main`

## Testing Conventions

- Use music thematics for test fixtures and naming (e.g., entity names like `Composition`, `Instrument`, `Rehearsal`, `Score`; file names like `symphony_no_5.pdf`, `violin_concerto.mp3`, `moonlight_sonata.jpg`; prefixes like `scores`, `recordings`)
