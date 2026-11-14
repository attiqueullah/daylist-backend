<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * Shared behaviour of rows that sync to devices:
 *  - UUID keys; a client-supplied "id" is kept (offline creates),
 *  - timestamps stored with microseconds so the sync cursor is exact.
 */
trait SyncableModel
{
    use HasUuids;

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    // Random v4 UUIDs, the same kind the iOS app generates with UUID().
    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
