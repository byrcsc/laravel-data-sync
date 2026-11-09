<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use Illuminate\Database\Eloquent\Model;

final class BulkWriteAttributes
{
    /**
     * The column values an insert or upsert writes for one row. Filling a model
     * applies its casts and mutators, and the timestamps are set by hand
     * because a query-builder write skips Eloquent's save().
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function for(string $modelClass, array $row): array
    {
        $model = new $modelClass;
        $model->forceFill($row);

        if ($model->usesTimestamps()) {
            $timestamp = $model->freshTimestampString();
            $createdAt = $model->getCreatedAtColumn();
            $updatedAt = $model->getUpdatedAtColumn();

            if ($createdAt !== null && $createdAt !== '') {
                $model->setAttribute($createdAt, $timestamp);
            }

            if ($updatedAt !== null && $updatedAt !== '') {
                $model->setAttribute($updatedAt, $timestamp);
            }
        }

        return $model->getAttributes();
    }
}
