<?php

namespace App\Models;

use App\Enum\HttpResponseCodes;
use App\Jobs\MoveMediaJob;
use App\Jobs\OptimizeMediaJob;
use App\Jobs\StoreUploadedFileJob;
use App\Traits\Uuids;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Media extends Model
{
    use HasFactory;
    use Uuids;
    use DispatchesJobs;

    public const INVALID_FILE_ERROR         = 1;
    public const FILE_SIZE_EXCEEDED_ERROR   = 2;
    public const FILE_NAME_EXISTS_ERROR     = 3;
    public const TEMP_FILE_ERROR            = 4;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'user_id',
        'mime_type',
        'permission',
        'storage',
        'description',
        'name',
        'size',
        'status',
    ];

    /**
     * The attributes that are appended.
     *
     * @var array<string>
     */
    protected $appends = [
        'url',
    ];

    /**
     * The default attributes.
     *
     * @var string[]
     */
    protected $attributes = [
        'storage' => 'cdn',
        'variants' => '[]',
        'description' => '',
        'dimensions' => '',
        'permission' => '',
    ];

    /**
     * The storage file list cache.
     *
     * @var array
     */
    protected static $storageFileListCache = [];

    /**
     * The variant types.
     *
     * @var int[][][]
     */
    protected static $variantTypes = [
        'image' => [
            'thumb'     => ['width' => 150, 'height' => 150],
            'small'     => ['width' => 300, 'height' => 225],
            'medium'    => ['width' => 768, 'height' => 576],
            'large'     => ['width' => 1024, 'height' => 768],
            'xlarge'    => ['width' => 1536, 'height' => 1152],
            'xxlarge'   => ['width' => 2048, 'height' => 1536],
            'scaled'    => ['width' => 2560, 'height' => 1920]
        ]
    ];


    /**
     * Model Boot
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updating(function ($media) {
            if (array_key_exists('permission', $media->getChanges()) === true) {
                $origPermission = $media->getOriginal()['permission'];
                $newPermission = $media->permission;

                $newPermissionLen = strlen($newPermission);

                if ($newPermissionLen !== strlen($origPermission)) {
                    if ($newPermissionLen === 0) {
                        $this->moveToStorage('cdn');
                    } else {
                        $this->moveToStorage('private');
                    }
                }
            }
        });

        static::deleting(function ($media) {
            $media->deleteFile();
        });
    }


    /**
     * Get Type Variants.
     *
     * @param string $type The variant type to get.
     * @return array The variant data.
     */
    public static function getTypeVariants(string $type): array
    {
        if (isset(self::$variantTypes[$type]) === true) {
            return self::$variantTypes[$type];
        }

        return [];
    }

    /**
     * Variants Get Mutator.
     *
     * @param mixed $value The value to mutate.
     * @return array|null The mutated value.
     */
    public function getVariantsAttribute(mixed $value): array|null
    {
        if (is_string($value) === true) {
            return json_decode($value, true);
        }

        return [];
    }

    /**
     * Variants Set Mutator.
     *
     * @param mixed $value The value to mutate.
     */
    public function setVariantsAttribute(mixed $value): void
    {
        if (is_array($value) !== true) {
            $value = [];
        }

        $this->attributes['variants'] = json_encode(($value ?? []));
    }

    /**
     * Get previous variant.
     *
     * @param string $type    The variant type.
     * @param string $variant The initial variant.
     * @return string The previous variant name (or '').
     */
    public function getPreviousVariant(string $type, string $variant): string
    {
        if (isset(self::$variantTypes[$type]) === false) {
            return '';
        }

        $variants = self::$variantTypes[$type];
        $keys = array_keys($variants);

        $currentIndex = array_search($variant, $keys);
        if ($currentIndex === false || $currentIndex === 0) {
            return '';
        }

        return $keys[($currentIndex - 1)];
    }

    /**
     * Get next variant.
     *
     * @param string $type    The variant type.
     * @param string $variant The initial variant.
     * @return string The next variant name (or '').
     */
    public function getNextVariant(string $type, string $variant): string
    {
        if (isset(self::$variantTypes[$type]) === false) {
            return '';
        }

        $variants = self::$variantTypes[$type];
        $keys = array_keys($variants);

        $currentIndex = array_search($variant, $keys);
        if ($currentIndex === false || $currentIndex === (count($keys) - 1)) {
            return '';
        }

        return $keys[($currentIndex + 1)];
    }

    /**
     * Get variant URL.
     *
     * @param string  $variant       The variant to find.
     * @param boolean $returnNearest Return the nearest variant if request is not found.
     * @return string The URL.
     */
    public function getVariantURL(string $variant, bool $returnNearest = true): string
    {
        $variants = $this->variants;
        if (isset($variants[$variant]) === true) {
            return self::getUrlPath() . $variants[$variant];
        }

        if ($returnNearest === true) {
            $variantType = explode('/', $this->mime_type)[0];
            $previousVariant = $variant;
            while (empty($previousVariant) === false) {
                $previousVariant = $this->getPreviousVariant($variantType, $previousVariant);
                if (empty($previousVariant) === false && isset($variants[$previousVariant]) === true) {
                    return self::getUrlPath() . $variants[$previousVariant];
                }
            }
        }

        return '';
    }




    /**
     * Delete file and associated files with the modal.
     */
    public function deleteFile(): void
    {
        $fileName = $this->name;
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        $files = Storage::disk($this->storage)->files();

        foreach ($files as $file) {
            if (preg_match("/{$baseName}(-[a-zA-Z0-9]+)?\.{$extension}/", $file) === 1) {
                Storage::disk($this->storage)->delete($file);
            }
        }

        $this->invalidateCFCache();
    }

    /**
     * Invalidate Cloudflare Cache.
     *
     * @throws InvalidArgumentException Exception.
     */
    private function invalidateCFCache(): void
    {
        $zone_id = env("CLOUDFLARE_ZONE_ID");
        $api_key = env("CLOUDFLARE_API_KEY");
        if ($zone_id !== null && $api_key !== null && $this->url !== "") {
            $urls = [$this->url];

            foreach ($this->variants as $variant => $name) {
                $urls[] = str_replace($this->name, $name, $this->url);
            }

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://api.cloudflare.com/client/v4/zones/" . $zone_id . "/purge_cache",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => "DELETE",
                CURLOPT_POSTFIELDS => json_encode(["files" => $urls]),
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "Authorization: Bearer " . $api_key
                ],
            ]);
            curl_exec($curl);
            curl_close($curl);
        }//end if
    }

    /**
     * Get URL path
     */
    public function getUrlPath(): string
    {
        $url = config("filesystems.disks.$this->storage.url");
        return "$url/";
    }

    /**
     * Return the file URL
     */
    public function getUrlAttribute(): string
    {
        if (isset($this->attributes['name']) === true) {
            return self::getUrlPath() . $this->name;
        }

        return '';
    }

    /**
     * Return the file owner
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Move files to new storage device.
     *
     * @param string $storage The storage ID to move to.
     */
    public function moveToStorage(string $storage): void
    {
        if ($storage !== $this->storage && Config::has("filesystems.disks.$storage") === true) {
            $this->status = "Processing media";
            MoveMediaJob::dispatch($this, $storage)->onQueue('media');
            $this->save();
        }
    }

    /**
     * Create new Media from UploadedFile data.
     *
     * @param App\Models\Request           $request The request data.
     * @param Illuminate\Http\UploadedFile $file    The file.
     * @return null|Media The result or null if not successful.
     */
    public static function createFromUploadedFile(Request $request, UploadedFile $file): ?Media
    {
        $request->merge([
            'title' => $request->get('title', ''),
            'name' => '',
            'size' => 0,
            'mime_type' => '',
            'status' => '',
        ]);

        if ($request->get('storage') === null) {
            // We store images by default locally
            if (strpos($file->getMimeType(), 'image/') === 0) {
                $request->merge([
                    'storage' => 'local',
                ]);
            } else {
                $request->merge([
                    'storage' => 'cdn',
                ]);
            }
        }

        $mediaItem = $request->user()->media()->create($request->all());
        $mediaItem->updateWithUploadedFile($file);

        return $mediaItem;
    }

    /**
     * Update Media with UploadedFile data.
     *
     * @param Illuminate\Http\UploadedFile $file The file.
     * @return null|Media The media item.
     */
    public function updateWithUploadedFile(UploadedFile $file): ?Media
    {
        if ($file === null || $file->isValid() !== true) {
            throw new \Exception('The file is invalid.', self::INVALID_FILE_ERROR);
        }

        if ($file->getSize() > static::getMaxUploadSize()) {
            throw new \Exception('The file size is larger then permitted.', self::FILE_SIZE_EXCEEDED_ERROR);
        }

        $name = static::generateUniqueFileName($file->getClientOriginalName());
        if ($name === false) {
            throw new \Exception('The file name already exists in storage.', self::FILE_NAME_EXISTS_ERROR);
        }

        // remove file if there is an existing entry in this medium item
        if (strlen($this->name) > 0 && strlen($this->storage) > 0) {
            Storage::disk($this->storage)->delete($this->name);
            foreach ($this->variants as $variantName => $fileName) {
                Storage::disk($this->storage)->delete($fileName);
            }

            $this->name = '';
            $this->variants = [];
        }

        if (strlen($this->title) === 0) {
            $this->title = $name;
        }

        $this->name = $name;
        $this->size = $file->getSize();
        $this->mime_type = $file->getMimeType();
        $this->status = 'Processing media';
        $this->save();

        $temporaryFilePath = generateTempFilePath();
        copy($file->path(), $temporaryFilePath);

        try {
            StoreUploadedFileJob::dispatch($this, $temporaryFilePath)->onQueue('media');
        } catch (\Exception $e) {
            $this->status = 'Error';
            $this->save();

            throw $e;
        }//end try

        return $this;
    }

    /**
     * Download the file from the storage to the user.
     *
     * @param string  $variant  The variant to download or null if none.
     * @param boolean $fallback Fallback to the original file if the variant is not found.
     * @return JsonResponse|StreamedResponse The response.
     * @throws BindingResolutionException The Exception.
     */
    public function download(string $variant = null, bool $fallback = true)
    {
        $path = $this->name;
        if ($variant !== null) {
            if (array_key_exists($variant, $this->variant) === true) {
                $path = $this->variant[$variant];
            } else {
                return response()->json(['message' => 'The resource was not found.'], HttpResponseCodes::HTTP_NOT_FOUND);
            }
        }

        $disk = Storage::disk($this->storage);
        if ($disk->exists($path) === true) {
            $stream = $disk->readStream($path);
            $response = response()->stream(
                function () use ($stream) {
                    fpassthru($stream);
                },
                200,
                [
                    'Content-Type' => $this->mime_type,
                    'Content-Length' => $disk->size($path),
                    'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
                ]
            );

            return $response;
        }

        return response()->json(['message' => 'The resource was not found.'], HttpResponseCodes::HTTP_NOT_FOUND);
    }

    /**
     * Get the server maximum upload size
     */
    public static function getMaxUploadSize(): int
    {
        $sizes = [
            ini_get('upload_max_filesize'),
            ini_get('post_max_size'),
            ini_get('memory_limit')
        ];

        foreach ($sizes as &$size) {
            $size = trim($size);
            $last = strtolower($size[(strlen($size) - 1)]);
            switch ($last) {
                case 'g':
                    $size = (intval($size) * 1024);
                    // Size is in MB - fallthrough
                case 'm':
                    $size = (intval($size) * 1024);
                    // Size is in KB - fallthrough
                case 'k':
                    $size = (intval($size) * 1024);
                    // Size is in B - fallthrough
            }
        }

        return min($sizes);
    }

    /**
     * Generate a file name that is available within storage.
     *
     * @param string $fileName The proposed file name.
     * @return string|boolean The available file name or false if failed.
     */
    public static function generateUniqueFileName(string $fileName)
    {
        $index = 1;
        $maxTries = 100;
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $fileName = static::sanitizeFilename(pathinfo($fileName, PATHINFO_FILENAME));

        if (static::fileNameHasSuffix($fileName) === true || static::fileExistsInStorage("$fileName.$extension") === true || Media::where('name', "$fileName.$extension")->where('status', 'not like', 'failed%')->exists() === true) {
            $fileName .= '-';
            for ($i = 1; $i < $maxTries; $i++) {
                $fileNameIndex = $fileName . $index;
                if (static::fileExistsInStorage("$fileNameIndex.$extension") !== true && Media::where('name', "$fileNameIndex.$extension")->where('status', 'not like', 'Failed%')->exists() !== true) {
                    return "$fileNameIndex.$extension";
                }

                ++$index;
            }

            return false;
        }

        return "$fileName.$extension";
    }

    /**
     * Determines if the file name exists in any of the storage disks.
     *
     * @param string  $fileName    The file name to check.
     * @param boolean $ignoreCache Ignore the file list cache.
     * @return boolean If the file exists on any storage disks.
     */
    public static function fileExistsInStorage(string $fileName, bool $ignoreCache = false): bool
    {
        $disks = array_keys(Config::get('filesystems.disks'));

        if ($ignoreCache === false) {
            if (count(static::$storageFileListCache) === 0) {
                $disks = array_keys(Config::get('filesystems.disks'));

                foreach ($disks as $disk) {
                    try {
                        static::$storageFileListCache[$disk] = Storage::disk($disk)->allFiles();
                    } catch (\Exception $e) {
                        Log::error("Cannot get a file list for storage device '$disk'. Error: " . $e->getMessage());
                        continue;
                    }
                }
            }

            foreach (static::$storageFileListCache as $disk => $files) {
                if (in_array($fileName, $files) === true) {
                    return true;
                }
            }
        } else {
            $disks = array_keys(Config::get('filesystems.disks'));

            foreach ($disks as $disk) {
                try {
                    if (Storage::disk($disk)->exists($fileName) === true) {
                        return true;
                    }
                } catch (\Exception $e) {
                    Log::error($e->getMessage());
                    throw new \Exception("Cannot verify if file '$fileName' already exists in storage device '$disk'");
                }
            }
        }//end if

        return false;
    }

    /**
     * Test if the file name contains a special suffix.
     *
     * @param string $fileName The file name to test.
     * @return boolean If the file name contains the special suffix.
     */
    public static function fileNameHasSuffix(string $fileName): bool
    {
        $suffix = '/(-\d+x\d+|-scaled)$/i';
        $fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);

        return preg_match($suffix, $fileNameWithoutExtension) === 1;
    }

    /**
     * Sanitize fileName for upload
     *
     * @param string $fileName Filename to sanitize.
     */
    private static function sanitizeFilename(string $fileName): string
    {
        /*
        # file system reserved https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
        [<>:"/\\\|?*]|

        # control characters http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
        [\x00-\x1F]|

        # non-printing characters DEL, NO-BREAK SPACE, SOFT HYPHEN
        [\x7F\xA0\xAD]|

        # URI reserved https://www.rfc-editor.org/rfc/rfc3986#section-2.2
        [#\[\]@!$&\'()+,;=]|

        # URL unsafe characters https://www.ietf.org/rfc/rfc1738.txt
        [{}^\~`]
        */

        $fileName = preg_replace(
            '~
            [<>:"/\\\|?*]|
            [\x00-\x1F]|
            [\x7F\xA0\xAD]|
            [#\[\]@!$&\'()+,;=]|
            [{}^\~`]
            ~x',
            '-',
            $fileName
        );

        $fileName = ltrim($fileName, '.-');

        $fileName = preg_replace([
        // "file   name.zip" becomes "file-name.zip"
            '/ +/',
        // "file___name.zip" becomes "file-name.zip"
            '/_+/',
        // "file---name.zip" becomes "file-name.zip"
            '/-+/'
        ], '-', $fileName);
        $fileName = preg_replace([
        // "file--.--.-.--name.zip" becomes "file.name.zip"
            '/-*\.-*/',
        // "file...name..zip" becomes "file.name.zip"
            '/\.{2,}/'
        ], '.', $fileName);
        // lowercase for windows/unix interoperability http://support.microsoft.com/kb/100625
        $fileName = mb_strtolower($fileName, mb_detect_encoding($fileName));
        // ".file-name.-" becomes "file-name"
        $fileName = trim($fileName, '.-');

        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $fileName = mb_strcut(
            pathinfo($fileName, PATHINFO_FILENAME),
            0,
            (255 - ($ext !== '' ? strlen($ext) + 1 : 0)),
            mb_detect_encoding($fileName)
        ) . ($ext !== '' ? '.' . $ext : '');
        return $fileName;
    }
}
