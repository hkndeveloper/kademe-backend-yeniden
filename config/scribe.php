<?php

use App\Support\KademeOpenApiSchemaGenerator;
use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\configureStrategy;
use function Knuckles\Scribe\Config\removeStrategies;

// knuckleswtf/scribe sadece require-dev; `composer install --no-dev` (Docker/Railway) paketi yuklemez.
if (! class_exists(Defaults::class)) {
    // config() yukleme siralamasinda donguye girmesin: env() kullan (sadece Scribe yokken, prod Docker)
    return [
        'title' => (string) (env('APP_NAME', 'Laravel')) . ' API',
        'description' => 'KADEME backend API dokumantasyonu. Public, mobil katilimci ve tek panel endpointleri OpenAPI/Swagger uyumlu olarak bu dokumanda toplanir.',
        'base_url' => (string) (env('APP_URL', 'http://localhost')),
    ];
}

// Only the most common configs are shown. See the https://scribe.knuckles.wtf/laravel/reference/config for all.

return [
    // The HTML <title> for the generated documentation.
    'title' => config('app.name').' API Documentation',

    // A short description of your API. Will be included in the docs webpage, Postman collection and OpenAPI spec.
    'description' => 'KADEME backend API dokumantasyonu. Public, mobil katilimci ve tek panel endpointleri OpenAPI/Swagger uyumlu olarak bu dokumanda toplanir.',

    // Text to place in the "Introduction" section, right after the `description`. Markdown and HTML are supported.
    'intro_text' => <<<'INTRO'
KADEME API dokumantasyonu mobil uygulama, public web arayuzu ve tek panel entegrasyonlari icin hazirlanir.

Endpointler uc ana guvenlik seviyesinde ele alinir:

- Public endpointler token gerektirmez.
- Authenticated endpointler `Authorization: Bearer {TOKEN}` header'i gerektirir.
- Panel ve proje bazli endpointlerde tokena ek olarak rol, action+scope yetkisi, proje/birim/kendi kaydi gibi backend kontrolleri uygulanir.

Genel header kullanimi:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer {TOKEN}
```

Dosya yukleme endpointleri `multipart/form-data`, indirme/export endpointleri ise binary response veya gecici download URL'i donebilir.

Ortak response ve hata modelleri OpenAPI `components.schemas` bolumunde toplanir. Sik kullanilanlar:

- `SuccessMessage`: Standart basarili islem mesaji.
- `PaginatedResponse`: Laravel pagination icin `data`, `links`, `meta` yapisi.
- `ValidationError`: 422 validasyon hatalari; `message` ve alan bazli `errors`.
- `UnauthorizedError`: 401 token yok, suresi dolmus veya gecersiz.
- `ForbiddenError`: 403 rol, action+scope, proje/birim/self erisimi, KVKK veya sifre kurulumu engeli.
- `NotFoundError`: 404 kayit veya dosya bulunamadi.
- `KvkkRequiredError`, `PasswordSetupPendingError`, `BlacklistError`, `ArchiveLockedError`: KADEME'ye ozel erisim/hata durumlari.

Sik kullanilan `User`, `Project`, `Application`, `Program`, `Attendance`, `Certificate`, `SupportTicket`, `ServiceRequest`, `Announcement`, `Trainer`, `FinancialTransaction` ve `ActivityLog` semalari da OpenAPI components altinda bulunur. Endpoint response ornekleri yine ilgili endpointte kalir; components bolumu mobilci icin ortak sozlesme referansidir.
INTRO,

    // The base URL displayed in the docs.
    // If you're using `laravel` type, you can set this to a dynamic string, like '{{ config("app.tenant_url") }}' to get a dynamic base URL.
    'base_url' => config('app.url'),

    // Routes to include in the docs
    'routes' => [
        [
            'match' => [
                // Match only routes whose paths match this pattern (use * as a wildcard to match any characters). Example: 'users/*'.
                'prefixes' => ['api/*'],

                // Match only routes whose domains match this pattern (use * as a wildcard to match any characters). Example: 'api.*'.
                'domains' => ['*'],
            ],

            // Include these routes even if they did not match the rules above.
            'include' => [
                // 'users.index', 'POST /new', '/auth/*'
            ],

            // Exclude these routes even if they matched the rules above.
            'exclude' => [
                // 'GET /health', 'admin.*'
            ],
        ],
    ],

    // The type of documentation output to generate.
    // - "static" will generate a static HTMl page in the /public/docs folder,
    // - "laravel" will generate the documentation as a Blade view, so you can add routing and authentication.
    // - "external_static" and "external_laravel" do the same as above, but pass the OpenAPI spec as a URL to an external UI template
    'type' => 'laravel',

    // See https://scribe.knuckles.wtf/laravel/reference/config#theme for supported options
    'theme' => 'default',

    'static' => [
        // HTML documentation, assets and Postman collection will be generated to this folder.
        // Source Markdown will still be in resources/docs.
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        // Whether to automatically create a docs route for you to view your generated docs. You can still set up routing manually.
        'add_routes' => filter_var(env('SCRIBE_DOCS_ADD_ROUTES', true), FILTER_VALIDATE_BOOLEAN),

        // URL path to use for the docs endpoint (if `add_routes` is true).
        // By default, `/docs` opens the HTML page, `/docs.postman` opens the Postman collection, and `/docs.openapi` the OpenAPI spec.
        'docs_url' => '/docs',

        // Directory within `public` in which to store CSS and JS assets.
        // By default, assets are stored in `public/vendor/scribe`.
        // If set, assets will be stored in `public/{{assets_directory}}`
        'assets_directory' => null,

        // Middleware to attach to the docs endpoint (if `add_routes` is true).
        'middleware' => array_values(array_filter(array_map('trim', explode(',', (string) env('SCRIBE_DOCS_MIDDLEWARE', ''))))),
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        // Add a Try It Out button to your endpoints so consumers can test endpoints right from their browser.
        // Don't forget to enable CORS headers for your endpoints.
        'enabled' => filter_var(env('SCRIBE_TRY_IT_OUT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // The base URL to use in the API tester. Leave as null to be the same as the displayed URL (`scribe.base_url`).
        'base_url' => null,

        // [Laravel Sanctum] Fetch a CSRF token before each request, and add it as an X-XSRF-TOKEN header.
        'use_csrf' => false,

        // The URL to fetch the CSRF token from (if `use_csrf` is true).
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    // How is your API authenticated? This information will be used in the displayed docs, generated examples and response calls.
    'auth' => [
        // Set this to true if ANY endpoints in your API use authentication.
        'enabled' => true,

        // Set this to true if your API should be authenticated by default. If so, you must also set `enabled` (above) to true.
        // You can then use @unauthenticated or @authenticated on individual endpoints to change their status from the default.
        'default' => true,

        // Where is the auth value meant to be sent in a request?
        'in' => AuthIn::BEARER->value,

        // The name of the auth parameter (e.g. token, key, apiKey) or header (e.g. Authorization, Api-Key).
        'name' => 'Authorization',

        // The value of the parameter to be used by Scribe to authenticate response calls.
        // This will NOT be included in the generated documentation. If empty, Scribe will use a random value.
        'use_value' => env('SCRIBE_AUTH_KEY'),

        // Placeholder your users will see for the auth parameter in the example requests.
        // Set this to null if you want Scribe to use a random value as placeholder instead.
        'placeholder' => '{YOUR_ACCESS_TOKEN}',

        // Any extra authentication-related info for your users. Markdown and HTML are supported.
        'extra_info' => 'Token almak icin /api/auth/login endpointini kullanin. Donen access_token degeri sonraki isteklerde Authorization: Bearer {TOKEN} seklinde gonderilir. Token tek basina tum endpointlere erisim vermez; rol, action+scope, KVKK, sifre kurulumu ve proje/birim/kayit erisimi backend tarafinda ayrica kontrol edilir.',
    ],

    // Example requests for each endpoint will be shown in each of these languages.
    // Supported options are: bash, javascript, php, python
    // To add a language of your own, see https://scribe.knuckles.wtf/laravel/advanced/example-requests
    // Note: does not work for `external` docs types
    'example_languages' => [
        'bash',
        'javascript',
    ],

    // Generate a Postman collection (v2.1.0) in addition to HTML docs.
    // For 'static' docs, the collection will be generated to public/docs/collection.json.
    // For 'laravel' docs, it will be generated to storage/app/scribe/collection.json.
    // Setting `laravel.add_routes` to true (above) will also add a route for the collection.
    'postman' => [
        'enabled' => true,

        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],

    // Generate an OpenAPI spec in addition to docs webpage.
    // For 'static' docs, the collection will be generated to public/docs/openapi.yaml.
    // For 'laravel' docs, it will be generated to storage/app/scribe/openapi.yaml.
    // Setting `laravel.add_routes` to true (above) will also add a route for the spec.
    'openapi' => [
        'enabled' => true,

        // The OpenAPI spec version to generate. Supported versions: '3.0.3', '3.1.0'.
        // OpenAPI 3.1 is more compatible with JSON Schema and is becoming the dominant version.
        // See https://spec.openapis.org/oas/v3.1.0 for details on 3.1 changes.
        'version' => '3.0.3',

        'overrides' => [
            // 'info.version' => '2.0.0',
        ],

        // Additional generators to use when generating the OpenAPI spec.
        // Should extend `Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator`.
        'generators' => [
            KademeOpenApiSchemaGenerator::class,
        ],
    ],

    'groups' => [
        // Endpoints which don't have a @group will be placed in this default group.
        'default' => 'Endpoints',

        // By default, Scribe will sort groups alphabetically, and endpoints in the order their routes are defined.
        // You can override this by listing the groups, subgroups and endpoints here in the order you want them.
        // See https://scribe.knuckles.wtf/blog/laravel-v4#easier-sorting and https://scribe.knuckles.wtf/laravel/reference/config#order for details
        // Note: does not work for `external` docs types
        'order' => [
            'Health',
            'Public Content',
            'Projects',
            'Certificates',
            'Newsletter',
            'Contact',
            'Auth',
            'User',
            'Participant Dashboard',
            'Applications',
            'Programs & Attendance',
            'Participant Content',
            'Assignments',
            'Digital Bohca',
            'Feedback',
            'Forum',
            'KPD',
            'Requests',
            'Support',
            'Volunteer',
            'Alumni Opportunities',
            'Announcements & Inbox',
            'Social Sharing',
            'Admin Dashboard',
            'Admin Applications',
            'Admin Panel',
            'Participants',
            'Projects',
            'Project Special Modules',
            'Project Family Modules',
            'Periods',
            'Credits & Rewards',
            'Financials',
            'Users',
            'Permissions Matrix',
            'Staff',
            'Trainers',
            'Content Management',
            'Site Settings',
            'Files & Exports',
            'Chatbot',
            'Calendar',
            'Personality Tests',
            'Motivation',
        ],
    ],

    // Custom logo path. This will be used as the value of the src attribute for the <img> tag,
    // so make sure it points to an accessible URL or path. Set to false to not use a logo.
    // For example, if your logo is in public/img:
    // - 'logo' => '../img/logo.png' // for `static` type (output folder is public/docs)
    // - 'logo' => 'img/logo.png' // for `laravel` type
    'logo' => false,

    // Customize the "Last updated" value displayed in the docs by specifying tokens and formats.
    // Examples:
    // - {date:F j Y} => March 28, 2022
    // - {git:short} => Short hash of the last Git commit
    // Available tokens are `{date:<format>}` and `{git:<format>}`.
    // The format you pass to `date` will be passed to PHP's `date()` function.
    // The format you pass to `git` can be either "short" or "long".
    // Note: does not work for `external` docs types
    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [
        // Set this to any number to generate the same example values for parameters on each run,
        'faker_seed' => 1234,

        // With API resources and transformers, Scribe tries to generate example models to use in your API responses.
        // By default, Scribe will try the model's factory, and if that fails, try fetching the first from the database.
        // You can reorder or remove strategies here.
        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    // The strategies Scribe will use to extract information about your routes at each stage.
    // Use configureStrategy() to specify settings for a strategy in the list.
    // Use removeStrategies() to remove an included strategy.
    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        'responses' => configureStrategy(
            Defaults::RESPONSES_STRATEGIES,
            Strategies\Responses\ResponseCalls::withSettings(
                only: [
                    'GET api/ping',
                    'GET api/blogs*',
                    'GET api/faqs',
                    'GET api/activities*',
                    'GET api/projects*',
                    'GET api/site-config',
                    'GET api/homepage',
                    'GET api/motivation/current',
                    'GET api/certificates/verify/*',
                ],
                // Recommended: disable debug mode in response calls to avoid error stack traces in responses
                config: [
                    'app.debug' => false,
                ]
            )
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    // For response calls, API resource responses and transformer responses,
    // Scribe will try to start database transactions, so no changes are persisted to your database.
    // Tell Scribe which connections should be transacted here. If you only use one db connection, you can leave this as is.
    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        // If you are using a custom serializer with league/fractal, you can specify it here.
        'serializer' => null,
    ],
];
