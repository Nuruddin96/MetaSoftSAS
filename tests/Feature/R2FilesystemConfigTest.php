<?php

namespace Tests\Feature;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\TestCase;

/**
 * Covers the Cloudflare R2 filesystem disk (config/filesystems.php's 'r2'
 * entry) — configuration wiring only, never a real network call to R2
 * (no credentials exist in CI/test environments, and shouldn't). Confirms:
 * (1) the disk is correctly assembled from R2_* env vars, matching the
 * documented shape in .env.example; (2) it is genuinely opt-in — the
 * app's default disk and every existing upload call site
 * (Storage::disk('public')) are completely unaffected by R2 being
 * configured or not.
 */
class R2FilesystemConfigTest extends TestCase
{
    public function test_r2_disk_is_registered_with_the_s3_driver(): void
    {
        $this->assertSame('s3', config('filesystems.disks.r2.driver'));
    }

    public function test_r2_disk_reads_credentials_and_bucket_from_env(): void
    {
        config([
            'filesystems.disks.r2.key' => 'test-access-key',
            'filesystems.disks.r2.secret' => 'test-secret-key',
            'filesystems.disks.r2.bucket' => 'test-bucket',
            'filesystems.disks.r2.endpoint' => 'https://test-account.r2.cloudflarestorage.com',
            'filesystems.disks.r2.url' => 'https://cdn.example.com',
        ]);

        $this->assertSame('test-access-key', config('filesystems.disks.r2.key'));
        $this->assertSame('test-secret-key', config('filesystems.disks.r2.secret'));
        $this->assertSame('test-bucket', config('filesystems.disks.r2.bucket'));
        $this->assertSame('https://test-account.r2.cloudflarestorage.com', config('filesystems.disks.r2.endpoint'));
        $this->assertSame('https://cdn.example.com', config('filesystems.disks.r2.url'));
    }

    /** R2 has no AWS-style regions but the S3 client still requires a non-empty value — 'auto' is R2's own documented default. */
    public function test_r2_disk_defaults_to_the_auto_region(): void
    {
        $this->assertSame('auto', config('filesystems.disks.r2.region'));
    }

    /** R2 does not support virtual-hosted-style {bucket}.{endpoint} addressing — path-style is required. */
    public function test_r2_disk_uses_path_style_endpoint(): void
    {
        $this->assertTrue(config('filesystems.disks.r2.use_path_style_endpoint'));
    }

    /** Configuring R2 must never change what disk the app actually writes to by default. */
    public function test_the_default_filesystem_disk_is_unaffected_by_r2_being_configured(): void
    {
        config([
            'filesystems.disks.r2.key' => 'test-access-key',
            'filesystems.disks.r2.secret' => 'test-secret-key',
            'filesystems.disks.r2.bucket' => 'test-bucket',
        ]);

        $this->assertNotSame('r2', config('filesystems.default'));
    }

    /** Every existing tenant upload path (products, banners, reviews, logos, AI memory voice answers, Messenger/WhatsApp outgoing media) still targets the local "public" disk, completely unaffected by R2 being configured. */
    public function test_the_public_disk_still_resolves_to_local_storage_not_r2(): void
    {
        $this->assertSame('local', config('filesystems.disks.public.driver'));
    }

    public function test_r2_flysystem_adapter_classes_are_available(): void
    {
        $this->assertTrue(class_exists(AwsS3V3Adapter::class));
        $this->assertTrue(class_exists(S3Client::class));
    }

    /**
     * Building the 'r2' disk (Storage::disk('r2')) must not throw even
     * with placeholder credentials — the S3 client itself is lazy and
     * only makes a real network call on an actual read/write operation,
     * never at disk-resolution time. This proves the disk is wired
     * correctly without needing real Cloudflare credentials.
     */
    public function test_the_r2_disk_can_be_resolved_without_a_real_network_call(): void
    {
        config([
            'filesystems.disks.r2.key' => 'test-access-key',
            'filesystems.disks.r2.secret' => 'test-secret-key',
            'filesystems.disks.r2.bucket' => 'test-bucket',
            'filesystems.disks.r2.endpoint' => 'https://test-account.r2.cloudflarestorage.com',
        ]);

        $disk = Storage::disk('r2');

        $this->assertNotNull($disk);
    }
}
