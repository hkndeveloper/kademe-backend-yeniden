<?php

namespace App\Support;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;

class KademeOpenApiSchemaGenerator extends OpenApiGenerator
{
    public function root(array $root, array $groupedEndpoints): array
    {
        return array_replace_recursive($root, [
            'components' => [
                'schemas' => $this->schemas(),
                'responses' => $this->responses(),
            ],
        ]);
    }

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        if (! isset($pathItem['responses']) || ! is_array($pathItem['responses'])) {
            $pathItem['responses'] = [];
        }

        if ($endpoint->metadata->authenticated) {
            $pathItem['responses']['401'] ??= ['$ref' => '#/components/responses/Unauthorized'];
            $pathItem['responses']['403'] ??= ['$ref' => '#/components/responses/Forbidden'];
        }

        if ($this->hasValidationSurface($endpoint)) {
            $pathItem['responses']['422'] ??= ['$ref' => '#/components/responses/ValidationError'];
        }

        if (! empty($endpoint->urlParameters)) {
            $pathItem['responses']['404'] ??= ['$ref' => '#/components/responses/NotFound'];
        }

        return $pathItem;
    }

    private function hasValidationSurface(OutputEndpointData $endpoint): bool
    {
        return ! empty($endpoint->bodyParameters)
            || ! empty($endpoint->queryParameters)
            || in_array($endpoint->httpMethods[0] ?? 'GET', ['POST', 'PUT', 'PATCH'], true);
    }

    private function responses(): array
    {
        return [
            'Unauthorized' => [
                'description' => 'Bearer token missing, expired or invalid.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/UnauthorizedError'],
                    ],
                ],
            ],
            'Forbidden' => [
                'description' => 'The authenticated user does not have the required role, action+scope, project/unit/self access, KVKK approval or password setup state.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ForbiddenError'],
                    ],
                ],
            ],
            'ValidationError' => [
                'description' => 'Validation failed.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ValidationError'],
                    ],
                ],
            ],
            'NotFound' => [
                'description' => 'Requested record or file was not found.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/NotFoundError'],
                    ],
                ],
            ],
            'ArchiveLocked' => [
                'description' => 'The target period is completed/archived and cannot be changed without archive correction permission.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ArchiveLockedError'],
                    ],
                ],
            ],
        ];
    }

    private function schemas(): array
    {
        return [
            'SuccessMessage' => $this->object([
                'message' => ['type' => 'string', 'example' => 'Islem basariyla tamamlandi.'],
            ], ['message']),
            'ValidationError' => $this->object([
                'message' => ['type' => 'string', 'example' => 'The email field is required.'],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'example' => [
                        'email' => ['The email field is required.'],
                    ],
                ],
            ], ['message', 'errors']),
            'UnauthorizedError' => $this->object([
                'message' => ['type' => 'string', 'example' => 'Unauthenticated.'],
            ], ['message']),
            'ForbiddenError' => $this->object([
                'message' => ['type' => 'string', 'example' => 'Bu islem icin yetkiniz bulunmuyor.'],
            ], ['message']),
            'NotFoundError' => $this->object([
                'message' => ['type' => 'string', 'example' => 'No query results for model [App\\Models\\Project].'],
            ], ['message']),
            'KvkkRequiredError' => $this->object([
                'kvkk_required' => ['type' => 'boolean', 'example' => true],
                'message' => ['type' => 'string', 'example' => 'Sistemi kullanmaya devam edebilmek icin KVKK aydinlatma metnini onaylamaniz gerekmektedir.'],
            ], ['kvkk_required', 'message']),
            'PasswordSetupPendingError' => $this->object([
                'message' => ['type' => 'string', 'example' => 'Sifrenizi henuz belirlemediniz. E-postaniza gonderilen baglanti ile sifre olusturun.'],
                'password_setup_required' => ['type' => 'boolean', 'example' => true],
            ], ['message', 'password_setup_required']),
            'BlacklistError' => $this->object([
                'blacklisted' => ['type' => 'boolean', 'example' => true],
                'message' => ['type' => 'string', 'example' => 'Hesabiniz sistem kurallarina uymadiginiz icin kisitlanmistir.'],
            ], ['blacklisted', 'message']),
            'ArchiveLockedError' => $this->object([
                'message' => ['type' => 'string', 'example' => 'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.'],
            ], ['message']),
            'PaginationLinks' => $this->object([
                'first' => ['type' => 'string', 'nullable' => true, 'example' => 'https://api.example.com/api/projects?page=1'],
                'last' => ['type' => 'string', 'nullable' => true, 'example' => 'https://api.example.com/api/projects?page=10'],
                'prev' => ['type' => 'string', 'nullable' => true, 'example' => null],
                'next' => ['type' => 'string', 'nullable' => true, 'example' => 'https://api.example.com/api/projects?page=2'],
            ]),
            'PaginationMeta' => $this->object([
                'current_page' => ['type' => 'integer', 'example' => 1],
                'from' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                'last_page' => ['type' => 'integer', 'example' => 10],
                'path' => ['type' => 'string', 'example' => 'https://api.example.com/api/projects'],
                'per_page' => ['type' => 'integer', 'example' => 15],
                'to' => ['type' => 'integer', 'nullable' => true, 'example' => 15],
                'total' => ['type' => 'integer', 'example' => 150],
            ]),
            'PaginatedResponse' => $this->object([
                'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                'links' => ['$ref' => '#/components/schemas/PaginationLinks'],
                'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
            ], ['data']),
            'User' => $this->object([
                'id' => ['type' => 'integer', 'example' => 42],
                'name' => ['type' => 'string', 'example' => 'Ada'],
                'surname' => ['type' => 'string', 'example' => 'Yilmaz'],
                'email' => ['type' => 'string', 'nullable' => true, 'example' => 'ada@example.com'],
                'phone' => ['type' => 'string', 'nullable' => true, 'example' => '+905551112233'],
                'role' => ['type' => 'string', 'example' => 'student'],
                'status' => ['type' => 'string', 'example' => 'active'],
                'permission_scopes' => ['type' => 'object', 'additionalProperties' => true],
                'authorization_context' => ['type' => 'object', 'additionalProperties' => true],
            ], ['id', 'name', 'surname', 'role', 'status']),
            'Profile' => $this->object([
                'id' => ['type' => 'integer', 'example' => 10],
                'user_id' => ['type' => 'integer', 'example' => 42],
                'bio' => ['type' => 'string', 'nullable' => true],
                'avatar_url' => ['type' => 'string', 'nullable' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'updated_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ]),
            'Project' => $this->object([
                'id' => ['type' => 'integer', 'example' => 3],
                'name' => ['type' => 'string', 'example' => 'Diplomasi360'],
                'slug' => ['type' => 'string', 'example' => 'diplomasi360'],
                'type' => ['type' => 'string', 'nullable' => true, 'example' => 'diplomacy'],
                'short_description' => ['type' => 'string', 'nullable' => true],
                'cover_image' => ['type' => 'string', 'nullable' => true],
                'status' => ['type' => 'string', 'example' => 'active'],
                'is_application_open' => ['type' => 'boolean', 'example' => true],
            ], ['id', 'name', 'slug', 'status']),
            'Application' => $this->object([
                'id' => ['type' => 'integer', 'example' => 1001],
                'user_id' => ['type' => 'integer', 'nullable' => true, 'example' => 42],
                'project_id' => ['type' => 'integer', 'example' => 3],
                'period_id' => ['type' => 'integer', 'nullable' => true, 'example' => 12],
                'status' => ['type' => 'string', 'example' => 'pending'],
                'form_data' => ['type' => 'object', 'additionalProperties' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ], ['id', 'project_id', 'status']),
            'Program' => $this->object([
                'id' => ['type' => 'integer', 'example' => 44],
                'title' => ['type' => 'string', 'example' => 'Acilis Programi'],
                'description' => ['type' => 'string', 'nullable' => true],
                'location' => ['type' => 'string', 'nullable' => true],
                'location_place_name' => ['type' => 'string', 'nullable' => true],
                'location_place_address' => ['type' => 'string', 'nullable' => true],
                'location_place_id' => ['type' => 'string', 'nullable' => true],
                'location_place_provider' => ['type' => 'string', 'nullable' => true],
                'latitude' => ['type' => 'number', 'format' => 'float', 'nullable' => true],
                'longitude' => ['type' => 'number', 'format' => 'float', 'nullable' => true],
                'start_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'end_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'status' => ['type' => 'string', 'example' => 'scheduled'],
                'project' => ['$ref' => '#/components/schemas/Project'],
            ], ['id', 'title', 'status']),
            'Attendance' => $this->object([
                'id' => ['type' => 'integer', 'example' => 501],
                'program_id' => ['type' => 'integer', 'example' => 44],
                'participant_id' => ['type' => 'integer', 'example' => 77],
                'is_valid' => ['type' => 'boolean', 'example' => true],
                'attended_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ], ['id', 'program_id', 'participant_id']),
            'Certificate' => $this->object([
                'id' => ['type' => 'integer', 'example' => 900],
                'type' => ['type' => 'string', 'example' => 'participation'],
                'verification_code' => ['type' => 'string', 'example' => 'ABC123'],
                'issued_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'certificate_path' => ['type' => 'string', 'nullable' => true],
                'file_url' => ['type' => 'string', 'nullable' => true],
                'download_url' => ['type' => 'string', 'nullable' => true],
            ], ['id', 'type', 'verification_code']),
            'SupportTicket' => $this->object([
                'id' => ['type' => 'integer', 'example' => 70],
                'subject' => ['type' => 'string', 'example' => 'Belge yukleme sorunu'],
                'category' => ['type' => 'string', 'nullable' => true],
                'status' => ['type' => 'string', 'example' => 'open'],
                'priority' => ['type' => 'string', 'nullable' => true],
                'project_id' => ['type' => 'integer', 'nullable' => true],
                'assigned_to' => ['type' => 'integer', 'nullable' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ], ['id', 'subject', 'status']),
            'ServiceRequest' => $this->object([
                'id' => ['type' => 'integer', 'example' => 81],
                'type' => ['type' => 'string', 'example' => 'document'],
                'target_unit' => ['type' => 'string', 'nullable' => true],
                'description' => ['type' => 'string', 'nullable' => true],
                'status' => ['type' => 'string', 'example' => 'pending'],
                'response_file_download_url' => ['type' => 'string', 'nullable' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ], ['id', 'type', 'status']),
            'Announcement' => $this->object([
                'id' => ['type' => 'integer', 'example' => 30],
                'title' => ['type' => 'string', 'example' => 'Yeni duyuru'],
                'content' => ['type' => 'string', 'nullable' => true],
                'category' => ['type' => 'string', 'nullable' => true],
                'target_roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                'target_units' => ['type' => 'array', 'items' => ['type' => 'string']],
                'published_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ], ['id', 'title']),
            'Trainer' => $this->object([
                'id' => ['type' => 'integer', 'example' => 14],
                'first_name' => ['type' => 'string', 'example' => 'Zeynep'],
                'last_name' => ['type' => 'string', 'example' => 'Demir'],
                'full_name' => ['type' => 'string', 'example' => 'Zeynep Demir'],
                'email' => ['type' => 'string', 'nullable' => true],
                'phone' => ['type' => 'string', 'nullable' => true],
                'title' => ['type' => 'string', 'nullable' => true],
                'organization' => ['type' => 'string', 'nullable' => true],
                'expertise' => ['type' => 'string', 'nullable' => true],
                'status' => ['type' => 'string', 'example' => 'active'],
                'kademe_comment' => ['type' => 'string', 'nullable' => true],
            ], ['id', 'full_name', 'status']),
            'FinancialTransaction' => $this->object([
                'id' => ['type' => 'integer', 'example' => 99],
                'project_id' => ['type' => 'integer', 'nullable' => true],
                'period_id' => ['type' => 'integer', 'nullable' => true],
                'type' => ['type' => 'string', 'example' => 'expense'],
                'category' => ['type' => 'string', 'nullable' => true],
                'payee_name' => ['type' => 'string', 'nullable' => true],
                'amount' => ['type' => 'string', 'example' => '1250.00'],
                'status' => ['type' => 'string', 'example' => 'pending'],
                'invoice_path' => ['type' => 'string', 'nullable' => true],
                'payment_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
            ], ['id', 'type', 'amount', 'status']),
            'ActivityLog' => $this->object([
                'id' => ['type' => 'integer', 'example' => 1200],
                'log_name' => ['type' => 'string', 'nullable' => true],
                'description' => ['type' => 'string', 'nullable' => true],
                'event' => ['type' => 'string', 'nullable' => true],
                'subject_type' => ['type' => 'string', 'nullable' => true],
                'subject_id' => ['type' => 'integer', 'nullable' => true],
                'causer_type' => ['type' => 'string', 'nullable' => true],
                'causer_id' => ['type' => 'integer', 'nullable' => true],
                'properties' => ['type' => 'object', 'additionalProperties' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ], ['id']),
        ];
    }

    private function object(array $properties, array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
