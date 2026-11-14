<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    /**
     * Offline replays send the same create twice. If a row with the client's
     * id already exists and the user may see it, return it instead of failing.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    protected function existingForReplay(string $model, ?string $id, callable $userCanSee): ?Model
    {
        if ($id === null) {
            return null;
        }

        $existing = $model::withTrashed()->find($id);
        if ($existing === null) {
            return null;
        }

        if ($existing->trashed() || ! $userCanSee($existing)) {
            throw new HttpException(409, 'This id is already in use.');
        }

        return $existing;
    }
}
