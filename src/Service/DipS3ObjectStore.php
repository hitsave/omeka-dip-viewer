<?php
namespace OmekaDipViewer\Service;

use Aws\S3\S3Client;

/**
 * Read BagIt members from an unpacked prefix on Wasabi/S3.
 */
class DipS3ObjectStore
{
    protected DipS3Config $config;
    protected ?S3Client $client = null;

    public function __construct(DipS3Config $config)
    {
        $this->config = $config;
    }

    public function isEnabled(): bool
    {
        return $this->config->isConfigured();
    }

    public function objectKey(string $packagePrefix, string $innerPath): string
    {
        $prefix = trim(str_replace('\\', '/', $packagePrefix), '/');
        $inner = ltrim(str_replace('\\', '/', $innerPath), '/');

        return $prefix === '' ? $inner : $prefix . '/' . $inner;
    }

    public function getObject(string $packagePrefix, string $innerPath, ?string $bucket = null): string
    {
        $result = $this->client()->getObject([
            'Bucket' => $this->resolveBucket($bucket),
            'Key' => $this->objectKey($packagePrefix, $innerPath),
        ]);
        $body = $result['Body'] ?? null;
        if ($body === null) {
            throw new \RuntimeException('Empty S3 object body.');
        }

        return (string) $body;
    }

    /**
     * @return array{body: string, content_type: string, content_length: int}
     */
    public function getObjectRange(
        string $packagePrefix,
        string $innerPath,
        ?int $start = null,
        ?int $end = null,
        ?string $bucket = null
    ): array {
        $args = [
            'Bucket' => $this->resolveBucket($bucket),
            'Key' => $this->objectKey($packagePrefix, $innerPath),
        ];
        if ($start !== null && $end !== null) {
            $args['Range'] = sprintf('bytes=%d-%d', $start, $end);
        }
        $result = $this->client()->getObject($args);
        $body = (string) ($result['Body'] ?? '');
        $type = (string) ($result['ContentType'] ?? 'application/octet-stream');
        $length = (int) ($result['ContentLength'] ?? strlen($body));

        return [
            'body' => $body,
            'content_type' => $type,
            'content_length' => $length,
        ];
    }

    public function headContentLength(string $packagePrefix, string $innerPath, ?string $bucket = null): int
    {
        $result = $this->client()->headObject([
            'Bucket' => $this->resolveBucket($bucket),
            'Key' => $this->objectKey($packagePrefix, $innerPath),
        ]);

        return (int) ($result['ContentLength'] ?? 0);
    }

    protected function resolveBucket(?string $bucket): string
    {
        $resolved = trim((string) ($bucket ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }
        $default = $this->config->getBucket();
        if ($default === '') {
            throw new \InvalidArgumentException(
                'S3 bucket required: set dip_wasabi_uri on the media or a default bucket in dip-s3.config.php.'
            );
        }

        return $default;
    }

    protected function client(): S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!$this->isEnabled()) {
            throw new \RuntimeException('DIP S3 is not configured (config/dip-s3.config.php).');
        }
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $this->config->getRegion(),
            'endpoint' => $this->config->getEndpoint(),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $this->config->getAccessKey(),
                'secret' => $this->config->getSecretKey(),
            ],
        ]);

        return $this->client;
    }
}
