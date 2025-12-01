<?php

namespace Roshify\VersionVault\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Roshify\VersionVault\VersionVaultServiceProvider;
use Illuminate\Database\Schema\Blueprint;

/**
 * @mixin Orchestra\Testbench\TestCase
 *
 * Helper methods available on `$this` in tests:
 * @method void createTable(string $name, \Closure $callback)
 * @method string registerTestModel(string $shortClass, array $options = [])
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Return package service providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            VersionVaultServiceProvider::class,
        ];
    }

    /**
     * Configure environment before application boots.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Use sqlite in-memory for speed
        $app['config']->set('database.default', env('DB_CONNECTION', 'sqlite'));
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
        ]);

        // Make sure package config keys exist with sensible defaults for tests
        $app['config']->set('version-vault.snapshot_interval', 10);
        $app['config']->set('version-vault.table_name', 'version_vault_versions');
        $app['config']->set('version-vault.store_empty', false);
    }

    /**
     * Bootstrap and migrate for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure package migrations are available to Testbench
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Make sure the testing database is fresh for each test
        // `migrate:fresh` will drop all tables and re-run migrations
        Artisan::call('migrate:fresh', ['--database' => 'sqlite']);

        // Optionally run package seeders here if needed:
        // Artisan::call('db:seed', ['--class' => SomeSeeder::class]);
    }

    /**
     * Convenience helper: create a schema for a table used in tests.
     *
     * Example:
     * $this->createTable('articles', function (Blueprint $table) { ... });
     *
     * @param string $table
     * @param \Closure $callback
     * @return void
     */
    protected function createTable(string $table, \Closure $callback): void
    {
        if (Schema::hasTable($table)) {
            Schema::drop($table);
        }

        Schema::create($table, $callback);
    }

    /**
     * Convenience helper: drop a table if exists.
     *
     * @param string $table
     * @return void
     */
    protected function dropTableIfExists(string $table): void
    {
        if (Schema::hasTable($table)) {
            Schema::drop($table);
        }
    }

    /**
     * Register an inline model class in the test namespace using the given classname.
     *
     * Usage:
     * $modelClass = $this->registerTestModel('Article', [
     *     'table' => 'articles',
     *     'fillable' => ['title', 'body'],
     *     'useTrait' => \Roshify\VersionVault\Traits\HasVersioning::class,
     * ]);
     *
     * Returns full class FQN of the created model.
     *
     * @param string $shortClass
     * @param array $options
     *      - table: string
     *      - fillable: array
     *      - timestamps: bool
     *      - useTrait: class-string|null
     * @return string
     */
    protected function registerTestModel(string $shortClass, array $options = []): string
    {
        $namespace = 'Roshify\\VersionVault\\Tests\\Fixtures';
        $class = $shortClass;
        $fqcn = "{$namespace}\\{$class}";

        $table = $options['table'] ?? strtolower($class) . 's';
        $fillable = $options['fillable'] ?? [];
        $timestamps = array_key_exists('timestamps', $options) ? (bool)$options['timestamps'] : false;

        $trait = $options['useTrait'] ?? null;
        $traitLine = '';
        if ($trait) {
            $trait = ltrim($trait, '\\');
            $traitLine = "    use \\" . $trait . ";\n";
        }

        $timestampsLine = $timestamps ? "    public \$timestamps = true;\n" : "    public \$timestamps = false;\n";
        $fillablePhp = var_export($fillable, true);

        $php  = "namespace {$namespace};\n\n";
        $php .= "use Illuminate\\Database\\Eloquent\\Model;\n\n";
        $php .= "class {$class} extends Model\n";
        $php .= "{\n";
        $php .= $traitLine;
        $php .= "    protected \$table = '{$table}';\n";
        $php .= "    protected \$fillable = {$fillablePhp};\n";
        $php .= $timestampsLine;
        $php .= "}\n";

        if (!class_exists($fqcn, false)) {
            eval($php);
        }

        return $fqcn;
    }
}
