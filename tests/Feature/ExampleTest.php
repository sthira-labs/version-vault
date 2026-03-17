<?php

it('loads the package provider', function () {
    $this->assertTrue(app()->providerIsLoaded(
        \SthiraLabs\VersionVault\VersionVaultServiceProvider::class
    ));
});

