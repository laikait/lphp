<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Account;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Auth\Providers\EmptyProvider;
use App\Engine\Auth\Totp;
use App\Engine\Auth\TwoFactor;
use App\Engine\Auth\TwoFactorResult;
use App\Engine\Auth\UserProvider;
use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Tests\Fixtures\Auth\TwoFactorMemoryAccounts;
use App\Tests\Support\TestCase;

final class TwoFactorTest extends TestCase
{
    private Application $app;

    private TwoFactorMemoryAccounts $accounts;

    private string $secret;

    protected function setUp(): void
    {
        $this->app = $this->shippedApplication([
            'session' => ['store' => 'memory'],
            'auth' => ['password' => ['options' => ['cost' => 4]], 'two_factor' => ['issuer' => 'Shop']],
        ])->boot();

        $hash = $this->app->container()->get(Password::class)->hash(new Secret('pw'));
        $this->secret = Totp::secret();
        $this->accounts = new TwoFactorMemoryAccounts();
        $this->accounts->add('ada', 'ada@example.test', $hash, $this->secret);
        $this->accounts->add('bob', 'bob@example.test', $hash);
        $this->app->container()->instance(UserProvider::class, $this->accounts);
    }

    private function twoFactor(): TwoFactor
    {
        return $this->app->container()->get(TwoFactor::class);
    }

    private function auth(): AuthManager
    {
        return $this->app->container()->get(AuthManager::class);
    }

    public function test_without_a_secret_the_password_is_enough(): void
    {
        self::assertSame(TwoFactorResult::LoggedIn, $this->twoFactor()->attempt('bob@example.test', new Secret('pw')));
        self::assertSame('bob', $this->auth()->identity()->id);
    }

    public function test_with_a_secret_the_password_alone_logs_nobody_in(): void
    {
        self::assertSame(TwoFactorResult::ChallengeRequired, $this->twoFactor()->attempt('ada@example.test', new Secret('pw')));
        self::assertTrue($this->auth()->guest());
        self::assertTrue($this->twoFactor()->pending());

        $identity = $this->twoFactor()->challenge(Totp::code($this->secret));

        self::assertSame('ada', $identity?->id);
        self::assertSame('ada', $this->auth()->identity()->id);
        self::assertFalse($this->twoFactor()->pending());
    }

    public function test_a_wrong_password_never_reaches_the_code(): void
    {
        self::assertSame(TwoFactorResult::Failed, $this->twoFactor()->attempt('ada@example.test', new Secret('nope')));
        self::assertFalse($this->twoFactor()->pending());
        self::assertNull($this->twoFactor()->challenge(Totp::code($this->secret)));
    }

    public function test_a_code_works_once(): void
    {
        $code = Totp::code($this->secret);
        $this->twoFactor()->attempt('ada@example.test', new Secret('pw'));
        self::assertNotNull($this->twoFactor()->challenge($code));

        $this->auth()->logout();
        $this->twoFactor()->attempt('ada@example.test', new Secret('pw'));

        self::assertNull($this->twoFactor()->challenge($code));
        self::assertTrue($this->auth()->guest());
    }

    public function test_five_wrong_codes_and_the_password_is_needed_again(): void
    {
        $this->twoFactor()->attempt('ada@example.test', new Secret('pw'));

        for ($i = 1; $i < TwoFactor::MAX_TRIES; ++$i) {
            self::assertNull($this->twoFactor()->challenge('000000'));
            self::assertTrue($this->twoFactor()->pending(), 'Gave up after ' . $i);
        }

        self::assertNull($this->twoFactor()->challenge('000000'));
        self::assertFalse($this->twoFactor()->pending());
        self::assertNull($this->twoFactor()->challenge(Totp::code($this->secret)));
    }

    public function test_switching_it_on_and_a_recovery_code(): void
    {
        $bob = $this->accounts->byId('bob') ?? throw new \LogicException();
        $enrolment = $this->twoFactor()->begin($bob);

        self::assertStringStartsWith('otpauth://totp/Shop:bob%40example.test?secret=' . $enrolment->secret, $enrolment->uri);
        self::assertStringStartsWith('<svg', $enrolment->qrSvg);
        self::assertNull($this->twoFactor()->confirm($bob, '000000'), 'A wrong code switched it on.');
        self::assertNull($this->accounts->secretOf('bob'));

        $codes = $this->twoFactor()->confirm($bob, Totp::code($enrolment->secret));

        self::assertIsArray($codes);
        self::assertCount(10, $codes);
        self::assertMatchesRegularExpression('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $codes[0]);
        self::assertSame($enrolment->secret, $this->accounts->secretOf('bob'));
        self::assertNotContains($codes[0], $this->accounts->recoveryCodes($bob), 'A recovery code was stored in the clear.');

        // A lost phone: a recovery code instead, once.
        $this->twoFactor()->attempt('bob@example.test', new Secret('pw'));
        self::assertSame('bob', $this->twoFactor()->challenge(\strtoupper($codes[3]))?->id);
        self::assertCount(9, $this->accounts->recoveryCodes($bob));

        $this->auth()->logout();
        $this->twoFactor()->attempt('bob@example.test', new Secret('pw'));
        self::assertNull($this->twoFactor()->challenge($codes[3]));
    }

    public function test_switching_it_off(): void
    {
        $ada = $this->accounts->byId('ada') ?? throw new \LogicException();
        $this->twoFactor()->disable($ada);

        self::assertSame(TwoFactorResult::LoggedIn, $this->twoFactor()->attempt('ada@example.test', new Secret('pw')));
    }

    public function test_a_provider_without_the_interface_is_named(): void
    {
        $this->app->container()->instance(UserProvider::class, new EmptyProvider());

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('TwoFactorAccounts');

        $this->twoFactor()->begin(new Account(new Identity('x')));
    }
}
