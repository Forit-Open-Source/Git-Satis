<?php

namespace Forit\GitSatis;

use Forit\GitSatis\Command\Build;
use Symfony\Component\Console\Application;

class App
{
    public static function run(): void
    {
        $app = new Application('git-satis');
        $app->add(new Build());
        $app->run();
    }
}
