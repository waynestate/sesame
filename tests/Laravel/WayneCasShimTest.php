<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
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
}
