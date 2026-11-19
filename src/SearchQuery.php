<?php

declare(strict_types=1);

namespace CzuPef\Ldap;

/**
 * Splits a picker query into the words that search() matches one by one.
 *
 * Shared by Ldap and LdapFake so the two drivers agree on what a "word" is.
 *
 * @internal
 */
final class SearchQuery
{
    /**
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);

        // preg_split() fails on malformed UTF-8. Fall back to the whole query
        // as one word, which is what search() did before it split anything,
        // rather than to no words at all (an unconstrained search).
        if ($words === false) {
            return [trim($query)];
        }

        return $words;
    }
}
