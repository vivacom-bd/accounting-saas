<?php

use App\Providers\AdminServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AdminServiceProvider::class,
    FeatureServiceProvider::class,
];
