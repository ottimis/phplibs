<?php

namespace ottimis\phplibs;

use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;
use ottimis\phplibs\schemas\Base\OGResponse;
use RuntimeException;

class OGStorage
{
    private S3Client $client;
    private string $bucket;
    protected Logger $Logger;

    public function __construct(?string $bucket = null)
    {
        $this->Logger = Logger::getInstance();
        $this->bucket = $bucket ?? getenv('S3_BUCKET') ?: '';

        if (empty($this->bucket)) {
            throw new RuntimeException("S3 bucket is required. Set S3_BUCKET env or pass it to the constructor.");
        }

        $config = [
            'version' => 'latest',
            'region' => getenv('S3_REGION') ?: getenv('AWS_REGION') ?: 'eu-central-1',
        ];

        // Custom endpoint for S3-compatible services (DigitalOcean Spaces, MinIO, etc.)
        $endpoint = getenv('S3_ENDPOINT');
        if (!empty($endpoint)) {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = filter_var(
                getenv('S3_PATH_STYLE') ?: 'false',
                FILTER_VALIDATE_BOOLEAN
            );
        }

        // Explicit credentials (access key + secret)
        $accessKey = getenv('S3_ACCESS_KEY') ?: getenv('AWS_ACCESS_KEY_ID');
        $secret = getenv('S3_SECRET_KEY') ?: getenv('AWS_SECRET_ACCESS_KEY');

        if (!empty($accessKey) && !empty($secret)) {
            $config['credentials'] = [
                'key' => $accessKey,
                'secret' => $secret,
            ];
        } elseif (getenv('ENV') === 'local' && !empty(getenv('S3_PROFILE_NAME'))) {
            $config['credentials'] = CredentialProvider::sso(getenv('S3_PROFILE_NAME'));
        }
        // Otherwise let the SDK use the default credential chain (IAM role, instance profile, etc.)

        $this->client = new S3Client($config);
    }

    /**
     * Upload a file from a local path.
     *
     * @param string $key The destination path/key in the bucket
     * @param string $filePath Absolute path to the local file
     * @param string $acl ACL policy (private, public-read, etc.)
     * @param string|null $contentType MIME type. Auto-detected if null.
     * @param array $metadata Custom metadata key-value pairs
     */
    public function upload(
        string  $key,
        string  $filePath,
        string  $acl = 'private',
        ?string $contentType = null,
        array   $metadata = []
    ): OGResponse
    {
        if (!file_exists($filePath)) {
            return new OGResponse(success: false, errorMessage: "File not found: $filePath");
        }

        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $filePath,
            'ACL' => $acl,
        ];

        if ($contentType) {
            $params['ContentType'] = $contentType;
        } elseif ($mime = mime_content_type($filePath)) {
            $params['ContentType'] = $mime;
        }

        if (!empty($metadata)) {
            $params['Metadata'] = $metadata;
        }

        try {
            $result = $this->client->putObject($params);
            return new OGResponse(
                success: true,
                data: [
                    'key' => $key,
                    'url' => $result['ObjectURL'] ?? $this->getUrl($key),
                ]
            );
        } catch (\Throwable $e) {
            $this->Logger->error("S3 upload failed for key '$key': " . $e->getMessage(), "OGSTG");
            return new OGResponse(success: false, errorMessage: $e->getMessage());
        }
    }

    /**
     * Upload raw content (string or binary data).
     *
     * @param string $key The destination path/key in the bucket
     * @param string $body The file content
     * @param string $contentType MIME type
     * @param string $acl ACL policy
     * @param array $metadata Custom metadata key-value pairs
     */
    public function put(
        string $key,
        string $body,
        string $contentType = 'application/octet-stream',
        string $acl = 'private',
        array  $metadata = []
    ): OGResponse
    {
        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $contentType,
            'ACL' => $acl,
        ];

        if (!empty($metadata)) {
            $params['Metadata'] = $metadata;
        }

        try {
            $result = $this->client->putObject($params);
            return new OGResponse(
                success: true,
                data: [
                    'key' => $key,
                    'url' => $result['ObjectURL'] ?? $this->getUrl($key),
                ]
            );
        } catch (\Throwable $e) {
            $this->Logger->error("S3 put failed for key '$key': " . $e->getMessage(), "OGSTG");
            return new OGResponse(success: false, errorMessage: $e->getMessage());
        }
    }

    /**
     * Upload a base64-encoded file.
     *
     * @param string $key The destination path/key in the bucket
     * @param string $base64 Base64-encoded content (with or without data URI prefix)
     * @param string|null $contentType MIME type. Auto-detected from data URI if null.
     * @param string $acl ACL policy
     */
    public function putBase64(
        string  $key,
        string  $base64,
        ?string $contentType = null,
        string  $acl = 'private'
    ): OGResponse
    {
        // Handle data URI format: data:image/png;base64,iVBOR...
        if (str_starts_with($base64, 'data:')) {
            if (preg_match('/^data:([^;]+);base64,(.+)$/', $base64, $matches)) {
                $contentType ??= $matches[1];
                $base64 = $matches[2];
            }
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return new OGResponse(success: false, errorMessage: "Invalid base64 data");
        }

        return $this->put($key, $decoded, $contentType ?? 'application/octet-stream', $acl);
    }

    /**
     * Download a file and return its content as a string.
     */
    public function get(string $key): OGResponse
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return new OGResponse(
                success: true,
                data: [
                    'body' => (string)$result['Body'],
                    'contentType' => $result['ContentType'] ?? null,
                    'contentLength' => $result['ContentLength'] ?? null,
                    'lastModified' => $result['LastModified'] ? $result['LastModified']->format('c') : null,
                    'metadata' => $result['Metadata'] ?? [],
                ]
            );
        } catch (\Throwable $e) {
            $this->Logger->error("S3 get failed for key '$key': " . $e->getMessage(), "OGSTG");
            return new OGResponse(success: false, errorMessage: $e->getMessage());
        }
    }

    /**
     * Download a file to a local path.
     */
    public function download(string $key, string $destinationPath): OGResponse
    {
        try {
            $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SaveAs' => $destinationPath,
            ]);

            return new OGResponse(success: true, data: ['path' => $destinationPath]);
        } catch (\Throwable $e) {
            $this->Logger->error("S3 download failed for key '$key': " . $e->getMessage(), "OGSTG");
            return new OGResponse(success: false, errorMessage: $e->getMessage());
        }
    }

    /**
     * Delete one or more objects.
     */
    public function delete(string ...$keys): OGResponse
    {
        if (count($keys) === 1) {
            try {
                $this->client->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $keys[0],
                ]);
                return new OGResponse(success: true);
            } catch (\Throwable $e) {
                $this->Logger->error("S3 delete failed for key '$keys[0]': " . $e->getMessage(), "OGSTG");
                return new OGResponse(success: false, errorMessage: $e->getMessage());
            }
        }

        // Batch delete
        $objects = array_map(fn(string $k) => ['Key' => $k], $keys);
        try {
            $this->client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $objects],
            ]);
            return new OGResponse(success: true);
        } catch (\Throwable $e) {
            $this->Logger->error("S3 batch delete failed: " . $e->getMessage(), "OGSTG");
            return new OGResponse(success: false, errorMessage: $e->getMessage());
        }
    }

    /**
     * Check if an object exists.
     */
    public function exists(string $key): bool
    {
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    /**
     * Generate the public URL for an object.
     */
    public function getUrl(string $key): string
    {
        return $this->client->getObjectUrl($this->bucket, $key);
    }

    /**
     * Generate a pre-signed (temporary) URL for an object.
     *
     * @param string $key Object key
     * @param int $expiresIn Seconds until the URL expires (default: 3600)
     */
    public function getSignedUrl(string $key, int $expiresIn = 3600): string
    {
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $request = $this->client->createPresignedRequest($cmd, "+{$expiresIn} seconds");
        return (string)$request->getUri();
    }

    /**
     * Generate a pre-signed URL for uploading an object directly from a client.
     *
     * @param string $key Object key
     * @param string $contentType Expected content type
     * @param int $expiresIn Seconds until the URL expires (default: 3600)
     * @param string $acl ACL policy
     */
    public function getSignedUploadUrl(
        string $key,
        string $contentType = 'application/octet-stream',
        int    $expiresIn = 3600,
        string $acl = 'private'
    ): string
    {
        $cmd = $this->client->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $contentType,
            'ACL' => $acl,
        ]);

        $request = $this->client->createPresignedRequest($cmd, "+{$expiresIn} seconds");
        return (string)$request->getUri();
    }

    /**
     * List objects under a given prefix.
     *
     * @param string $prefix Key prefix to filter by
     * @param int $maxKeys Maximum number of keys to return (default: 1000)
     */
    public function list(string $prefix = '', int $maxKeys = 1000): OGResponse
    {
        try {
            $result = $this->client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => $maxKeys,
            ]);

            $objects = array_map(fn($obj) => [
                'key' => $obj['Key'],
                'size' => $obj['Size'],
                'lastModified' => $obj['LastModified']->format('c'),
            ], $result['Contents'] ?? []);

            return new OGResponse(success: true, data: $objects);
        } catch (\Throwable $e) {
            $this->Logger->error("S3 list failed for prefix '$prefix': " . $e->getMessage(), "OGSTG");
            return new OGResponse(success: false, errorMessage: $e->getMessage());
        }
    }

    /**
     * Copy an object to a new key within the same bucket.
     */
    public function copy(string $sourceKey, string $destinationKey, string $acl = 'private'): OGResponse
    {
        try {
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'CopySource' => $this->bucket . '/' . $sourceKey,
                'Key' => $destinationKey,
                'ACL' => $acl,
            ]);

            return new OGResponse(
                success: true,
                data: [
                    'key' => $destinationKey,
                    'url' => $this->getUrl($destinationKey),
                ]
            );
        } catch (\Throwable $e) {
            $this->Logger->error("S3 copy failed from '$sourceKey' to '$destinationKey': " . $e->getMessage(), "OGSTG");
            return new OGResponse(success: false, errorMessage: $e->getMessage());
        }
    }

    /**
     * Access the underlying S3Client for advanced operations.
     */
    public function getClient(): S3Client
    {
        return $this->client;
    }
}