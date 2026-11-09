<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Laravel\Support\NullUser;
use Waynestate\Sesame\Tests\Laravel\Support\RecordingUser;
use Waynestate\Sesame\WayneCas\Deprecation;
use Waynestate\Sesame\WayneCas\UserResolver;

/**
 * The wayne-cas compatibility shim.
 *
 * It finds the user the way wayne-cas did (the model's static
 * findOrImportUser, with the verified username as the access ID), throws the
 * same AuthorizationException when there is no user, and says once that it is
 * a deprecated bridge.
 */
final class WayneCasShimTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', RecordingUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Deprecation::reset();
        RecordingUser::reset();
    }

    private function identity(): Identity
    {
        return new Identity('jdoe', [], 'fake', new DateTimeImmutable());
    }

    public function testItFindsTheUserThroughTheModel(): void
    {
        $user = (new UserResolver())->findOrImportUser($this->identity());

        self::assertSame('the-imported-user', $user);
        self::assertSame(['jdoe'], RecordingUser::$calls);
    }

    public function testItThrowsWhenTheModelHasNoUser(): void
    {
        config()->set('auth.providers.users.model', NullUser::class);

        $this->expectException(AuthorizationException::class);

        (new UserResolver())->findOrImportUser($this->identity());
    }

    public function testItThrowsWhenTheModelIsNotConfigured(): void
    {
        config()->set('auth.providers.users.model', null);

        $this->expectException(InvalidConfig::class);

        (new UserResolver())->findOrImportUser($this->identity());
    }

    public function testItLogsTheDeprecationOnce(): void
    {
        Log::partialMock()->shouldReceive('warning')->once()->with(Deprecation::MESSAGE);

        $resolver = new UserResolver();
        $user = $resolver->findOrImportUser($this->identity());
        $resolver->findOrImportUser($this->identity());

        self::assertSame('the-imported-user', $user);
    }

    public function testItAnswersAnAjaxGuestWith403(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app['router']->get('/wp', fn() => 'ok')->middleware(['web', 'wayne.cas']);

        $this->get('/wp', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(403);
    }

    public function testItLetsSesamesOwnRoutesThroughWhenInTheWebGroup(): void
    {
        // The way wayne-cas apps mount it: in the global web group. A guest
        // on /login must reach the login route, not be sent back to /login
        // by the middleware that guards the group.
        $app = $this->app;
        self::assertNotNull($app);
        $app['router']->pushMiddlewareToGroup('web', 'wayne.cas');

        $this->get('/login')->assertRedirect('/');
    }

    public function testItRefreshesAnEloquentUserThroughTheQuery(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'Jane Doe']);

        $refreshed = (new UserResolver())->refreshUser(new EloquentCasUser(['id' => 'jdoe']));

        self::assertInstanceOf(EloquentCasUser::class, $refreshed);
        self::assertSame('jdoe', $refreshed->getKey());
        self::assertSame('Jane Doe', $refreshed->getAttribute('username'));
    }

    public function testItRefreshesAnEloquentUserToNullAfterDeletion(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'Jane Doe']);

        $resolver = new UserResolver();
        self::assertInstanceOf(EloquentCasUser::class, $resolver->refreshUser(new EloquentCasUser(['id' => 'jdoe'])));

        DB::table('cas_users')->where('id', 'jdoe')->delete();

        self::assertNull($resolver->refreshUser(new EloquentCasUser(['id' => 'jdoe'])));
    }

    public function testItRefreshesAUserThroughAStaticFindMethod(): void
    {
        config()->set('auth.providers.users.model', StaticFindUser::class);

        $refreshed = (new UserResolver())->refreshUser(new EloquentCasUser(['id' => 'jdoe']));

        self::assertSame('jdoe', $refreshed);
    }

    public function testItThrowsWhenTheModelIsNeitherEloquentNorStaticFind(): void
    {
        config()->set('auth.providers.users.model', NullUser::class);

        try {
            (new UserResolver())->refreshUser(new EloquentCasUser(['id' => 'jdoe']));
            self::fail('Expected an InvalidConfig.');
        } catch (InvalidConfig $e) {
            self::assertSame('The wayne-cas shim needs auth.providers.users.model to be an Eloquent model or a class with a static find method.', $e->getMessage());
        }
    }

    public function testItRefreshesAnArrayUserThroughTheEloquentQuery(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'John Doe']);

        $refreshed = (new UserResolver())->refreshUser(['id' => 'jdoe', 'username' => 'Jane Doe']);

        self::assertInstanceOf(EloquentCasUser::class, $refreshed);
        self::assertSame('jdoe', $refreshed->getKey());
        self::assertSame('John Doe', $refreshed->getAttribute('username'));
    }

    public function testItRefreshesAnArrayUserToNullAfterDeletion(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        DB::table('cas_users')->insert(['id' => 'jdoe', 'username' => 'Jane Doe']);

        $resolver = new UserResolver();
        self::assertInstanceOf(EloquentCasUser::class, $resolver->refreshUser(['id' => 'jdoe', 'username' => 'Jane Doe']));

        DB::table('cas_users')->where('id', 'jdoe')->delete();

        self::assertNull($resolver->refreshUser(['id' => 'jdoe', 'username' => 'Jane Doe']));
    }

    public function testItRefreshesAnArrayWithNoKeyToNull(): void
    {
        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);

        self::assertNull((new UserResolver())->refreshUser(['username' => 'x']));
        self::assertSame([], RecordingUser::$calls);
    }

    public function testItPassesAnArrayThroughForANonEloquentModel(): void
    {
        config()->set('auth.providers.users.model', StaticFindUser::class);

        $array = ['id' => 'jdoe', 'username' => 'Jane Doe'];

        self::assertSame($array, (new UserResolver())->refreshUser($array));
    }

    public function testAJsonSessionArrayUserSignsInThroughTheShim(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $this->createCasUsersTable();

        config()->set('auth.providers.users.model', EloquentCasUser::class);
        config()->set('auth.guards.web.driver', 'sesame');
        config()->set('sesame.user_resolver', UserResolver::class);

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
 * A small Eloquent user for the wayne-cas shim tests.
 *
 * It is keyed by the string access ID, the way wayne-cas users are, and it
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
 * wayne-cas shim tests.
 *
 * It is not an Eloquent model, so the shim must take the static find() path
 * when it refreshes.
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
