<?php

namespace App\Services\GoLive;

use App\Models\User;

/** Who runs an import, its options (e.g. --location) and the source file name. */
final class ImportContext
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(public User $actor, public array $options, public string $file) {}
}
