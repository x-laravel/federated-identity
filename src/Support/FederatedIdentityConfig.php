<?php

namespace XLaravel\FederatedIdentity\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use XLaravel\FederatedIdentity\Models\Identity;

/**
 * @internal
 */
final class FederatedIdentityConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function provider(string $name): array
    {
        $config = Config::get("federated-identity.providers.{$name}");

        if (! is_array($config)) {
            return [];
        }

        /** @var array<string, mixed> */
        return array_filter($config, fn (mixed $key) => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return list<string>
     */
    public static function providerNames(): array
    {
        $providers = Config::get('federated-identity.providers', []);

        return is_array($providers) ? array_values(array_filter(array_keys($providers), is_string(...))) : [];
    }

    /**
     * @return list<string>
     */
    public static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $value = array_map(fn (mixed $item) => is_string($item) ? trim($item) : '', $value);

        return array_values(array_filter($value, fn (string $item) => $item !== ''));
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    public static function userProvider(): string
    {
        return self::string('provider') ?: 'users';
    }

    /**
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        $provider = self::userProvider();
        $model = Config::get("auth.providers.{$provider}.model");

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("Set auth.providers.{$provider}.model to the Eloquent model of your users.");
        }

        return $model;
    }

    /**
     * @return class-string<Identity>
     */
    public static function identityModel(): string
    {
        $model = Config::get('federated-identity.model') ?? Identity::class;

        if (! is_string($model) || ! is_a($model, Identity::class, true)) {
            throw new InvalidArgumentException('Set federated-identity.model to a model that extends ' . Identity::class . '.');
        }

        return $model;
    }

    public static function table(): string
    {
        return self::string('table') ?: 'user_identities';
    }

    public static function connection(): ?string
    {
        return self::string('connection') ?: null;
    }

    public static function string(string $key): string
    {
        return self::text(Config::get("federated-identity.{$key}"));
    }

    public static function integer(string $key, int $default): int
    {
        $value = Config::get("federated-identity.{$key}");

        return is_numeric($value) ? (int) $value : $default;
    }
}
