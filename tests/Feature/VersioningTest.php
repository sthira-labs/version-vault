<?php

use Illuminate\Support\Facades\Schema;

it('has versions table from package migration', function () {
    $this->assertTrue(Schema::hasTable(config('version-vault.table_name')));
});

it('can create an article and the HasVersioning trait is available', function () {
    // create articles table
    /** @var \Roshify\VersionVault\Tests\TestCase $this */
    $this->createTable('articles', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('title')->nullable();
        $table->text('body')->nullable();
    });

    // register model class
    /** @var \Roshify\VersionVault\Tests\TestCase $this */
    $articleClass = $this->registerTestModel('Article', [
        'table' => 'articles',
        'fillable' => ['title', 'body'],
        'timestamps' => false,
        'useTrait' => \Roshify\VersionVault\Traits\HasVersioning::class,
    ]);

    // create record
    $a = $articleClass::create(['title' => 'Hello', 'body' => 'World']);

    // trait stub should expose recordVersion method (assert method exists)
    $this->assertTrue(method_exists($a, 'recordVersion'));
});
