<?php

declare(strict_types=1);

use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;

return [
    RepositoryInterface::class => InMemoryRepository::class,
    'repository.database' => ['singleton' => DatabaseRepository::class],
    'db.dsn' => ['value' => 'pgsql://localhost/app'],
    'repository' => ['alias' => RepositoryInterface::class],
    'config.factory' => ['factory' => static fn (): ClassWithoutDependencies => new ClassWithoutDependencies()],
    'reporter' => ['singleton' => InMemoryRepository::class, 'tags' => ['reporters']],
];
