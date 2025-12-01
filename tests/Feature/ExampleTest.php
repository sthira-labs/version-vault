<?php

it('loads the package provider', function () {
    $this->assertTrue(app()->providerIsLoaded(
        \Roshify\VersionVault\VersionVaultServiceProvider::class
    ));
});

