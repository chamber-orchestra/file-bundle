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
- **postFlush**: Executes deferred file removals from `$pendingRemove` and archives from `$pendingArchive`. Both arrays store `[entity, storageName, paths]` tuples. Removals/archives are deferred to postFlush to ensure database changes succeed before files are affected.

### Handler

**Handler** (`src/Handler/Handler.php`): Registered as `lazy: true` in the service container. Depends on `StorageResolver`, `EventDispatcherInterface`, and `$archivePath`. Resolves the correct storage per entity via `UploadableConfiguration::getStorage()`. Implements six operations:
- `notify()`: Skips `Model\File` instances (already injected, mappedBy is correct). For other `File` instances, resolves its relative path via `Storage::resolveRelativePath()` and sets it on the `mappedBy` field. Sets `null` if no file.
- `update()`: After upload, reads the file from the `inversedBy` field and sets the relative path on the `mappedBy` field via `getPathname()`. Handles null/missing files.
- `upload()`: Dispatches `PreUploadEvent` (with `$entity`), creates a `NamingStrategyInterface` instance via `NamingStrategyFactory::create()`, delegates to `Storage::upload()`, wraps the result in a `Model\File` with resolved path and URI, then dispatches `PostUploadEvent` (with `$entity`).
- `remove(object $entity, string $storageName, ?string $relativePath)`: Resolves path/URI, dispatches `PreRemoveEvent` (with `$entity`), calls `Storage::remove()`, dispatches `PostRemoveEvent` (with `$entity`).
- `archive(string $storageName, ?string $relativePath)`: Downloads the file from storage to the archive directory via `Storage::download()`, then removes it from storage. Works with both filesystem and S3 backends.
- `inject()`: Reads the relative path from `mappedBy`, resolves to absolute path and URI via Storage, creates a `Model\File` instance and sets it on the file property.

### Storage

**StorageInterface** (`src/Storage/StorageInterface.php`): Contract for file operations: `upload`, `remove`, `resolvePath`, `resolveRelativePath`, `resolveUri`, `download`.

**FileSystemStorage** (`src/Storage/FileSystemStorage.php`): `readonly` local filesystem implementation. Constructed with `uploadPath` (absolute directory) and optional `uriPrefix` (web-accessible path prefix or CDN URL). `upload()` creates the target directory if needed, generates a filename via the naming strategy, validates the filename against path traversal (rejects `/`, `\`, `..`), moves the file to `{uploadPath}/{prefix}/`, and returns the relative path. `download()` copies the file from storage to a local target path. `resolvePath()` prepends the upload root. `resolveUri()` prepends the URI prefix (can be a CDN URL for absolute URIs).

**S3Storage** (`src/Storage/S3Storage.php`): `readonly` AWS S3 implementation. Constructed with `S3Client`, `bucket`, and optional `uriPrefix`. `upload()` puts the object to S3 and wraps `S3Exception` in a bundle `RuntimeException`. `remove()` catches `S3Exception`, returns `false` for `NoSuchKey` and rethrows any other unchanged (unwrapped), so a 3xx never reads as a missing object. `download()` uses `S3Client::getObject()` with `SaveAs` to download files to a local path; if it fails, the target file may hold a partial body or the S3 error answer's XML body (the SDK streams each attempt's body into it and nothing deletes it on error), so the caller deletes it — `Handler::archive` does not. `resolveUri()` uses `S3Client::getObjectUrl()` when no URI prefix is configured.

**S3RedirectGuard** (`src/Storage/S3RedirectGuard.php`): Middleware for an S3 storage with `http` (CI-8 R-B7). `register(S3Client)` appends one step named `S3RedirectGuard::NAME` to the client's `init` handler-list step, outside `retry` and `s3.permanent_redirect` (both in `sign`). The step turns a successful result with `@metadata.statusCode` 300–399 into exactly `S3Exception` (message with the status, `connection_error` false, the result attached; the body is not parsed, so `getAwsErrorCode()` is `null`, never `NoSuchKey`; the exception has no response, so `getStatusCode()` is `null` and the status is in `getResult()['@metadata']['statusCode']`). `retry` never sees that error, so a 3xx costs one HTTP request. A 301 is already a `PermanentRedirectException` from `s3.permanent_redirect`; the step passes rejected promises through unchanged. The extension registers it as the client definition's DI configurator only when `http` is set.

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

All events carry `$entity` (the entity object), enabling listeners to filter by entity type via `instanceof`.

**PreUploadEvent** / **PostUploadEvent** (`src/Events/`): Dispatched before/after file upload. Both carry readonly `$entity`, `$file`, and `$fieldName`. `PreUploadEvent` provides the original source file (before storage move). `PostUploadEvent` provides the resolved `Model\File` (after storage). Use `PostUploadEvent` for post-processing such as image resizing, thumbnail generation, or metadata extraction.

**PreRemoveEvent** / **PostRemoveEvent** (`src/Events/`): Dispatched before/after file deletion. Both extend `AbstractEvent` which carries readonly `$entity`, `$relativePath`, `$resolvedPath`, and `$resolvedUri`. Subscribe to these to hook into file removal (e.g., clearing CDN cache, removing thumbnails).

### Form Type

**FileType** (`src/Form/Type/FileType.php`): Compound Symfony form type for file uploads. Block prefix: `chamber_orchestra_file`. Contains a `file` child (Symfony `FileType`) and an optional `delete` checkbox. Uses `$originalFiles` map keyed by `uniqid()` for per-form-instance state to preserve the original `Model\File` across submissions. Options: `multiple`, `mime_types`, `required`, `allow_delete`, `entry_options`, `delete_options`. `POST_SET_DATA` listener stores the original file, `PRE_SUBMIT` restores it if no new upload, `POST_SUBMIT` cleans up the `$originalFiles` entry. `CallbackTransformer` handles compound-to-scalar conversion. `buildView` exposes `original_file`, `allow_delete`, `multiple` to templates. Conditionally registered only when `symfony/form` is installed (checked via `class_exists(AbstractType::class)` in the extension). The `Form` directory is excluded from autowiring in `services.php`.

### Serializer

**FileNormalizer** (`src/Serializer/Normalizer/FileNormalizer.php`): Symfony Serializer normalizer for `Model\File`. Constructed with `$baseUrl` (wired to `%env(APP_URL)%` by the bundle extension). Normalizes to an absolute URL by prepending `$baseUrl` to relative URIs. Absolute URIs (starting with `http://` or `https://`, e.g. from S3 or CDN storage) are returned as-is. Per-storage CDN is configured by setting the storage's `uri_prefix` to the CDN URL.

### Service Configuration

Services are autowired and autoconfigured via `src/Resources/config/services.php`. The `Handler` is registered as `lazy: true`. Directories excluded from autowiring: `DependencyInjection`, `Resources`, `ExceptionInterface`, `NamingStrategy`, `Model`, `Mapping`, `Entity`, `Events`, `Storage`, `Form`.

Bundle configuration key is `chamber_orchestra_file`. Storages are defined under `storages` as a named map. Each storage has: `enabled` (default `true`), `driver` (`file_system` or `s3`), `path`, `uri_prefix` (null for private storage, or a CDN URL for absolute URIs), and S3-specific `bucket`, `region`, `endpoint`, `path_style`, `credentials` (`key`, `secret`), `http` (`connect_timeout`, `timeout`: seconds, float > 0; int literals are cast to float; `.inf` or a value below 0.001 passes validation, but Guzzle 8.2 then rejects every request with `InvalidArgumentException`; `%env(float:…)%` / `%env(int:…)%` placeholders fail validation, because Symfony checks them with a dummy 0, so the README asks for literals) and `retries` (`mode`: `legacy`/`standard`/`adaptive`; `max_attempts`: int >= 1). S3 storages require `bucket` and `region` (validated at the Configuration tree level). `http` and `retries` are optional blocks without defaults: if a block is set, every subkey is required, and a missing, unknown or invalid subkey throws `InvalidConfigurationException` with the key path; an unset block stays absent from the processed config and is not passed to `S3Client`, so without both blocks the client arguments are exactly those of 8.1.4 (`region`, `version`, then `endpoint`, `use_path_style_endpoint`, `credentials` when set; `http`, `retries` follow). A set `http` block also passes `allow_redirects: false` and `curl: [\CURLOPT_FORBID_REUSE => true]` in the client's `http` argument (so `http` is exactly `connect_timeout`, `timeout`, `allow_redirects`, `curl`) and sets the configurator `[S3RedirectGuard::class, 'register']` on the client definition; without `http` the definition has none of them (no `allow_redirects`, no `curl`, no configurator, no method calls). The `file_system` driver accepts and ignores both blocks (an `s3` base merged with a `file_system` override keeps them). The timeouts alone do not bound an operation. They need static `credentials` (otherwise the SDK provider chain runs outside them), Guzzle's curl handler and an asynchronous DNS resolver, and two client settings, both closed for a storage with `http`: redirects (`allow_redirects: false` + `S3RedirectGuard`, R-B7) and connection reuse (`CURLOPT_FORBID_REUSE`, R-B8: every request opens a new TCP (and TLS) connection, so Guzzle never re-sends a request on a dead reused connection and one SDK attempt is one transfer). A storage with `http` therefore requires ext-curl: the extension reads `\CURLOPT_FORBID_REUSE` in `load()`, so without ext-curl compiling the container fails with the `Error` `Undefined constant "CURLOPT_FORBID_REUSE"` (no separate check; a storage without `http` does not need ext-curl). Requests with the `stream` request option to such a client fail: Guzzle's `StreamHandler` rejects the `curl` option (`InvalidArgumentException`). The `default_storage` option selects which storage is the default (if omitted, the first enabled storage is used). The `archive_path` option (default `%kernel.project_dir%/var/archive`) sets the directory for `Behaviour::Archive`. The `FileNormalizer` receives `%env(APP_URL)%` as its base URL for resolving relative URIs. Entities select storage via `#[Uploadable(storage: 'name')]`.

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

- Requires PHP 8.5, Symfony 8.1 components (`dependency-injection`, `config`, `framework-bundle`, `runtime`, `options-resolver`), and `chamber-orchestra/metadata-bundle` 8.1
- Dev: PHPUnit 13, `symfony/test-pack`, `symfony/mime`, `symfony/serializer`, `symfony/form`, `symfony/validator`, `aws/aws-sdk-php`, `friendsofphp/php-cs-fixer`, `phpstan/phpstan`
- Suggests: `aws/aws-sdk-php` (for S3 storage driver), `symfony/form` (for FileType form type), `symfony/validator` (for file validation constraints)
- Main branch is `main`

## Testing Conventions

- Use music thematics for test fixtures and naming (e.g., entity names like `Composition`, `Instrument`, `Rehearsal`, `Score`; file names like `symphony_no_5.pdf`, `violin_concerto.mp3`, `moonlight_sonata.jpg`; prefixes like `scores`, `recordings`)
- S3 fault tests (`tests/Integrational/Storage/S3StorageFaultTest.php`) run each storage operation in a child process (`tests/Support/FakeS3/operation.php`: builds the storage through the extension, counts SDK attempts in the `attempt` step, prints one JSON line) against a fake TCP server in another child process (`server.php`: scenarios `silent`, `drip`, `unavailable_then_ok` (503 `ServiceUnavailable`, then 200), `no_such_key` (404) and `keep_alive` (below); journals every HTTP request as JSONL with its connection number, body length and sha256; answers with `Connection: close` except in `keep_alive`). The 503→200 test uploads 1 040 000 random bytes, so Guzzle streams the body (≥ 1 000 000 B) without `Expect: 100-Continue` (< 1 048 576 B), and checks the length and sha256 of the retried PUT's body; the 404 test checks one attempt and the kept `NoSuchKey` code. These two are ordinary regression tests: unlike the timeout tests, they do not need a client with timeouts to pass. `FakeS3Harness` kills the operation 10·T_test (T_test = 0.5 s) after `operation.php` prints `ready` (just before the operation starts; child start-up has its own 10·T_test deadline), so a client without timeouts fails instead of hanging; it also fails when the child exits non-zero or prints no JSON result. The A = 2 tests use one upper bound, A·T_test + 2 s + 1 s = 4 s; the single-attempt (A = 1) test uses T_test + 1 s. The server also closes each connection after 10·T_test and then stops accepting. The connect-timeout endpoint is a listener with a full backlog-1 accept queue on Linux and `192.0.2.1:81` (RFC 5737) on macOS, where a full queue answers RST; if a raw connection there does not time out, the test fails with an environment message and never skips. The connect-timeout test also fails (never skips) on libcurl < 8.11: older libcurl words a connect timeout as "Failed to connect … Timeout was reached", which Guzzle classifies as `NetworkTimeoutException`, not `ConnectTimeoutException`. CI (`.github/workflows/php.yml`) therefore runs the job in the `php:8.5-cli-trixie` container (libcurl 8.14), not on the runner's Ubuntu 24.04 (libcurl 8.5). The class takes about 30 s. `tests/Integrational/Storage/S3StorageRedirectTest.php` uses the same harness with the server scenario `redirect_<status>` (an empty-body 3xx, with `Location` when the harness passes one) and a second harness as the silent `Location` target: for PUT and GET, 301 + `Location` gives `PermanentRedirectException`, 307 + `Location` and 307/302 without it give exactly `S3Exception` without a code, each after one request to the redirecting server, none to the target, one SDK attempt, within the bound. `tests/Integrational/Storage/S3StorageKeepAliveTest.php` (AC-20, R-B8) uses the scenario `keep_alive`: request 1 gets `200` with an empty body and `Connection: keep-alive`; after the complete body of request 2 the server waits 0.8·T_test and closes the connection request 2 came on, unanswered; requests 3+ get no answer. `runOperation(..., warmUp: true)` makes `operation.php` call `download()` once before `ready` (outside the guard and the attempt count), then the test uploads 1 040 000 B and asserts every HTTP request on its own connection, PUT requests = SDK attempts ≤ A, the 4 s bound and a connection-error `S3Exception` without `NoSuchKey`. Without R-B8 it is red: 4 requests on 3 connections (the PUT reuses the warm-up connection, Guzzle re-sends it on a new one, then SDK attempt 2), 3 PUTs for 2 attempts. `FakeS3Harness` holds the shared test values: `T_TEST`, `C_TEST`, `ATTEMPTS`, the one time bound `UPPER_BOUND` (4 s) and the storage config `storage()`.
