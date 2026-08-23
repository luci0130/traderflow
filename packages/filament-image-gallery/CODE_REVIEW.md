# Code Review — `turiac/filament-image-gallery`

**Reviewed version:** `0.1.0` (working tree at `packages/filament-image-gallery/`)
**Review date:** 2026-08-19
**Environment verified against:** PHP 8.5.0, Laravel 13.25.0, Livewire 4.4.0, Filament 5.x, Flysystem 3.x, GD + EXIF + fileinfo enabled.

**Scope:** every file in the package (17 PHP files, 5 Blade views, 2 JS files, 1 migration, 1 config, 2 language files) plus the execution paths through Filament `Repeater`/`BaseFileUpload`, Livewire `SupportFileUploads`, Flysystem and the consuming application (`app/Modules/**`) where behaviour depends on them.

---

## 1. Executive Summary

### Overall code quality — **Good, above average for a first-party package**

The package is written by someone who clearly knows Filament v5 internals. It uses the right extension points (`saveRelationshipsUsing`, `loadStateFromRelationshipsUsing`, `#[ExposedLivewireMethod]`, `registerActions`, `actionJs`), types nearly everything, uses `readonly` value objects, and hides an interface (`GalleryRepository`) behind the persistence layer. The three-table schema (galleries / images / roles) with a partial-unique role constraint is a genuinely good design — better than storing a `is_primary` boolean.

The main quality problems are concentration and hygiene: `ImageGallery.php` is **1,392 lines** doing schema construction, state reconciliation, role arbitration, URL generation, formatting, image resizing orchestration and JS generation. And the package ships **zero tests**, no README, no LICENSE file, no `phpunit.xml`, no static analysis config, and no CI.

### Security assessment — **NOT production-ready. One critical class of vulnerability.**

The SSRF defence in `RemoteImageDownloader` is genuinely well built (HTTPS-only, port pinning, DNS pre-resolution, private/reserved range rejection, `CURLOPT_RESOLVE` rebinding protection, manual redirect re-validation). SVG is correctly excluded everywhere. There is no SQL injection and no unescaped Blade output.

But the package **trusts the browser-supplied `path` and `disk` values** that live in Livewire state. Filament's own source explicitly warns about this (`vendor/filament/forms/src/Components/BaseFileUpload.php:117-119`: *"It is therefore not a security boundary for submitted paths"*). Because this package additionally **persists those paths to its own table and later deletes the referenced files**, that trust converts into arbitrary file deletion and cross-disk file disclosure. See C-1, C-2, C-3.

### Performance assessment — **Fine at the default cap (10), degrades badly above ~100**

At the shipped default `max_images: 10` there is no meaningful performance problem: `stateFor()` is properly eager-loaded (4 queries), unchanged images produce zero writes and zero filesystem calls on save, and `isDirty()` guards updates. Above ~100 images the O(n²) `reconcileLoadedState()`, the per-item `getRoles()` re-evaluation, the N presigned-URL generations, and the raw Livewire payload size all become real. The Repeater architecture keeps every image's full metadata in the Livewire snapshot on every request, which is a hard ceiling.

### Laravel / Livewire / Filament compatibility — **Works today, needlessly narrow, two undeclared dependencies**

Every Filament v5 API used was verified to exist (`Slider`, `Section::secondary()`, `Action::actionJs()`, `makeModalSubmitAction()`, `stickyModalHeader()`, `extraModalWindowAttributes()`, `replaceMountedAction()`, `shouldPartiallyRenderAfterActionsCalled()`, `ExposedLivewireMethod`). Nothing deprecated is used.

The problems are in `composer.json`: `php: ^8.5` locks out ~99% of the Laravel ecosystem, `illuminate/*: ^13.0` excludes Laravel 11/12 that Filament v5 itself supports, `filament/filament` is used but not required, and `ext-curl` (load-bearing for the SSRF defence) is not required. Two Livewire *internal* classes are used directly.

### The 5 most important improvements

| # | Improvement | Why |
|---|---|---|
| 1 | **Validate submitted `path` against a server-owned allowlist** (C-1) | An authenticated editor can make the gallery delete any file on the disk, including other tenants' images. |
| 2 | **Stop taking `disk` from Livewire state** (C-2, C-3) | Lets a user mint presigned URLs for arbitrary objects on any configured disk, and copy private files into the public gallery. |
| 3 | **Ship a test suite** (T-1) | Zero tests today. Every fix below is unverifiable and every future Filament release is a blind upgrade. |
| 4 | **Fix `composer.json`** (H-1, H-2, H-3) | `php: ^8.5` + missing `filament/filament` + missing `ext-curl` make it uninstallable for almost everyone and silently disable the SSRF pinning where curl is absent. |
| 5 | **Guard the soft-delete hook and the exposed Livewire method** (H-4, H-6) | Soft-deleting an owner permanently destroys its image files. `promotePendingUploaderFiles()` bypasses `isAddable()`. |

### Release verdict

> **Not ready to release publicly.** The security fixes (C-1 … C-3) and the packaging fixes (H-1 … H-3) are mandatory. With those plus a test suite it becomes a strong, differentiated package — the role system, focal point, remote-URL ingestion and in-modal editor are features no other Filament gallery package combines.

---

## 2. Findings

## CRITICAL

---

### [CRITICAL] C-1 — Browser-controlled `path` allows arbitrary file deletion and file "adoption" on the storage disk

**Location:**
`src/Services/GalleryPersister.php:105` (`persist()` → `pathFrom()`), `src/Services/GalleryPersister.php:110`, `src/Services/GalleryPersister.php:151-154`, `src/Services/GalleryPersister.php:284-291` (`pathFrom()`)
`src/Forms/Components/ImageGallery.php:568-584` (`makeFileUpload()` — the `path` field)

**Problem:**

Each gallery item's `path` is the state of a Filament `FileUpload` field, bound to `data.{field}.{itemKey}.path`. Filament's `BaseFileUpload::hydrateFiles()` only checks disk existence when state is hydrated *from the record* on initial load; Filament's own source comments this explicitly:

```php
// vendor/filament/forms/src/Components/BaseFileUpload.php:116-119
// This disk-existence check only runs when state is hydrated from the record
// (initial form load), not when state is later updated from a Livewire request.
// It is therefore not a security boundary for submitted paths
```

Any subsequent `$wire.set('data.images.<key>.path', ['some/other/file.webp'])` is accepted verbatim. `pathFrom()` (line 284) returns the first string it finds with no validation at all, and `persist()` writes it straight into `image_gallery_images.path`.

Two consequences, both reachable by any user who can edit *one* record with a gallery:

1. **Arbitrary file deletion.** Point an item at another record's (or another tenant's) file, save — a row now references the victim's file. Remove that item, save again. `persist()` collects removed images at line 151-154 and unconditionally runs `Storage::disk($fileDisk)->delete($path)` at line 190. The victim's file is gone. The same works via the "replace" path at line 110 (`$image->path !== $path` → old file queued for deletion), except there the queued path is the DB value — the *first* save is what plants the hostile path.
2. **File adoption / disclosure.** Any readable object on the gallery disk can be referenced and rendered by the gallery UI, and `metadataFor()` (line 252) will `Storage::get()` its full contents to probe it.

The `(gallery_id, path)` unique index does **not** prevent this — it is scoped per gallery, so two galleries may legally claim the same path.

Path traversal *above* the disk root is blocked (Flysystem's `WhitespacePathNormalizer` throws `PathTraversalDetected` on `..`, verified at `vendor/league/flysystem/src/WhitespacePathNormalizer.php:38-41`), so this is confined to the configured disk — but that disk is `public` by default and shared by every tenant.

**Impact:**
Destructive, cross-tenant, and silent. In this application `directory()` is `products/{tenantId}` but nothing enforces that the submitted path stays inside it — a producer can delete an admin's product images. Rated CRITICAL because it is data loss triggered by a normal authenticated user with no unusual privileges.

**Recommendation:**
Validate every submitted path server-side inside `persist()`, before it is trusted. Three complementary checks, cheapest first:

1. **Prefix check** — the path must live under the component's configured `directory()`. Pass the directory into `persist()`.
2. **Shape check** — the basename must match the UUID+extension format `ImageProcessor` generates. This is what actually distinguishes "a file this component created" from "any other file".
3. **Ownership check** — no *other* gallery image row may already reference `(disk, path)`.

**Suggested change:**

```php
// src/Contracts/GalleryRepository.php — add $directory to the signature (BC break, see note)
public function persist(
    Model $owner,
    string $collection,
    array $state,
    array $configuredRoles,
    string $disk,
    string $visibility,
    string $directory,
): void;
```

```php
// src/Services/GalleryPersister.php
private const PATH_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|jpeg|png|webp|gif)$/i';

/**
 * A submitted path is only trusted when it lives inside the component's own
 * directory, carries the generated UUID filename, and is not already claimed
 * by another gallery image.
 */
private function assertPathIsOwned(string $path, string $disk, string $directory, ?GalleryImage $existing): void
{
    if ($existing !== null && $existing->path === $path) {
        return; // Unchanged, already validated when it was first stored.
    }

    $directory = trim($directory, '/');

    if ($directory !== '' && ! str_starts_with($path, $directory.'/')) {
        throw ValidationException::withMessages([
            'images' => __('filament-image-gallery::gallery.validation.invalid_image'),
        ]);
    }

    if (! preg_match(self::PATH_PATTERN, basename($path))) {
        throw ValidationException::withMessages([
            'images' => __('filament-image-gallery::gallery.validation.invalid_image'),
        ]);
    }

    $isClaimed = GalleryImage::query()
        ->where('disk', $disk)
        ->where('path', $path)
        ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
        ->exists();

    if ($isClaimed) {
        throw ValidationException::withMessages([
            'images' => __('filament-image-gallery::gallery.validation.invalid_image'),
        ]);
    }
}
```

Call it in `persist()` immediately after `$path = $this->pathFrom(...)` (line 105). Add a supporting index so the ownership check is cheap:

```php
// database/migrations/..._create_filament_image_gallery_tables.php
$table->index(['disk', 'path']);
```

**Trade-offs / BC:**
Adding `$directory` to the `GalleryRepository::persist()` signature is a **backwards-incompatible interface change**. The consuming application calls it directly at `app/Modules/Supermarkets/Filament/Pages/ReviewPricePhotos.php:448` and would need updating. If BC must be preserved for 0.x, add it as an optional trailing parameter `string $directory = ''` and skip the prefix check when empty — but then document loudly that the prefix check is the primary defence. The shape and ownership checks work without it and should be unconditional.

---

### [CRITICAL] C-2 — Browser-controlled `disk` in `getPreviewUrl()` mints presigned URLs for arbitrary objects on any configured disk

**Location:** `src/Forms/Components/ImageGallery.php:1067-1077` (`getPreviewUrl()`), reached from `resources/views/forms/components/image-gallery.blade.php:115` and `src/Forms/Components/ImageGallery.php:1292,1299` (`editorPreviewData()`)

**Problem:**

```php
// src/Forms/Components/ImageGallery.php:1067
$storage = Storage::disk($state['disk'] ?? $this->getDisk());

if (($state['visibility'] ?? $this->getVisibility()) === 'private') {
    return $storage->temporaryUrl($file, now()->addMinutes(...));
}

return $storage->url($file);
```

Both `disk` and `visibility` are `Hidden` fields in the item schema (`src/Forms/Components/ImageGallery.php:400,401`), so both are ordinary Livewire state under `data.{field}.{itemKey}.*` and both are writable from the browser.

An attacker sets `disk` to any *other* configured disk name (`s3`, `s3-private`, `backups`, …), `visibility` to `private`, and `path` to any object key. `getPreviewUrl()` is then called during render (blade line 115) and returns a **valid presigned S3 URL** for that object, which is rendered into the page as `<img src>` and is also visible in the Livewire response body. For S3 that is a working, time-limited read of any object in the bucket — application backups, other tenants' private uploads, exports.

For the local driver `temporaryUrl()` throws instead (unsupported), which surfaces as a 500 — a lesser but still user-triggerable availability problem, since an unknown disk name also throws `InvalidArgumentException: Disk [x] does not have a configured driver.`

**Impact:**
Arbitrary read of any object on any filesystem disk the application has configured, by any user who can open a form containing this field. Severity depends on what other disks exist, which is out of the package's control — so the package must not offer the choice at all.

**Recommendation:**
Never resolve a disk from state. The persisted disk of an existing image is already known server-side; the state copy exists only for display. Restrict to the component's own disk plus the disk actually recorded on the row.

**Suggested change:**

```php
// src/Forms/Components/ImageGallery.php

/**
 * The disk is resolved from the component configuration, never from Livewire
 * state, which the browser can rewrite.
 */
private function resolveDisk(array $state): string
{
    $disk = $this->getDisk();

    if (blank($state['_id'] ?? null)) {
        return $disk;
    }

    return $this->persistedDisks()[(int) $state['_id']] ?? $disk;
}

/**
 * @return array<int, string> Image id => disk, memoised per request.
 */
private function persistedDisks(): array
{
    return $this->persistedDisks ??= GalleryImage::query()
        ->whereIn('id', array_filter(array_column($this->realItems($this->getRawState() ?? []), '_id')))
        ->pluck('disk', 'id')
        ->all();
}

public function getPreviewUrl(array $state): ?string
{
    $file = collect(Arr::wrap($state['path'] ?? null))->first(
        fn (mixed $item): bool => is_string($item) || $item instanceof TemporaryUploadedFile,
    );

    if ($file instanceof TemporaryUploadedFile) {
        return $file->temporaryUrl();
    }

    if (! is_string($file) || blank($file)) {
        return null;
    }

    $storage = Storage::disk($this->resolveDisk($state));

    if ($this->getVisibility() === 'private') {
        return rescue(fn (): string => $storage->temporaryUrl(
            $file,
            now()->addMinutes((int) config('filament-image-gallery.temporary_url_expiration', 60)),
        ), fn (): string => $storage->url($file), report: false);
    }

    return $storage->url($file);
}
```

The simplest acceptable fix — if you do not want the extra query — is to drop the state lookup entirely and always use `$this->getDisk()` / `$this->getVisibility()`. That is correct for every gallery that has not changed its configured disk mid-life, which is the overwhelming majority.

**Trade-offs:** Galleries whose `disk()` changed after images were stored would render broken previews under the "always use configured disk" variant. The memoised-lookup variant above avoids that at the cost of one extra query per render.

---

### [CRITICAL] C-3 — `resizeStoredImages()` reads from a browser-controlled disk and path, copying arbitrary files into the public gallery

**Location:** `src/Forms/Components/ImageGallery.php:801-858`, specifically lines 815-826

**Problem:**

```php
// src/Forms/Components/ImageGallery.php:813-826
if (blank($item['_id'] ?? null)) {
    continue;
}

$path = $this->fileIdentity($item['path'] ?? null);
// ...
$disk = filled($item['disk'] ?? null) ? (string) $item['disk'] : $this->getDisk();
$visibility = filled($item['visibility'] ?? null) ? (string) $item['visibility'] : $this->getVisibility();
$processed = app(ImageProcessor::class)->processStored(
    sourcePath: $path,
    disk: $disk,
    ...
);
```

`_id`, `disk`, `path`, `resize_width` and `resize_height` are all `Hidden` fields and all browser-writable. `processStored()` then does `Storage::disk($disk)->readStream($sourcePath)` (`src/Services/ImageProcessor.php:44`), streams the file to a local temp file, decodes it with GD, and **writes the result into the gallery's public directory**.

The `_id` guard is not a defence: the attacker uses their own legitimate image id and simply rewrites that row's `disk` and `path`. `persist()` runs *after* `resizeStoredImages()` (`src/Forms/Components/ImageGallery.php:136` before `:139`), so the copy has already happened by the time any ownership check could fire. The copied file's new path is then written to the DB by `persist()` and served publicly.

Only files GD recognises as JPEG/PNG/WebP/GIF within the dimension caps survive — but that is exactly the class of file a private image disk holds (customer uploads, ID scans, private product photography).

**Impact:**
Private-disk image exfiltration to a public URL. Also a DoS vector: `processStored()` will happily stream a multi-gigabyte object into `sys_get_temp_dir()` before the size check in `process()` (line 108) rejects it, because that check reads `$file->getSize()` *after* the full copy.

**Recommendation:**
Never take `disk` or `visibility` from state here. Resolve them from the persisted `GalleryImage` row identified by `_id`, and verify the row belongs to this record's gallery *before* touching the filesystem. Also move the size check ahead of the stream copy.

**Suggested change:**

```php
// src/Forms/Components/ImageGallery.php — inside resizeStoredImages()
$image = GalleryImage::query()
    ->whereKey((int) $item['_id'])
    ->whereHas('gallery', fn ($query) => $query
        ->whereMorphedTo('owner', $this->getRecord())
        ->where('collection', $this->getCollection()))
    ->first();

if ($image === null || $image->path !== $path) {
    continue;
}

$disk = $image->disk;
$visibility = $image->visibility;
```

```php
// src/Services/ImageProcessor.php — processStored(), before the stream copy
$sourceSize = $storage->size($sourcePath);

if ($sourceSize > ($maxSizeKilobytes * 1024)) {
    throw new RuntimeException(__('filament-image-gallery::gallery.validation.max_size', [
        'size' => $maxSizeKilobytes / 1024,
    ]));
}
```

**Trade-offs:** One extra query per resized image on save. Only fires for images that actually carry a pending resize, so the cost is negligible.

---

## HIGH

---

### [HIGH] H-1 — `composer.json` requires `php: ^8.5`, locking out virtually the entire ecosystem

**Location:** `composer.json:9`

**Problem:**
```json
"php": "^8.5"
```
PHP 8.5 was released in November 2025. Filament v5 itself requires `php: ^8.2` (verified in `vendor/filament/support/composer.json`), as do Laravel 11/12/13. Nothing in the package's source needs 8.5 — I checked: the newest language features used are `final readonly class` (`src/Support/ProcessedImage.php:5`, PHP 8.2), constructor property promotion (8.0), named arguments (8.0), first-class enum-free `match` (8.0) and `never`-free return types. There are no 8.3/8.4/8.5-only functions.

**Impact:**
`composer require turiac/filament-image-gallery` fails for essentially every existing Filament project. This alone makes a public release pointless.

**Recommendation:** `"php": "^8.2"`, and add a CI matrix across 8.2–8.5.

**Suggested change:**
```json
"require": {
    "php": "^8.2",
    "ext-fileinfo": "*",
    "ext-gd": "*",
    "ext-curl": "*",
    "filament/actions": "^5.0",
    "filament/filament": "^5.0",
    "filament/forms": "^5.0",
    "filament/notifications": "^5.0",
    "filament/schemas": "^5.0",
    "filament/support": "^5.0",
    "illuminate/database": "^11.28|^12.0|^13.0",
    "illuminate/filesystem": "^11.28|^12.0|^13.0",
    "illuminate/http": "^11.28|^12.0|^13.0",
    "illuminate/support": "^11.28|^12.0|^13.0",
    "livewire/livewire": "^4.1"
},
"suggest": {
    "ext-exif": "Corrects JPEG orientation from EXIF data on upload."
}
```

**Trade-offs:** Supporting Laravel 11/12 means CI must actually test them. `illuminate/*: ^13.0` is also unnecessarily narrow given Filament v5 supports `^11.28|^12.0|^13.0`.

---

### [HIGH] H-2 — `filament/filament` is used but not declared as a dependency

**Location:** `src/FilamentImageGalleryPlugin.php:5-6`, `composer.json:11-20`

**Problem:**
```php
use Filament\Contracts\Plugin;   // vendor/filament/filament/src/Contracts/Plugin.php
use Filament\Panel;              // vendor/filament/filament/src/Panel.php
```
Both live in `filament/filament` (the panels package), which is **not** in `require`. `FilamentImageGalleryPlugin::get()` also calls the `filament()` helper from that package. The package only works today because the host application happens to install `filament/filament`.

`Filament\Notifications\Notification` (`src/Forms/Components/ImageGallery.php:14`) and `Filament\Schemas\Components\*` (lines 15-19) are also undeclared, though those arrive transitively via `filament/actions`. Direct dependencies should be declared directly.

**Impact:**
Install into a Filament *forms-only* application (no panels) → fatal `Class "Filament\Contracts\Plugin" not found` at autoload time. Composer cannot warn because the constraint is absent.

**Recommendation:** Add `filament/filament`, `filament/notifications`, `filament/schemas` to `require` (see H-1 patch). Alternatively, if the goal is to support forms-only apps, delete the plugin class — see M-9, it currently does nothing.

---

### [HIGH] H-3 — `ext-curl` is load-bearing for the SSRF defence but is not required, and the defence fails *open* without it

**Location:** `src/Services/RemoteImageDownloader.php:69-73`

**Problem:**
```php
$curlOptions = [];

if (defined('CURLOPT_RESOLVE')) {
    $curlOptions[CURLOPT_RESOLVE] = ["{$host}:443:{$ip}"];
}
```
`CURLOPT_RESOLVE` pins the hostname to the IP that was just validated as public — this is the DNS-rebinding defence, and it is the only thing standing between `validateUrl()` and a TOCTOU attack where the attacker's DNS returns a public IP for the validation lookup and `169.254.169.254` (cloud metadata) for the actual request.

When `ext-curl` is missing, `defined('CURLOPT_RESOLVE')` is false, the array stays empty, Guzzle silently falls back to the PHP stream handler, and the request resolves DNS again — **unpinned**. The code fails open with no error and no log. `ext-curl` is not in `composer.json`.

**Impact:**
SSRF to cloud metadata endpoints / internal services on any deployment without ext-curl. The window is small (the attacker must control DNS with a very short TTL) but the payoff is instance credentials.

**Recommendation:** Require `ext-curl`, and fail closed if the option cannot be applied.

**Suggested change:**
```php
// src/Services/RemoteImageDownloader.php
private function request(string $url, string $sink, string $host, string $ip, int $maxBytes): Response
{
    if (! defined('CURLOPT_RESOLVE')) {
        throw new RuntimeException(__('filament-image-gallery::gallery.validation.download_failed'));
    }

    return Http::connectTimeout(10)
        ->timeout(30)
        ->withOptions([
            'allow_redirects' => false,
            'sink' => $sink,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]],
            'progress' => static function (int $downloadTotal, int $downloaded) use ($maxBytes): void { /* ... */ },
        ])
        ->get($url);
}
```

Add `"ext-curl": "*"` to `require` (included in the H-1 patch).

---

### [HIGH] H-4 — The `deleting` hook destroys image files on **soft delete**

**Location:** `src/Concerns/HasImageGalleries.php:14-32`

**Problem:**
```php
public static function bootHasImageGalleries(): void
{
    static::deleting(function (Model $owner): void {
        // ... collects [disk, path] pairs ...
        $owner->imageGalleries()->delete();

        DB::afterCommit(function () use ($files): void {
            foreach ($files as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
        });
    });
}
```

Eloquent fires `deleting` for **soft deletes** too. A model that uses `SoftDeletes` alongside `HasImageGalleries` will, on `$model->delete()`:
1. hard-delete every `image_galleries` row (cascading to images and roles via the FK),
2. permanently delete every file from storage,
3. …and then soft-delete the owner, leaving a "restorable" record whose images are irrecoverably gone.

Restoring the record restores nothing.

None of the three models in this application currently use `SoftDeletes`, so it is latent here — but for a public package this is a data-loss trap that will be hit.

There is a second, smaller issue in the same block: when the delete does **not** run inside a transaction, `DB::afterCommit()` executes the callback **immediately** (verified: `DatabaseTransactionsManager::addCallback()` at `vendor/laravel/framework/src/Illuminate/Database/DatabaseTransactionsManager.php:213-220` invokes `$callback()` when there is no pending transaction). Since this is the `deleting` hook, the files are removed *before* the owner row is deleted — if the delete then fails, the files are gone and the record survives.

**Impact:** Silent, permanent, unrecoverable data loss.

**Recommendation:** Skip soft deletes, and move the file cleanup to `deleted`.

**Suggested change:**
```php
// src/Concerns/HasImageGalleries.php
public static function bootHasImageGalleries(): void
{
    static::deleting(function (Model $owner): void {
        if (method_exists($owner, 'isForceDeleting') && ! $owner->isForceDeleting()) {
            return; // Soft delete: keep galleries and files so a restore is complete.
        }

        $files = $owner->imageGalleries()
            ->with('images:id,gallery_id,disk,path')
            ->get()
            ->flatMap(fn (Gallery $gallery) => $gallery->images)
            ->map(fn (GalleryImage $image): array => [$image->disk, $image->path])
            ->all();

        $owner->imageGalleries()->delete();

        $owner->once('deleted', fn () => DB::afterCommit(function () use ($files): void {
            foreach ($files as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
        }));
    });
}
```

If `once()` is unavailable on the target Laravel versions, register a separate `static::deleted()` listener and stash `$files` on a protected property keyed by the model key.

**Trade-offs:** Soft-deleted owners keep orphaned files until force-deleted. That is the correct behaviour — document a `image-gallery:prune` command as a follow-up (see O-4).

---

### [HIGH] H-5 — `InvalidArgumentException` from `persist()` surfaces as a 500, and mid-save failures leave the record partially saved

**Location:** `src/Services/GalleryPersister.php:102, 199-220, 256`

**Problem:**
`persist()` throws `InvalidArgumentException` for five distinct user-reachable conditions:

| Line | Condition | Reachable by |
|---|---|---|
| 102 | `_id` does not belong to this gallery | Rewriting `data.images.<key>._id` |
| 206 | Unknown role | Rewriting `data.images.<key>.roles` |
| 210 | Role assigned twice | Concurrent edits, or rewriting state |
| 218 | No `main` image | Rewriting state |
| 256 | Stored file is not a valid image | Pointing `path` at a non-image |

`saveRelationshipsUsing` runs during Filament's save, *after* the owner record has already been updated. So the user sees Laravel's error page, the record's non-gallery changes are committed, and the gallery changes are not. There is no validation message, no field highlight, nothing actionable.

Cases 206/210/218 are also *unreachable through the UI* — `normalizeRoles()` (`src/Forms/Components/ImageGallery.php:1083-1107`) guarantees them — which means in practice they only ever fire for tampered state, i.e. exactly when you want a 422, not a 500.

**Impact:** Broken UX on a legitimate race (two editors, one gallery), and an unhandled-exception log flood from any tamperer. Also a partial-write correctness problem.

**Recommendation:** Throw `ValidationException` keyed to the field so Filament renders it inline, and let Filament's own save transaction roll the record back.

**Suggested change:**
```php
// src/Contracts/GalleryRepository.php — document that implementations throw ValidationException.

// src/Services/GalleryPersister.php
use Illuminate\Validation\ValidationException;

private function fail(string $message): never
{
    throw ValidationException::withMessages(['images' => $message]);
}

// then, e.g. line 102:
if ($imageId !== null && $image === null) {
    $this->fail(__('filament-image-gallery::gallery.validation.unknown_image'));
}
```

Add the corresponding keys to `resources/lang/{en,ro}/gallery.php`. `InvalidArgumentException` remains correct for the two *developer* errors in `getRoles()`/`getDefaultRoles()` (`src/Forms/Components/ImageGallery.php:1229, 1245`) — those are misconfiguration, not user input.

**Trade-offs:** `ValidationException` messages are keyed to `'images'`, which only matches when the field is named `images`. Derive the key from `$component->getStatePath()` by passing it into `persist()`, or dispatch a `Notification` instead and return without persisting.

---

### [HIGH] H-6 — `promotePendingUploaderFiles()` bypasses `isAddable()`

**Location:** `src/Forms/Components/ImageGallery.php:718-730`

**Problem:**
```php
#[ExposedLivewireMethod]
public function promotePendingUploaderFiles(): void
{
    foreach ($this->getRawState() ?? [] as $item) {
        if (! ($item['_is_uploader'] ?? false)) {
            continue;
        }

        $this->promoteUploaderFiles($item['path'] ?? []);
        $this->partiallyRender();

        return;
    }
}
```

The attribute is correctly applied (Filament refuses to dispatch to methods without it — verified at `vendor/filament/schemas/src/Concerns/InteractsWithSchemas.php:79-83`), and `promoteUploaderFiles()` correctly clamps to the remaining image budget. But it never consults `isAddable()`.

Both add actions gate on it (`->visible(fn (ImageGallery $component): bool => $component->isAddable())`, lines 220 and 244) and `isAddable()` evaluates a user-supplied closure (line 1047) — the natural place to put `->addable(fn () => auth()->user()->can('uploadImages', $record))`. A user calls `$wire.callSchemaComponentMethod(key, 'promotePendingUploaderFiles')` directly and the closure is never evaluated. The files land in state and, because the field itself is enabled and visible, `saveRelationships()` persists them.

Filament's own guard rails cover the *disabled* case (`vendor/filament/schemas/src/Components/Concerns/BelongsToModel.php:44-48` skips `saveRelationshipsUsing` for disabled/hidden components), so this is specifically an `addable()`-policy bypass, not a full write bypass.

**Impact:** Any authorization expressed via `->addable()` is advisory only.

**Recommendation:**
```php
#[ExposedLivewireMethod]
public function promotePendingUploaderFiles(): void
{
    if (! $this->isAddable()) {
        return;
    }

    // ... unchanged
}
```

Apply the same guard to `appendUpload()` (line 685), which is public and called from both add actions.

---

### [HIGH] H-7 — `editImage` action has no visibility guard, so a read-only gallery can still trigger remote downloads

**Location:** `src/Forms/Components/ImageGallery.php:209-309` (`getEditImageAction()`), `src/Forms/Components/ImageGallery.php:687-700` (`applyReplacementToEditorData()`)

**Problem:**
Unlike `getAddAction()` and `getAddFromUrlAction()`, `getEditImageAction()` carries no `->visible()` / `->authorize()` constraint. Its `action()` calls `applyReplacementToEditorData()`, which will fetch `$data['replacement_url']` through `RemoteImageDownloader` (line 697).

The gallery might be `->disabled()` (read-only reviewer), in which case nothing persists — but the outbound HTTP request still happens, on demand, at a user-chosen HTTPS host, with no rate limit anywhere in the package. That makes the endpoint a per-request outbound fetch primitive.

Combined with the fact that `$arguments['item']` is used unchecked (see M-4), the action is also the easiest thing to fuzz.

**Impact:** Outbound-request amplification from a read-only surface; bandwidth/DoS; a fetch-on-behalf-of-the-server primitive that survives permission checks placed on the field.

**Recommendation:**
```php
return Action::make('editImage')
    // ...
    ->visible(fn (ImageGallery $component): bool => ! $component->isDisabled())
    ->modalWidth(Width::SevenExtraLarge);
```

And rate-limit remote downloads:
```php
// src/Services/RemoteImageDownloader.php
public function download(string $url, int $maxBytes, int $maxRedirects = 5): TemporaryUploadedFile
{
    $key = 'fig-remote-download:'.(Auth::id() ?? request()->ip());

    if (RateLimiter::tooManyAttempts($key, (int) config('filament-image-gallery.remote_downloads_per_minute', 10))) {
        throw new RuntimeException(__('filament-image-gallery::gallery.validation.too_many_downloads'));
    }

    RateLimiter::hit($key);

    // ... unchanged
}
```

---

## MEDIUM

---

### [MEDIUM] M-1 — The "Add images" modal skips the dimension check, so oversized images fail with a 500 at save time

**Location:** `src/Forms/Components/ImageGallery.php:159-173` (add action's `FileUpload`) vs `src/Forms/Components/ImageGallery.php:574-576` (item `FileUpload`) and `src/Services/ImageProcessor.php:128-133`

**Problem:**
The item-level upload field carries the dimension rule:
```php
->rule(Rule::dimensions()
    ->maxWidth((int) config('filament-image-gallery.max_width', 5000))
    ->maxHeight((int) config('filament-image-gallery.max_height', 5000)));
```
The add-action's field does not — it has `->image()`, `->maxFiles()`, `->maxSize()` and `acceptedFileTypes()` only. The same gap exists for `RemoteImageDownloader::download()`, which validates MIME and byte size (lines 36-43) but never dimensions.

A 8000×8000 upload therefore passes the modal, is appended to state by `appendFiles()` (line 733), and only fails much later inside `ImageProcessor::process()` at line 128, which throws a bare `RuntimeException` during dehydration → unhandled 500.

**Impact:** Confusing failure (the user filled the form, hit Save, got an error page), plus log noise. Not exploitable, but a bad first impression on a package whose whole job is image handling.

**Recommendation:** Add the same rule to the add action and validate remote downloads.

**Suggested change:**
```php
// src/Forms/Components/ImageGallery.php — inside getAddAction()
FileUpload::make('uploads')
    // ...
    ->maxSize(fn (): int => (int) config('filament-image-gallery.max_size', 10240))
    ->rule(Rule::dimensions()
        ->maxWidth((int) config('filament-image-gallery.max_width', 5000))
        ->maxHeight((int) config('filament-image-gallery.max_height', 5000)))
    ->storeFiles(false)
    ->required(),
```

```php
// src/Services/RemoteImageDownloader.php:36 — extend the existing guard
if (
    $size === false || $size > $maxBytes
    || $imageInfo === false
    || ! in_array($imageInfo['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
    || $imageInfo[0] > (int) config('filament-image-gallery.max_width', 5000)
    || $imageInfo[1] > (int) config('filament-image-gallery.max_height', 5000)
) {
    throw new RuntimeException(__('filament-image-gallery::gallery.validation.invalid_image'));
}
```

---

### [MEDIUM] M-2 — Unoptimised uploads are stored byte-for-byte, preserving EXIF/GPS and any appended payload

**Location:** `src/Services/ImageProcessor.php:138-162`

**Problem:**
```php
if (! $optimize && $targetWidth === null && $targetHeight === null) {
    $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'img');
    $path = $directory.'/'.Str::uuid().'.'.$extension;
    $stream = fopen($sourcePath, 'rb');
    // ... Storage::put($path, $stream, ['visibility' => $visibility]);
```
With `optimize_images => false`, the original bytes are stored verbatim. Consequences:

1. **EXIF/GPS retained.** A photo taken on a phone carries GPS coordinates, device serial, and timestamps. Published to the `public` disk, that is a privacy leak the site operator never opted into. Note that `orientImage()` (line 244) *reads* EXIF but only in the GD branch, which this path skips entirely — so an unoptimised JPEG is also not orientation-corrected.
2. **Polyglot / appended payloads survive.** `getimagesize()` (line 120) validates only the *header*. A file with a valid JPEG header and a ZIP/PHP/HTML tail passes and is stored intact under a public URL. This is not directly executable on a correctly configured server, but it is a classic stepping stone (misconfigured nginx `location ~ \.php`, image-processing pipelines downstream, AV-scanner bypass).
3. **Attacker-influenced extension.** The fallback chain `guessExtension() ?: getClientOriginalExtension() ?: 'img'` reaches `getClientOriginalExtension()`, which for a remote download is `basename(parse_url($url, PHP_URL_PATH))`'s extension — fully attacker-chosen (`.php`, `.html`, `.svg`). In practice `guessExtension()` resolves first because the MIME was already verified, so this is a latent rather than live hole — but the verified MIME is right there and should be used directly.

**Impact:** Privacy leak (GPS) is the realistic one; the rest are defence-in-depth. Note the default config has `optimize_images => true`, so out of the box this path is not taken — it only matters for users who disable optimisation, which is a legitimate choice for lossless PNG workflows.

**Recommendation:** Derive the extension from the verified MIME, and strip metadata even when not optimising.

**Suggested change:**
```php
// src/Services/ImageProcessor.php

private const EXTENSIONS = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

// inside process(), replacing line 139:
$extension = self::EXTENSIONS[$imageInfo['mime']];
```

For metadata stripping, either always route through GD (accepting the recompression cost) or, better, expose it as a separate flag so operators can keep lossless storage while still stripping:

```php
// config/filament-image-gallery.php
'strip_metadata' => true,
```

Document clearly in the README that `optimize_images => false` stores the original bytes including all embedded metadata.

---

### [MEDIUM] M-3 — Concurrent first-save on the same owner+collection produces a unique-constraint 500

**Location:** `src/Services/GalleryPersister.php:74-92`

**Problem:**
```php
$gallery = Gallery::query()
    ->whereMorphedTo('owner', $owner)
    ->where('collection', $collection)
    ->lockForUpdate()
    ->first();
// ...
$gallery ??= $owner->imageGalleries()->create(['collection' => $collection]);
```
`lockForUpdate()` on a `SELECT` that matches **no rows** takes no lock (there is no row to lock; gap locking behaviour differs per engine and is absent on PostgreSQL for this shape). Two requests saving the same record's first image both see `null`, both `create()`, and the second violates `image_galleries_owner_collection_unique` → `QueryException` → 500.

The window is small but real: double-click on Save, or two tabs.

**Impact:** Intermittent 500 on first save, and the user's images are lost from that request.

**Recommendation:** Create-then-lock, so the unique index arbitrates instead of the application.

**Suggested change:**
```php
// src/Services/GalleryPersister.php — inside the transaction
$gallery = Gallery::query()
    ->whereMorphedTo('owner', $owner)
    ->where('collection', $collection)
    ->lockForUpdate()
    ->first();

if ($state === []) {
    // ... unchanged
    return;
}

if ($gallery === null) {
    $gallery = retry(2, fn (): Gallery => Gallery::query()->firstOrCreate([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->getKey(),
        'collection' => $collection,
    ]), 0);

    $gallery = Gallery::query()->whereKey($gallery)->lockForUpdate()->first();
}
```

`firstOrCreate` still races, hence the `retry(2)` — the second attempt finds the row the winner created. Note `Gallery::$fillable` is `['collection']`, so `firstOrCreate` with morph attributes needs them added to `$fillable` or set via `forceFill`.

**Trade-offs:** One extra `SELECT` on first save only.

---

### [MEDIUM] M-4 — `editImage` crashes on a missing or unknown `item` argument

**Location:** `src/Forms/Components/ImageGallery.php:214-215, 255, 298`

**Problem:**
```php
->fillForm(fn (array $arguments, ImageGallery $component): array => $component->editorState($arguments['item']))
->schema(fn (array $arguments, ImageGallery $component): array => $component->editorSchema($arguments['item'] ?? ''))
->action(function (array $data, array $arguments, ...): void {
    $itemKey = $arguments['item'];
    $items = $component->getRawState();
    // ...
    $items[$itemKey] = [...$items[$itemKey], ...$data];
```

`$arguments` is attacker-controlled: `$wire.mountAction('editImage', { }, { schemaComponent: '...' })`.

- Omitting `item` entirely → `editorState($arguments['item'])` throws `TypeError` (declared `string $itemKey`) at line 214, and `Undefined array key "item"` at line 255. Note the inconsistency: line 215 defensively uses `?? ''`, lines 214 and 255 do not.
- Passing an unknown key → line 298 spreads `null`: `Only arrays and Traversables can be unpacked` → fatal.
- Passing the *uploader* item's key → `editorState()` correctly returns `[]` (line 780), but line 298 then merges editor data into the uploader pseudo-item, corrupting it. `realItems()` filters it out and `withUploaderItem()` re-creates one, so state self-heals — but the user's edit silently vanishes.

**Impact:** Trivially triggerable 500s; log noise; no data compromise.

**Recommendation:** Validate once at the top of the action and halt cleanly.

**Suggested change:**
```php
->action(function (array $data, array $arguments, ImageGallery $component, Action $action, LivewireComponent $livewire): void {
    $itemKey = (string) ($arguments['item'] ?? '');
    $items = $component->getRawState() ?? [];

    if (! isset($items[$itemKey]) || ! is_array($items[$itemKey]) || ($items[$itemKey]['_is_uploader'] ?? false)) {
        $action->halt();

        return;
    }

    $previousPath = $items[$itemKey]['path'] ?? null;
    // ... unchanged
```

And make `fillForm` defensive to match `schema`:
```php
->fillForm(fn (array $arguments, ImageGallery $component): array => $component->editorState((string) ($arguments['item'] ?? '')))
```

---

### [MEDIUM] M-5 — No server-side validation on the hidden metadata fields

**Location:** `src/Forms/Components/ImageGallery.php:396-417` (`imageItemSchema()`)

**Problem:**
Of the 18 `Hidden` fields, only `resize_width` and `resize_height` carry rules (lines 412-417). `title`, `alt_text`, `original_filename`, `mime_type`, `size`, `original_size`, `width`, `height`, `focal_x`, `focal_y`, `roles`, `uploaded_at`, `uploader`, `_id`, `_is_uploader` are unvalidated.

Concrete consequences, all reachable by rewriting Livewire state:

| Field | Column | Failure |
|---|---|---|
| `title` | `varchar(255)` | >255 chars → `QueryException` (MySQL strict) or silent truncation |
| `original_filename` | `varchar(255)` | same |
| `mime_type` | `varchar(100)` | same; also stores a lie about the file |
| `size` / `original_size` | `unsignedBigInteger` | negative → `QueryException` |
| `focal_x` / `focal_y` | `decimal(8,5)` | `99999` accepted; `>999.99999` → out-of-range error |
| `_id` | — | arbitrary int; caught by `persist()` line 102 but as a 500 (see H-5) |

`hasTrustedMetadata()` (`src/Services/GalleryPersister.php:272-282`) is a good instinct — it re-derives metadata from the actual file whenever the submitted values look implausible — but it only checks *plausibility*, not *length*, so an attacker supplying a 10,000-character `original_filename` alongside a valid MIME/size/dimensions passes the trust check and hits the DB.

**Impact:** User-triggerable 500s and DB errors; garbage metadata persisted. No privilege escalation.

**Recommendation:**
```php
// src/Forms/Components/ImageGallery.php — imageItemSchema()
Hidden::make('title')->rules(['nullable', 'string', 'max:255']),
Hidden::make('alt_text')->rules(['nullable', 'string', 'max:1000']),
Hidden::make('original_filename')->rules(['nullable', 'string', 'max:255']),
Hidden::make('mime_type')->rules(['nullable', 'string', Rule::in(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])]),
Hidden::make('size')->rules(['nullable', 'integer', 'min:0']),
Hidden::make('original_size')->rules(['nullable', 'integer', 'min:0']),
Hidden::make('width')->rules(['nullable', 'integer', 'min:1', 'max:'.(int) config('filament-image-gallery.max_width', 5000)]),
Hidden::make('height')->rules(['nullable', 'integer', 'min:1', 'max:'.(int) config('filament-image-gallery.max_height', 5000)]),
Hidden::make('focal_x')->rules(['nullable', 'numeric', 'between:0,100']),
Hidden::make('focal_y')->rules(['nullable', 'numeric', 'between:0,100']),
Hidden::make('roles')->rules(['nullable', 'array']),
```

Belt-and-braces: also truncate in `GalleryPersister::metadataFor()` with `Str::limit($value, 255, '')`.

---

### [MEDIUM] M-6 — `reconcileLoadedState()` is O(n²) and allocates a Collection per unmatched item

**Location:** `src/Forms/Components/ImageGallery.php:906-949`

**Problem:**
```php
foreach ($loadedItems as $loadedKey => $loadedItem) {
    foreach ($currentRealItems as $currentKey => $currentItem) {
        // ... fileIdentity() called on both sides, every iteration
    }

    if ($matchedKey === null) {
        $fallbackKey = collect($currentKeys)->first(fn (...) => ! isset($usedCurrentKeys[...]));
    }
}
```

Nested iteration over both sets (n × n), with `fileIdentity()` — which itself does `Arr::wrap()` + a loop — invoked on both operands inside the inner loop, so ~2n² `Arr::wrap()` allocations. Plus a fresh `Collection` allocation per unmatched item.

This runs on **every state hydration from the relationship**, i.e. every time the edit form loads.

| Gallery size | Inner iterations | Practical cost |
|---|---|---|
| 10 | 100 | negligible |
| 100 | 10,000 | ~5–15 ms |
| 1,000 | 1,000,000 | ~0.5–2 s of pure PHP |

**Impact:** At the shipped default of 10 this is free. It is a wall for anyone who raises `max_images`.

**Recommendation:** Index the current items by `_id` and by path identity once, then match in O(n).

**Suggested change:**
```php
private function reconcileLoadedState(array $loadedItems, array $currentItems): array
{
    $currentRealItems = $this->realItems($currentItems);
    $keysById = [];
    $keysByPath = [];

    foreach ($currentRealItems as $currentKey => $currentItem) {
        if (filled($currentItem['_id'] ?? null)) {
            $keysById[(string) $currentItem['_id']] = (string) $currentKey;
        }

        if (($path = $this->fileIdentity($currentItem['path'] ?? null)) !== null) {
            $keysByPath[$path] ??= (string) $currentKey;
        }
    }

    $unusedKeys = array_map(strval(...), array_keys($currentRealItems));
    $usedCurrentKeys = [];
    $reconciledItems = [];

    foreach ($loadedItems as $loadedKey => $loadedItem) {
        $loadedPath = $this->fileIdentity($loadedItem['path'] ?? null);
        $matchedKey = $keysById[(string) ($loadedItem['_id'] ?? '')]
            ?? ($loadedPath === null ? null : ($keysByPath[$loadedPath] ?? null));

        if ($matchedKey !== null && isset($usedCurrentKeys[$matchedKey])) {
            $matchedKey = null;
        }

        while ($matchedKey === null && $unusedKeys !== []) {
            $candidate = array_shift($unusedKeys);

            if (! isset($usedCurrentKeys[$candidate])) {
                $matchedKey = $candidate;
            }
        }

        $matchedKey ??= (string) $loadedKey;
        $usedCurrentKeys[$matchedKey] = true;
        $reconciledItems[$matchedKey] = $loadedItem;
    }

    return $this->withUploaderItem($reconciledItems, $this->uploaderItemKey($currentItems));
}
```

**Trade-offs:** Behaviour is identical for the common cases. The original loop preferred the *earliest unused* current key on a fallback; the rewrite preserves that via the `$unusedKeys` queue.

---

### [MEDIUM] M-7 — `getRoles()` is fully re-evaluated on every call, including inside per-item loops

**Location:** `src/Forms/Components/ImageGallery.php:1223-1236`, called from `:446, :447, :449, :1090, :1242` and `resources/views/forms/components/image-gallery.blade.php:53, 203`

**Problem:**
```php
public function getRoles(): array
{
    $roles = $this->evaluate($this->roles) ?? config('filament-image-gallery.roles', []);

    if (! array_key_exists('main', $roles)) {
        throw new InvalidArgumentException(...);
    }

    return collect($roles)
        ->map(fn (string $label): string => str_contains($label, '::') ? __($label) : $label)
        ->all();
}
```
Every call: a `Closure` evaluation through Filament's reflection-based `evaluate()`, a `config()` lookup, a `Collection` allocation, and one `__()` translation lookup **per role**.

It is called **inside `normalizeRoles()`'s per-item loop** (`:1090`, `array_keys($this->getRoles())`) and **inside the Blade per-item loop** (`image-gallery.blade.php:203`, `$getRoles()[$role]`). For a 100-image gallery with 4 roles that is ~400 wasted translation lookups plus 100 Collection allocations per render, on top of `evaluate()`'s reflection.

`getTools()` (`:1252`) has the same shape and is called 4× in `toolSchema()` plus once per item in `editorPreviewData()`.

**Impact:** Not visible at 10 images; measurable at 100+. Mostly this is death by a thousand cuts in a hot render path.

**Recommendation:** Memoise per instance. Filament components are rebuilt per request, so a plain property is safe and there is no stale-state risk across requests.

**Suggested change:**
```php
/** @var array<string, string> | null */
protected ?array $resolvedRoles = null;

public function getRoles(): array
{
    if ($this->resolvedRoles !== null) {
        return $this->resolvedRoles;
    }

    $roles = $this->evaluate($this->roles) ?? config('filament-image-gallery.roles', []);

    if (! array_key_exists('main', $roles)) {
        throw new InvalidArgumentException('Image gallery roles must contain the required [main] role.');
    }

    return $this->resolvedRoles = array_map(
        fn (string $label): string => str_contains($label, '::') ? __($label) : $label,
        $roles,
    );
}
```

Reset it in the `roles()` setter (`:1155`) so a later reconfiguration is honoured. Do the same for `getTools()`.

**Note on the `::` heuristic:** `str_contains($label, '::') ? __($label) : $label` breaks for a legitimate label containing `::`. It is a small fragility; a cleaner API would accept `Htmlable|string|Closure` labels and let the caller call `__()` themselves. Flagged, not urgent.

---

### [MEDIUM] M-8 — The per-item `FileUpload` does not disable `fetchFileInformation`, costing one `exists()` per image on form load

**Location:** `src/Forms/Components/ImageGallery.php:568-661` (`makeFileUpload()`), compare `:593`

**Problem:**
The **editor** upload sets `->fetchFileInformation(false)` (line 593). The **item** upload — the one instantiated once per gallery image — does not. `BaseFileUpload::hydrateFiles()` therefore runs `$this->getDisk()->exists($file)` for every item on initial hydration (`vendor/filament/forms/src/Components/BaseFileUpload.php:156`).

This is pure waste here, because the package already carries `size`, `mime_type`, `width` and `height` in its own `Hidden` fields, and the blade never renders the item `FileUpload` for real items — only for the uploader pseudo-item (`image-gallery.blade.php:129`).

| Disk | 10 images | 100 | 1,000 |
|---|---|---|---|
| local | 10 `stat()` | 100 | 1,000 |
| s3 | 10 HEAD requests | 100 | 1,000 sequential HTTP round trips |

On S3 at ~30 ms per HEAD, a 100-image gallery adds ~3 s to form load, and 1,000 images times out.

**Impact:** Silent, serialised network latency on form load for remote disks. This is probably the single highest-value one-line performance fix in the package.

**Recommendation:**
```php
// src/Forms/Components/ImageGallery.php — makeFileUpload(), non-editor branch
return $upload
    ->multiple(fn (Get $get): bool => (bool) $get('_is_uploader'))
    ->fetchFileInformation(false)
    ->maxFiles(fn (): int => max(1, $this->getMaximumImages() - $this->getRealItemsCount()))
    // ... unchanged
```

**Trade-offs:** Gallery rows whose underlying file was deleted out-of-band will no longer be silently dropped from state on load; they will render with a broken preview instead. That is arguably *better* (the row is real, the file is missing — the operator should see that), but it is a behaviour change worth a line in the changelog.

---

### [MEDIUM] M-9 — `FilamentImageGalleryPlugin` is a no-op that implies configuration it does not provide

**Location:** `src/FilamentImageGalleryPlugin.php:1-28`

**Problem:**
```php
public function register(Panel $panel): void {}

public function boot(Panel $panel): void {}
```
The plugin does nothing. Assets are registered unconditionally in `FilamentImageGalleryServiceProvider::boot()` (`src/FilamentImageGalleryServiceProvider.php:22-32`), views/translations/migrations likewise. The consuming application registers it at `app/Providers/Filament/AdminPanelProvider.php:133` with no effect.

Meanwhile `::get()` calls `filament(app(static::class)->getId())`, which will throw for any panel that did not register it — a footgun with no upside.

It also drags in the undeclared `filament/filament` dependency (H-2).

**Impact:** Misleading public API; users will reasonably expect `->disk()`, `->maxImages()` etc. to be configurable panel-wide through it, and there is nothing there. Dead code in a package that is about to be published.

**Recommendation:** Either delete the class (and drop `filament/filament` from H-1's patch), or give it a reason to exist — panel-scoped defaults are the obvious one:

```php
class FilamentImageGalleryPlugin implements Plugin
{
    protected string | Closure | null $disk = null;

    protected int | Closure | null $maxImages = null;

    public function disk(string | Closure | null $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function getDisk(): ?string
    {
        return $this->evaluate($this->disk);
    }

    // ... and have ImageGallery::getDisk() consult the plugin before falling back to config()
}
```

Deleting it is the honest 0.x move; adding panel defaults is the better 1.0 feature.

---

### [MEDIUM] M-10 — `hydrateItems()` copies Filament internals and will silently drift

**Location:** `src/Forms/Components/ImageGallery.php:124-149`

**Problem:**
The override is a near-verbatim copy of `Repeater::hydrateItems()` (`vendor/filament/forms/src/Components/Repeater.php:159-189`), with one deliberate change: item keys are preserved (`$items[(string) $itemKey] = $itemData;`) instead of regenerated via `generateUuid()`. That is necessary — the package's keys are `record-{id}` and carry meaning.

But it reaches into three `protected` parent members (`$hydratedDefaultState`, `$shouldMergeHydratedDefaultStateWithItemsStateAfterStateHydrated`, `mergeHydratedDefaultStateWithItemsState()`). Any Filament patch release that adds a step to `hydrateItems()` — or renames one of those properties — either breaks the package with a fatal error or, worse, silently skips the new behaviour.

**Impact:** Upgrade fragility. This is the single most likely thing to break on `composer update filament/*`.

**Recommendation:** Narrow the override to the one thing that differs, so upstream changes are inherited:

```php
public function hydrateItems(): void
{
    parent::hydrateItems();

    // The parent regenerates UUID keys; this component's keys (`record-{id}`)
    // identify persisted rows and must survive hydration.
    $this->rawState($this->restoreItemKeys($this->getRawState() ?? []));
}
```

If the key mapping cannot be reconstructed after the fact, keep the override but add a guard test that fails loudly when upstream changes:

```php
public function test_repeater_hydrate_items_has_not_changed_upstream(): void
{
    $method = new ReflectionMethod(Repeater::class, 'hydrateItems');
    $body = implode("\n", array_slice(
        file($method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    $this->assertSame(
        'e3b0c442...', // sha1 of the reviewed implementation
        sha1(preg_replace('/\s+/', ' ', $body)),
        'Repeater::hydrateItems() changed upstream — re-check ImageGallery::hydrateItems().',
    );
}
```

That is not elegant, but it converts a silent behavioural regression into a red test.

---

### [MEDIUM] M-11 — Direct use of Livewire internal classes

**Location:** `src/Services/RemoteImageDownloader.php:9-10, 148-150`

**Problem:**
```php
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
// ...
$storedPath = FileUploadConfiguration::storeTemporaryFile($uploadedFile, FileUploadConfiguration::disk());

return TemporaryUploadedFile::createFromLivewire(basename($storedPath));
```
`Livewire\Features\**` is Livewire's internal namespace — not covered by its BC promise. `FileUploadConfiguration::storeTemporaryFile()` in particular is an implementation detail (verified at `vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadConfiguration.php:128`) and its signature has changed across Livewire majors.

`TemporaryUploadedFile` itself is effectively public API (Filament's own `saveUploadedFileUsing` type-hints it), so that one is fine. The `FileUploadConfiguration` calls are the exposure.

There is a second, smaller issue in `toTemporaryUpload()`: `TemporaryUploadedFile::generateHashName()` builds the stored name as `random(40) . '.' . $file->getClientOriginalExtension()`. Since `$filename` derives from `basename(parse_url($url, PHP_URL_PATH))`, a URL with no path extension yields a trailing-dot filename (`abc….`), and a URL like `https://host/x.php` writes `random.php` into `livewire-tmp`. That directory is non-public and the file is re-encoded before it reaches the gallery, so it is not exploitable — but it is avoidable.

**Impact:** A Livewire 4.x minor could break remote-URL ingestion with a fatal error. Low likelihood, high blast radius (the feature stops working entirely).

**Recommendation:** Pin the Livewire constraint tightly (`^4.1`, already in H-1's patch), add a smoke test that covers `download()` end-to-end so the breakage surfaces in CI rather than production, and normalise the filename extension from the verified MIME:

```php
private function toTemporaryUpload(string $path, string $url, string $mimeType): TemporaryUploadedFile
{
    $extension = self::EXTENSIONS[$mimeType];
    $basename = Str::of(basename((string) parse_url($url, PHP_URL_PATH)))
        ->beforeLast('.')
        ->limit(100, '')
        ->whenEmpty(fn (): Stringable => Str::of('remote-image'))
        ->value();

    $uploadedFile = new UploadedFile($path, "{$basename}.{$extension}", $mimeType, null, true);
    // ...
}
```

---

### [MEDIUM] M-12 — `metadataFor()` loads whole files into memory and calls `size()` twice

**Location:** `src/Services/GalleryPersister.php:252-266`

**Problem:**
```php
$contents = $storage->get($path);
$dimensions = @getimagesizefromstring($contents);
// ...
return [
    // ...
    'size' => (int) $storage->size($path),
    'original_size' => (int) ($item['original_size'] ?? $storage->size($path)),
    // ...
];
```
Three problems in five lines:
1. `$storage->get($path)` reads the **entire** file into a PHP string to extract a header that lives in the first ~64 bytes. A 10 MB image costs 10 MB of peak memory (20 MB counting the copy `getimagesizefromstring` may make). On a save with 10 changed images that is up to 200 MB.
2. `$storage->size($path)` is called twice — two round trips on S3.
3. `$storage->exists($path)` in the trusted branch (line 241) plus `size()` here means up to 3 remote calls per changed image.

This only fires for images whose path changed or whose submitted metadata failed `hasTrustedMetadata()`, so the common save path is unaffected — but the uncommon path is where large files live.

**Impact:** Memory spikes and avoidable latency on saves that involve replacements. Combined with `max_parallel_uploads: 4` and concurrent users, a plausible OOM.

**Recommendation:** Read only the header, and reuse the size.

**Suggested change:**
```php
$size = (int) $storage->size($path);
$stream = $storage->readStream($path);

if ($stream === false) {
    throw ValidationException::withMessages([
        'images' => __('filament-image-gallery::gallery.validation.invalid_image'),
    ]);
}

try {
    $header = stream_get_contents($stream, 65_536);
} finally {
    fclose($stream);
}

$dimensions = @getimagesizefromstring((string) $header);

if ($dimensions === false) {
    throw ValidationException::withMessages([
        'images' => __('filament-image-gallery::gallery.validation.invalid_image'),
    ]);
}

return [
    'original_filename' => Str::limit((string) ($item['original_filename'] ?? basename($path)), 255, ''),
    'mime_type' => $item['mime_type'] ?? ($dimensions['mime'] ?? $storage->mimeType($path)),
    'size' => $size,
    'original_size' => (int) ($item['original_size'] ?? $size),
    'width' => (int) $dimensions[0],
    'height' => (int) $dimensions[1],
];
```

**Trade-offs:** 64 KB is enough for JPEG/PNG/GIF/WebP headers in every real-world case, but a pathological file with a huge APP1/EXIF segment before SOF could fail. If that matters, retry with the full file on `false`.

---

### [MEDIUM] M-13 — `ImageProcessor` calls `Storage::size()` after `put()` instead of using the local file

**Location:** `src/Services/ImageProcessor.php:156, 232`

**Problem:**
```php
Storage::disk($disk)->put($path, $stream, ['visibility' => $visibility]);
// ...
size: (int) Storage::disk($disk)->size($path),
```
The bytes were just written from a local file whose size is known. On S3 this is an extra HEAD request per upload, immediately after a PUT — and it is subject to read-after-write consistency semantics.

`Storage::disk($disk)` is also resolved twice (once for `put`, once for `size`), going through the `FilesystemManager` each time.

**Impact:** One superfluous round trip per uploaded image. With `max_parallel_uploads: 4` and a 10-image batch, 10 extra S3 calls per gallery save.

**Recommendation:**
```php
$storage = Storage::disk($disk);
$stream = fopen($temporaryPath, 'rb');

if ($stream === false) {
    throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
}

try {
    $storage->put($path, $stream, ['visibility' => $visibility]);
} finally {
    fclose($stream);
}

return new ProcessedImage(
    path: $path,
    mimeType: $outputMimeType,
    size: (int) filesize($temporaryPath),
    originalSize: $originalSize,
    width: $width,
    height: $height,
    originalFilename: $originalFilename,
);
```

Apply the same change to the unoptimised branch (line 156), using `filesize($sourcePath)`.

---

### [MEDIUM] M-14 — GD image processing has no memory guard; a 5000×5000 upload allocates ~100 MB

**Location:** `src/Services/ImageProcessor.php:164-176`, `config/filament-image-gallery.php:8-9`

**Problem:**
The dimension check at line 128 correctly runs **before** `imagecreatefrom*()`, which is the right ordering and blocks classic decompression bombs. Good.

But the *permitted* maximum is itself large: `max_width`/`max_height` default to 5000, so a 5000×5000 truecolor image is `25,000,000 px × 4 bytes ≈ 100 MB` in GD, plus another ~100 MB for the resize destination in `resizeImage()` (line 286) — up to **200 MB peak for a single image**. `imagerotate()` in `orientImage()` (line 253) adds a third buffer for EXIF-rotated JPEGs.

With `max_parallel_uploads: 4`, a single user uploading four such images concurrently needs ~800 MB. PHP's default `memory_limit` in most Laravel deployments is 128–512 MB → fatal error mid-upload, and — because the fatal happens inside `saveUploadedFileUsing` during dehydration — a half-saved form.

**Impact:** Self-inflicted OOM under normal use with large photos; a trivial DoS if an attacker uploads in parallel. No hard failure at the shipped 10-image cap with typical 2–4 MP photos.

**Recommendation:** Add an explicit pixel budget separate from the per-axis caps, checked before decode, and document the memory relationship.

**Suggested change:**
```php
// config/filament-image-gallery.php
'max_pixels' => 24_000_000, // ~24 MP; roughly 100 MB peak in GD.
```

```php
// src/Services/ImageProcessor.php — after the existing dimension check
if (($width * $height) > $maxPixels) {
    throw new RuntimeException(__('filament-image-gallery::gallery.validation.dimensions', [
        'width' => $maxWidth,
        'height' => $maxHeight,
    ]));
}
```

Also worth documenting in the README: GD peak memory ≈ `width × height × 4 × 2` bytes, so `memory_limit` must exceed that. Longer term, moving processing to a queued job (see O-2) removes the request-time memory pressure entirely.

---

### [MEDIUM] M-15 — Reordering issues one `UPDATE` per image

**Location:** `src/Services/GalleryPersister.php:126, 138-144`

**Problem:**
```php
'sort_order' => $index + 1,
// ...
$image->fill($values);

if ($image->isDirty()) {
    $image->save();
}
```
The `isDirty()` guard is a genuinely good touch — untouched images cost zero writes. But dragging the *first* image to the *last* position changes `sort_order` for **every** image, producing n `UPDATE` statements inside the transaction.

| Gallery size | UPDATEs on a full reorder |
|---|---|
| 10 | 10 |
| 100 | 100 |
| 1,000 | 1,000 |

At 1,000 that is a long-held write transaction with `lockForUpdate()` on every row.

**Impact:** Fine at the default cap. A lock-contention problem above ~200.

**Recommendation:** Batch the sort-order rewrite into a single statement when only ordering changed.

**Suggested change:**
```php
// Collect id => sort_order for rows whose only change is position, then:
if ($sortOrders !== []) {
    $cases = collect($sortOrders)
        ->map(fn (int $order, int $id): string => "when {$id} then {$order}")
        ->implode(' ');

    $gallery->images()
        ->whereKey(array_keys($sortOrders))
        ->update(['sort_order' => DB::raw("case id {$cases} end")]);
}
```

Or, more portably, `GalleryImage::upsert($rows, ['id'], ['sort_order'])`.

**Trade-offs:** Bypasses model events and `updated_at`. Since `sort_order` carries no business logic here, that is acceptable — but add `'updated_at' => now()` to the update if the timestamp matters.

---

## LOW

---

### [LOW] L-1 — The `(image_id, role)` unique index is redundant

**Location:** `database/migrations/2026_08_17_000000_create_filament_image_gallery_tables.php:52`

**Problem:**
```php
$table->unique(['gallery_id', 'role']);
$table->unique(['image_id', 'role']);
$table->index(['gallery_id', 'image_id']);
```
An image belongs to exactly one gallery (`gallery_id` FK, non-nullable). `unique(['gallery_id', 'role'])` therefore already guarantees at most one row per role per gallery, which implies at most one row per (image, role). The second unique index can never fire.

It is not merely redundant — it constrains the `upsert()` at `src/Services/GalleryPersister.php:173-177`. On MySQL, `upsert()` compiles to `ON DUPLICATE KEY UPDATE`, which reacts to **any** unique key, not just `$uniqueBy`. Role reassignments happen to work through this today (I traced the swap cases), but the extra index is an unnecessary source of ordering sensitivity.

**Impact:** One superfluous index maintained on every role write. No correctness bug observed.

**Recommendation:** Drop `unique(['image_id', 'role'])`. Keep `index(['gallery_id', 'image_id'])`.

---

### [LOW] L-2 — `uploader` morph assumes integer keys

**Location:** `database/migrations/2026_08_17_000000_create_filament_image_gallery_tables.php:24`

**Problem:** `$table->nullableMorphs('uploader')` creates `uploader_id` as `unsignedBigInteger`. Applications with UUID or ULID user primary keys cannot use the package — inserts fail.

**Recommendation:** Make the morph type configurable.

```php
// config/filament-image-gallery.php
'uploader_morph_type' => 'id', // 'id' | 'uuid' | 'ulid'
```
```php
match (config('filament-image-gallery.uploader_morph_type', 'id')) {
    'uuid' => $table->nullableUuidMorphs('uploader'),
    'ulid' => $table->nullableUlidMorphs('uploader'),
    default => $table->nullableMorphs('uploader'),
};
```

The same applies to `morphs('owner')` on `image_galleries`.

---

### [LOW] L-3 — Models and table names are not swappable

**Location:** `src/Models/Gallery.php:11`, `src/Models/GalleryImage.php:14`, `src/Models/GalleryImageRole.php:10`, `src/Services/GalleryPersister.php` (hard references throughout)

**Problem:** Table names and model classes are hard-coded. A host application cannot add a global scope, a tenant column, an observer, or resolve a table-name collision with `image_galleries`.

`GalleryRepository` is bound in the container (`src/FilamentImageGalleryServiceProvider.php:16`), which is the right instinct, but `GalleryPersister` still `new`s the concrete models directly.

**Recommendation:** Config-driven model resolution, the standard pattern for Filament/Spatie packages:
```php
// config/filament-image-gallery.php
'models' => [
    'gallery' => \Turiac\FilamentImageGallery\Models\Gallery::class,
    'image' => \Turiac\FilamentImageGallery\Models\GalleryImage::class,
    'role' => \Turiac\FilamentImageGallery\Models\GalleryImageRole::class,
],
'table_names' => [
    'galleries' => 'image_galleries',
    'images' => 'image_gallery_images',
    'roles' => 'image_gallery_roles',
],
```

**Trade-offs:** More indirection. Worth it for a public package; skip if this stays internal.

---

### [LOW] L-4 — No factories, so consuming apps cannot easily test against the package

**Location:** `database/` (no `factories/` directory)

**Problem:** None of the three models use `HasFactory` and no factories ship. An application writing a feature test that needs a gallery must hand-build rows.

**Recommendation:** Add `HasFactory` and ship `GalleryFactory` / `GalleryImageFactory` under `database/factories/`, registered via `Factory::guessFactoryNamesUsing()` in the service provider or an explicit `newFactory()` on each model.

---

### [LOW] L-5 — Alpine components are registered without `x-load`, relying on load order

**Location:** `resources/views/forms/components/image-editor-resize.blade.php:4`, `resources/views/forms/components/image-editor-preview.blade.php:18`, `resources/js/image-gallery.js:98-101, 272`

**Problem:**
```html
<!-- image-editor-resize.blade.php:4 — no x-load / x-load-src -->
<div x-data="imageGalleryResize({ ... })">
```
```js
// image-gallery.js:98
globalThis.Alpine?.data('imageGalleryEditorPreview', imageGalleryEditorPreview);
```
`imageGalleryResize` and `imageGalleryEditorPreview` are registered as a side effect of the *gallery* bundle being loaded, and the gallery bundle is loaded lazily via `x-load-src` (`image-gallery.blade.php:49-50`). The editor modal's components have no `x-load` of their own.

Two failure modes:
1. If the modal renders before the gallery bundle resolves, Alpine throws `imageGalleryResize is not defined`.
2. The `?.` in `globalThis.Alpine?.data(...)` means that if the module evaluates before Alpine is on `globalThis`, registration is **silently skipped** — no error, the component just never works.

In practice the modal can only open after the gallery has rendered, so this is latent rather than live. But it is exactly the kind of thing that breaks under a slow network or a future Filament asset-loading change.

**Recommendation:** Register on the `alpine:init` event rather than at module scope, and give the editor components their own `x-load`:

```js
// resources/js/image-gallery.js
const register = () => {
    window.Alpine.data('imageGalleryEditorPreview', imageGalleryEditorPreview);
    window.Alpine.data('imageGalleryResize', imageGalleryResize);
};

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register, { once: true });
}
```

Also: the package ships raw ES modules with no build step (no `package.json`, no esbuild config). Filament's convention is a compiled bundle in `resources/dist/`. The current files have no imports so they work as-is, but add a minimal `package.json` + esbuild script before release so contributors have a defined build.

---

### [LOW] L-6 — `imageGalleryFocalPointPreview` does not implement the crop trigger

**Location:** `resources/js/image-gallery-focal-point.js:1-91` vs `resources/js/image-gallery.js:1-96`

**Problem:** `image-editor-preview.blade.php:9-19` swaps the Alpine component depending on whether the `focal-point` tool is enabled:

```blade
@if ($hasFocalPoint)
    x-data="imageGalleryFocalPointPreview({ ... })"
@else
    x-data="imageGalleryEditorPreview()"
@endif
```

`imageGalleryEditorPreview` implements `handleCropTriggerClick()`, `openFileEditor()` and `openImageEditor()` — the machinery that makes clicking the "Crop and transform" section header open FilePond's image editor. `imageGalleryFocalPointPreview` implements only `toggleFileEditor()`.

So enabling **both** `focal-point` and `crop` produces a Crop section whose header does nothing. Only the pencil button works.

The shipped default `tools` is `['information', 'crop', 'resize', 'replace']` — `focal-point` is not in it — so this is not hit out of the box. But `focal-point` is a documented tool and the combination is the obvious one to want.

**Recommendation:** Extract the shared preview behaviour into one factory and compose the focal-point behaviour on top:

```js
export function imageGalleryEditorPreview({ focalX = null, focalY = null } = {}) {
    const hasFocalPoint = focalX !== null || focalY !== null;

    return {
        ...basePreviewBehaviour(),
        ...(hasFocalPoint ? focalPointBehaviour(focalX, focalY) : {}),
    };
}
```

Then have both Blade branches use the same component name with different arguments.

---

### [LOW] L-7 — `imageGallery()` re-serialises every item's metadata into `x-data`

**Location:** `resources/views/forms/components/image-gallery.blade.php:28-44, 56`

**Problem:**
```php
$initialItems[$itemKey] = ['roles' => array_values((array) ($itemState['roles'] ?? []))];

foreach ($editableMetadataFields as $metadataField) {
    $initialItems[$itemKey][$metadataField] = $itemState[$metadataField] ?? null;
}
```
rendered as `initialItems: @js($initialItems)`.

This duplicates state that is *already* in the Livewire snapshot, inline in the HTML. Per item that is `roles`, `alt_text`, `title` (+ `focal_x`/`focal_y`/`resize_*` when those tools are on) — roughly 150–300 bytes of JSON.

| Gallery size | Extra inline JSON | Livewire snapshot (approx.) |
|---|---|---|
| 10 | ~2 KB | ~25 KB |
| 100 | ~25 KB | ~250 KB |
| 1,000 | ~250 KB | ~2.5 MB **per request, both directions** |

The Livewire snapshot figure is the real ceiling: the Repeater keeps all 18 fields for all items in `$data`, and Livewire serialises and HMACs the whole thing on every round trip.

**Impact:** Invisible at 10. The dominant cost at 1,000, ahead of any query concern.

**Recommendation:** The `initialItems` duplication is fixable — drop the fields Alpine does not actually read. Scanning `image-gallery.js`, Alpine uses `items[key].roles` (`hasRole`, `roleSummary`, `normalizeRoles`) and `items[key].title` (`image-gallery.blade.php:192`). `alt_text`, `focal_x`, `focal_y`, `resize_*` are only read from `editorInitialData` in `applyEditor()`, which is populated from the *mounted action's* data (`captureEditorState()`, line 368) — not from `initialItems`. So most of the payload is unused:

```php
$initialItems[$itemKey] = [
    'roles' => array_values((array) ($itemState['roles'] ?? [])),
    'title' => $itemState['title'] ?? null,
];
```

The snapshot size itself is architectural — see the scalability section.

---

### [LOW] L-8 — Animated GIFs are silently flattened to a single frame

**Location:** `src/Services/ImageProcessor.php:168, 198-204`

**Problem:** `imagecreatefromgif()` decodes only the first frame. With `optimize: true` (the default) an animated GIF becomes a static WebP; with `optimize: false` + a resize it becomes a static GIF. The user gets no warning — they uploaded an animation and got a still.

`image/gif` is in `acceptedFileTypes` at three places (`ImageGallery.php:164, 526, 572`) and in `ImageProcessor::SUPPORTED_MIME_TYPES`, so it is an advertised format.

**Recommendation:** Either drop GIF from the accepted types (simplest, and defensible for a product-image gallery), or detect animation and pass those files through unmodified:

```php
private function isAnimatedGif(string $path): bool
{
    return substr_count((string) file_get_contents($path), "\x00\x21\xF9\x04") > 1;
}
```

At minimum, document the behaviour in the README.

---

### [LOW] L-9 — `getMaximumImages()` has a dead fallback

**Location:** `src/Forms/Components/ImageGallery.php:1034-1037`, `src/Forms/Components/ImageGallery.php:75`

**Problem:**
```php
// setUp(), line 75:
$this->maxItems(fn (): int => (int) config('filament-image-gallery.max_images', 10));

// line 1036:
return $this->getMaxItems() ?? (int) config('filament-image-gallery.max_images', 10);
```
`maxItems()` is always set in `setUp()`, so `getMaxItems()` never returns `null` and the `??` branch is unreachable. Harmless, but it reads as though the config is a fallback when it is actually the primary source, which will mislead the next maintainer.

**Recommendation:** `return $this->getMaxItems() ?? PHP_INT_MAX;` — or just delete the redundancy and keep the config lookup in one place.

---

### [LOW] L-10 — Two translation keys are defined but never used

**Location:** `resources/lang/en/gallery.php:54, 62`, `resources/lang/ro/gallery.php` (same keys)

**Problem:** `tools.replace_helper` and `tools.resize_helper` are defined in both locales but referenced nowhere in `src/` or `resources/views/`. Verified by extracting every `filament-image-gallery::gallery.*` reference and diffing against the defined keys.

Everything else is in parity: EN and RO define exactly the same key set, and every key used in code exists in both.

**Recommendation:** Either wire them into the corresponding `Section::description()` calls (they read like intended section descriptions) or delete them.

---

### [LOW] L-11 — Reorder handler built by string concatenation instead of `Js::from()`

**Location:** `resources/views/forms/components/image-gallery.blade.php:105`

**Problem:**
```php
'x-on:end.stop' => '$wire.mountAction(\'reorder\', { items: $event.target.sortable.toArray() }, { schemaComponent: \'' . $key . '\' })',
```
merged with `escape: false`. `$key` comes from `$getKey()`, which Filament derives from the component's state path — not user input — so this is not injectable today. But the file consistently uses `@js()` everywhere else (lines 53-63, 142, 162, 167), and `ImageGallery::applyEditorActionJs()` correctly uses `Js::from()->toHtml()` throughout. This one line is the odd one out.

**Recommendation:**
```php
'x-on:end.stop' => '$wire.mountAction(\'reorder\', { items: $event.target.sortable.toArray() }, { schemaComponent: '.\Illuminate\Support\Js::from($key).' })',
```

---

## OPTIONAL OPTIMIZATION

---

### [OPT] O-1 — Generate and store thumbnails

**Location:** `src/Services/ImageProcessor.php` (new capability), `resources/views/forms/components/image-gallery.blade.php:147-152`, `src/Forms/Components/ImageGallery.php:1296-1301`

The gallery grid renders each image at roughly 200×200 CSS pixels, and the editor thumbnail strip at 80–96 px — but both load the **full-resolution** original. A 10-image gallery of 3 MB photos transfers 30 MB to render a grid of thumbnails. The editor modal is worse: `editorPreviewData()` builds a thumbnail entry for *every* item (line 1296), so opening the editor on a 100-image gallery loads 100 full-size images.

`loading="lazy"` (blade line 151) mitigates the below-the-fold portion but not the visible grid.

The schema already models this: the `roles` system has `thumbnail` and `small` roles, but they designate *which image* plays that part, not *derivative renditions* of one image. Adding a `renditions` concept (or a simple `thumbnail_path` column) would be the single biggest UX win in the package.

```php
// Sketch: generate one 400px rendition alongside the main file.
$thumbnail = $this->resizeImage(clone $image, 400, 400);
$thumbnailPath = $directory.'/'.Str::uuid().'-thumb.webp';
```

**Trade-offs:** More storage, a schema addition, and a migration path for existing rows (a backfill command). Substantial work — plan it for 1.1 rather than 1.0.

---

### [OPT] O-2 — Move image processing to a queued job

**Location:** `src/Forms/Components/ImageGallery.php:641-661` (`saveUploadedFileUsing`)

GD decode + resize + re-encode runs synchronously inside the Livewire request. For a 4000×3000 JPEG that is 300–800 ms of CPU and ~100 MB of memory *per image*, inside a request the user is waiting on. The package already has the right infrastructure for deferring — `ProcessedImage` is a clean value object and `GalleryPersister` is decoupled behind an interface.

A queued variant would store the original immediately, return its path, and dispatch a job to optimise and swap the path. It needs a "processing" state in the UI and makes the `resize_width`/`resize_height` flow asynchronous, so it is a meaningful design change — but it removes the memory ceiling (M-14) and the latency at once.

---

### [OPT] O-3 — Cache `imageForRole()` lookups

**Location:** `src/Concerns/HasImageGalleries.php:48-69`

```php
public function imageForRole(string $role, string $collection = 'default'): ?GalleryImage
{
    $gallery = $this->imageGallery($collection);
    // ...
    if ($gallery->relationLoaded('images')) {
        return $gallery->images->first(function (GalleryImage $image) use ($role): bool {
            if ($image->relationLoaded('roles')) {
                return $image->roles->contains('role', $role);
            }

            return $image->roles()->where('role', $role)->exists();  // ← query per image
        });
    }

    return $gallery->images()
        ->whereHas('roles', fn ($query) => $query->where('role', $role))
        ->first();
}
```

The eager-load fast paths are correct and the graceful degradation is thoughtful. Two refinements:

1. The `$image->roles()->where(...)->exists()` fallback (line 62) fires **per image** when `images` is loaded but `roles` is not — a partial eager load is worse than none. `Product::table()` in this application does load `imageGalleries.images.roles` (`app/Modules/Products/Filament/Resources/Products/ProductResource.php:109`), so it is fine here, but a consumer who loads `imageGalleries.images` alone gets a silent N+1.
2. Nothing is memoised, and the calling accessor (`Product::displayImagePath()`) is not `->shouldCache()`. Each access re-walks the collection.

Add a package-provided scope so consumers cannot get the eager loading wrong:
```php
public function scopeWithImageGalleries(Builder $query, string ...$collections): void
{
    $query->with(['imageGalleries' => fn ($query) => $query
        ->when($collections !== [], fn ($query) => $query->whereIn('collection', $collections))
        ->with('images.roles')]);
}
```

---

### [OPT] O-4 — Ship an orphan-file pruning command

Once H-4 keeps files for soft-deleted owners, and given that any mid-save failure can leave a written-but-unreferenced file (`ImageProcessor` writes before `persist()` commits), an `image-gallery:prune` command that lists files in the configured directory with no matching `image_gallery_images` row — `--dry-run` by default — would be a valuable operational tool.

---

### [OPT] O-5 — Split `ImageGallery.php`

At 1,392 lines it holds at least five responsibilities: Filament schema construction (~350 lines), state reconciliation and role arbitration (~250), action definitions (~200), preview/URL/formatting helpers (~150), and JS generation. Extracting three collaborators would make each testable in isolation:

- `GalleryItemState` — `realItems`, `withUploaderItem`, `uploaderItemKey`, `uploaderItemState`, `reorderItems`, `reconcileLoadedState`, `normalizeRoles`, `fileIdentity`, `adjacentItemKey`
- `GalleryPreviewUrlResolver` — `getPreviewUrl`, `editorPreviewData`
- `GalleryEditorSchema` — `editorSchema`, `toolSchema`, `editorFileDetails`, `formatFileSize`, `formatLabel`

This is a maintainability play, not a correctness one — do it only alongside the test suite, so the extraction is verified.

---

## 3. Areas Reviewed With No Significant Problems Found

Stating these explicitly, as requested:

| Area | Verdict |
|---|---|
| **SQL injection** | None. Every query uses Eloquent/query-builder bindings. `whereNotIn('role', ...)` (`GalleryPersister.php:169`) binds values; `whereMorphedTo` is safe; `upsert()` binds. Role values are validated against `$configuredRoles` before reaching the DB. |
| **XSS / Blade escaping** | Clean. No `{!! !!}` anywhere in the package (verified by grep across `src/` and `resources/`). All interpolation into JS uses `@js()` or `Js::from()->toHtml()`. The two `escape: false` usages are Filament's standard `ComponentAttributeBag` merge pattern with server-derived values. `Str::sanitizeUrl()` is applied by Filament on upload URLs. |
| **SVG handling** | Correctly and completely excluded. `acceptedFileTypes` lists only jpeg/png/webp/gif at all three upload points (`ImageGallery.php:164, 526, 572`), and `ImageProcessor::SUPPORTED_MIME_TYPES` re-validates server-side via `getimagesize()`'s detected MIME rather than the client-supplied type or the extension. No embedded-JavaScript vector. |
| **Path traversal above the disk root** | Blocked by Flysystem. `WhitespacePathNormalizer::normalizePath()` throws `PathTraversalDetected` for any `..` that escapes the root (`vendor/league/flysystem/src/WhitespacePathNormalizer.php:38-41`). Confinement *within* the disk is the problem — see C-1. |
| **SSRF defence design** | Strong. HTTPS-only, port 443 only, DNS pre-resolution with `FILTER_FLAG_NO_PRIV_RANGE\|NO_RES_RANGE` on **every** returned address, `CURLOPT_RESOLVE` pinning against rebinding, manual redirect handling with full re-validation per hop, redirect cap, size cap enforced by a progress callback, temp file cleaned in `finally`. IPv4-mapped IPv6 (`::ffff:127.0.0.1`) is covered by PHP's reserved-range filter. The only gap is the fail-open behaviour without ext-curl (H-3). |
| **`#[ExposedLivewireMethod]` usage** | Correct. Verified that Filament refuses to dispatch to methods lacking the attribute (`vendor/filament/schemas/src/Concerns/InteractsWithSchemas.php:79-83`). Exactly one method is exposed, and it does not accept arguments. |
| **Disabled/hidden component write protection** | Handled by Filament, not needed in the package. `BelongsToModel::saveRelationships()` (`vendor/filament/schemas/src/Components/Concerns/BelongsToModel.php:44-52`) skips `saveRelationshipsUsing` for disabled and hidden components. |
| **Reorder argument handling** | Safe. `reorderItems()` (`ImageGallery.php:1002-1032`) validates each submitted key with `array_key_exists` against real items and appends any missing ones, so a forged `items` array can reorder but cannot inject, duplicate or drop entries. |
| **Delete-then-normalise ordering** | Correct. I checked whether `deleteAction->after()`'s `normalizeRoles()` (line 93) runs before or after the partial render. `PartialsComponentHook::renderPartial()` (`vendor/filament/support/src/Livewire/Partials/PartialsComponentHook.php:208-213`) pushes a *closure* evaluated at response time, so the re-render reflects the normalised roles. No stale-badge bug. |
| **Transaction rollback of generated files** | Correct. Both `resizeStoredImages()` (`ImageGallery.php:851-857`) and the `saveRelationshipsUsing` wrapper (`ImageGallery.php:145-151`) delete files generated during a failed save. Good discipline that many packages miss. |
| **`isDirty()` write avoidance** | Correct and valuable. Unchanged images produce zero UPDATEs and zero filesystem calls on save (`GalleryPersister.php:141`, plus the `$existingImage->path === $path` short-circuit at line 228). |
| **Eager loading in `stateFor()`** | Correct. `->with(['images.roles', 'images.uploader'])` (`GalleryPersister.php:27`) — 4 queries regardless of gallery size. No N+1. |
| **Translation completeness** | EN and RO are in exact parity (verified by flattening both and diffing key sets in both directions). Every key referenced in code exists in both files. Only two defined-but-unused keys (L-10). |
| **Placeholder substitution in validation messages** | Correct. `:size` receives MB in both call sites (`ImageProcessor.php:110` passes `$maxSizeKilobytes / 1024`; `RemoteImageDownloader.php:84` passes `$maxBytes / 1024 / 1024`), matching the `":size MB"` string. |
| **Syntax / lint** | `php -l` clean across all 17 source files. |
| **Deprecated APIs** | None found. Every Filament v5, Livewire v4 and Laravel 13 API used was verified present in the installed vendor tree. |

---

## 4. Test Coverage

**Current state: `tests/Unit/` exists and is empty. There are zero tests.**

For a package that deletes files from disk, executes outbound HTTP on user input, and processes untrusted images with GD, this is the second-most-serious finding after C-1.

### [HIGH] T-1 — No test suite, no test infrastructure

There is no `phpunit.xml`, no `require-dev`, no `autoload-dev`, no Orchestra Testbench, no CI workflow. Nothing can be verified and nothing guards against Filament/Livewire upgrades.

**Minimum viable setup:**

```json
"require-dev": {
    "larastan/larastan": "^3.0",
    "laravel/pint": "^1.18",
    "orchestra/testbench": "^9.0|^10.0|^11.0",
    "phpunit/phpunit": "^11.0|^12.0"
},
"autoload-dev": {
    "psr-4": {
        "Turiac\\FilamentImageGallery\\Tests\\": "tests/"
    }
}
```

### Required tests, prioritised

**Security (write these first — they are the regression tests for C-1 … C-3, H-4, H-6):**

| # | Test | Guards |
|---|---|---|
| 1 | Submitting a `path` outside the configured directory is rejected | C-1 |
| 2 | Submitting a `path` already owned by another gallery image is rejected | C-1 |
| 3 | Removing a tampered item does **not** delete the victim's file | C-1 |
| 4 | A tampered `disk` in state does not change which disk `getPreviewUrl()` reads | C-2 |
| 5 | A tampered `disk`/`path` on a resize does not copy from another disk | C-3 |
| 6 | Soft-deleting an owner preserves galleries and files | H-4 |
| 7 | Force-deleting an owner removes galleries and files | H-4 |
| 8 | `promotePendingUploaderFiles()` is a no-op when `addable(false)` | H-6 |
| 9 | `mountAction('editImage')` with a missing/unknown `item` does not fatal | M-4 |
| 10 | `_id` belonging to another gallery is rejected as a validation error, not a 500 | H-5 |

```php
public function test_a_tampered_path_cannot_delete_another_records_file(): void
{
    Storage::fake('public');
    Storage::disk('public')->put('image-gallery/victim.webp', 'victim-bytes');

    $product = Product::factory()->create();

    // Plant a hostile path via raw Livewire state, exactly as a browser could.
    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->set('data.images.item1._id', null)
        ->set('data.images.item1.path', ['image-gallery/victim.webp'])
        ->set('data.images.item1.title', 'stolen')
        ->set('data.images.item1.roles', ['main'])
        ->call('save')
        ->assertHasFormErrors(['images']);

    Storage::disk('public')->assertExists('image-gallery/victim.webp');
    $this->assertDatabaseMissing('image_gallery_images', ['path' => 'image-gallery/victim.webp']);
}
```

**Upload / processing:**
- Valid JPEG/PNG/WebP/GIF upload → stored, converted to WebP when `optimize_images`, metadata correct
- Non-image with an image extension → rejected by `ImageProcessor`
- Image exceeding `max_width`/`max_height` → rejected in the add modal (M-1), not at save time
- Image exceeding `max_size` → rejected
- `optimize_images => false` → original bytes preserved, extension derived from the verified MIME (M-2)
- EXIF orientation 3/6/8 → correctly rotated
- Corrupt/truncated image → `RuntimeException`, temp files cleaned up
- Upload beyond `max_images` → clamped, not errored

**Remote download (`RemoteImageDownloader`):**
- `http://` URL → rejected
- URL on a non-443 port → rejected
- Host resolving to `127.0.0.1` / `10.0.0.1` / `169.254.169.254` / `::1` → rejected
- Host resolving to `::ffff:127.0.0.1` → rejected
- More redirects than `$maxRedirects` → rejected
- Redirect to a private IP → rejected on the second hop
- Response larger than `$maxBytes` → rejected, temp file removed
- Non-image response body with an `image/jpeg` header → rejected
- `CURLOPT_RESOLVE` unavailable → fails closed (H-3)

**Persistence (`GalleryPersister`):**
- Empty state deletes the gallery and queues every file for deletion
- Unchanged images produce zero UPDATEs (assert with `DB::listen`)
- Reorder updates only `sort_order`
- Role reassignment moves the role row rather than duplicating it
- A role assigned to two images → validation error
- Empty `main` on a non-empty gallery → validation error
- Concurrent first-save does not 500 (M-3)
- An exception mid-persist deletes generated files and rolls back the DB

**Livewire component behaviour:**
- `reorder` with forged keys cannot inject or drop items
- `reorder` with an unknown key is ignored
- Deleting the main image promotes the next image to main
- The uploader pseudo-item disappears at `max_images` and returns after a delete
- `reconcileLoadedState` preserves keys across a save→reload cycle

**Filesystem failures:**
- `Storage::shouldReceive('delete')->andThrow(...)` → save still succeeds (file cleanup must not break the transaction)
- Missing file on load → row survives, preview is null (see M-8's trade-off)

**Static analysis:** run Larastan at level 6+ and Pint. I expect a handful of findings around `Repeater`'s untyped `getRawState()` returns and the `mixed` parameters in `fileIdentity()`/`pathFrom()`.

---

## 5. Scalability Analysis

Behaviour as gallery size grows, per the requested breakdown:

### 10 images (the shipped default)

**Verdict: healthy.** Nothing here is a problem.

- Load: 4 queries (`stateFor` is properly eager-loaded), 10 `exists()` calls (M-8), `reconcileLoadedState` does 100 inner iterations.
- Render: 10 `Storage::url()` calls (free for public disks), ~40 redundant `__()` lookups (M-7).
- Save with no changes: 1 SELECT + 1 SELECT…FOR UPDATE + 1 role DELETE + 1 role upsert. **Zero** image UPDATEs thanks to `isDirty()`. Zero filesystem calls.
- Livewire snapshot: ~25 KB.

### 100 images

**Verdict: usable but noticeably slow; two fixes make it fine.**

- `reconcileLoadedState`: 10,000 inner iterations with ~20,000 `Arr::wrap()` allocations → ~5–15 ms (M-6).
- `fetchFileInformation`: 100 `stat()` locally — fine; **100 sequential S3 HEAD requests** — ~3 s (M-8). This dominates.
- `getRoles()` in loops: ~400 extra translation lookups + 100 Collection allocations (M-7).
- `editorPreviewData`: 100 preview URLs built per editor open; on a private disk that is 100 presigned-URL computations (~10 ms) and, more importantly, 100 full-resolution images requested by the browser for an 80 px thumbnail strip (O-1).
- Full reorder: 100 UPDATEs inside one transaction (M-15).
- Livewire snapshot: ~250 KB per request, each direction, HMAC'd.

Fixing M-8 and M-6 brings 100 images into comfortable territory. O-1 (thumbnails) is what makes it *pleasant*.

### 1,000+ images

**Verdict: not supported by this architecture. Do not raise `max_images` this far without redesign.**

- `reconcileLoadedState`: 1,000,000 inner iterations → 0.5–2 s of pure PHP **on every form load** (M-6).
- `fetchFileInformation`: 1,000 sequential S3 HEADs → request timeout (M-8).
- Livewire snapshot: ~2.5 MB serialised, HMAC'd and transmitted **on every interaction** — including every keystroke in an unrelated field on the same form. This is the hard wall and it is inherent to putting all items in Repeater state.
- Blade `$initialItems`: ~250 KB of inline JSON on top (L-7).
- Browser: 1,000 `<img>` elements; `loading="lazy"` helps, but 1,000 DOM nodes each with Alpine bindings (`x-bind:data-main`, `x-show`, `x-text` × 3) is ~5,000 reactive effects.
- Full reorder: 1,000 UPDATEs holding row locks.
- `getPreviewUrl` on a private disk: 1,000 presigned URLs per render.

**Recommendation:** Keep `max_images` at a sane default (10 is right; 50 is defensible), **document the ceiling explicitly** in the README, and consider adding a hard guard:

```php
// src/Forms/Components/ImageGallery.php — setUp()
$this->maxItems(function (): int {
    $max = (int) config('filament-image-gallery.max_images', 10);

    if ($max > 200) {
        throw new InvalidArgumentException(
            'filament-image-gallery.max_images above 200 is not supported; '
            .'the Livewire payload grows linearly with gallery size.'
        );
    }

    return $max;
});
```

Supporting thousands of images genuinely requires a different design — server-side pagination of the gallery grid with images stored outside Livewire state, which is a 1.x/2.0 conversation, not a fix.

---

## 6. Prioritised Implementation Plan

### Priority 1 — Fix immediately (blocks any public release)

| Ref | Item |
|---|---|
| C-1 | Validate submitted `path` (directory prefix + UUID shape + cross-gallery ownership) in `GalleryPersister::persist()`; add the `(disk, path)` index |
| C-2 | Remove `disk`/`visibility` state lookup from `getPreviewUrl()` |
| C-3 | Resolve `disk`/`visibility` from the persisted row in `resizeStoredImages()`; move the size check ahead of the stream copy in `processStored()` |
| H-1 | `php: ^8.2`, `illuminate/*: ^11.28\|^12.0\|^13.0`, `livewire/livewire: ^4.1` |
| H-2 | Declare `filament/filament`, `filament/notifications`, `filament/schemas` |
| H-3 | Require `ext-curl`; fail closed when `CURLOPT_RESOLVE` is unavailable |
| H-4 | Skip the delete hook for soft deletes; move file cleanup to `deleted` |
| H-6 | Guard `promotePendingUploaderFiles()` and `appendUpload()` with `isAddable()` |
| T-1 | Test infrastructure + the ten security regression tests above |

### Priority 2 — Recommended before release

| Ref | Item |
|---|---|
| H-5 | `ValidationException` instead of `InvalidArgumentException` for user-reachable failures |
| H-7 | `->visible()` on `editImage`; rate-limit remote downloads |
| M-1 | Dimension rule on the add modal and on remote downloads |
| M-4 | Validate `$arguments['item']` in the `editImage` action |
| M-5 | Rules on the hidden metadata fields |
| M-8 | `->fetchFileInformation(false)` on the item upload — one line, largest single perf win |
| M-3 | Fix the concurrent first-save race |
| M-14 | `max_pixels` guard before GD decode |
| M-2 | Derive the extension from the verified MIME; document the unoptimised-storage behaviour |
| M-6 | Rewrite `reconcileLoadedState()` as O(n) |
| M-7 | Memoise `getRoles()` / `getTools()` |
| M-12 | Header-only read + single `size()` call in `metadataFor()` |
| M-13 | `filesize()` instead of `Storage::size()` after `put()` |
| — | README, LICENSE (MIT — not `proprietary`), CHANGELOG, `.gitignore`, CI matrix, Pint + Larastan configs; drop the hardcoded `"version"` field |

### Priority 3 — Nice to have

| Ref | Item |
|---|---|
| M-9 | Give `FilamentImageGalleryPlugin` a purpose or delete it |
| M-10 | Narrow the `hydrateItems()` override, or add an upstream-drift guard test |
| M-11 | Reduce coupling to Livewire internals |
| M-15 | Batch the sort-order rewrite |
| L-1 | Drop the redundant `(image_id, role)` unique index |
| L-2 | Configurable morph key types |
| L-3 | Config-driven models and table names |
| L-4 | Ship factories |
| L-5 | Register Alpine components on `alpine:init`; add a build step |
| L-6 | Unify the two preview Alpine components so crop works with focal-point |
| L-7 | Trim `initialItems` to `roles` + `title` |
| L-8 | Decide on animated GIFs and document it |
| L-9 | Remove the dead `getMaximumImages()` fallback |
| L-10 | Wire up or remove the two unused translation keys |
| L-11 | `Js::from()` for the reorder handler |
| O-1 | Thumbnail generation |
| O-2 | Queued image processing |
| O-3 | `withImageGalleries()` eager-load scope |
| O-4 | `image-gallery:prune` command |
| O-5 | Split `ImageGallery.php` |

---

## 7. Summary Table

| Priority | Ref | Issue | Type | Impact | Effort |
|---|---|---|---|---|---|
| P1 | C-1 | Browser-controlled `path` → arbitrary file deletion / adoption | Security | Critical | Medium |
| P1 | C-2 | Browser-controlled `disk` → presigned URLs for arbitrary objects | Security | Critical | Low |
| P1 | C-3 | `resizeStoredImages()` reads from a tampered disk/path | Security | Critical | Low |
| P1 | H-4 | Soft delete permanently destroys image files | Data loss | High | Low |
| P1 | H-1 | `php: ^8.5` locks out the ecosystem | Compatibility | High | Low |
| P1 | H-2 | `filament/filament` used but undeclared | Compatibility | High | Low |
| P1 | H-3 | SSRF pinning fails open without ext-curl | Security | High | Low |
| P1 | H-6 | `promotePendingUploaderFiles()` bypasses `isAddable()` | Security | High | Low |
| P1 | T-1 | No tests, no test infrastructure | Reliability | High | High |
| P2 | H-5 | `InvalidArgumentException` → 500 + partial save | Reliability | High | Low |
| P2 | H-7 | `editImage` unguarded; unthrottled remote fetch | Security | Medium | Low |
| P2 | M-8 | `fetchFileInformation` → N `exists()` on form load | Performance | High | Low |
| P2 | M-14 | No pixel budget; ~200 MB GD peak per image | Performance | High | Low |
| P2 | M-6 | `reconcileLoadedState()` is O(n²) | Performance | Medium | Medium |
| P2 | M-3 | Concurrent first-save unique-constraint 500 | Correctness | Medium | Low |
| P2 | M-1 | Add modal skips the dimension check | Reliability | Medium | Low |
| P2 | M-5 | Hidden metadata fields unvalidated | Security | Medium | Low |
| P2 | M-4 | `editImage` fatals on a bad `item` argument | Reliability | Medium | Low |
| P2 | M-2 | Unoptimised uploads retain EXIF/GPS and payloads | Security | Medium | Medium |
| P2 | M-7 | `getRoles()` re-evaluated inside loops | Performance | Medium | Low |
| P2 | M-12 | Whole file loaded to read an image header | Performance | Medium | Low |
| P2 | M-13 | `Storage::size()` round trip after `put()` | Performance | Low | Low |
| P2 | — | No README / LICENSE / CI / static analysis | Maintainability | High | Medium |
| P3 | M-15 | Reorder issues one UPDATE per image | Performance | Low | Medium |
| P3 | M-9 | Plugin class is a no-op | Architecture | Low | Low |
| P3 | M-10 | `hydrateItems()` copies Filament internals | Compatibility | Medium | Medium |
| P3 | M-11 | Direct use of Livewire internal classes | Compatibility | Medium | Medium |
| P3 | L-1 | Redundant `(image_id, role)` unique index | Performance | Low | Low |
| P3 | L-2 | `uploader` morph assumes integer keys | Compatibility | Low | Low |
| P3 | L-3 | Models and table names not swappable | Architecture | Low | Medium |
| P3 | L-4 | No factories | DX | Low | Low |
| P3 | L-5 | Alpine registration depends on load order | Reliability | Low | Low |
| P3 | L-6 | Crop trigger broken when focal-point is enabled | Correctness | Low | Low |
| P3 | L-7 | `initialItems` duplicates unused state | Performance | Low | Low |
| P3 | L-8 | Animated GIFs silently flattened | Correctness | Low | Low |
| P3 | L-9 | Dead `getMaximumImages()` fallback | Code quality | Low | Low |
| P3 | L-10 | Two unused translation keys | Code quality | Low | Low |
| P3 | L-11 | Reorder handler built by concatenation | Code quality | Low | Low |
| P3 | O-1 | No thumbnail generation | Performance | High | High |
| P3 | O-2 | Synchronous image processing | Performance | Medium | High |
| P3 | O-3 | `imageForRole()` N+1 on partial eager loads | Performance | Medium | Low |
| P3 | O-4 | No orphan-file pruning | Operations | Low | Medium |
| P3 | O-5 | `ImageGallery.php` is 1,392 lines | Maintainability | Medium | High |

