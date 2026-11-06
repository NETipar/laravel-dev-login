<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class UuidAccount extends Authenticatable
{
    use HasUuids;

    protected $table = 'uuid_accounts';

    protected $guarded = [];
}
