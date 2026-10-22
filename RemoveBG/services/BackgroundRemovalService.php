<?php
declare(strict_types=1);

/** Contract every background-removal provider implements. Returns transparent PNG bytes. */
interface BackgroundRemovalProvider
{
    public function removeBackground(string $path, string $mime): string;
}

final class BackgroundRemovalService
{
    public function __construct(private BackgroundRemovalProvider $provider)
    {
    }

    /** Picks the provider from configuration. Add new providers here. */
    public static function fromConfig(): self
    {
        switch (strtolower((string)cfg('provider', 'pixelcut'))) {
            case 'pixelcut':
                return new self(new PixelcutProvider(
                    (string)cfg('api_key', ''),
                    (string)cfg('api_url'),
                    max(5, (int)cfg('api_timeout_seconds', 60)),
                    (string)cfg('response_mode', 'binary'),
                    (array)cfg('trusted_result_hosts', []),
                    (int)cfg('max_result_bytes', 52428800),
                    (string)cfg('ca_bundle', '')
                ));
        }
        throw new AppException('not_configured',
            'Background removal is not configured on this server yet. Please contact the site owner.',
            503, 'unknown provider');
    }

    public function validateImage(?array $uploadedFile): array
    {
        return validate_uploaded_image($uploadedFile);
    }

    public function removeBackground(array $image): string
    {
        return $this->provider->removeBackground($image['path'], $image['mime']);
    }

    /** @return array{0:string,1:string} [job id, file path] */
    public function saveProcessedImage(string $png): array
    {
        $id = random_id();
        $path = storage_dir('processed') . DIRECTORY_SEPARATOR . $id . '.png';
        if (@file_put_contents($path, $png, LOCK_EX) !== strlen($png)) {
            @unlink($path);
            throw new AppException('storage_unavailable',
                'The server could not save the processed image. Please contact the site owner.', 500, 'write failed');
        }
        return [$id, $path];
    }

    public function deleteTemporaryFiles(array $paths): void
    {
        foreach ($paths as $p) {
            if (is_string($p) && is_file($p)) {
                @unlink($p);
            }
        }
    }
}
