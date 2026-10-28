<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel\Support;

use Illuminate\Database\Eloquent\Model;
use Waynestate\Sesame\WayneCas\ReadOnlyAttributes;
use Waynestate\Sesame\WayneCas\WayneCasable;

/**
 * A stand-in for a wayne-cas app's user model.
 *
 * It uses the ReadOnlyAttributes trait the way a wayne-cas model would
 * (see the README's "Moving from wayne-cas" section), so the trait's public
 * methods can be exercised and PHPStan can see the trait in use.
 */
class ReadOnlyUser extends Model implements WayneCasable
{
    use ReadOnlyAttributes;

    /** @var string */
    protected $table = 'users';

    /** @var bool */
    public $timestamps = false;

    public static function findOrImportUser($accessId)
    {
        // Stand-in for the wayne-cas lookup; the trait tests never call it.
        return new self(['username' => $accessId]);
    }

    /**
     * @param  array<string, mixed>  $persistedAttributes
     */
    public function getReadOnlyAttributes($persistedAttributes = []): array
    {
        return [
            'cn' => $persistedAttributes['cn'] ?? null,
        ];
    }
}
