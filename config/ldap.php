<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | Leave LDAP_SERVER empty and the container resolves LdapFake instead of
    | Ldap, so local and CI environments work without reaching the server.
    | In production an empty LDAP_SERVER is an error instead: resolving the
    | driver throws LdapException rather than quietly accepting any login.
    |
    | LDAP_SERVER may be a bare host ("ldap.czu.cz") or a full URI
    | ("ldaps://ldap.czu.cz:636"). A URI wins and LDAP_PORT is ignored.
    |
    */

    'server' => env('LDAP_SERVER'),

    'port' => env('LDAP_PORT', 636),

    'username' => env('LDAP_USERNAME'),

    'password' => env('LDAP_PASSWORD'),

    /*
     | Seconds to wait for the TCP connection before giving up. Without this a
     | firewalled server hangs the request until PHP's max_execution_time.
     */
    'timeout' => env('LDAP_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Directory layout
    |--------------------------------------------------------------------------
    */

    'base_dn' => env('LDAP_BASE_DN', 'OU=_Production,DC=czu,DC=cz'),

    /*
     | Attributes requested from the server. ldap_get_entries() lower-cases
     | every key it returns, which is why LdapUser reads them lower-cased.
     */
    'attributes' => [
        'cn',
        'dn',
        'givenname',
        'sn',
        'fullname',
        'mail',
        'sAMAccountName',
        'czuuic',
        'workforceid',
        'accountexpires',
    ],

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    |
    | {query} is replaced with the caller's input, escaped with ldap_escape().
    | Override any of these per project rather than subclassing Ldap.
    |
    */

    'filters' => [

        // find() — exact match on the identifiers a person might type.
        'find' => '(|(mail={query})(sAMAccountName={query})(czuuic={query})(czuChipLongCard={query}))',

        // search() — fuzzy match for pickers and autocompletes.
        'search' => '(&(objectClass=user)(czuUIC=*)(|(sn=*{query}*)(givenname=*{query}*)(czuuic=*{query}*)))',

        // cursor()/chunk() — every real, active, non-expired person.
        'all' => '(&(!(objectclass=computer))(objectClass=user)(!(|(samAccountName=ext.)(samAccountName=svc.)(userAccountControl:1.2.840.113556.1.4.803:=2)(extensionAttribute10=Expired)))(extensionAttribute7=O365_Import)(czuUIC=*))',

    ],

    /*
     | Server-side page size used by cursor() and chunk(). Active Directory
     | caps this at 1000 by default; asking for more silently returns 1000.
     */
    'page_size' => env('LDAP_PAGE_SIZE', 500),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Used by the `ldap` auth provider driver. Wire it up in config/auth.php:
    |
    |     'providers' => [
    |         'users' => [
    |             'driver' => 'ldap',
    |             'model' => App\Models\User::class,
    |         ],
    |     ],
    |
    | `Auth::attempt(['login' => ..., 'password' => ...])` then binds against
    | the directory and creates or refreshes the local row — but only once the
    | password checks out, so a failed login never leaves a row behind.
    |
    */

    'auth' => [

        /*
         | The local column and the LdapUser property used to match an existing
         | row. Anything unique and stable works; UIC is the safest, since
         | people's logins and addresses change.
         */
        'key' => 'uic',
        'ldap_key' => 'uic',

        /*
         | local column => LdapUser property, applied on every successful login.
         | A property that is null (the directory did not return the attribute)
         | is skipped, so it never overwrites what the local row already holds.
         | Replace this wholesale with LdapUserProvider::syncUsing() when the
         | projection needs more than a field map.
         */
        'sync' => [
            'email' => 'email',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
        ],

        /*
         | Which credential key carries the login. null means "the first
         | non-password credential", so email, login and uic all just work.
         */
        'credential_key' => null,

    ],

    /*
    |--------------------------------------------------------------------------
    | Fake driver
    |--------------------------------------------------------------------------
    */

    'fake' => [
        'password' => env('LDAP_FAKE_PASSWORD', 'heslo123'),
        'email_domain' => env('LDAP_FAKE_EMAIL_DOMAIN', 'czu.cz'),
        'users' => env('LDAP_FAKE_USERS', 50),
    ],

];
