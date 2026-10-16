<?php

namespace XLaravel\FederatedIdentity\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use XLaravel\FederatedIdentity\Events\IdentityLinked;
use XLaravel\FederatedIdentity\Events\IdentityUnlinked;
use XLaravel\FederatedIdentity\Exceptions\AccountExistsException;
use XLaravel\FederatedIdentity\Exceptions\AlreadyLinkedException;
use XLaravel\FederatedIdentity\Exceptions\EmailMissingException;
use XLaravel\FederatedIdentity\Exceptions\IdentityTakenException;
use XLaravel\FederatedIdentity\IdentityResolver;
use XLaravel\FederatedIdentity\Models\Identity;
use XLaravel\FederatedIdentity\ProviderUser;
use XLaravel\FederatedIdentity\Tests\Fixtures\CustomIdentity;
use XLaravel\FederatedIdentity\Tests\Fixtures\LowercaseUserProvider;
use XLaravel\FederatedIdentity\Tests\Fixtures\User;

class IdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function testFindsNothingWhenNoAccountExists(): void
    {
        $this->assertNull($this->resolver()->find($this->providerUser()));
        $this->assertNull($this->resolver()->match($this->providerUser()));
        $this->assertSame(0, Identity::query()->count());
    }

    public function testFindsTheLinkedAccountBySubjectEvenWhenTheEmailChanged(): void
    {
        $user = $this->user(verified: true);
        $this->resolver()->link($user, $this->providerUser());

        $found = $this->resolver()->find($this->providerUser(email: 'new@example.com'));

        $this->assertSameUser($user, $found);
    }

    public function testFindsALinkedAccountWithoutAnEmail(): void
    {
        $user = $this->user(verified: true);
        $this->resolver()->link($user, $this->providerUser(provider: 'apple'));

        $found = $this->resolver()->find($this->providerUser(provider: 'apple', email: null));

        $this->assertSameUser($user, $found);
    }

    public function testMatchesAnExistingAccountWithoutLinkingIt(): void
    {
        $user = $this->user(verified: true);

        $matched = $this->resolver()->match($this->providerUser());

        $this->assertSameUser($user, $matched);
        $this->assertSame(0, Identity::query()->count());
        $this->assertNull($this->resolver()->find($this->providerUser()));
    }

    public function testRefusesToMatchWhenTheProviderDidNotVerifyTheEmail(): void
    {
        $this->user(verified: true);

        $this->expectExceptionObject(AccountExistsException::for('google'));

        $this->resolver()->match($this->providerUser(emailVerified: false));
    }

    public function testRefusesToMatchWhenTheAccountDidNotVerifyTheEmail(): void
    {
        $this->user(verified: false);

        $this->expectException(AccountExistsException::class);

        $this->resolver()->match($this->providerUser());
    }

    public function testRefusesToMatchByEmailWhenItIsDisabled(): void
    {
        Config::set('federated-identity.link_by_email', false);
        $this->user(verified: true);

        $this->expectException(AccountExistsException::class);

        $this->resolver()->match($this->providerUser());
    }

    public function testRefusesToMatchAnAccountThatHasAnotherAccountOfTheProvider(): void
    {
        $user = $this->user(verified: true);
        $this->resolver()->link($user, $this->providerUser(id: 'first'));

        $this->expectException(AccountExistsException::class);

        $this->resolver()->match($this->providerUser(id: 'second'));
    }

    public function testRequiresAnEmailToMatch(): void
    {
        $this->expectExceptionObject(EmailMissingException::for('apple'));

        $this->resolver()->match($this->providerUser(provider: 'apple', email: null));
    }

    public function testFindsUsersThroughTheConfiguredUserProvider(): void
    {
        Auth::provider('lowercase', fn () => new LowercaseUserProvider(app(Hasher::class)));
        Config::set('auth.providers.lowercase', ['driver' => 'lowercase', 'model' => User::class]);
        Config::set('federated-identity.provider', 'lowercase');
        $user = $this->user(verified: true);

        $matched = $this->resolver()->match($this->providerUser(email: 'ALI@example.com'));

        $this->assertSameUser($user, $matched);
    }

    public function testTheApplicationSignsInAMatchedAccountByLinkingIt(): void
    {
        Event::fake([IdentityLinked::class]);
        $user = $this->user(verified: true);

        $matched = $this->resolver()->match($this->providerUser());
        $this->assertNotNull($matched);
        $this->resolver()->link($matched, $this->providerUser());

        $found = $this->resolver()->find($this->providerUser());

        $this->assertSameUser($user, $found);
        Event::assertDispatched(IdentityLinked::class, fn (IdentityLinked $event) => $event->user->getAuthIdentifier() === $user->getKey());
    }

    public function testTheApplicationRegistersAnAccountAndLinksIt(): void
    {
        $user = DB::transaction(function () {
            $user = $this->user(verified: true);
            $this->resolver()->link($user, $this->providerUser());

            return $user;
        });

        $found = $this->resolver()->find($this->providerUser());

        $this->assertSameUser($user, $found);
    }

    public function testUsesTheConfiguredIdentityModel(): void
    {
        Config::set('federated-identity.model', CustomIdentity::class);
        $user = $this->user(verified: true);

        $this->assertInstanceOf(CustomIdentity::class, $this->resolver()->link($user, $this->providerUser()));
        $this->assertInstanceOf(CustomIdentity::class, $this->resolver()->identity($this->providerUser()));
        $this->assertInstanceOf(CustomIdentity::class, $user->identities()->first());
        $this->assertSameUser($user, $this->resolver()->find($this->providerUser()));
    }

    public function testLinkingTheSameAccountTwiceIsHarmless(): void
    {
        $user = $this->user(verified: true);

        $first = $this->resolver()->link($user, $this->providerUser());
        $second = $this->resolver()->link($user, $this->providerUser());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Identity::query()->count());
    }

    public function testRefusesToLinkAProviderAccountOfAnotherUser(): void
    {
        $this->resolver()->link($this->user(verified: true), $this->providerUser());

        $this->expectExceptionObject(IdentityTakenException::for('google'));

        $this->resolver()->link($this->user(verified: true, email: 'other@example.com'), $this->providerUser());
    }

    public function testRefusesToLinkAnotherAccountOfALinkedProvider(): void
    {
        $user = $this->user(verified: true);
        $this->resolver()->link($user, $this->providerUser(id: 'first'));

        $this->expectExceptionObject(AlreadyLinkedException::for('google'));

        $this->resolver()->link($user, $this->providerUser(id: 'second'));
    }

    public function testASignUpThatLostTheRaceFindsTheWinnersAccount(): void
    {
        $winner = $this->user(verified: true, email: 'winner@example.com');
        $this->resolver()->link($winner, $this->providerUser());
        $loser = $this->user(verified: true, email: 'loser@example.com');

        try {
            $this->resolver()->link($loser, $this->providerUser());
            $this->fail('Linked a provider account that belongs to another user.');
        } catch (IdentityTakenException) {
            $loser->forceDelete();
        }

        $found = $this->resolver()->find($this->providerUser());

        $this->assertSameUser($winner, $found);
        $this->assertSame(1, User::query()->count());
    }

    public function testUnlinksAProvider(): void
    {
        Event::fake([IdentityUnlinked::class]);
        $user = $this->user(verified: true);
        $this->resolver()->link($user, $this->providerUser());

        $identity = $this->resolver()->unlink($user, 'google');

        $this->assertNotNull($identity);
        $this->assertFalse($user->hasIdentity('google'));
        $this->assertNull($this->resolver()->unlink($user, 'google'));
        Event::assertDispatchedTimes(IdentityUnlinked::class, 1);
    }

    public function testTellsWhetherAUserOwnsAProviderAccount(): void
    {
        $user = $this->user(verified: true);
        $other = $this->user(verified: true, email: 'other@example.com');
        $this->resolver()->link($user, $this->providerUser());

        $this->assertTrue($this->resolver()->owns($user, $this->providerUser()));
        $this->assertFalse($this->resolver()->owns($other, $this->providerUser()));
        $this->assertFalse($this->resolver()->owns($user, $this->providerUser(id: 'unknown')));
    }

    public function testReturnsTheIdentityOfAProviderAccount(): void
    {
        $user = $this->user(verified: true);
        $linked = $this->resolver()->link($user, $this->providerUser());

        $identity = $this->resolver()->identity($this->providerUser());

        $this->assertNotNull($identity);
        $this->assertTrue($identity->is($linked));
        $this->assertNull($this->resolver()->identity($this->providerUser(id: 'unknown')));
    }

    private function assertSameUser(User $expected, ?Authenticatable $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertSame($expected->getKey(), $actual->getAuthIdentifier());
    }

    private function resolver(): IdentityResolver
    {
        return app()->make(IdentityResolver::class);
    }

    private function user(bool $verified, string $email = 'ali@example.com'): User
    {
        return User::query()->forceCreate([
            'name' => 'Existing',
            'email' => $email,
            'email_verified_at' => $verified ? now() : null,
            'password' => 'secret',
        ]);
    }

    private function providerUser(
        string $provider = 'google',
        string $id = '1098',
        ?string $email = 'ali@example.com',
        bool $emailVerified = true,
    ): ProviderUser {
        return new ProviderUser(
            provider: $provider,
            id: $id,
            audience: 'web.apps.googleusercontent.com',
            email: $email,
            emailVerified: $emailVerified,
            givenName: 'Ali',
            familyName: 'Veli',
        );
    }
}
