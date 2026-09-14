<?php

declare(strict_types=1);

namespace App\Engine\Cache;

/**
 * One stored value, and when it stops being valid.
 *
 * This exists so that a store can say "there is nothing here" without using the
 * value itself to say it. A store whose get() returns null for a miss cannot
 * hold a null, and the consequence is not theoretical: remember() around a
 * lookup that legitimately answers null would recompute on every single request
 * forever, quietly, and the cache would look like it was working.
 *
 * So a miss is a null CacheEntry, and a stored null is an entry whose value is
 * null. Every store in this framework is required to keep them apart, and the
 * conformance test that every store runs checks exactly that.
 */
final readonly class CacheEntry
{
    public function __construct(
        public mixed $value,
        /** Unix timestamp, or null for an entry that does not expire. */
        public ?int $expiresAt = null,
    ) {}

    public function hasExpired(?int $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($now ?? \time());
    }

    /** Seconds left, or null when it does not expire. */
    public function remaining(?int $now = null): ?int
    {
        return $this->expiresAt === null ? null : \max(0, $this->expiresAt - ($now ?? \time()));
    }
}
