<?php

namespace Avocadesign\StatamicTools\Tests;

use Avocadesign\StatamicTools\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
