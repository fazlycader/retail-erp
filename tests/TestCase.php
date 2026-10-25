<?php

namespace Tests;

use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\LocationSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Settings are memoised per process; every test starts with a fresh database.
        CompanySetting::forget();
        LocationSetting::forget();
        Category::forget();
    }
}
