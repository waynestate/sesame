---
title: "Quick start | SESAME docs"
description: "Sign a user in from scratch with the fake driver, one step at a time."
url: https://sesame.apps.wayne.edu/docs/guides/quick-start.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Quick start[#](https://sesame.apps.wayne.edu/docs/guides/quick-start.html#quick-start)

The shortest walk through the door: a framework-free PHP page and the `fake` driver, which needs no server and no registration. Every snippet below is the [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html) example, one move at a time.

1. **Require the package.**
   ```
   composer require waynestate/sesame
   ```
   Expected: `waynestate/sesame` is in `composer.json` and installed in `vendor/`.
2. **Start the session.** The app owns the session; SESAME never starts one.
   ```php
   <?php
   
   declare(strict_types=1);
   
   require __DIR__.'/vendor/autoload.php';
   
   session_start();
   ```
   Note
   Set `session.gc_maxlifetime` to at least SESAME's idle timeout. PHP's default is 1440 seconds (24 minutes), and PHP's garbage collector deletes session data older than that, so on the defaults a raw-PHP app signs people out after 24 quiet minutes, long before SESAME's 120-minute idle timeout. Set `session.gc_maxlifetime = 7200` (your `session.idle_minutes` times 60) in `php.ini` or the FPM pool, or call `ini_set('session.gc_maxlifetime', '7200')` before `session_start()`.
   Expected: the session is started, and the autoload file sees the package.
3. **Configure the `fake` driver for local development.**
   ```php
   use Waynestate\Sesame\Config;
   
   $config = Config::fromArray([
       'driver' => 'fake',
       'environment' => 'local',
       'fake' => [
           'username' => 'jdoe',
           'attributes' => ['cn' => ['John Doe']],
       ],
   ]);
   ```
   Expected: `$config` names the `fake` driver, the local environment, and the identity the driver will return.
4. **Build the facade and hand it to the functions.**
   ```php
   use Waynestate\Sesame\DriverFactory;
   use Waynestate\Sesame\Identity;
   use Waynestate\Sesame\Sesame;
   use Waynestate\Sesame\Session\NativeSession;
   use Waynestate\Sesame\UserResolver;
   
   $sesame = new Sesame(
       DriverFactory::make($config),
       new class implements UserResolver {
           public function findOrImportUser(Identity $identity): mixed
           {
               return $identity->username;
           }
       },
       new NativeSession(),
       $config,
   );
   
   sesame($sesame);
   ```
   Expected: no output. The next call has a driver, a resolver, a session and a config to work with.
5. **Sign the user in.**
   ```php
   $user = sesame_login();
   ```
   Expected: when the login has not completed, SESAME sends the redirect to the identity provider and stops the script; when a ticket completes it, it sends the redirect to the driver's service URL and stops the script, so the ticket never stays in the address bar; when the user is already signed in and the request has no ticket, it returns the user. With the `fake` driver, that user is the one your resolver made from `jdoe`.
6. **Read the user on a protected page.**
   ```php
   $user = sesame_user();
   ```
   Expected: the user the login stored in the session.

## What you built[#](https://sesame.apps.wayne.edu/docs/guides/quick-start.html#what-you-built)

A framework-free PHP page that signs a user in through SESAME and reads the signed-in user back out of the session. The protocol never held the session: you started it and you read it, and switching driver is a config change with no code change.

## Next steps[#](https://sesame.apps.wayne.edu/docs/guides/quick-start.html#next-steps)

- [Which driver for my app?](https://sesame.apps.wayne.edu/docs/guides/which-driver.html): pick the key for your real door.
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html): the whole example, with every piece named.
- [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html): the same door in a Laravel app.
- [Session timeouts](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html): the two clocks every session runs on.
