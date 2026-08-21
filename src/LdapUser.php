<?php

declare(strict_types=1);

namespace CzuPef\Ldap;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class LdapUser implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw  the untouched ldap_get_entries() row, so a
     *                                     project can read attributes this class does
     *                                     not model without forking the package
     */
    public function __construct(
        public string $dn,
        public ?string $login,
        public ?string $email,
        public ?string $first_name,
        public ?string $last_name,
        public ?int $uic = null,
        public ?string $cn = null,
        public ?CarbonInterface $expires = null,
        public array $raw = [],
    ) {}

    /**
     * Map one ldap_get_entries() row to an LdapUser.
     *
     * Every attribute is optional because callers may request a narrow
     * attribute set; only `dn` is always present in an LDAP result. A missing
     * or empty attribute comes back as null, so "the directory didn't say" is
     * never confused with a real value.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromLdapResult(array $entry): self
    {
        $value = static function (string $attribute) use ($entry): ?string {
            $value = $entry[$attribute][0] ?? null;

            if (! is_scalar($value) || (string) $value === '') {
                return null;
            }

            return (string) $value;
        };

        $uic = $value('czuuic');

        return new self(
            dn: is_string($entry['dn'] ?? null) ? $entry['dn'] : '',
            login: $value('samaccountname'),
            email: $value('mail'),
            first_name: $value('givenname'),
            last_name: $value('sn'),
            uic: is_numeric($uic) ? (int) $uic : null,
            cn: $value('cn'),
            expires: self::parseAccountExpires($value('accountexpires')),
            raw: $entry,
        );
    }

    /**
     * Active Directory stores accountExpires as 100-nanosecond intervals since
     * 1601-01-01 UTC. Both 0 and 0x7FFFFFFFFFFFFFFF mean "never expires".
     *
     * @see https://learn.microsoft.com/en-us/windows/win32/adschema/a-accountexpires
     */
    public static function parseAccountExpires(int|string|null $timestamp): ?CarbonImmutable
    {
        if ($timestamp === null || ! is_numeric($timestamp)) {
            return null;
        }

        $timestamp = (int) $timestamp;

        if ($timestamp <= 0 || $timestamp >= PHP_INT_MAX) {
            return null;
        }

        // 11644473600 = exact seconds between 1601-01-01 and 1970-01-01.
        return CarbonImmutable::createFromTimestampUTC(intdiv($timestamp, 10_000_000) - 11_644_473_600);
    }

    /**
     * Read an attribute the class does not model, e.g. attribute('workforceid').
     */
    public function attribute(string $name, ?string $default = null): ?string
    {
        $value = $this->raw[Str::lower($name)][0] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function name(): string
    {
        return trim(implode(' ', array_filter(
            [$this->first_name, $this->last_name],
            static fn (?string $part): bool => $part !== null && $part !== '',
        )));
    }

    /**
     * A user with no expiry date never expires.
     */
    public function expired(): bool
    {
        return $this->expires?->isPast() ?? false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fake(array $attributes = []): self
    {
        // array_key_exists rather than ??, so an explicit null is kept as null
        // instead of being replaced by a generated value.
        $first = array_key_exists('first_name', $attributes) ? $attributes['first_name'] : fake()->firstName();
        $last = array_key_exists('last_name', $attributes) ? $attributes['last_name'] : fake()->lastName();

        $handle = Str::lower(Str::ascii(Str::substr($first ?? '', 0, 1).$last));
        $handle = $handle !== '' ? $handle : Str::lower(Str::ascii(fake()->userName()));

        $login = array_key_exists('login', $attributes) ? $attributes['login'] : $handle;
        $name = trim("{$first} {$last}");
        $name = $name !== '' ? $name : ($login ?? $handle);
        $domain = config('ldap.fake.email_domain', 'czu.cz');

        return new self(...array_merge([
            'dn' => "CN={$name},".config('ldap.base_dn'),
            'login' => $login,
            'email' => ($login ?? $handle)."@{$domain}",
            'first_name' => $first,
            'last_name' => $last,
            'uic' => fake()->numberBetween(100000, 999999),
            'cn' => $name,
            'expires' => CarbonImmutable::now()->addYear(),
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dn' => $this->dn,
            'login' => $this->login,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'uic' => $this->uic,
            'cn' => $this->cn,
            'expires' => $this->expires?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
