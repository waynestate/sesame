<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Laravel\ModelUserResolver;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Laravel\Support\NullUser;
use Waynestate\Sesame\Tests\Laravel\Support\RecordingUser;

/**
 * SESAME's own user resolver.
 *
 * It finds the user through the model named in auth.providers.users.model
 * (the model's static findOrImportUser, with the verified username as the
 * access ID) and throws an AuthorizationException when there is no user.
 */
final class ModelUserResolverTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', RecordingUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingUser::reset();
    }

    private function identity(): Identity
    {
        return new Identity('jdoe', [], 'fake', new DateTimeImmutable());
    }

    public function testItFindsTheUserThroughTheModel(): void
    {
        $user = (new ModelUserResolver())->findOrImportUser($this->identity());

        self::assertSame('the-imported-user', $user);
        self::assertSame(['jdoe'], RecordingUser::$calls);
    }

    public function testItThrowsWhenTheModelHasNoUser(): void
    {
        config()->set('auth.providers.users.model', NullUser::class);

        $this->expectException(AuthorizationException::class);

        (new ModelUserResolver())->findOrImportUser($this->identity());
    }

    public function testItThrowsWhenTheModelIsNotConfigured(): void
    {
        config()->set('auth.providers.users.model', null);

        $this->expectException(InvalidConfig::class);

        (new ModelUserResolver())->findOrImportUser($this->identity());
    }

    public function testItRefreshesAnEloquentUserThroughTheQuery(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'Jane Doe']);

        $refreshed = (new ModelUserResolver())->refreshUser(new EloquentCasUser(['id' => 'jdoe']));

        self::assertInstanceOf(EloquentCasUser::class, $refreshed);
        self::assertSame('jdoe', $refreshed->getKey());
        self::assertSame('Jane Doe', $refreshed->getAttribute('username'));
    }

    public function testItRefreshesAnEloquentUserToNullAfterDeletion(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'Jane Doe']);

        $resolver = new ModelUserResolver();
        self::assertInstanceOf(EloquentCasUser::class, $resolver->refreshUser(new EloquentCasUser(['id' => 'jdoe'])));

        DB::table('cas_users')->where('id', 'jdoe')->delete();

        self::assertNull($resolver->refreshUser(new EloquentCasUser(['id' => 'jdoe'])));
    }

    public function testItRefreshesAUserThroughAStaticFindMethod(): void
    {
        config()->set('auth.providers.users.model', StaticFindUser::class);

        $refreshed = (new ModelUserResolver())->refreshUser(new EloquentCasUser(['id' => 'jdoe']));

        self::assertSame('jdoe', $refreshed);
    }

    public function testItThrowsWhenTheModelIsNeitherEloquentNorStaticFind(): void
    {
        config()->set('auth.providers.users.model', NullUser::class);

        try {
            (new ModelUserResolver())->refreshUser(new EloquentCasUser(['id' => 'jdoe']));
            self::fail('Expected an InvalidConfig.');
        } catch (InvalidConfig $e) {
            self::assertSame('The SESAME model resolver needs auth.providers.users.model to be an Eloquent model or a class with a static find method.', $e->getMessage());
        }
    }

    public function testItRefreshesAnArrayUserThroughTheEloquentQuery(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'John Doe']);

        $refreshed = (new ModelUserResolver())->refreshUser(['id' => 'jdoe', 'username' => 'Jane Doe']);

        self::assertInstanceOf(EloquentCasUser::class, $refreshed);
        self::assertSame('jdoe', $refreshed->getKey());
        self::assertSame('John Doe', $refreshed->getAttribute('username'));
    }

    public function testItRefreshesAnArrayUserToNullAfterDeletion(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'Jane Doe']);

        $resolver = new ModelUserResolver();
        self::assertInstanceOf(EloquentCasUser::class, $resolver->refreshUser(['id' => 'jdoe', 'username' => 'Jane Doe']));

        DB::table('cas_users')->where('id', 'jdoe')->delete();

        self::assertNull($resolver->refreshUser(['id' => 'jdoe', 'username' => 'Jane Doe']));
    }

    public function testItRefreshesAnArrayWithNoKeyToNull(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);

        self::assertNull((new ModelUserResolver())->refreshUser(['username' => 'x']));
        self::assertSame([], RecordingUser::$calls);
    }

    public function testItPassesAnArrayThroughForANonEloquentModel(): void
    {
        config()->set('auth.providers.users.model', StaticFindUser::class);

        $array = ['id' => 'jdoe', 'username' => 'Jane Doe'];

        self::assertSame($array, (new ModelUserResolver())->refreshUser($array));
    }

    public function testAJsonSessionArrayUserSignsInThroughTheResolver(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        config()->set('auth.guards.web.driver', 'sesame');
        config()->set('sesame.user_resolver', ModelUserResolver::class);

        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'Jane Doe']);

        /** @var \Illuminate\Contracts\Session\Session $store */
        $store = $app['session.store'];
        $store->put('sesame.user', ['id' => 'jdoe', 'username' => 'Jane Doe']);
        $store->put('sesame.signed_in_at', time());
        $store->put('sesame.last_seen', time());

        self::assertInstanceOf(EloquentCasUser::class, app(Sesame::class)->user());
        self::assertNotNull(Auth::guard('web')->user());
    }

    private function createCasUsersTable(): void
    {
        Schema::create('cas_users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('username');
        });
    }
}

/**
 * A small Eloquent user for the model resolver tests.
 *
 * It is keyed by the string access ID, the way the app's users are, and it
 * implements Authenticatable like the app's user model, so the sesame guard
 * can hand it back. The cas_users table is created by the test on the
 * testbench sqlite connection.
 */
class EloquentCasUser extends \Illuminate\Database\Eloquent\Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'cas_users';

    public function getKeyType(): string
    {
        return 'string';
    }
}

/**
 * A stand-in for a user model with a static find() method, for the
 * model resolver tests.
 *
 * It is not an Eloquent model, so the resolver must take the static find()
 * path when it refreshes.
 */
class StaticFindUser
{
    /**
     * @param  mixed  $key
     * @return mixed
     */
    public static function find($key)
    {
        return $key;
    }
}
