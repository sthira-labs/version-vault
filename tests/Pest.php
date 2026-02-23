<?php

/**
 * @mixin \SthiraLabs\VersionVault\Tests\TestCase
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Services\ChangeDetector;
use SthiraLabs\VersionVault\Services\ConfigNormalizer;
use SthiraLabs\VersionVault\Services\SnapshotBuilder;
use SthiraLabs\VersionVault\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/
// /**
//  * @mixin \SthiraLabs\VersionVault\Tests\TestCase
//  */
// pest()->extend(\SthiraLabs\VersionVault\Tests\TestCase::class)
//  // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
//     ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function ensureVersionsTable(): void
{
    $table = config('version-vault.table_name', 'version_vault_versions');

    if (Schema::hasTable($table)) {
        return;
    }

    Schema::create($table, function (Blueprint $t) {
        $t->id();
        $t->string('versionable_type');
        $t->unsignedBigInteger('versionable_id');
        $t->unsignedBigInteger('version');
        $t->json('diff')->nullable();
        $t->json('snapshot')->nullable();
        $t->json('changed_paths')->nullable();
        $t->string('action')->nullable();
        $t->json('meta')->nullable();
        $t->timestamp('created_at')->nullable();
    });
}

function changeDetector(): ChangeDetector
{
    return new ChangeDetector();
}

function changeDetectorProxy(): ChangeDetectorTestProxy
{
    return new ChangeDetectorTestProxy();
}

function snapshotBuilder(): SnapshotBuilder
{
    return new SnapshotBuilder(new ConfigNormalizer());
}
