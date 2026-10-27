<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * The app's side of a login.
 *
 * Every driver ends here: the core hands the app a verified identity, and the
 * app decides what a user is and what to do when it has not seen one before.
 * The wayne-cas contract, kept.
 */
interface UserResolver
{
    /**
     * Find the user this identity belongs to, importing it when needed.
     *
     * @return mixed The app's user, in whatever shape the app uses.
     */
    public function findOrImportUser(Identity $identity): mixed;
}
