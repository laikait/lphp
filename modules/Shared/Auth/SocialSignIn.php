<?php

declare(strict_types=1);

namespace App\Modules\Shared\Auth;

use App\Engine\Auth\Social\SocialException;
use App\Engine\Auth\Social\SocialLogin;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;

/**
 * GET /auth/{provider} and GET|POST /auth/{provider}/callback: signing in
 * with the providers under auth.social.providers.
 *
 *     <a href="/auth/google?return=/account">Sign in with Google</a>
 *
 * A provider that is not configured is a 404, so on a fresh installation
 * these routes answer nothing. Replace them -- declare the same paths in your
 * module, which registers later -- for a different answer when somebody has
 * no account, or a page instead of a redirect.
 */
final class SocialSignIn
{
    public function __construct(private readonly SocialLogin $social) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        if (!$this->social->has($provider)) {
            throw HttpException::notFound();
        }

        $return = $request->query('return');

        return $this->social->redirect($provider, $request, \is_string($return) ? $return : null);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (!$this->social->has($provider)) {
            throw HttpException::notFound();
        }

        try {
            $user = $this->social->callback($provider, $request);
        } catch (SocialException $e) {
            if ($e->isClientError()) {
                throw HttpException::badRequest($e->getMessage());
            }

            throw $e;
        }

        if ($this->social->login($user) === null) {
            throw HttpException::forbidden(\sprintf('No account here belongs to that %s account.', \ucfirst($provider)));
        }

        return (new RedirectResponse($this->social->returnTo($request)))->withCookie($this->social->forgetCookie());
    }
}
