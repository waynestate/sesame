<?php

declare(strict_types=1);

namespace Waynestate\Sesame\WayneCas;

/**
 * The wayne-cas user model contract, kept as an interface.
 *
 * A wayne-cas model used the WayneCasable trait. A SESAME model implements
 * this interface and uses the ReadOnlyAttributes trait, so the model's
 * findOrImportUser and getReadOnlyAttributes keep their wayne-cas shape.
 */
interface WayneCasable
{
    /**
     * Find the user this access ID belongs to, importing it when needed.
     *
     * @param mixed $accessId
     * @return mixed The user model, or null when the access ID has no user.
     */
    public static function findOrImportUser($accessId);

    /**
     * The read-only attributes for the model, as key => value pairs.
     *
     * One signature for the interface and the ReadOnlyAttributes trait, so
     * a wayne-cas model satisfies both: the method goes from protected to
     * public and takes the persisted attributes, optional.
     *
     * @param array<string, mixed> $persistedAttributes
     * @return array<string, mixed>
     */
    public function getReadOnlyAttributes($persistedAttributes = []): array;
}
