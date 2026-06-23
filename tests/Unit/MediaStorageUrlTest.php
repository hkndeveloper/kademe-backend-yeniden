<?php

namespace Tests\Unit;

use App\Support\MediaStorage;
use Tests\TestCase;

class MediaStorageUrlTest extends TestCase
{
    public function test_r2_paths_are_served_from_custom_public_domain(): void
    {
        config()->set('filesystems.media_disk', 'r2');
        config()->set('filesystems.disks.r2.url', 'https://img.hakankekec.me');
        config()->set('filesystems.disks.r2.legacy_urls', []);

        $this->assertSame(
            'https://img.hakankekec.me/program-photos/1/photo.jpg',
            MediaStorage::url('program-photos/1/photo.jpg'),
        );
    }

    public function test_legacy_r2_public_urls_are_rewritten_to_custom_public_domain(): void
    {
        config()->set('filesystems.media_disk', 'r2');
        config()->set('filesystems.disks.r2.url', 'https://img.hakankekec.me');
        config()->set('filesystems.disks.r2.legacy_urls', ['https://pub-old.r2.dev']);

        $this->assertSame(
            'https://img.hakankekec.me/digital-bohca/file.pdf',
            MediaStorage::url('https://pub-old.r2.dev/digital-bohca/file.pdf'),
        );
    }

    public function test_r2_dev_active_public_url_prefers_stable_custom_domain(): void
    {
        config()->set('filesystems.media_disk', 'r2');
        config()->set('filesystems.disks.r2.url', 'https://pub-589f223e143c4507ac7880af5db1dbc8.r2.dev');
        config()->set('filesystems.disks.r2.stable_url', 'https://img.hakankekec.me');
        config()->set('filesystems.disks.r2.legacy_urls', []);

        $this->assertSame(
            'https://img.hakankekec.me/kademe-media/homepage/card.jpg',
            MediaStorage::url('https://pub-589f223e143c4507ac7880af5db1dbc8.r2.dev/kademe-media/homepage/card.jpg'),
        );

        $this->assertSame(
            'https://img.hakankekec.me/kademe-media/homepage/card.jpg',
            MediaStorage::url('kademe-media/homepage/card.jpg'),
        );
    }

    public function test_unrelated_external_urls_are_not_rewritten(): void
    {
        config()->set('filesystems.media_disk', 'r2');
        config()->set('filesystems.disks.r2.url', 'https://img.hakankekec.me');
        config()->set('filesystems.disks.r2.legacy_urls', ['https://pub-old.r2.dev']);

        $this->assertSame(
            'https://example.com/external/file.pdf',
            MediaStorage::url('https://example.com/external/file.pdf'),
        );
    }
}
