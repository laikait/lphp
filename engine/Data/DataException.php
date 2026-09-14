<?php

declare(strict_types=1);

namespace App\Engine\Data;

use App\Engine\Error\FrameworkException;

final class DataException extends FrameworkException
{
    public static function operatorNeedsAList(string $field, Operator $operator): self
    {
        return new self(\sprintf(
            'The "%s" criterion uses %s, which compares against a list, but it was given a single value.',
            $field,
            $operator->value,
        ));
    }

    /**
     * An empty IN would match nothing, which is almost always a bug upstream --
     * an unfiltered list of keys, most often. Saying so beats returning an
     * empty result that looks like valid data.
     */
    public static function emptyList(string $field, Operator $operator): self
    {
        return new self(\sprintf(
            'The "%s" criterion uses %s with an empty list, which can never match. '
            . 'Check the result of whatever produced the list before querying.',
            $field,
            $operator->value,
        ));
    }

    public static function noModel(string $collection, string $method): self
    {
        return new self(\sprintf(
            'This query over "%s" was not given a model class, so %s() has nothing to build. '
            . 'Use rows() for plain arrays, into() for read models, or create the query from a repository.',
            $collection,
            $method,
        ));
    }

    public static function negativePage(int $page, int $perPage): self
    {
        return new self(\sprintf(
            'A page must be at least 1 and a page size at least 1; %d and %d were given.',
            $page,
            $perPage,
        ));
    }

    public static function nothingToPersist(string $model): self
    {
        return new self(\sprintf(
            'There is nothing to persist: the %s has no changes. '
            . 'A model loaded from storage and left alone does not need to be written.',
            $model,
        ));
    }

    public static function cannotRemoveWithoutIdentity(string $model): self
    {
        return new self(\sprintf('A %s with no identity has never been stored, so it cannot be removed.', $model));
    }

    public static function insertReturnedNoIdentity(string $collection, string $key): self
    {
        return new self(\sprintf(
            'Inserting into "%s" produced no value for the "%s" key. '
            . 'Either the source assigns identities and did not, or the model should carry its own.',
            $collection,
            $key,
        ));
    }

    public static function unknownCollection(string $collection): self
    {
        return new self(\sprintf('No collection named "%s" exists in this source.', $collection));
    }
}
