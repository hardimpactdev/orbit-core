<?php

use HardImpact\Orbit\Jobs\DeleteSiteJob;
use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Models\Site;

beforeEach(function () {
    $environment = Environment::factory()->local()->create([
        'tld' => 'test',
    ]);
    $site = Site::create([
        'environment_id' => $environment->id,
        'name' => 'delete-test-site',
        'display_name' => 'Delete Test Site',
        'slug' => 'delete-test-site',
        'path' => '/tmp/delete-test-site',
        'php_version' => '8.4',
        'has_public_folder' => true,
        'status' => 'ready',
        'domain' => 'delete-test-site.test',
    ]);

    test()->environment = $environment;
    test()->site = $site;
});

describe('DeleteSiteJob', function () {
    describe('constructor', function () {
        it('accepts siteId and keepDatabase', function () {
            $job = new DeleteSiteJob(
                siteId: test()->site->id,
                keepDatabase: false,
            );

            expect($job->siteId)->toBe(test()->site->id);
            expect($job->keepDatabase)->toBeFalse();
        });

        it('defaults keepDatabase to false', function () {
            $job = new DeleteSiteJob(
                siteId: test()->site->id,
            );

            expect($job->keepDatabase)->toBeFalse();
        });

        it('can set keepDatabase to true', function () {
            $job = new DeleteSiteJob(
                siteId: test()->site->id,
                keepDatabase: true,
            );

            expect($job->keepDatabase)->toBeTrue();
        });
    });

    describe('tags', function () {
        it('returns correct horizon tags', function () {
            $job = new DeleteSiteJob(
                siteId: test()->site->id,
            );

            $tags = $job->tags();

            $siteId = test()->site->id;
            expect($tags)->toBe([
                'delete-site',
                "site-id:{$siteId}",
            ]);
        });
    });

    describe('job configuration', function () {
        it('has 120 second timeout', function () {
            $job = new DeleteSiteJob(
                siteId: test()->site->id,
            );

            expect($job->timeout)->toBe(120);
        });

        it('has 1 try (no retries)', function () {
            $job = new DeleteSiteJob(
                siteId: test()->site->id,
            );

            expect($job->tries)->toBe(1);
        });
    });

    // Note: handle() tests require integration testing as the job
    // interacts with filesystem and potentially Docker for database drops.
    // The core functionality is tested via DeletionPipeline unit tests.
});
