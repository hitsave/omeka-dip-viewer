<?php
namespace OmekaDipViewer\Service;

/**
 * Wasabi/S3 connection settings (config/dip-s3.config.php).
 *
 * Bucket is optional: each DIP media should carry bucket in dip_wasabi_uri / package_wasabi_uri.
 * When only package_storage_prefix is set, getBucket() is used as a legacy default.
 */
class DipS3Config
{
    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        $creds = $this->config['credentials'] ?? [];
        $key = trim((string) ($creds['key'] ?? ''));
        $secret = trim((string) ($creds['secret'] ?? ''));

        return $key !== '' && $secret !== '' && $this->getEndpoint() !== ''
            && !str_starts_with($key, 'REPLACE');
    }

    public function getEndpoint(): string
    {
        return rtrim((string) ($this->config['endpoint'] ?? ''), '/');
    }

    public function getRegion(): string
    {
        return (string) ($this->config['region'] ?? 'us-east-1');
    }

    public function getBucket(): string
    {
        return trim((string) ($this->config['bucket'] ?? ''));
    }

    public function getAccessKey(): string
    {
        return trim((string) ($this->config['credentials']['key'] ?? ''));
    }

    public function getSecretKey(): string
    {
        return trim((string) ($this->config['credentials']['secret'] ?? ''));
    }
}
