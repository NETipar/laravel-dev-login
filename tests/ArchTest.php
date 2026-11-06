<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('NETipar\DevLogin')
    ->toUseStrictTypes();

arch('the events are plain value objects')
    ->expect('NETipar\DevLogin\Events')
    ->toBeFinal()
    ->toBeReadonly();

arch('the package declares no interface the consuming app could implement (require-dev safety)')
    ->expect('NETipar\DevLogin')
    ->not->toBeInterfaces();
