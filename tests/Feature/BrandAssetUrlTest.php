<?php

it('resolves the brand logo and favicon through ASSET_URL', function () {
    config(['app.asset_url' => 'https://example.test/hms/public']);

    $this->get('/login')
        ->assertOk()
        ->assertSee('href="https://example.test/hms/public/branding/st-joseph-dmi-logo.png"', false)
        ->assertSee('https:\/\/example.test\/hms\/public\/branding\/st-joseph-dmi-logo.png', false);
});
