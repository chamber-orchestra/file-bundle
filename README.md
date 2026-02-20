# ChamberOrchestra File Bundle

[![PHP Composer](https://github.com/chamber-orchestra/file-bundle/actions/workflows/php.yml/badge.svg)](https://github.com/chamber-orchestra/file-bundle/actions/workflows/php.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-brightgreen.svg)](https://phpstan.org/)
[![PHP-CS-Fixer](https://img.shields.io/badge/code%20style-PER--CS%20%2F%20Symfony-blue.svg)](https://cs.symfony.com/)
[![Latest Stable Version](https://img.shields.io/packagist/v/chamber-orchestra/file-bundle.svg)](https://packagist.org/packages/chamber-orchestra/file-bundle)
[![Total Downloads](https://img.shields.io/packagist/dt/chamber-orchestra/file-bundle.svg)](https://packagist.org/packages/chamber-orchestra/file-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-777BB4.svg)](https://www.php.net/)
[![Symfony 8.0](https://img.shields.io/badge/Symfony-8.0-000000.svg)](https://symfony.com/)

A Symfony bundle for automatic file upload and image upload handling on Doctrine ORM entities. Mark your entity with PHP attributes, and the bundle transparently uploads, injects, and removes files through Doctrine lifecycle events.

Supports local filesystem and Amazon S3 storage backends, multiple named storages, CDN integration, pluggable naming strategies, file archiving, and Doctrine embeddables.

### Features

- **Automatic file uploads** via Doctrine lifecycle events — no manual upload logic
- **Multiple storage backends** — local filesystem, Amazon S3, MinIO
- **Per-entity storage** — different entities can use different storages
- **CDN support** — serve files through CloudFront, Cloudflare, or any CDN
- **Private/secure storage** — store files outside the web root with controlled access
- **File archiving** — archive files before deletion instead of permanent removal
- **Image support** — dimensions, EXIF metadata, orientation detection
- **Doctrine embeddables** — uploadable fields inside embedded objects
- **Pluggable naming strategies** — hashing (default), original name, or custom
- **Symfony Form integration** — compound FileType with delete checkbox and file preservation
- **Symfony Serializer integration** — normalizes files to absolute URLs

## Requirements

- PHP 8.5+
- Symfony 8.0
- Doctrine ORM

## Installation

```bash
composer require chamber-orchestra/file-bundle
```

For S3 storage support:

```bash
composer require aws/aws-sdk-php
```

## Quick Start

### 1. Configure the bundle

```yaml
# config/packages/chamber_orchestra_file.yaml
chamber_orchestra_file:
    storages:
        default:
            driver: file_system
            path: '%kernel.project_dir%/public/uploads'
            uri_prefix: '/uploads'
```

### 2. Add attributes to your entity

```php
use ChamberOrchestra\FileBundle\Mapping\Attribute\Uploadable;
use ChamberOrchestra\FileBundle\Mapping\Attribute\UploadableProperty;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity]
#[Uploadable]
class Composition
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[UploadableProperty(mappedBy: 'scorePath')]
    private ?File $score = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $scorePath = null;

    // getters and setters...

    public function getScore(): ?File
    {
        return $this->score;
    }

    public function setScore(?File $score): void
    {
        $this->score = $score;
    }
}
```

### 3. Upload a file

```php
use Symfony\Component\HttpFoundation\File\UploadedFile;

$composition = new Composition();
$composition->setTitle('Symphony No. 5');
$composition->setScore($uploadedFile);

$entityManager->persist($composition);
$entityManager->flush();
```

That's it. The bundle handles the rest:
- Moves the file to the configured storage path
- Persists the relative path in `scorePath`
- On subsequent loads, injects a `Model\File` object with the resolved path and URI

### 4. Access the file

After loading the entity from the database, the `score` property holds a `ChamberOrchestra\FileBundle\Model\File` instance:

```php
$composition = $entityManager->find(Composition::class, 1);

$file = $composition->getScore();
$file->getUri();      // "/uploads/symphony_no_5_a1b2c3.pdf"
$file->getPathname(); // "/var/www/public/uploads/symphony_no_5_a1b2c3.pdf"
```

## Configuration

The bundle supports multiple named storages. Each storage is defined under the `storages` key and can use different drivers and settings.

### Local Filesystem

```yaml
chamber_orchestra_file:
    storages:
        default:
            driver: file_system
            path: '%kernel.project_dir%/public/uploads'
            uri_prefix: '/uploads'
```

### Amazon S3

```yaml
chamber_orchestra_file:
    storages:
        default:
            driver: s3
            bucket: my-recordings-bucket
            region: us-east-1
            uri_prefix: '/uploads'        # optional, uses S3 URLs if omitted
            endpoint: 'http://localhost:9000' # optional, for MinIO/localstack
```

### Multiple Storages

Define as many storages as you need. Each entity can select which storage to use via the `#[Uploadable]` attribute:

```yaml
chamber_orchestra_file:
    default_storage: public          # optional, first enabled storage is used if omitted
    storages:
        public:
            driver: file_system
            path: '%kernel.project_dir%/public/uploads'
            uri_prefix: '/uploads'
        secure:
            driver: file_system
            path: '%kernel.project_dir%/var/share'
        archive:
            driver: s3
            bucket: orchestra-archive
            region: eu-west-1
```

When only one storage is defined, it becomes the default automatically.

### Secure Storage (private files)

For files that should not be publicly accessible (contracts, invoices, internal documents), define a storage without a `uri_prefix`:

```yaml
chamber_orchestra_file:
    storages:
        default:
            driver: file_system
            path: '%kernel.project_dir%/public/uploads'
            uri_prefix: '/uploads'
        secure:
            driver: file_system
            path: '%kernel.project_dir%/var/share'
```

Files stored via a storage with no URI prefix have `getUri()` returning `null`. To serve them, use a controller that reads the file and streams the response with appropriate access control:

```php
#[Uploadable(storage: 'secure', prefix: 'contracts')]
class Contract
{
    #[UploadableProperty(mappedBy: 'documentPath')]
    private ?File $document = null;

    #[ORM\Column(nullable: true)]
    private ?string $documentPath = null;
}
```

### Disabling a Storage

A storage can be temporarily disabled without removing its configuration:

```yaml
chamber_orchestra_file:
    storages:
        default:
            driver: file_system
            path: '%kernel.project_dir%/public/uploads'
            uri_prefix: '/uploads'
        staging:
            enabled: false
            driver: s3
            bucket: staging-uploads
            region: us-east-1
```

### Configuration Reference

| Option | Type | Default | Description |
|---|---|---|---|
| `default_storage` | `string\|null` | `null` | Name of the default storage. If null, the first enabled storage is used |
| `storages` | `map` | required | Named storage definitions (at least one required) |
| `storages.*.enabled` | `bool` | `true` | Whether this storage is active |
| `storages.*.driver` | `string` | `file_system` | Storage driver: `file_system` or `s3` |
| `storages.*.path` | `string` | `%kernel.project_dir%/public/uploads` | Filesystem path (file_system driver) |
| `storages.*.uri_prefix` | `string\|null` | `null` | Public URI prefix. Null means files are not web-accessible |
| `storages.*.bucket` | `string\|null` | `null` | S3 bucket name (required for s3 driver) |
| `storages.*.region` | `string\|null` | `null` | AWS region (required for s3 driver) |
| `storages.*.endpoint` | `string\|null` | `null` | Custom S3 endpoint for MinIO/localstack |
| `archive_path` | `string` | `%kernel.project_dir%/var/archive` | Local directory for archived files (`Behaviour::Archive`) |

## Entity Attributes

### `#[Uploadable]`

Applied to the entity class. Options:

| Option | Type | Default | Description |
|---|---|---|---|
| `prefix` | `string` | `''` | Subdirectory within the storage path |
| `namingStrategy` | `string` | `HashingNamingStrategy::class` | Class implementing `NamingStrategyInterface` |
| `behaviour` | `Behaviour` | `Behaviour::Remove` | What happens to files on entity update/delete |
| `storage` | `string` | `'default'` | Named storage backend to use |

```php
use ChamberOrchestra\FileBundle\Mapping\Attribute\Uploadable;
use ChamberOrchestra\FileBundle\Mapping\Helper\Behaviour;
use ChamberOrchestra\FileBundle\NamingStrategy\OriginNamingStrategy;

#[Uploadable(
    prefix: 'scores',
    namingStrategy: OriginNamingStrategy::class,
    behaviour: Behaviour::Keep,
    storage: 'archive',
)]
class Score
{
    // ...
}
```

### `#[UploadableProperty]`

Applied to file properties. Options:

| Option | Type | Description |
|---|---|---|
| `mappedBy` | `string` | Name of the string property that stores the relative file path |

The `mappedBy` property must exist on the same class and be a Doctrine-mapped column.

## Behaviour

The `Behaviour` enum controls what happens to files when an entity is updated or deleted:

- `Behaviour::Remove` (default) — old files are deleted from storage after a successful flush
- `Behaviour::Keep` — old files remain in storage (useful for audit trails or versioning)
- `Behaviour::Archive` — old files are moved to a local archive directory before being removed from storage

### Archiving

When using `Behaviour::Archive`, files are downloaded from their storage backend (including S3) and saved to a local archive directory before deletion. Configure the archive path:

```yaml
chamber_orchestra_file:
    archive_path: '%kernel.project_dir%/var/archive'   # default
    storages:
        default:
            driver: file_system
            path: '%kernel.project_dir%/public/uploads'
            uri_prefix: '/uploads'
```

```php
#[Uploadable(behaviour: Behaviour::Archive, prefix: 'contracts')]
class Contract
{
    // Files are archived to var/archive/contracts/ before removal
}
```

## Naming Strategies

### `HashingNamingStrategy` (default)

Generates a unique filename using MD5 hash with random bytes, preserving the guessed file extension:

```
a1b2c3d4e5f67890abcdef1234567890.pdf
```

### `OriginNamingStrategy`

Preserves the original filename as uploaded by the client. Automatically appends a version suffix (`_1`, `_2`, etc.) when a file with the same name already exists in the target directory:

```
moonlight_sonata.pdf
moonlight_sonata_1.pdf   # if moonlight_sonata.pdf already exists
moonlight_sonata_2.pdf   # if _1 also exists
```

### Custom Naming Strategy

Implement `NamingStrategyInterface`:

```php
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyInterface;
use Symfony\Component\HttpFoundation\File\File;

class TimestampNamingStrategy implements NamingStrategyInterface
{
    public function name(File $file, string $targetDir = ''): string
    {
        return \time() . '_' . \bin2hex(\random_bytes(4)) . '.' . $file->guessExtension();
    }
}
```

Then reference it in the attribute:

```php
#[Uploadable(namingStrategy: TimestampNamingStrategy::class)]
```

## Entity Traits

The bundle provides convenience traits for common file/image patterns:

```php
use ChamberOrchestra\FileBundle\Entity\FileTrait;
use ChamberOrchestra\FileBundle\Mapping\Attribute\Uploadable;

#[ORM\Entity]
#[Uploadable(prefix: 'recordings')]
class Recording
{
    use FileTrait; // adds $file + $filePath + getFile()

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
}
```

Available traits:

| Trait | Properties | Nullable |
|---|---|---|
| `FileTrait` | `$file` / `$filePath` | Yes |
| `RequiredFileTrait` | `$file` / `$filePath` | No |
| `ImageTrait` | `$image` / `$imagePath` | Yes |
| `RequiredImageTrait` | `$image` / `$imagePath` | No |

## Multiple File Fields

An entity can have multiple uploadable properties:

```php
#[ORM\Entity]
#[Uploadable(prefix: 'compositions')]
class Composition
{
    #[UploadableProperty(mappedBy: 'scorePath')]
    private ?File $score = null;

    #[ORM\Column(nullable: true)]
    private ?string $scorePath = null;

    #[UploadableProperty(mappedBy: 'recordingPath')]
    private ?File $recording = null;

    #[ORM\Column(nullable: true)]
    private ?string $recordingPath = null;
}
```

## Doctrine Embeddables

The bundle supports uploadable fields inside Doctrine embeddables:

```php
#[ORM\Embeddable]
#[Uploadable(prefix: 'media')]
class MediaEmbed
{
    #[UploadableProperty(mappedBy: 'coverPath')]
    private ?File $cover = null;

    #[ORM\Column(nullable: true)]
    private ?string $coverPath = null;
}

#[ORM\Entity]
#[Uploadable]
class Album
{
    #[ORM\Embedded(class: MediaEmbed::class)]
    private MediaEmbed $media;
}
```

## Image Support

`Model\File` includes `ImageTrait` with image-specific helpers:

```php
$image = $album->getCover();

if ($image->isImage()) {
    $image->getWidth();           // 1920
    $image->getHeight();          // 1080
    $image->getRatio();           // 1.78
    $image->getOrientation();     // EXIF orientation value
    $image->getOrientationAngle(); // 90, 180, -90, or 0
    $image->getMetadata();        // Full EXIF data array
}
```

## Events

The bundle dispatches events around file upload and removal, allowing you to hook in for image processing, cache clearing, thumbnail cleanup, CDN invalidation, etc.

All events carry `$entityClass` — the FQCN of the entity that triggered the event. This allows listeners to target specific entity types.

| Event | Dispatched |
|---|---|
| `PreUploadEvent` | Before a file is uploaded to storage |
| `PostUploadEvent` | After a file is uploaded to storage |
| `PreRemoveEvent` | Before a file is deleted from storage |
| `PostRemoveEvent` | After a file is deleted from storage |

### Upload Events

Upload events carry `$entityClass`, `$entity`, `$file`, and `$fieldName`. Use `PostUploadEvent` for post-processing like image resizing:

```php
use ChamberOrchestra\FileBundle\Events\PostUploadEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class ImageResizeListener
{
    public function __invoke(PostUploadEvent $event): void
    {
        // Only process images for specific entity types
        if (Album::class !== $event->entityClass) {
            return;
        }

        if ($event->file->isImage()) {
            $this->resizer->resize($event->file->getPathname(), 1920, 1080);
        }
    }
}
```

### Removal Events

Removal events carry `$entityClass`, `$relativePath`, `$resolvedPath`, and `$resolvedUri`:

```php
use ChamberOrchestra\FileBundle\Events\PreRemoveEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class ThumbnailCleanupListener
{
    public function __invoke(PreRemoveEvent $event): void
    {
        // Clear thumbnails for the file being removed
        $this->thumbnailService->purge($event->relativePath);
    }
}
```

## Form Type

The bundle provides a `FileType` form type for handling file uploads in Symfony forms. It is a compound form with a file input and an optional delete checkbox that preserves the original `Model\File` across submissions.

```bash
composer require symfony/form symfony/validator
```

### Basic Usage

```php
use ChamberOrchestra\FileBundle\Form\Type\FileType;

$builder->add('score', FileType::class);
```

### Options

| Option | Type | Default | Description |
|---|---|---|---|
| `multiple` | `bool` | `false` | Allow multiple file uploads |
| `mime_types` | `array` | `[]` | Allowed MIME types (sets `accept` attribute and validation) |
| `required` | `bool` | `false` | Whether a file is required |
| `allow_delete` | `bool` | `true` | Show a delete checkbox (only when `required` is `false`) |
| `entry_options` | `array` | `[]` | Options passed to the inner Symfony `FileType` |
| `delete_options` | `array` | `[]` | Options passed to the delete `CheckboxType` |

### With MIME Type Restriction

```php
$builder->add('score', FileType::class, [
    'mime_types' => ['application/pdf'],
    'required' => true,
]);
```

### Template Variables

The form view exposes:
- `original_file` — the original `Model\File` instance (for displaying the current file)
- `allow_delete` — whether the delete checkbox is shown
- `multiple` — whether multiple uploads are allowed

## Serializer Integration

The bundle includes a Symfony Serializer normalizer for `Model\File` that outputs an absolute URL.

The normalizer uses the `APP_URL` environment variable as the base URL:

```php
$serializer->normalize($composition);
// "score" => "https://example.com/uploads/scores/a1b2c3.pdf"
```

When a file's URI is already an absolute URL (common with S3 or CDN storages), the normalizer returns it as-is without prepending the base:

```php
// S3 storage with no uri_prefix — URI is already absolute
$serializer->normalize($recording);
// "score" => "https://my-bucket.s3.amazonaws.com/scores/a1b2c3.pdf"

// Storage with CDN uri_prefix — URI is already absolute
// uri_prefix: 'https://cdn.example.com/uploads'
$serializer->normalize($recording);
// "score" => "https://cdn.example.com/uploads/scores/a1b2c3.pdf"
```

### CDN Support

To serve files through a CDN, set the storage's `uri_prefix` to the CDN base URL:

```yaml
chamber_orchestra_file:
    storages:
        default:
            driver: file_system
            path: '%kernel.project_dir%/public/uploads'
            uri_prefix: 'https://cdn.example.com/uploads'
```

Files will be injected with the CDN URL as their URI, and the serializer will pass it through unchanged.

## Security

The default storage path (`%kernel.project_dir%/public/uploads`) is inside the web root. If your web server is configured to execute scripts (PHP, Python, etc.) from that directory, an uploaded file could be executed via HTTP.

**Disable script execution** in your upload directories:

Nginx:

```nginx
location /uploads {
    location ~ \.(php|phtml|php[0-9])$ {
        deny all;
    }
}
```

Apache (`.htaccess` in the uploads directory):

```apache
<FilesMatch "\.(php|phtml|php[0-9])$">
    Require all denied
</FilesMatch>
```

Alternatively, store files **outside the web root** by using a storage path like `%kernel.project_dir%/var/files` with no `uri_prefix`, and serve them through a controller with access control.

## License

MIT License. See [LICENSE](LICENSE) for details.
