<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Auth\Authenticators\SessionAuthenticator;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Security\Secret;
use App\Engine\Session\SessionManager;

/**
 * Who this request is, and how it stops or starts being somebody.
 *
 * **Lazy, like the session underneath it.** Authenticators run the first time
 * something asks, so a public page that never asks pays nothing and never
 * touches storage. AuthGuard asks only for routes that declared a requirement.
 *
 * **Authenticators run in order and the first answer wins.** Bearer token
 * before session cookie: a token is an explicit credential put on the request
 * on purpose, a cookie is one the browser attached on its own, and when both
 * are present the deliberate one is the one that was meant.
 */
final class AuthManager
{
    private ?Request $request = null;

    private ?Identity $identity = null;

    /** @param list<Authenticator> $authenticators */
    public function __construct(
        private readonly UserProvider $provider,
        private readonly Password $passwords,
        private readonly array $authenticators = [],
        private readonly ?SessionManager $sessions = null,
        private readonly ?HookEngine $hooks = null,
        /** @var list<string> */
        private readonly array $guestRoles = [],
    ) {}

    public function describe(): string
    {
        $names = \array_map(
            static fn(Authenticator $authenticator): string => $authenticator->describe(),
            $this->authenticators,
        );

        return $names === [] ? 'nothing attached' : \implode(', ', $names);
    }

    public function provider(): UserProvider
    {
        return $this->provider;
    }

    /** request.received: remember what is being served, and identify nobody yet. */
    public function onRequest(Request $request): void
    {
        $this->request = $request;
        $this->identity = null;
    }

    /**
     * Who this request is -- a guest if nothing said otherwise.
     *
     * Never null. An unauthenticated request is Identity::guest(), so nothing
     * downstream has a null case to forget, and a guest can still be granted
     * capabilities by configuration if an application wants anonymous readers.
     */
    public function identity(): Identity
    {
        if ($this->identity !== null) {
            return $this->identity;
        }

        $request = $this->request;

        if ($request === null) {
            return $this->identity = Identity::guest($this->guestRoles);
        }

        foreach ($this->authenticators as $authenticator) {
            $identity = $authenticator->identify($request);

            if ($identity !== null) {
                $this->hooks?->do('auth.identified', $identity, $authenticator->describe());

                return $this->identity = $identity;
            }
        }

        return $this->identity = Identity::guest($this->guestRoles);
    }

    public function check(): bool
    {
        return !$this->identity()->isGuest();
    }

    public function guest(): bool
    {
        return $this->identity()->isGuest();
    }

    /**
     * Check a password and log in if it is right.
     *
     * Returns null for every kind of failure -- no such account, wrong
     * password, suspended -- and takes about the same time doing it. **The
     * caller must not explain which.** "No account with that email" is a way to
     * find out which addresses are registered, and the usual next step is to
     * try that address on other sites.
     *
     * The hook fires for both outcomes, because a failed attempt is the thing
     * worth counting: rate limiting a login form and alerting on a burst both
     * need to hear about the failures, not the successes.
     */
    public function attempt(string $login, Secret $password): ?Identity
    {
        if ($login === '') {
            throw AuthException::emptyLogin();
        }

        $account = $this->provider->byLogin($login);

        // Even when there is no account: see Password::verify().
        $verified = $this->passwords->verify($password, $account?->passwordHash);

        if ($account === null || !$account->canUsePassword() || !$verified) {
            $this->hooks?->do('auth.failed', $login, $this->request);

            return null;
        }

        // The one moment the application holds the plaintext, and so the only
        // moment an old hash can be upgraded. A framework that did not say so
        // would leave every account on whatever was default the year it was
        // created -- but writing it is the application's job, because only the
        // provider knows where the hash lives.
        if ($account->passwordHash !== null && $this->passwords->needsRehash($account->passwordHash)) {
            $this->hooks?->do('auth.rehash', $account->identity, $this->passwords->hash($password));
        }

        $this->login($account->identity);

        return $account->identity;
    }

    /**
     * Make this identity the current one, and say so to the session.
     *
     * **The session id is regenerated here**, which is the reason
     * Session::regenerate() exists. Without it, an attacker who planted a
     * session id in the victim's browser before the login is holding a valid
     * authenticated session after it -- session fixation, and the one place in
     * a web application where it can be closed for good.
     *
     * Callable directly, without a password, which is what a "log in as" for
     * support staff or a single-sign-on callback needs. That is deliberate and
     * it is why it fires a hook: an application that wants to audit who became
     * whom listens here rather than trusting every caller to remember.
     */
    public function login(Identity $identity): void
    {
        $this->identity = $identity;

        if ($this->sessions !== null) {
            $session = $this->sessions->session();
            $session->regenerate();
            SessionAuthenticator::remember($session, $identity);
        }

        $this->hooks?->do('auth.login', $identity);
    }

    /**
     * Stop being anybody.
     *
     * invalidate(), not just forgetting the key: the session id changes and the
     * data goes with it. A logout that left the session intact would leave
     * whatever was in it -- a basket, a half-finished form, the previous user's
     * filters -- for whoever sits down at that machine next.
     */
    public function logout(): void
    {
        $previous = $this->identity();

        if ($this->sessions !== null && $this->sessions->isResumable()) {
            $this->sessions->session()->invalidate();
        }

        $this->identity = Identity::guest($this->guestRoles);

        if (!$previous->isGuest()) {
            $this->hooks?->do('auth.logout', $previous);
        }
    }
}
