<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Symfony annonce « en-us » par défaut ; l'application, elle,
        // envoie toujours sa langue. Les tests parlent français, comme
        // l'application par défaut, sauf s'ils en choisissent une autre.
        $this->withHeader('Accept-Language', 'fr');
    }
}
