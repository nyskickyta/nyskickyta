<?php
declare(strict_types=1);

require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/customer_lookup.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/services/invoice_base_totals.php';
require_once __DIR__ . '/services/job_totals.php';
require_once __DIR__ . '/services/entity_log.php';

function seed_data(): array
{
    $defaultRegions = default_swedish_regions();

    $defaultOrganization = [
        'id' => 1,
        'name' => 'Nyskick Dalarna',
        'slug' => 'nyskick-dalarna',
        'organization_type' => ORGANIZATION_TYPE_REGIONAL_COMPANY,
        'parent_organization_id' => null,
        'region_id' => 1,
        'service_postcode_prefixes' => '',
        'service_cities' => '',
        'is_active' => true,
        'created_at' => '2026-03-15 08:00:00',
        'updated_at' => '2026-03-15 08:00:00',
    ];

    $privateCustomer = build_customer_record([
        'id' => 1,
        'regionId' => 1,
        'organizationId' => 1,
        'customerType' => 'private',
        'billingVatMode' => 'standard_vat',
        'name' => 'Anna Lind',
        'companyName' => '',
        'phone' => '070-111 22 33',
        'email' => 'anna@example.se',
        'address' => 'Björkvägen 8',
        'postalCode' => '791 34',
        'city' => 'Falun',
        'personalNumber' => '850101-1234',
        'organizationNumber' => '',
        'vatNumber' => '',
        'rutEnabled' => true,
        'notes' => 'Vill gärna boka vardagar efter 15:00.',
    ]);
    $privateCustomer['created_at'] = '2026-03-20 09:30:00';
    $privateCustomer['updated_at'] = '2026-03-28 09:30:00';
    $privateCustomer['last_activity'] = '2026-03-28 09:30:00';

    $companyCustomer = build_customer_record([
        'id' => 2,
        'regionId' => 1,
        'organizationId' => 1,
        'customerType' => 'company',
        'billingVatMode' => 'standard_vat',
        'name' => 'Johan Berg',
        'companyName' => 'Berg Fastighet AB',
        'phone' => '070-444 55 66',
        'email' => 'johan@example.se',
        'address' => 'Tjärnavägen 14',
        'postalCode' => '784 34',
        'city' => 'Borlänge',
        'organizationNumber' => '559123-4567',
        'vatNumber' => 'SE559123456701',
        'rutEnabled' => false,
        'notes' => 'Företagskund med vanlig moms.',
    ]);
    $companyCustomer['created_at'] = '2026-03-19 14:10:00';
    $companyCustomer['updated_at'] = '2026-03-27 14:10:00';
    $companyCustomer['last_activity'] = '2026-03-27 14:10:00';

    $reverseChargeCustomer = build_customer_record([
        'id' => 3,
        'regionId' => 1,
        'organizationId' => 1,
        'customerType' => 'company',
        'billingVatMode' => 'reverse_charge',
        'name' => 'Sara Nyström',
        'companyName' => 'Nyström Entreprenad AB',
        'phone' => '070-777 88 99',
        'email' => 'sara@example.se',
        'address' => 'Tallstigen 3',
        'postalCode' => '793 31',
        'city' => 'Leksand',
        'organizationNumber' => '556888-9900',
        'vatNumber' => 'SE556888990001',
        'rutEnabled' => false,
        'notes' => 'Företagskund med omvänd moms.',
    ]);
    $reverseChargeCustomer['created_at'] = '2026-03-18 08:20:00';
    $reverseChargeCustomer['updated_at'] = '2026-03-26 08:20:00';
    $reverseChargeCustomer['last_activity'] = '2026-03-26 08:20:00';

    $associationCustomer = build_customer_record([
        'id' => 4,
        'regionId' => 1,
        'organizationId' => 1,
        'customerType' => 'association',
        'billingVatMode' => 'standard_vat',
        'name' => 'Brf Solgläntan',
        'associationName' => 'Brf Solgläntan',
        'contactPerson' => 'Maria Svensson',
        'phone' => '070-333 44 55',
        'email' => 'styrelsen@solglantan.se',
        'serviceAddress' => 'Solvägen 12',
        'servicePostalCode' => '792 32',
        'serviceCity' => 'Mora',
        'billingAddress' => 'Brf Solgläntan, Box 12',
        'billingPostalCode' => '792 21',
        'billingCity' => 'Mora',
        'organizationNumber' => '769900-1234',
        'vatNumber' => 'SE769900123401',
        'rutEnabled' => false,
        'notes' => 'Förening med separat fakturaadress.',
    ]);
    $associationCustomer['created_at'] = '2026-03-21 10:00:00';
    $associationCustomer['updated_at'] = '2026-03-27 10:00:00';
    $associationCustomer['last_activity'] = '2026-03-27 10:00:00';

    $quote1 = build_quote_record([
        'id' => 1,
        'serviceType' => 'Altantvätt',
        'description' => 'Tvätt och lätt behandling av träaltan, cirka 45 kvm.',
        'laborAmountExVat' => 3200,
        'materialAmountExVat' => 700,
        'otherAmountExVat' => 300,
        'status' => 'sent',
        'validUntil' => '2026-04-08',
        'notes' => 'Kund önskar start nästa vecka.',
    ], $privateCustomer);
    $quote1['created_at'] = '2026-03-28 10:00:00';
    $quote1['updated_at'] = '2026-03-28 10:00:00';

    $quote2 = build_quote_record([
        'id' => 2,
        'serviceType' => 'Stentvätt',
        'description' => 'Rengöring och impregnering av uppfart och gång.',
        'laborAmountExVat' => 4800,
        'materialAmountExVat' => 1500,
        'otherAmountExVat' => 500,
        'status' => 'approved',
        'validUntil' => '2026-04-03',
        'notes' => 'Godkänd via telefon.',
    ], $companyCustomer);
    $quote2['created_at'] = '2026-03-27 13:00:00';
    $quote2['updated_at'] = '2026-03-27 13:00:00';

    $quote3 = build_quote_record([
        'id' => 3,
        'serviceType' => 'Stentvätt',
        'description' => 'Offert för entreprenadjobb med omvänd moms.',
        'laborAmountExVat' => 2400,
        'materialAmountExVat' => 800,
        'otherAmountExVat' => 300,
        'status' => 'draft',
        'validUntil' => '2026-04-12',
        'notes' => 'Fakturan ska märkas med omvänd skattskyldighet.',
    ], $reverseChargeCustomer);
    $quote3['created_at'] = '2026-03-29 07:33:41';
    $quote3['updated_at'] = '2026-03-29 07:33:41';

    $job1 = build_job_record([
        'id' => 1,
        'regionId' => 1,
        'quoteId' => 2,
        'serviceType' => 'Stentvätt',
        'description' => 'Rengöring och impregnering av uppfart och gång.',
        'scheduledDate' => date('Y-m-d'),
        'completedDate' => '',
        'assignedTo' => 'Jonas',
        'status' => 'booked',
        'notes' => 'Ta med impregnering.',
    ], $companyCustomer);
    $job1['created_at'] = '2026-03-28 11:15:00';
    $job1['updated_at'] = '2026-03-28 11:15:00';

    $job2 = build_job_record([
        'id' => 2,
        'regionId' => 1,
        'quoteId' => 1,
        'serviceType' => 'Altantvätt',
        'description' => 'Tvätt av träaltan och lätt behandling.',
        'scheduledDate' => date('Y-m-d', strtotime('+1 day')),
        'scheduledTime' => '09:00',
        'completedDate' => '',
        'assignedTo' => 'Alex',
        'status' => 'planned',
        'notes' => 'Behöver ringa 30 min innan ankomst.',
    ], $privateCustomer);
    $job2['created_at'] = '2026-03-28 23:33:41';
    $job2['updated_at'] = '2026-03-28 23:33:41';

    $job3 = build_job_record([
        'id' => 3,
        'regionId' => 1,
        'quoteId' => 3,
        'serviceType' => 'Stentvätt',
        'description' => 'Utfört jobb med omvänd moms.',
        'scheduledDate' => date('Y-m-d', strtotime('-1 day')),
        'scheduledTime' => '13:30',
        'completedDate' => date('Y-m-d', strtotime('-1 day')),
        'assignedTo' => 'Jonas',
        'status' => 'done',
        'finalLaborAmountExVat' => 3000,
        'finalMaterialAmountExVat' => 600,
        'finalOtherAmountExVat' => 200,
        'readyForInvoicing' => true,
        'notes' => 'Omvänd skattskyldighet gäller.',
    ], $reverseChargeCustomer);
    $job3['created_at'] = '2026-03-27 09:00:00';
    $job3['updated_at'] = '2026-03-28 16:15:00';

    return [
        'users' => [
            [
                'id' => 1,
                'username' => 'admin',
                'name' => 'Nyskick Admin',
                'role' => 'admin',
                'organization_id' => 1,
                'region_id' => 1,
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => '',
                'last_login_at' => '',
                'two_factor_enabled' => false,
                'two_factor_secret' => '',
                'two_factor_confirmed_at' => '',
                'password_hash' => '$2y$12$lJF9fyX1/Yh1OXitwo3qGuLPcwH2r1fO7k38ymj83KbXRKWY5XRru',
                'created_at' => '2026-03-15 08:00:00',
                'updated_at' => '2026-03-15 08:00:00'
            ]
        ],
        'organizations' => [$defaultOrganization],
        'organization_memberships' => [
            [
                'id' => 1,
                'user_id' => 1,
                'organization_id' => 1,
                'role' => 'admin',
                'is_primary' => true,
                'created_at' => '2026-03-15 08:00:00',
                'updated_at' => '2026-03-15 08:00:00',
            ],
        ],
        'regions' => $defaultRegions,
        'products' => [],
        'service_packages' => [],
        'service_package_items' => [],
        'customers' => [$privateCustomer, $companyCustomer, $reverseChargeCustomer, $associationCustomer],
        'quotes' => [$quote1, $quote2, $quote3],
        'quote_items' => array_merge(
            build_quote_items_from_quote($quote1),
            build_quote_items_from_quote($quote2),
            build_quote_items_from_quote($quote3)
        ),
        'jobs' => [$job1, $job2, $job3],
        'job_items' => array_merge(
            build_job_items_from_job($job1),
            build_job_items_from_job($job2),
            build_job_items_from_job($job3)
        ),
        'web_quote_requests' => [],
        'entity_logs' => [],
    ];
}

function default_swedish_regions(): array
{
    $definitions = [
        ['id' => 1, 'name' => 'Region Dalarna', 'slug' => 'region-dalarna', 'is_active' => true],
        ['id' => 2, 'name' => 'Region Blekinge', 'slug' => 'region-blekinge', 'is_active' => false],
        ['id' => 3, 'name' => 'Region Gotland', 'slug' => 'region-gotland', 'is_active' => false],
        ['id' => 4, 'name' => 'Region Gävleborg', 'slug' => 'region-gavleborg', 'is_active' => false],
        ['id' => 5, 'name' => 'Region Halland', 'slug' => 'region-halland', 'is_active' => false],
        ['id' => 6, 'name' => 'Region Jämtland Härjedalen', 'slug' => 'region-jamtland-harjedalen', 'is_active' => false],
        ['id' => 7, 'name' => 'Region Jönköpings län', 'slug' => 'region-jonkopings-lan', 'is_active' => false],
        ['id' => 8, 'name' => 'Region Kalmar län', 'slug' => 'region-kalmar-lan', 'is_active' => false],
        ['id' => 9, 'name' => 'Region Kronoberg', 'slug' => 'region-kronoberg', 'is_active' => false],
        ['id' => 10, 'name' => 'Region Norrbotten', 'slug' => 'region-norrbotten', 'is_active' => false],
        ['id' => 11, 'name' => 'Region Skåne', 'slug' => 'region-skane', 'is_active' => false],
        ['id' => 12, 'name' => 'Region Stockholm', 'slug' => 'region-stockholm', 'is_active' => false],
        ['id' => 13, 'name' => 'Region Sörmland', 'slug' => 'region-sormland', 'is_active' => false],
        ['id' => 14, 'name' => 'Region Uppsala', 'slug' => 'region-uppsala', 'is_active' => false],
        ['id' => 15, 'name' => 'Region Värmland', 'slug' => 'region-varmland', 'is_active' => false],
        ['id' => 16, 'name' => 'Region Västerbotten', 'slug' => 'region-vasterbotten', 'is_active' => false],
        ['id' => 17, 'name' => 'Region Västernorrland', 'slug' => 'region-vasternorrland', 'is_active' => false],
        ['id' => 18, 'name' => 'Region Västmanland', 'slug' => 'region-vastmanland', 'is_active' => false],
        ['id' => 19, 'name' => 'Region Örebro län', 'slug' => 'region-orebro-lan', 'is_active' => false],
        ['id' => 20, 'name' => 'Region Östergötland', 'slug' => 'region-ostergotland', 'is_active' => false],
        ['id' => 21, 'name' => 'Västra Götalandsregionen', 'slug' => 'vastra-gotalandsregionen', 'is_active' => false],
    ];

    return array_map(static function (array $region): array {
        return [
            'id' => (int)$region['id'],
            'name' => (string)$region['name'],
            'slug' => (string)$region['slug'],
            'is_active' => (bool)$region['is_active'],
            'created_at' => '2026-03-15 08:00:00',
            'updated_at' => '2026-03-15 08:00:00',
        ];
    }, $definitions);
}

function normalize_region_slug(string $value, string $fallbackName = ''): string
{
    $candidate = trim($value);
    if ($candidate === '' && $fallbackName !== '') {
        $candidate = $fallbackName;
    }

    $candidate = mb_strtolower($candidate, 'UTF-8');
    $candidate = strtr($candidate, [
        'å' => 'a',
        'ä' => 'a',
        'ö' => 'o',
        'é' => 'e',
        'è' => 'e',
        'ü' => 'u',
    ]);
    $candidate = preg_replace('/[^a-z0-9]+/', '-', $candidate) ?? '';
    $candidate = trim($candidate, '-');

    return $candidate !== '' ? $candidate : 'region';
}

function valid_organization_types(): array
{
    return [
        ORGANIZATION_TYPE_HQ,
        ORGANIZATION_TYPE_REGIONAL_COMPANY,
        ORGANIZATION_TYPE_FRANCHISE_UNIT,
    ];
}

function normalize_organization_type(string $value): string
{
    return in_array($value, valid_organization_types(), true) ? $value : ORGANIZATION_TYPE_FRANCHISE_UNIT;
}

function normalize_organization(array $organization): array
{
    $name = trim((string)($organization['name'] ?? ''));

    return [
        'id' => (int)($organization['id'] ?? 0),
        'name' => $name,
        'slug' => normalize_region_slug((string)($organization['slug'] ?? ''), $name),
        'organization_type' => normalize_organization_type((string)($organization['organization_type'] ?? $organization['organizationType'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT)),
        'parent_organization_id' => ($organization['parent_organization_id'] ?? $organization['parentOrganizationId'] ?? null) !== null
            && ($organization['parent_organization_id'] ?? $organization['parentOrganizationId']) !== ''
            ? (int)($organization['parent_organization_id'] ?? $organization['parentOrganizationId'])
            : null,
        'region_id' => ($organization['region_id'] ?? $organization['regionId'] ?? null) !== null
            && ($organization['region_id'] ?? $organization['regionId']) !== ''
            ? (int)($organization['region_id'] ?? $organization['regionId'])
            : null,
        'service_postcode_prefixes' => trim((string)($organization['service_postcode_prefixes'] ?? $organization['servicePostcodePrefixes'] ?? '')),
        'service_cities' => trim((string)($organization['service_cities'] ?? $organization['serviceCities'] ?? '')),
        'is_active' => !array_key_exists('is_active', $organization) || !empty($organization['is_active']),
        'created_at' => (string)($organization['created_at'] ?? $organization['createdAt'] ?? now_iso()),
        'updated_at' => (string)($organization['updated_at'] ?? $organization['updatedAt'] ?? now_iso()),
    ];
}

function normalize_web_quote_request(array $request): array
{
    return [
        'id' => (int)($request['id'] ?? 0),
        'name' => trim((string)($request['name'] ?? '')),
        'phone' => trim((string)($request['phone'] ?? '')),
        'email' => trim((string)($request['email'] ?? '')),
        'service_address' => trim((string)($request['service_address'] ?? $request['serviceAddress'] ?? '')),
        'service_postcode' => trim((string)($request['service_postcode'] ?? $request['servicePostcode'] ?? '')),
        'service_city' => trim((string)($request['service_city'] ?? $request['serviceCity'] ?? '')),
        'message' => trim((string)($request['message'] ?? '')),
        'source_page' => trim((string)($request['source_page'] ?? $request['sourcePage'] ?? '')),
        'region_id' => ($request['region_id'] ?? $request['regionId'] ?? null) !== null && ($request['region_id'] ?? $request['regionId']) !== ''
            ? (int)($request['region_id'] ?? $request['regionId'])
            : null,
        'requested_region_name' => trim((string)($request['requested_region_name'] ?? $request['requestedRegionName'] ?? '')),
        'organization_id' => ($request['organization_id'] ?? $request['organizationId'] ?? null) !== null && ($request['organization_id'] ?? $request['organizationId']) !== ''
            ? (int)($request['organization_id'] ?? $request['organizationId'])
            : null,
        'assignment_basis' => trim((string)($request['assignment_basis'] ?? $request['assignmentBasis'] ?? '')),
        'status' => in_array((string)($request['status'] ?? 'new'), ['new', 'handled', 'archived'], true) ? (string)($request['status'] ?? 'new') : 'new',
        'handled_by_username' => trim((string)($request['handled_by_username'] ?? $request['handledByUsername'] ?? '')),
        'handled_at' => trim((string)($request['handled_at'] ?? $request['handledAt'] ?? '')),
        'created_at' => (string)($request['created_at'] ?? $request['createdAt'] ?? now_iso()),
        'updated_at' => (string)($request['updated_at'] ?? $request['updatedAt'] ?? now_iso()),
    ];
}

function normalize_organization_membership(array $membership): array
{
    return [
        'id' => (int)($membership['id'] ?? 0),
        'user_id' => (int)($membership['user_id'] ?? $membership['userId'] ?? 0),
        'organization_id' => (int)($membership['organization_id'] ?? $membership['organizationId'] ?? 0),
        'role' => normalize_user_role((string)($membership['role'] ?? USER_ROLE_WORKER)),
        'is_primary' => !empty($membership['is_primary'] ?? $membership['isPrimary'] ?? false),
        'created_at' => (string)($membership['created_at'] ?? $membership['createdAt'] ?? now_iso()),
        'updated_at' => (string)($membership['updated_at'] ?? $membership['updatedAt'] ?? now_iso()),
    ];
}

function normalize_region(array $region): array
{
    $name = trim((string)($region['name'] ?? ''));

    return [
        'id' => (int)($region['id'] ?? 0),
        'name' => $name,
        'slug' => normalize_region_slug((string)($region['slug'] ?? ''), $name),
        'is_active' => !array_key_exists('is_active', $region) || !empty($region['is_active']),
        'created_at' => (string)($region['created_at'] ?? $region['createdAt'] ?? now_iso()),
        'updated_at' => (string)($region['updated_at'] ?? $region['updatedAt'] ?? now_iso()),
    ];
}

function region_form_defaults(?array $region = null): array
{
    $normalized = $region ? normalize_region($region) : null;

    return [
        'name' => (string)($normalized['name'] ?? ''),
        'slug' => (string)($normalized['slug'] ?? ''),
        'isActive' => !isset($normalized['is_active']) || !empty($normalized['is_active']) ? '1' : '0',
    ];
}

function validate_region_payload(array $payload): array
{
    $errors = [];

    if (trim((string)($payload['name'] ?? '')) === '') {
        $errors['name'] = 'Regionsnamn krävs.';
    }

    if (!in_array((string)($payload['isActive'] ?? '1'), ['0', '1'], true)) {
        $errors['isActive'] = 'Ogiltig regionsstatus.';
    }

    return $errors;
}

function build_region_record(array $payload, ?array $existingRegion = null): array
{
    $now = now_iso();
    $name = trim((string)($payload['name'] ?? ''));

    return normalize_region([
        'id' => (int)($existingRegion['id'] ?? $payload['id'] ?? 0),
        'name' => $name,
        'slug' => normalize_region_slug((string)($payload['slug'] ?? ''), $name),
        'is_active' => (string)($payload['isActive'] ?? '1') !== '0',
        'created_at' => (string)($existingRegion['created_at'] ?? $now),
        'updated_at' => $now,
    ]);
}

function validate_organization_payload(array $payload): array
{
    $errors = [];

    if (trim((string)($payload['name'] ?? '')) === '') {
        $errors['name'] = 'Organisationsnamn krävs.';
    }

    if (!in_array((string)($payload['organizationType'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT), valid_organization_types(), true)) {
        $errors['organizationType'] = 'Ogiltig organisationstyp.';
    }

    if (!in_array((string)($payload['isActive'] ?? '1'), ['0', '1'], true)) {
        $errors['isActive'] = 'Ogiltig organisationsstatus.';
    }

    if (($payload['regionId'] ?? '') !== '' && (int)($payload['regionId'] ?? 0) < 0) {
        $errors['regionId'] = 'Ogiltig region.';
    }

    if (($payload['parentOrganizationId'] ?? '') !== '' && (int)($payload['parentOrganizationId'] ?? 0) < 0) {
        $errors['parentOrganizationId'] = 'Ogiltig överordnad organisation.';
    }

    if (mb_strlen(trim((string)($payload['servicePostcodePrefixes'] ?? '')), 'UTF-8') > 255) {
        $errors['servicePostcodePrefixes'] = 'Postnummerprefix får vara max 255 tecken.';
    }

    if (mb_strlen(trim((string)($payload['serviceCities'] ?? '')), 'UTF-8') > 255) {
        $errors['serviceCities'] = 'Orter får vara max 255 tecken.';
    }
    return $errors;
}

function build_organization_record(array $payload, ?array $existingOrganization = null): array
{
    $now = now_iso();
    $name = trim((string)($payload['name'] ?? ''));

    return normalize_organization([
        'id' => (int)($existingOrganization['id'] ?? $payload['id'] ?? 0),
        'name' => $name,
        'slug' => normalize_region_slug((string)($payload['slug'] ?? ''), $name),
        'organization_type' => (string)($payload['organizationType'] ?? $existingOrganization['organization_type'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT),
        'parent_organization_id' => trim((string)($payload['parentOrganizationId'] ?? '')) !== '' ? (int)($payload['parentOrganizationId'] ?? 0) : null,
        'region_id' => trim((string)($payload['regionId'] ?? '')) !== '' ? (int)($payload['regionId'] ?? 0) : null,
        'service_postcode_prefixes' => trim((string)($payload['servicePostcodePrefixes'] ?? '')),
        'service_cities' => trim((string)($payload['serviceCities'] ?? '')),
        'is_active' => (string)($payload['isActive'] ?? '1') !== '0',
        'created_at' => (string)($existingOrganization['created_at'] ?? $now),
        'updated_at' => $now,
    ]);
}

function organization_form_defaults(?array $organization = null): array
{
    return [
        'name' => (string)($organization['name'] ?? ''),
        'slug' => (string)($organization['slug'] ?? ''),
        'organizationType' => (string)($organization['organization_type'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT),
        'parentOrganizationId' => ($organization['parent_organization_id'] ?? null) !== null ? (string)$organization['parent_organization_id'] : '',
        'regionId' => ($organization['region_id'] ?? null) !== null ? (string)$organization['region_id'] : '',
        'servicePostcodePrefixes' => (string)($organization['service_postcode_prefixes'] ?? ''),
        'serviceCities' => (string)($organization['service_cities'] ?? ''),
        'isActive' => !array_key_exists('is_active', (array)$organization) || !empty($organization['is_active']) ? '1' : '0',
    ];
}

function csv_tokens(string $value): array
{
    $parts = preg_split('/[,;\n\r]+/', $value) ?: [];
    return array_values(array_filter(array_map(static fn(string $part): string => trim($part), $parts), static fn(string $part): bool => $part !== ''));
}

function normalize_postcode_digits(string $postcode): string
{
    return preg_replace('/\D+/', '', $postcode) ?? '';
}

function organization_service_postcode_prefixes(array $organization): array
{
    return array_values(array_filter(array_map(
        static fn(string $part): string => normalize_postcode_digits($part),
        csv_tokens((string)($organization['service_postcode_prefixes'] ?? ''))
    ), static fn(string $part): bool => $part !== ''));
}

function organization_service_cities(array $organization): array
{
    return array_values(array_filter(array_map(
        static fn(string $part): string => mb_strtolower(trim($part), 'UTF-8'),
        csv_tokens((string)($organization['service_cities'] ?? ''))
    ), static fn(string $part): bool => $part !== ''));
}

function find_matching_organization_for_web_quote_request(array $organizations, string $postcode, string $city): ?array
{
    $postcodeDigits = normalize_postcode_digits($postcode);
    $normalizedCity = mb_strtolower(trim($city), 'UTF-8');
    $bestMatch = null;
    $bestScore = -1;

    foreach ($organizations as $organization) {
        if (empty($organization['is_active'])) {
            continue;
        }

        $score = -1;
        foreach (organization_service_postcode_prefixes($organization) as $prefix) {
            if ($prefix !== '' && $postcodeDigits !== '' && str_starts_with($postcodeDigits, $prefix)) {
                $score = max($score, 20 + strlen($prefix));
            }
        }

        if ($normalizedCity !== '' && in_array($normalizedCity, organization_service_cities($organization), true)) {
            $score = max($score, 10);
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $score >= 0 ? $organization : $bestMatch;
        }
    }

    return $bestScore >= 0 ? $bestMatch : null;
}

function find_region_by_name_or_slug(array $regions, string $value): ?array
{
    $needle = mb_strtolower(trim($value), 'UTF-8');
    if ($needle === '') {
        return null;
    }

    $needlePlain = preg_replace('/^region\s+/u', '', $needle) ?? $needle;

    foreach ($regions as $region) {
        $name = mb_strtolower(trim((string)($region['name'] ?? '')), 'UTF-8');
        $slug = mb_strtolower(trim((string)($region['slug'] ?? '')), 'UTF-8');
        $namePlain = preg_replace('/^region\s+/u', '', $name) ?? $name;
        $slugPlain = preg_replace('/^region-/', '', $slug) ?? $slug;

        if ($needle === $name || $needle === $slug || $needlePlain === $namePlain || $needlePlain === $slugPlain) {
            return $region;
        }
    }

    return null;
}

function find_active_organization_for_region(array $organizations, int $regionId): ?array
{
    foreach ($organizations as $organization) {
        if (empty($organization['is_active'])) {
            continue;
        }

        if ((int)($organization['region_id'] ?? 0) === $regionId) {
            return $organization;
        }
    }

    return null;
}

function find_dalarna_fallback_organization(array $organizations, array $regions): ?array
{
    $dalarnaRegion = find_region_by_name_or_slug($regions, 'region-dalarna')
        ?? find_region_by_name_or_slug($regions, 'Region Dalarna');

    if ($dalarnaRegion !== null) {
        $organization = find_active_organization_for_region($organizations, (int)($dalarnaRegion['id'] ?? 0));
        if ($organization !== null) {
            return $organization;
        }
    }

    foreach ($organizations as $organization) {
        if (!empty($organization['is_active'])) {
            return $organization;
        }
    }

    return null;
}

function infer_region_from_postcode(string $postcode, array $regions): ?array
{
    $digits = normalize_postcode_digits($postcode);
    if (strlen($digits) < 5) {
        return null;
    }

    $postcodeNumber = (int)substr($digits, 0, 5);
    $ranges = [
        ['Region Blekinge', 29300, 29495],
        ['Region Blekinge', 37010, 37693],
        ['Region Halland', 30004, 31498],
        ['Region Jönköpings län', 33010, 33594],
        ['Region Kronoberg', 34010, 36433],
        ['Region Blekinge', 37010, 37693],
        ['Region Kalmar län', 38030, 39809],
        ['Västra Götalandsregionen', 40010, 42999],
        ['Region Halland', 43200, 43299],
        ['Region Halland', 43400, 43999],
        ['Västra Götalandsregionen', 43000, 54999],
        ['Region Jönköpings län', 55001, 57895],
        ['Region Östergötland', 58002, 61893],
        ['Region Gotland', 62010, 62470],
        ['Region Sörmland', 63003, 64991],
        ['Region Värmland', 65001, 68892],
        ['Region Örebro län', 69045, 71995],
        ['Region Västmanland', 72001, 73992],
        ['Region Uppsala', 74010, 75900],
        ['Region Dalarna', 77010, 79699],
        ['Region Gävleborg', 80002, 82899],
        ['Region Jämtland Härjedalen', 83001, 84299],
        ['Region Västernorrland', 85002, 89693],
        ['Region Västerbotten', 90001, 93796],
        ['Region Norrbotten', 93831, 99470],
        ['Region Stockholm', 10005, 19793],
        ['Region Skåne', 20001, 29899],
        ['Region Sörmland', 61050, 61999],
    ];

    foreach ($ranges as [$regionName, $start, $end]) {
        if ($postcodeNumber >= $start && $postcodeNumber <= $end) {
            return find_region_by_name_or_slug($regions, $regionName);
        }
    }

    return null;
}

function normalize_role_list(mixed $roles): array
{
    if (!is_array($roles)) {
        $roles = [$roles];
    }

    $normalized = array_values(array_unique(array_filter(array_map(
        static fn(mixed $role): string => normalize_user_role((string)$role),
        $roles
    ), static fn(string $role): bool => $role !== '')));

    return $normalized !== [] ? $normalized : [USER_ROLE_WORKER];
}

function normalize_user(array $user): array
{
    return [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'name' => (string)($user['name'] ?? $user['username'] ?? 'Admin'),
        'phone' => trim((string)($user['phone'] ?? $user['mobile'] ?? '')),
        'email' => trim((string)($user['email'] ?? '')),
        'role' => normalize_user_role((string)($user['role'] ?? USER_ROLE_WORKER)),
        'organization_id' => ($user['organization_id'] ?? $user['organizationId'] ?? null) !== null && ($user['organization_id'] ?? $user['organizationId']) !== ''
            ? (int)($user['organization_id'] ?? $user['organizationId'])
            : null,
        'region_id' => ($user['region_id'] ?? $user['regionId'] ?? null) !== null && ($user['region_id'] ?? $user['regionId']) !== ''
            ? (int)($user['region_id'] ?? $user['regionId'])
            : null,
        'organization_name' => (string)($user['organization_name'] ?? $user['organizationName'] ?? ''),
        'effective_roles' => array_values(array_unique(array_map(
            static fn(mixed $role): string => normalize_user_role((string)$role),
            is_array($user['effective_roles'] ?? null) ? $user['effective_roles'] : [(string)($user['role'] ?? USER_ROLE_WORKER)]
        ))),
        'is_active' => !array_key_exists('is_active', $user) || !empty($user['is_active']),
        'failed_login_attempts' => max(0, (int)($user['failed_login_attempts'] ?? $user['failedLoginAttempts'] ?? 0)),
        'locked_until' => trim((string)($user['locked_until'] ?? $user['lockedUntil'] ?? '')),
        'last_login_at' => trim((string)($user['last_login_at'] ?? $user['lastLoginAt'] ?? '')),
        'two_factor_enabled' => !empty($user['two_factor_enabled'] ?? $user['twoFactorEnabled'] ?? false),
        'two_factor_secret' => strtoupper(trim((string)($user['two_factor_secret'] ?? $user['twoFactorSecret'] ?? ''))),
        'two_factor_confirmed_at' => trim((string)($user['two_factor_confirmed_at'] ?? $user['twoFactorConfirmedAt'] ?? '')),
        'password_hash' => (string)($user['password_hash'] ?? ''),
        'created_at' => (string)($user['created_at'] ?? now_iso()),
        'updated_at' => (string)($user['updated_at'] ?? now_iso()),
    ];
}

function user_form_defaults(?array $user = null): array
{
    $roles = normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER));

    return [
        'username' => (string)($user['username'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => $roles[0] ?? USER_ROLE_WORKER,
        'roles' => $roles,
        'organizationId' => ($user['organization_id'] ?? null) !== null ? (string)($user['organization_id']) : '',
        'regionId' => ($user['region_id'] ?? null) !== null ? (string)($user['region_id']) : '',
        'isActive' => !array_key_exists('is_active', (array)$user) || !empty($user['is_active']) ? '1' : '0',
        'twoFactorEnabled' => !empty($user['two_factor_enabled']) ? '1' : '0',
        'password' => '',
        'passwordConfirm' => '',
    ];
}

function login_lockout_max_attempts(): int
{
    if (is_dev_environment()) {
        return 1000;
    }

    return 5;
}

function login_lockout_duration_minutes(): int
{
    if (is_dev_environment()) {
        return 0;
    }

    return 15;
}

function user_lock_is_active(array $user, ?string $now = null): bool
{
    if (is_dev_environment()) {
        return false;
    }

    $lockedUntil = trim((string)($user['locked_until'] ?? ''));
    if ($lockedUntil === '') {
        return false;
    }

    $lockedTimestamp = strtotime($lockedUntil);
    $nowTimestamp = strtotime($now ?? now_iso());

    return $lockedTimestamp !== false && $nowTimestamp !== false && $lockedTimestamp > $nowTimestamp;
}

function user_lock_remaining_minutes(array $user, ?string $now = null): int
{
    if (!user_lock_is_active($user, $now)) {
        return 0;
    }

    $lockedTimestamp = strtotime((string)($user['locked_until'] ?? ''));
    $nowTimestamp = strtotime($now ?? now_iso());
    if ($lockedTimestamp === false || $nowTimestamp === false) {
        return 0;
    }

    return max(1, (int)ceil(($lockedTimestamp - $nowTimestamp) / 60));
}

function login_attempt_bucket_key(string $username, string $ip): string
{
    return mb_strtolower(trim($username), 'UTF-8') . '|' . trim($ip);
}

function get_session_login_attempts(): array
{
    $attempts = $_SESSION['login_attempts'] ?? [];

    return is_array($attempts) ? $attempts : [];
}

function set_session_login_attempts(array $attempts): void
{
    $_SESSION['login_attempts'] = $attempts;
}

function bucket_login_attempts(string $username, string $ip): array
{
    $attempts = get_session_login_attempts();
    $attempt = $attempts[login_attempt_bucket_key($username, $ip)] ?? null;

    return is_array($attempt) ? $attempt : ['count' => 0, 'locked_until' => ''];
}

function bucket_login_is_locked(string $username, string $ip): bool
{
    if (is_dev_environment()) {
        return false;
    }

    $lockedUntil = trim((string)(bucket_login_attempts($username, $ip)['locked_until'] ?? ''));
    if ($lockedUntil === '') {
        return false;
    }

    $lockedTimestamp = strtotime($lockedUntil);

    return $lockedTimestamp !== false && $lockedTimestamp > time();
}

function bucket_login_remaining_minutes(string $username, string $ip): int
{
    if (is_dev_environment()) {
        return 0;
    }

    $lockedUntil = trim((string)(bucket_login_attempts($username, $ip)['locked_until'] ?? ''));
    $lockedTimestamp = strtotime($lockedUntil);
    if ($lockedTimestamp === false || $lockedTimestamp <= time()) {
        return 0;
    }

    return max(1, (int)ceil(($lockedTimestamp - time()) / 60));
}

function register_failed_login_attempt(string $username, string $ip, ?array $user = null): ?array
{
    if (is_dev_environment()) {
        return $user !== null ? normalize_user($user) : null;
    }

    $attempts = get_session_login_attempts();
    $key = login_attempt_bucket_key($username, $ip);
    $attempt = $attempts[$key] ?? ['count' => 0, 'locked_until' => ''];
    $count = max(0, (int)($attempt['count'] ?? 0)) + 1;
    $lockedUntil = '';

    if ($count >= login_lockout_max_attempts()) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime('+' . login_lockout_duration_minutes() . ' minutes'));
        $count = 0;
    }

    $attempts[$key] = [
        'count' => $count,
        'locked_until' => $lockedUntil,
    ];
    set_session_login_attempts($attempts);

    if ($user === null || !mysql_is_configured() || !user_security_schema_ready()) {
        auth_log_write(
            (int)($user['id'] ?? 0),
            $lockedUntil !== '' ? 'login_locked' : 'login_failed',
            'Misslyckad inloggning för ' . trim($username) . ' från ' . trim($ip) . ($lockedUntil !== '' ? '. Tillfällig spärr aktiverad.' : '.')
        );
        return null;
    }

    $user = normalize_user($user);
    $nextAttempts = max(0, (int)($user['failed_login_attempts'] ?? 0)) + 1;
    $userLockedUntil = '';

    if ($nextAttempts >= login_lockout_max_attempts()) {
        $userLockedUntil = date('Y-m-d H:i:s', strtotime('+' . login_lockout_duration_minutes() . ' minutes'));
        $nextAttempts = 0;
    }

    $timestamp = now_iso();
    $pdo = admin_pdo();
    $statement = $pdo->prepare(
        'UPDATE users
         SET failed_login_attempts = :failed_login_attempts,
             locked_until = :locked_until,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $statement->execute([
        'failed_login_attempts' => $nextAttempts,
        'locked_until' => mysql_nullable_string($userLockedUntil),
        'updated_at' => $timestamp,
        'id' => (int)($user['id'] ?? 0),
    ]);

    $user['failed_login_attempts'] = $nextAttempts;
    $user['locked_until'] = $userLockedUntil;
    $user['updated_at'] = $timestamp;

    auth_log_write(
        (int)($user['id'] ?? 0),
        $userLockedUntil !== '' ? 'login_locked' : 'login_failed',
        'Misslyckad inloggning för ' . (string)($user['username'] ?? trim($username)) . ' från ' . trim($ip) . ($userLockedUntil !== '' ? '. Kontot låstes tillfälligt.' : '.')
    );

    return normalize_user($user);
}

function clear_login_attempts(string $username, string $ip): void
{
    $attempts = get_session_login_attempts();
    unset($attempts[login_attempt_bucket_key($username, $ip)]);
    set_session_login_attempts($attempts);
}

function register_successful_login(array $user, string $ip): array
{
    $user = normalize_user($user);
    clear_login_attempts((string)($user['username'] ?? ''), $ip);

    $timestamp = now_iso();
    $user['failed_login_attempts'] = 0;
    $user['locked_until'] = '';
    $user['last_login_at'] = $timestamp;
    $user['updated_at'] = $timestamp;

    auth_log_write(
        (int)($user['id'] ?? 0),
        'login_success',
        'Lyckad inloggning för ' . (string)($user['username'] ?? '') . ' från ' . trim($ip) . '.'
    );

    if (!mysql_is_configured() || !user_security_schema_ready()) {
        return normalize_user($user);
    }

    $pdo = admin_pdo();
    $statement = $pdo->prepare(
        'UPDATE users
         SET failed_login_attempts = 0,
             locked_until = NULL,
             last_login_at = :last_login_at,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $statement->execute([
        'last_login_at' => $timestamp,
        'updated_at' => $timestamp,
        'id' => (int)($user['id'] ?? 0),
    ]);

    return normalize_user($user);
}

function prepare_user_two_factor(int $userId): array
{
    $data = load_data();
    $user = find_user_by_id($data, $userId);
    if ($user === null) {
        throw new RuntimeException('Användaren kunde inte hittas.');
    }

    if (!in_array(USER_ROLE_ADMIN, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true)) {
        throw new RuntimeException('2FA kan bara aktiveras för adminanvändare.');
    }

    $user = normalize_user($user);
    $user['two_factor_secret'] = generate_two_factor_secret();
    $user['two_factor_enabled'] = false;
    $user['two_factor_confirmed_at'] = '';
    $user['updated_at'] = now_iso();

    if (mysql_is_configured() && user_security_schema_ready()) {
        $pdo = admin_pdo();
        $statement = $pdo->prepare(
            'UPDATE users
             SET two_factor_enabled = 0,
                 two_factor_secret = :two_factor_secret,
                 two_factor_confirmed_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'two_factor_secret' => (string)$user['two_factor_secret'],
            'updated_at' => (string)$user['updated_at'],
            'id' => $userId,
        ]);
    } else {
        $data['users'] = array_map(static function (array $candidate) use ($userId, $user): array {
            return (int)($candidate['id'] ?? 0) === $userId ? normalize_user($user) : $candidate;
        }, $data['users'] ?? []);
        save_data($data);
    }

    auth_log_write(
        $userId,
        'two_factor_prepared',
        '2FA-hemlighet skapades för ' . (string)($user['username'] ?? '') . '.'
    );

    return normalize_user($user);
}

function confirm_user_two_factor(int $userId, string $code): array
{
    $data = load_data();
    $user = find_user_by_id($data, $userId);
    if ($user === null) {
        throw new RuntimeException('Användaren kunde inte hittas.');
    }

    $user = normalize_user($user);
    $secret = (string)($user['two_factor_secret'] ?? '');

    if (!is_valid_base32_secret($secret)) {
        throw new RuntimeException('Skapa först en 2FA-hemlighet.');
    }

    if (!verify_two_factor_code($secret, $code)) {
        throw new RuntimeException('Koden kunde inte verifieras. Kontrollera appen och prova igen.');
    }

    $timestamp = now_iso();
    $user['two_factor_enabled'] = true;
    $user['two_factor_confirmed_at'] = $timestamp;
    $user['updated_at'] = $timestamp;

    if (mysql_is_configured() && user_security_schema_ready()) {
        $pdo = admin_pdo();
        $statement = $pdo->prepare(
            'UPDATE users
             SET two_factor_enabled = 1,
                 two_factor_confirmed_at = :two_factor_confirmed_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'two_factor_confirmed_at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => $userId,
        ]);
    } else {
        $data['users'] = array_map(static function (array $candidate) use ($userId, $user): array {
            return (int)($candidate['id'] ?? 0) === $userId ? normalize_user($user) : $candidate;
        }, $data['users'] ?? []);
        save_data($data);
    }

    auth_log_write(
        $userId,
        'two_factor_enabled',
        '2FA aktiverades för ' . (string)($user['username'] ?? '') . '.'
    );

    return normalize_user($user);
}

function disable_user_two_factor(int $userId): array
{
    $data = load_data();
    $user = find_user_by_id($data, $userId);
    if ($user === null) {
        throw new RuntimeException('Användaren kunde inte hittas.');
    }

    $user = normalize_user($user);
    $timestamp = now_iso();
    $user['two_factor_enabled'] = false;
    $user['two_factor_secret'] = '';
    $user['two_factor_confirmed_at'] = '';
    $user['updated_at'] = $timestamp;

    if (mysql_is_configured() && user_security_schema_ready()) {
        $pdo = admin_pdo();
        $statement = $pdo->prepare(
            'UPDATE users
             SET two_factor_enabled = 0,
                 two_factor_secret = \'\',
                 two_factor_confirmed_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'updated_at' => $timestamp,
            'id' => $userId,
        ]);
    } else {
        $data['users'] = array_map(static function (array $candidate) use ($userId, $user): array {
            return (int)($candidate['id'] ?? 0) === $userId ? normalize_user($user) : $candidate;
        }, $data['users'] ?? []);
        save_data($data);
    }

    auth_log_write(
        $userId,
        'two_factor_disabled',
        '2FA stängdes av för ' . (string)($user['username'] ?? '') . '.'
    );

    return normalize_user($user);
}

function reset_user_login_lock(int $userId): array
{
    $data = load_data();
    $user = find_user_by_id($data, $userId);
    if ($user === null) {
        throw new RuntimeException('Användaren kunde inte hittas.');
    }

    $user = normalize_user($user);
    $timestamp = now_iso();
    $user['failed_login_attempts'] = 0;
    $user['locked_until'] = '';
    $user['updated_at'] = $timestamp;

    if (mysql_is_configured() && user_security_schema_ready()) {
        $pdo = admin_pdo();
        $statement = $pdo->prepare(
            'UPDATE users
             SET failed_login_attempts = 0,
                 locked_until = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'updated_at' => $timestamp,
            'id' => $userId,
        ]);
    } else {
        $data['users'] = array_map(static function (array $candidate) use ($userId, $user): array {
            return (int)($candidate['id'] ?? 0) === $userId ? normalize_user($user) : $candidate;
        }, $data['users'] ?? []);
        save_data($data);
    }

    auth_log_write(
        $userId,
        'login_lock_reset',
        'Inloggningsspärr nollställdes för ' . (string)($user['username'] ?? '') . '.'
    );

    return normalize_user($user);
}

function auth_log_write(int $userId, string $action, string $message): void
{
    if (!mysql_is_configured()) {
        return;
    }

    try {
        $pdo = admin_pdo();
        if (!mysql_table_exists($pdo, 'entity_logs')) {
            return;
        }

        entity_log_write($pdo, 'user', $userId, $action, $message);
    } catch (Throwable) {
        // Security logging must never break primary auth flow.
    }
}

function valid_product_item_types(): array
{
    return ['labor', 'material', 'service'];
}

function normalize_product_item_type(string $itemType): string
{
    return in_array($itemType, valid_product_item_types(), true) ? $itemType : 'service';
}

function valid_product_price_models(): array
{
    return ['fixed', 'per_sqm', 'per_mil', 'per_unit'];
}

function normalize_product_price_model(string $priceModel): string
{
    return in_array($priceModel, valid_product_price_models(), true) ? $priceModel : 'fixed';
}

function normalize_product_unit(string $unit, string $priceModel): string
{
    $unit = trim($unit);
    if ($unit !== '') {
        return $unit;
    }

    return match ($priceModel) {
        'per_sqm' => 'kvm',
        'per_mil' => 'mil',
        'per_unit' => 'st',
        default => 'st',
    };
}

function normalize_product(array $product): array
{
    $priceModel = normalize_product_price_model((string)($product['price_model'] ?? $product['priceModel'] ?? 'fixed'));
    $category = trim((string)($product['category'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    $itemType = normalize_product_item_type((string)($product['item_type'] ?? $product['itemType'] ?? 'service'));
    if (in_array(mb_strtolower($name, 'UTF-8'), ['fogsand', 'leverans av fogsand'], true)) {
        $itemType = 'service';
    } elseif (mb_strtolower($category, 'UTF-8') === 'kemikalier') {
        $itemType = 'material';
    }
    $vatRate = $product['vat_rate'] ?? $product['vatRate'] ?? 0.25;
    $vatRate = is_numeric($vatRate) ? (float)$vatRate : 0.25;
    if ($vatRate > 1.0) {
        $vatRate /= 100;
    }

    return [
        'id' => (int)($product['id'] ?? 0),
        'name' => $name,
        'description' => trim((string)($product['description'] ?? '')),
        'category' => $category,
        'item_type' => $itemType,
        'price_model' => $priceModel,
        'default_quantity' => max(0.01, to_float($product['default_quantity'] ?? $product['defaultQuantity'] ?? 1)),
        'unit' => normalize_product_unit((string)($product['unit'] ?? ''), $priceModel),
        'default_unit_price' => max(0, to_float($product['default_unit_price'] ?? $product['defaultUnitPrice'] ?? 0)),
        'vat_rate' => max(0, min(1, $vatRate)),
        'is_rut_eligible' => !empty($product['is_rut_eligible'] ?? $product['isRutEligible'] ?? false),
        'is_active' => !array_key_exists('is_active', $product) || !empty($product['is_active']),
        'created_at' => (string)($product['created_at'] ?? $product['createdAt'] ?? now_iso()),
        'updated_at' => (string)($product['updated_at'] ?? $product['updatedAt'] ?? now_iso()),
    ];
}

function product_form_defaults(?array $product = null): array
{
    $normalized = $product ? normalize_product($product) : null;

    return [
        'name' => (string)($normalized['name'] ?? ''),
        'description' => (string)($normalized['description'] ?? ''),
        'category' => (string)($normalized['category'] ?? ''),
        'itemType' => (string)($normalized['item_type'] ?? 'service'),
        'priceModel' => (string)($normalized['price_model'] ?? 'fixed'),
        'defaultQuantity' => isset($normalized['default_quantity']) ? (string)(float)$normalized['default_quantity'] : '1',
        'unit' => (string)($normalized['unit'] ?? 'st'),
        'defaultUnitPrice' => isset($normalized['default_unit_price']) ? (string)(float)$normalized['default_unit_price'] : '0',
        'vatRatePercent' => isset($normalized['vat_rate']) ? (string)(float)round(((float)$normalized['vat_rate']) * 100, 2) : '25',
        'isRutEligible' => !empty($normalized['is_rut_eligible']) ? '1' : '0',
        'isActive' => !isset($normalized['is_active']) || !empty($normalized['is_active']) ? '1' : '0',
    ];
}

function validate_product_payload(array $payload): array
{
    $errors = [];

    if (trim((string)($payload['name'] ?? '')) === '') {
        $errors['name'] = 'Produktnamn krävs.';
    }

    if (!in_array((string)($payload['itemType'] ?? ''), valid_product_item_types(), true)) {
        $errors['itemType'] = 'Ogiltig produkttyp.';
    }

    if (!in_array((string)($payload['priceModel'] ?? ''), valid_product_price_models(), true)) {
        $errors['priceModel'] = 'Ogiltig prismodell.';
    }

    if (to_float($payload['defaultQuantity'] ?? 0) <= 0) {
        $errors['defaultQuantity'] = 'Standardantal måste vara större än 0.';
    }

    if (to_float($payload['defaultUnitPrice'] ?? -1) < 0) {
        $errors['defaultUnitPrice'] = 'Standardpris kan inte vara negativt.';
    }

    $vatRatePercent = to_float($payload['vatRatePercent'] ?? 25);
    if ($vatRatePercent < 0 || $vatRatePercent > 100) {
        $errors['vatRatePercent'] = 'Moms måste vara mellan 0 och 100 %.';
    }

    if (!in_array((string)($payload['isRutEligible'] ?? '0'), ['0', '1'], true)) {
        $errors['isRutEligible'] = 'Ogiltigt RUT-värde.';
    }

    if (!in_array((string)($payload['isActive'] ?? '1'), ['0', '1'], true)) {
        $errors['isActive'] = 'Ogiltig produktstatus.';
    }

    return $errors;
}

function build_product_record(array $payload, ?array $existingProduct = null): array
{
    $now = now_iso();
    $priceModel = normalize_product_price_model((string)($payload['priceModel'] ?? 'fixed'));

    return normalize_product([
        'id' => (int)($existingProduct['id'] ?? $payload['id'] ?? 0),
        'name' => trim((string)($payload['name'] ?? '')),
        'description' => trim((string)($payload['description'] ?? '')),
        'category' => trim((string)($payload['category'] ?? '')),
        'item_type' => normalize_product_item_type((string)($payload['itemType'] ?? 'service')),
        'price_model' => $priceModel,
        'default_quantity' => to_float($payload['defaultQuantity'] ?? 1),
        'unit' => normalize_product_unit((string)($payload['unit'] ?? ''), $priceModel),
        'default_unit_price' => to_float($payload['defaultUnitPrice'] ?? 0),
        'vat_rate' => to_float($payload['vatRatePercent'] ?? 25) / 100,
        'is_rut_eligible' => (string)($payload['isRutEligible'] ?? '0') === '1',
        'is_active' => (string)($payload['isActive'] ?? '1') !== '0',
        'created_at' => (string)($existingProduct['created_at'] ?? $now),
        'updated_at' => $now,
    ]);
}

function valid_service_families(): array
{
    return ['stone', 'deck', 'general'];
}

function normalize_service_family(string $serviceFamily): string
{
    return in_array($serviceFamily, valid_service_families(), true) ? $serviceFamily : 'general';
}

function valid_package_quantity_modes(): array
{
    return ['product_default', 'fixed', 'per_sqm', 'per_mil'];
}

function normalize_package_quantity_mode(string $mode): string
{
    return in_array($mode, valid_package_quantity_modes(), true) ? $mode : 'product_default';
}

function normalize_service_package(array $package): array
{
    return [
        'id' => (int)($package['id'] ?? 0),
        'name' => trim((string)($package['name'] ?? '')),
        'service_family' => normalize_service_family((string)($package['service_family'] ?? $package['serviceFamily'] ?? 'general')),
        'description' => trim((string)($package['description'] ?? '')),
        'is_active' => !array_key_exists('is_active', $package) || !empty($package['is_active']),
        'sort_order' => (int)($package['sort_order'] ?? $package['sortOrder'] ?? 0),
        'created_at' => (string)($package['created_at'] ?? $package['createdAt'] ?? now_iso()),
        'updated_at' => (string)($package['updated_at'] ?? $package['updatedAt'] ?? now_iso()),
    ];
}

function normalize_service_package_item(array $item): array
{
    return [
        'id' => (int)($item['id'] ?? 0),
        'package_id' => (int)($item['package_id'] ?? $item['packageId'] ?? 0),
        'product_id' => (int)($item['product_id'] ?? $item['productId'] ?? 0),
        'sort_order' => (int)($item['sort_order'] ?? $item['sortOrder'] ?? 0),
        'quantity_mode' => normalize_package_quantity_mode((string)($item['quantity_mode'] ?? $item['quantityMode'] ?? 'product_default')),
        'quantity_value' => max(0.01, to_float($item['quantity_value'] ?? $item['quantityValue'] ?? 1)),
        'unit_price_override' => ($item['unit_price_override'] ?? $item['unitPriceOverride'] ?? '') === ''
            ? null
            : max(0, to_float($item['unit_price_override'] ?? $item['unitPriceOverride'] ?? 0)),
        'notes' => trim((string)($item['notes'] ?? '')),
        'created_at' => (string)($item['created_at'] ?? $item['createdAt'] ?? now_iso()),
        'updated_at' => (string)($item['updated_at'] ?? $item['updatedAt'] ?? now_iso()),
    ];
}

function package_form_defaults(?array $package = null): array
{
    $normalized = $package ? normalize_service_package($package) : null;

    return [
        'name' => (string)($normalized['name'] ?? ''),
        'serviceFamily' => (string)($normalized['service_family'] ?? 'general'),
        'description' => (string)($normalized['description'] ?? ''),
        'isActive' => !isset($normalized['is_active']) || !empty($normalized['is_active']) ? '1' : '0',
        'sortOrder' => isset($normalized['sort_order']) ? (string)(int)$normalized['sort_order'] : '0',
    ];
}

function package_item_form_rows(array $items = []): array
{
    $normalized = array_map('normalize_service_package_item', $items);
    usort($normalized, static fn(array $a, array $b): int => (int)$a['sort_order'] <=> (int)$b['sort_order']);

    $rows = array_map(static function (array $item): array {
        return [
            'productId' => (string)($item['product_id'] ?? 0),
            'sortOrder' => (string)($item['sort_order'] ?? 0),
            'quantityMode' => (string)($item['quantity_mode'] ?? 'product_default'),
            'quantityValue' => isset($item['quantity_value']) ? (string)(float)$item['quantity_value'] : '1',
            'unitPriceOverride' => $item['unit_price_override'] === null ? '' : (string)(float)$item['unit_price_override'],
            'notes' => (string)($item['notes'] ?? ''),
        ];
    }, $normalized);

    $nextSort = count($rows) > 0 ? ((int)($normalized[count($normalized) - 1]['sort_order'] ?? 0) + 10) : 10;
    for ($i = 0; $i < 3; $i++) {
        $rows[] = [
            'productId' => '',
            'sortOrder' => (string)($nextSort + ($i * 10)),
            'quantityMode' => 'product_default',
            'quantityValue' => '1',
            'unitPriceOverride' => '',
            'notes' => '',
        ];
    }

    return $rows;
}

function build_package_items_payload(array $payload): array
{
    $productIds = $payload['packageItemProductId'] ?? [];
    $sortOrders = $payload['packageItemSortOrder'] ?? [];
    $quantityModes = $payload['packageItemQuantityMode'] ?? [];
    $quantityValues = $payload['packageItemQuantityValue'] ?? [];
    $unitPriceOverrides = $payload['packageItemUnitPriceOverride'] ?? [];
    $notes = $payload['packageItemNotes'] ?? [];

    $rowCount = max(
        count(is_array($productIds) ? $productIds : []),
        count(is_array($sortOrders) ? $sortOrders : []),
        count(is_array($quantityModes) ? $quantityModes : []),
        count(is_array($quantityValues) ? $quantityValues : []),
        count(is_array($unitPriceOverrides) ? $unitPriceOverrides : []),
        count(is_array($notes) ? $notes : [])
    );

    $items = [];
    for ($index = 0; $index < $rowCount; $index++) {
        $productId = (int)($productIds[$index] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $items[] = [
            'productId' => $productId,
            'sortOrder' => (string)($sortOrders[$index] ?? (($index + 1) * 10)),
            'quantityMode' => (string)($quantityModes[$index] ?? 'product_default'),
            'quantityValue' => (string)($quantityValues[$index] ?? '1'),
            'unitPriceOverride' => (string)($unitPriceOverrides[$index] ?? ''),
            'notes' => trim((string)($notes[$index] ?? '')),
        ];
    }

    return $items;
}

function validate_package_payload(array $payload, array $products): array
{
    $errors = [];

    if (trim((string)($payload['name'] ?? '')) === '') {
        $errors['name'] = 'Paketnamn krävs.';
    }

    if (!in_array((string)($payload['serviceFamily'] ?? ''), valid_service_families(), true)) {
        $errors['serviceFamily'] = 'Ogiltig tjänstetyp.';
    }

    if (!in_array((string)($payload['isActive'] ?? '1'), ['0', '1'], true)) {
        $errors['isActive'] = 'Ogiltig status.';
    }

    $productIds = array_map(static fn(array $product): int => (int)($product['id'] ?? 0), $products);
    $packageItems = $payload['packageItems'] ?? [];
    foreach ($packageItems as $index => $item) {
        $productId = (int)($item['productId'] ?? 0);
        if (!in_array($productId, $productIds, true)) {
            $errors['packageItems'] = 'En eller flera paketrader använder en ogiltig produkt.';
            break;
        }

        if (!in_array((string)($item['quantityMode'] ?? ''), valid_package_quantity_modes(), true)) {
            $errors['packageItems'] = 'En eller flera paketrader har ogiltig mängdmodell.';
            break;
        }

        if (to_float($item['quantityValue'] ?? 0) <= 0) {
            $errors['packageItems'] = 'Paketrader måste ha ett antal eller en multiplikator som är större än 0.';
            break;
        }

        $unitPriceOverride = trim((string)($item['unitPriceOverride'] ?? ''));
        if ($unitPriceOverride !== '' && to_float($unitPriceOverride) < 0) {
            $errors['packageItems'] = 'Prisjustering på paketrad kan inte vara negativ.';
            break;
        }

        if ((int)($item['sortOrder'] ?? (($index + 1) * 10)) < 0) {
            $errors['packageItems'] = 'Sorteringsordning kan inte vara negativ.';
            break;
        }
    }

    return $errors;
}

function build_service_package_record(array $payload, ?array $existingPackage = null): array
{
    $now = now_iso();

    return normalize_service_package([
        'id' => (int)($existingPackage['id'] ?? $payload['id'] ?? 0),
        'name' => trim((string)($payload['name'] ?? '')),
        'service_family' => normalize_service_family((string)($payload['serviceFamily'] ?? 'general')),
        'description' => trim((string)($payload['description'] ?? '')),
        'is_active' => (string)($payload['isActive'] ?? '1') !== '0',
        'sort_order' => (int)($payload['sortOrder'] ?? 0),
        'created_at' => (string)($existingPackage['created_at'] ?? $now),
        'updated_at' => $now,
    ]);
}

function build_service_package_item_records(int $packageId, array $payloadItems, array $existingItems = []): array
{
    $records = [];
    $existingBySort = [];
    foreach ($existingItems as $existingItem) {
        $existingBySort[(int)($existingItem['sort_order'] ?? 0)] = $existingItem;
    }

    foreach ($payloadItems as $item) {
        $sortOrder = (int)($item['sortOrder'] ?? 0);
        $existingItem = $existingBySort[$sortOrder] ?? null;
        $now = now_iso();

        $records[] = normalize_service_package_item([
            'id' => (int)($existingItem['id'] ?? 0),
            'package_id' => $packageId,
            'product_id' => (int)($item['productId'] ?? 0),
            'sort_order' => $sortOrder,
            'quantity_mode' => normalize_package_quantity_mode((string)($item['quantityMode'] ?? 'product_default')),
            'quantity_value' => to_float($item['quantityValue'] ?? 1),
            'unit_price_override' => trim((string)($item['unitPriceOverride'] ?? '')) === '' ? null : to_float($item['unitPriceOverride']),
            'notes' => trim((string)($item['notes'] ?? '')),
            'created_at' => (string)($existingItem['created_at'] ?? $now),
            'updated_at' => $now,
        ]);
    }

    return $records;
}

function validate_user_payload(array $payload, bool $isUpdate = false): array
{
    $errors = [];

    if (trim((string)($payload['username'] ?? '')) === '') {
        $errors['username'] = 'Användarnamn krävs.';
    }

    if (trim((string)($payload['name'] ?? '')) === '') {
        $errors['name'] = 'Namn krävs.';
    }

    if (trim((string)($payload['phone'] ?? '')) === '') {
        $errors['phone'] = 'Mobilnummer krävs.';
    }

    $email = trim((string)($payload['email'] ?? ''));
    if ($email === '') {
        $errors['email'] = 'E-post krävs.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Ogiltig e-postadress.';
    }

    $rawRoles = $payload['roles'] ?? ($payload['role'] ?? []);
    if (!is_array($rawRoles)) {
        $rawRoles = [$rawRoles];
    }
    $roles = array_values(array_filter(array_map(
        static fn(mixed $role): string => trim((string)$role),
        $rawRoles
    ), static fn(string $role): bool => $role !== ''));
    if ($roles === []) {
        $errors['roles'] = 'Minst en roll måste anges.';
    } else {
        foreach ($roles as $role) {
            if (!in_array(normalize_user_role($role), valid_user_roles(), true)) {
                $errors['roles'] = 'Ogiltig roll.';
                break;
            }
        }
    }

    if (!in_array((string)($payload['isActive'] ?? '1'), ['0', '1'], true)) {
        $errors['isActive'] = 'Ogiltig användarstatus.';
    }

    if (($payload['organizationId'] ?? '') !== '' && (int)($payload['organizationId'] ?? 0) < 0) {
        $errors['organizationId'] = 'Ogiltig organisation.';
    }

    $password = (string)($payload['password'] ?? '');
    $passwordConfirm = (string)($payload['passwordConfirm'] ?? '');

    if (!$isUpdate && $password === '') {
        $errors['password'] = 'Lösenord krävs.';
    }

    if ($password !== '' && mb_strlen($password, 'UTF-8') < 10) {
        $errors['password'] = 'Lösenordet måste vara minst 10 tecken.';
    }

    if ($password !== '' && $password !== $passwordConfirm) {
        $errors['passwordConfirm'] = 'Lösenorden matchar inte.';
    }

    return $errors;
}

function build_user_record(array $payload, ?array $existingUser = null): array
{
    $now = now_iso();
    $passwordHash = (string)($existingUser['password_hash'] ?? '');
    $plainPassword = (string)($payload['password'] ?? '');
    $roles = normalize_role_list($payload['roles'] ?? ($payload['role'] ?? ($existingUser['effective_roles'] ?? [$existingUser['role'] ?? USER_ROLE_WORKER])));

    if ($plainPassword !== '') {
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    return normalize_user([
        'id' => (int)($existingUser['id'] ?? $payload['id'] ?? 0),
        'username' => trim((string)($payload['username'] ?? '')),
        'name' => trim((string)($payload['name'] ?? '')),
        'phone' => trim((string)($payload['phone'] ?? '')),
        'email' => trim((string)($payload['email'] ?? '')),
        'role' => $roles[0] ?? USER_ROLE_WORKER,
        'effective_roles' => $roles,
        'organization_id' => trim((string)($payload['organizationId'] ?? '')) !== '' ? (int)($payload['organizationId'] ?? 0) : null,
        'region_id' => trim((string)($payload['regionId'] ?? '')) !== '' ? (int)($payload['regionId'] ?? 0) : null,
        'is_active' => (string)($payload['isActive'] ?? '1') !== '0',
        'failed_login_attempts' => (int)($existingUser['failed_login_attempts'] ?? 0),
        'locked_until' => (string)($existingUser['locked_until'] ?? ''),
        'last_login_at' => (string)($existingUser['last_login_at'] ?? ''),
        'two_factor_enabled' => !empty($existingUser['two_factor_enabled']),
        'two_factor_secret' => (string)($existingUser['two_factor_secret'] ?? ''),
        'two_factor_confirmed_at' => (string)($existingUser['two_factor_confirmed_at'] ?? ''),
        'password_hash' => $passwordHash,
        'created_at' => (string)($existingUser['created_at'] ?? $now),
        'updated_at' => $now,
    ]);
}

function normalize_customer(array $customer): array
{
    $customerType = normalize_customer_type((string)($customer['customer_type'] ?? $customer['customerType'] ?? 'private'));
    $billingVatMode = normalize_billing_vat_mode((string)($customer['billing_vat_mode'] ?? $customer['billingVatMode'] ?? 'standard_vat'), $customerType);
    $rutEnabled = $customerType === 'private' && (bool)($customer['rut_enabled'] ?? $customer['rut_active'] ?? $customer['rutEnabled'] ?? false);
    $serviceAddressLine1 = (string)($customer['property_address_1'] ?? '');
    $serviceAddressLine2 = (string)($customer['property_address_2'] ?? '');
    $billingAddressLine1 = (string)($customer['billing_address_1'] ?? '');
    $billingAddressLine2 = (string)($customer['billing_address_2'] ?? '');
    $serviceAddress = trim(implode("\n", array_filter([
        $serviceAddressLine1,
        $serviceAddressLine2,
        (string)($customer['service_address'] ?? $customer['serviceAddress'] ?? $customer['address'] ?? ''),
    ], static fn(string $value): bool => $value !== '')));
    $servicePostalCode = (string)($customer['property_postcode'] ?? $customer['service_postal_code'] ?? $customer['servicePostalCode'] ?? $customer['postal_code'] ?? $customer['postalCode'] ?? '');
    $serviceCity = (string)($customer['property_city'] ?? $customer['service_city'] ?? $customer['serviceCity'] ?? $customer['city'] ?? '');
    $billingAddress = trim(implode("\n", array_filter([
        $billingAddressLine1,
        $billingAddressLine2,
        (string)($customer['billing_address'] ?? $customer['billingAddress'] ?? ''),
    ], static fn(string $value): bool => $value !== '')));
    $billingAddress = $billingAddress !== '' ? $billingAddress : $serviceAddress;
    $billingPostalCode = (string)($customer['billing_postcode'] ?? $customer['billing_postal_code'] ?? $customer['billingPostalCode'] ?? $servicePostalCode);
    $billingCity = (string)($customer['billing_city'] ?? $customer['billingCity'] ?? $serviceCity);
    $firstName = trim((string)($customer['first_name'] ?? $customer['firstName'] ?? ''));
    $lastName = trim((string)($customer['last_name'] ?? $customer['lastName'] ?? ''));
    $derivedName = trim(implode(' ', array_filter([$firstName, $lastName], static fn(string $value): bool => $value !== '')));

    return [
        'id' => (int)($customer['id'] ?? 0),
        'customer_type' => $customerType,
        'billing_vat_mode' => $billingVatMode,
        'service_type' => normalize_customer_service_type((string)($customer['service_type'] ?? $customer['serviceType'] ?? 'single')),
        'maintenance_plan_deck' => customer_has_maintenance_plan($customer, 'deck'),
        'maintenance_plan_stone' => customer_has_maintenance_plan($customer, 'stone'),
        'organization_id' => ($customer['organization_id'] ?? $customer['organizationId'] ?? null) !== null && ($customer['organization_id'] ?? $customer['organizationId']) !== ''
            ? (int)($customer['organization_id'] ?? $customer['organizationId'])
            : null,
        'region_id' => ($customer['region_id'] ?? $customer['regionId'] ?? null) !== null && ($customer['region_id'] ?? $customer['regionId']) !== ''
            ? (int)($customer['region_id'] ?? $customer['regionId'])
            : null,
        'name' => (string)($customer['name'] ?? ($derivedName !== '' ? $derivedName : '')),
        'company_name' => (string)($customer['company_name'] ?? $customer['companyName'] ?? ''),
        'association_name' => (string)($customer['association_name'] ?? $customer['associationName'] ?? ''),
        'contact_person' => (string)($customer['contact_person'] ?? $customer['contactPerson'] ?? ''),
        'phone' => (string)($customer['phone'] ?? ''),
        'email' => (string)($customer['email'] ?? ''),
        'service_address' => $serviceAddress,
        'service_postal_code' => $servicePostalCode,
        'service_city' => $serviceCity,
        'billing_address' => $billingAddress,
        'billing_postal_code' => $billingPostalCode,
        'billing_city' => $billingCity,
        'address' => $serviceAddress,
        'postal_code' => $servicePostalCode,
        'city' => $serviceCity,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'property_designation' => (string)($customer['property_designation'] ?? $customer['propertyDesignation'] ?? ''),
        'personal_number' => $customerType === 'private' ? (string)($customer['personal_number'] ?? $customer['personalNumber'] ?? '') : '',
        'organization_number' => in_array($customerType, ['company', 'association'], true) ? (string)($customer['organization_number'] ?? $customer['organizationNumber'] ?? '') : '',
        'vat_number' => in_array($customerType, ['company', 'association'], true) ? (string)($customer['vat_number'] ?? $customer['vatNumber'] ?? '') : '',
        'rut_enabled' => $rutEnabled,
        'rut_used_amount_this_year' => to_float($customer['rut_used_amount_this_year'] ?? $customer['rutUsedAmountThisYear'] ?? 0),
        'last_service_date' => (string)($customer['last_service_date'] ?? $customer['lastServiceDate'] ?? ''),
        'next_service_date' => (string)($customer['next_service_date'] ?? $customer['nextServiceDate'] ?? ''),
        'notes' => (string)($customer['notes'] ?? ''),
        'created_at' => (string)($customer['created_at'] ?? $customer['createdAt'] ?? now_iso()),
        'updated_at' => (string)($customer['updated_at'] ?? $customer['updatedAt'] ?? now_iso()),
        'last_activity' => (string)($customer['last_activity'] ?? $customer['updated_at'] ?? now_iso()),
    ];
}

function normalize_quote(array $quote, array $customers): array
{
    $customer = find_by_id($customers, (int)($quote['customer_id'] ?? $quote['customerId'] ?? 0)) ?? ['customer_type' => 'private', 'billing_vat_mode' => 'standard_vat', 'rut_enabled' => false];
    $customerType = (string)($customer['customer_type'] ?? 'private');
    $billingVatMode = (string)($customer['billing_vat_mode'] ?? 'standard_vat');
    $labor = to_float($quote['labor_amount_ex_vat'] ?? $quote['laborAmountExVat'] ?? $quote['labor_amount'] ?? $quote['laborAmount'] ?? 0);
    $material = to_float($quote['material_amount_ex_vat'] ?? $quote['materialAmountExVat'] ?? $quote['material_amount'] ?? $quote['materialAmount'] ?? 0);
    $other = to_float($quote['other_amount_ex_vat'] ?? $quote['otherAmountExVat'] ?? $quote['other_amount'] ?? $quote['otherAmount'] ?? 0);
    $totalExVat = calculateTotalExVat($labor, $material, $other);
    $vatRate = isset($quote['vat_rate']) || isset($quote['vatRate'])
        ? to_float($quote['vat_rate'] ?? $quote['vatRate'])
        : calculateVatRate($customerType, $billingVatMode);
    $vatAmount = isset($quote['vat_amount']) || isset($quote['vatAmount'])
        ? to_float($quote['vat_amount'] ?? $quote['vatAmount'])
        : calculateVat($totalExVat, $billingVatMode, $customerType);
    $totalIncVat = isset($quote['total_amount_inc_vat']) || isset($quote['totalAmountIncVat'])
        ? to_float($quote['total_amount_inc_vat'] ?? $quote['totalAmountIncVat'])
        : calculateTotalIncVat($totalExVat, $vatAmount);
    $rutAmount = isset($quote['rut_amount']) || isset($quote['rutAmount'])
        ? to_float($quote['rut_amount'] ?? $quote['rutAmount'])
        : calculateRutAmountWithUsedAmount($labor, (bool)($customer['rut_enabled'] ?? false), $customerType, to_float($customer['rut_used_amount_this_year'] ?? 0));

    return [
        'id' => (int)($quote['id'] ?? 0),
        'quote_number' => (string)($quote['quote_number'] ?? $quote['quoteNumber'] ?? ''),
        'created_by_username' => (string)($quote['created_by_username'] ?? $quote['createdByUsername'] ?? ''),
        'organization_id' => ($quote['organization_id'] ?? $quote['organizationId'] ?? $customer['organization_id'] ?? null) !== null
            && ($quote['organization_id'] ?? $quote['organizationId'] ?? $customer['organization_id']) !== ''
            ? (int)($quote['organization_id'] ?? $quote['organizationId'] ?? $customer['organization_id'])
            : null,
        'customer_id' => (int)($quote['customer_id'] ?? $quote['customerId'] ?? 0),
        'service_type' => (string)($quote['service_type'] ?? $quote['serviceType'] ?? $quote['service'] ?? ''),
        'description' => (string)($quote['description'] ?? $quote['work_description'] ?? $quote['workDescription'] ?? ''),
        'labor_amount_ex_vat' => $labor,
        'material_amount_ex_vat' => $material,
        'other_amount_ex_vat' => $other,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'subtotal' => isset($quote['subtotal']) ? to_float($quote['subtotal']) : $totalExVat,
        'total_amount_ex_vat' => isset($quote['total_amount_ex_vat']) || isset($quote['totalAmountExVat'])
            ? to_float($quote['total_amount_ex_vat'] ?? $quote['totalAmountExVat'])
            : (isset($quote['subtotal']) ? to_float($quote['subtotal']) : $totalExVat),
        'total_amount_inc_vat' => $totalIncVat,
        'rut_amount' => $rutAmount,
        'amount_after_rut' => isset($quote['amount_after_rut']) || isset($quote['amountAfterRut'])
            ? to_float($quote['amount_after_rut'] ?? $quote['amountAfterRut'])
            : calculateAmountToPay($totalIncVat, $totalExVat, $rutAmount, $customerType, $billingVatMode),
        'total_amount' => isset($quote['total_amount']) ? to_float($quote['total_amount']) : $totalIncVat,
        'is_rut_job' => (bool)($quote['is_rut_job'] ?? $quote['isRutJob'] ?? ($rutAmount > 0)),
        'reverse_charge_text' => (string)($quote['reverse_charge_text'] ?? $quote['reverseChargeText'] ?? reverseChargeText($customerType, $billingVatMode)),
        'status' => normalize_status((string)($quote['status'] ?? 'draft'), 'quote'),
        'issue_date' => (string)($quote['issue_date'] ?? $quote['issueDate'] ?? ''),
        'valid_until' => (string)($quote['valid_until'] ?? $quote['validUntil'] ?? ''),
        'work_description' => (string)($quote['work_description'] ?? $quote['workDescription'] ?? $quote['description'] ?? ''),
        'approved_at' => (string)($quote['approved_at'] ?? $quote['approvedAt'] ?? ''),
        'converted_to_job_at' => (string)($quote['converted_to_job_at'] ?? $quote['convertedToJobAt'] ?? ''),
        'notes' => (string)($quote['notes'] ?? $quote['note'] ?? ''),
        'created_at' => (string)($quote['created_at'] ?? $quote['createdAt'] ?? now_iso()),
        'updated_at' => (string)($quote['updated_at'] ?? $quote['updatedAt'] ?? now_iso()),
    ];
}

function normalize_quote_item(array $item): array
{
    $quantity = to_float($item['quantity'] ?? 1);
    $unitPrice = to_float($item['unit_price'] ?? $item['unitPrice'] ?? 0);
    $lineTotal = isset($item['line_total']) || isset($item['lineTotal'])
        ? to_float($item['line_total'] ?? $item['lineTotal'])
        : round($quantity * $unitPrice, 2);
    $itemType = (string)($item['item_type'] ?? $item['itemType'] ?? 'service');
    if (!in_array($itemType, ['labor', 'material', 'service', 'text', 'discount'], true)) {
        $itemType = 'service';
    }

    return [
        'id' => (int)($item['id'] ?? 0),
        'quote_id' => (int)($item['quote_id'] ?? $item['quoteId'] ?? 0),
        'sort_order' => (int)($item['sort_order'] ?? $item['sortOrder'] ?? 0),
        'item_type' => $itemType,
        'description' => (string)($item['description'] ?? ''),
        'quantity' => $quantity,
        'unit' => (string)($item['unit'] ?? 'st'),
        'unit_price' => $unitPrice,
        'vat_rate' => to_float($item['vat_rate'] ?? $item['vatRate'] ?? 0.25),
        'is_rut_eligible' => (bool)($item['is_rut_eligible'] ?? $item['isRutEligible'] ?? false),
        'line_total' => $lineTotal,
        'created_at' => (string)($item['created_at'] ?? $item['createdAt'] ?? now_iso()),
        'updated_at' => (string)($item['updated_at'] ?? $item['updatedAt'] ?? now_iso()),
    ];
}

function build_quote_items_from_quote(array $quote): array
{
    $providedItems = $quote['quote_items'] ?? $quote['quoteItems'] ?? [];
    if (is_array($providedItems) && $providedItems !== []) {
        $quoteId = (int)($quote['id'] ?? 0);
        $items = [];
        foreach ($providedItems as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = normalize_quote_item($item);
            $normalized['quote_id'] = $quoteId;
            if ($normalized['sort_order'] <= 0) {
                $normalized['sort_order'] = ($index + 1) * 10;
            }
            $items[] = $normalized;
        }

        return $items;
    }

    $quoteId = (int)($quote['id'] ?? 0);
    $serviceType = trim((string)($quote['service_type'] ?? $quote['serviceType'] ?? ''));
    $serviceLabel = $serviceType !== '' ? mb_strtolower($serviceType) : 'tjänst';
    $vatRate = to_float($quote['vat_rate'] ?? $quote['vatRate'] ?? 0.25);
    $labor = to_float($quote['labor_amount_ex_vat'] ?? $quote['laborAmountExVat'] ?? 0);
    $material = to_float($quote['material_amount_ex_vat'] ?? $quote['materialAmountExVat'] ?? 0);
    $other = to_float($quote['other_amount_ex_vat'] ?? $quote['otherAmountExVat'] ?? 0);
    $isRutJob = (bool)($quote['is_rut_job'] ?? $quote['isRutJob'] ?? false);
    $items = [];
    $nextId = 1;

    if ($labor > 0) {
        $items[] = normalize_quote_item([
            'id' => $nextId++,
            'quote_id' => $quoteId,
            'sort_order' => 10,
            'item_type' => 'labor',
            'description' => 'Arbetskostnad ' . $serviceLabel,
            'quantity' => 1,
            'unit' => 'st',
            'unit_price' => $labor,
            'vat_rate' => $vatRate,
            'is_rut_eligible' => $isRutJob,
            'line_total' => $labor,
        ]);
    }

    if ($material > 0) {
        $items[] = normalize_quote_item([
            'id' => $nextId++,
            'quote_id' => $quoteId,
            'sort_order' => 20,
            'item_type' => 'material',
            'description' => 'Material',
            'quantity' => 1,
            'unit' => 'st',
            'unit_price' => $material,
            'vat_rate' => $vatRate,
            'is_rut_eligible' => false,
            'line_total' => $material,
        ]);
    }

    if ($other > 0) {
        $items[] = normalize_quote_item([
            'id' => $nextId++,
            'quote_id' => $quoteId,
            'sort_order' => 30,
            'item_type' => 'service',
            'description' => 'Övriga kostnader',
            'quantity' => 1,
            'unit' => 'st',
            'unit_price' => $other,
            'vat_rate' => $vatRate,
            'is_rut_eligible' => false,
            'line_total' => $other,
        ]);
    }

    return $items;
}

function normalize_quote_items(array $quotes, array $quoteItems): array
{
    if ($quoteItems !== []) {
        $normalized = array_map('normalize_quote_item', $quoteItems);
        $result = [];
        $nextId = 1;
        $seenIds = [];

        foreach ($normalized as $item) {
            if ($item['id'] <= 0 || isset($seenIds[$item['id']])) {
                $item['id'] = $nextId;
            }

            $seenIds[$item['id']] = true;
            $nextId = max($nextId, $item['id'] + 1);
            $result[] = $item;
        }

        return $result;
    }

    $items = [];
    $nextId = 1;

    foreach ($quotes as $quote) {
        foreach (build_quote_items_from_quote($quote) as $item) {
            $item['id'] = $nextId++;
            $items[] = $item;
        }
    }

    return $items;
}

function normalize_job_item(array $item): array
{
    $quantity = to_float($item['quantity'] ?? 1);
    $unitPrice = to_float($item['unit_price'] ?? $item['unitPrice'] ?? 0);
    $lineTotal = isset($item['line_total']) || isset($item['lineTotal'])
        ? to_float($item['line_total'] ?? $item['lineTotal'])
        : round($quantity * $unitPrice, 2);
    $itemType = (string)($item['item_type'] ?? $item['itemType'] ?? 'service');
    if (!in_array($itemType, ['labor', 'material', 'service', 'text', 'discount'], true)) {
        $itemType = 'service';
    }

    return [
        'id' => (int)($item['id'] ?? 0),
        'job_id' => (int)($item['job_id'] ?? $item['jobId'] ?? 0),
        'quote_item_id' => ($item['quote_item_id'] ?? $item['quoteItemId'] ?? null) !== null && ($item['quote_item_id'] ?? $item['quoteItemId']) !== ''
            ? (int)($item['quote_item_id'] ?? $item['quoteItemId'])
            : null,
        'sort_order' => (int)($item['sort_order'] ?? $item['sortOrder'] ?? 0),
        'item_type' => $itemType,
        'description' => (string)($item['description'] ?? ''),
        'quantity' => $quantity,
        'unit' => (string)($item['unit'] ?? 'st'),
        'unit_price' => $unitPrice,
        'vat_rate' => to_float($item['vat_rate'] ?? $item['vatRate'] ?? 0.25),
        'is_rut_eligible' => (bool)($item['is_rut_eligible'] ?? $item['isRutEligible'] ?? false),
        'line_total' => $lineTotal,
        'created_at' => (string)($item['created_at'] ?? $item['createdAt'] ?? now_iso()),
        'updated_at' => (string)($item['updated_at'] ?? $item['updatedAt'] ?? now_iso()),
    ];
}

function build_job_items_from_job(array $job): array
{
    $jobId = (int)($job['id'] ?? 0);
    $serviceType = trim((string)($job['service_type'] ?? $job['serviceType'] ?? ''));
    $serviceLabel = $serviceType !== '' ? mb_strtolower($serviceType) : 'tjänst';
    $vatRate = to_float($job['final_vat_rate'] ?? $job['finalVatRate'] ?? 0.25);
    $labor = to_float($job['final_labor_amount_ex_vat'] ?? $job['finalLaborAmountExVat'] ?? 0);
    $material = to_float($job['final_material_amount_ex_vat'] ?? $job['finalMaterialAmountExVat'] ?? 0);
    $other = to_float($job['final_other_amount_ex_vat'] ?? $job['finalOtherAmountExVat'] ?? 0);
    $rutAmount = to_float($job['final_rut_amount'] ?? $job['finalRutAmount'] ?? 0);
    $isRutEligible = $rutAmount > 0;
    $items = [];
    $nextId = 1;

    if ($labor > 0) {
        $items[] = normalize_job_item([
            'id' => $nextId++,
            'job_id' => $jobId,
            'sort_order' => 10,
            'item_type' => 'labor',
            'description' => 'Arbetskostnad ' . $serviceLabel,
            'quantity' => 1,
            'unit' => 'st',
            'unit_price' => $labor,
            'vat_rate' => $vatRate,
            'is_rut_eligible' => $isRutEligible,
            'line_total' => $labor,
        ]);
    }

    if ($material > 0) {
        $items[] = normalize_job_item([
            'id' => $nextId++,
            'job_id' => $jobId,
            'sort_order' => 20,
            'item_type' => 'material',
            'description' => 'Material',
            'quantity' => 1,
            'unit' => 'st',
            'unit_price' => $material,
            'vat_rate' => $vatRate,
            'is_rut_eligible' => false,
            'line_total' => $material,
        ]);
    }

    if ($other > 0) {
        $items[] = normalize_job_item([
            'id' => $nextId++,
            'job_id' => $jobId,
            'sort_order' => 30,
            'item_type' => 'service',
            'description' => 'Övriga kostnader',
            'quantity' => 1,
            'unit' => 'st',
            'unit_price' => $other,
            'vat_rate' => $vatRate,
            'is_rut_eligible' => false,
            'line_total' => $other,
        ]);
    }

    return $items;
}

function normalize_job_items(array $jobs, array $jobItems): array
{
    if ($jobItems !== []) {
        $normalized = array_map('normalize_job_item', $jobItems);
        $result = [];
        $nextId = 1;
        $seenIds = [];

        foreach ($normalized as $item) {
            if ($item['id'] <= 0 || isset($seenIds[$item['id']])) {
                $item['id'] = $nextId;
            }

            $seenIds[$item['id']] = true;
            $nextId = max($nextId, $item['id'] + 1);
            $result[] = $item;
        }

        return $result;
    }

    $items = [];
    $nextId = 1;

    foreach ($jobs as $job) {
        foreach (build_job_items_from_job($job) as $item) {
            $item['id'] = $nextId++;
            $items[] = $item;
        }
    }

    return $items;
}

function normalize_invoice_basis(array $basis): array
{
    $status = normalize_invoice_base_status((string)($basis['status'] ?? $basis['invoice_status'] ?? $basis['invoiceStatus'] ?? 'pending'));

    return [
        'id' => (int)($basis['id'] ?? 0),
        'job_id' => (int)($basis['job_id'] ?? $basis['jobId'] ?? 0),
        'quote_id' => ($basis['quote_id'] ?? $basis['quoteId'] ?? null) !== null && ($basis['quote_id'] ?? $basis['quoteId']) !== ''
            ? (int)($basis['quote_id'] ?? $basis['quoteId'])
            : null,
        'customer_id' => (int)($basis['customer_id'] ?? $basis['customerId'] ?? 0),
        'organization_id' => ($basis['organization_id'] ?? $basis['organizationId'] ?? null) !== null && ($basis['organization_id'] ?? $basis['organizationId']) !== ''
            ? (int)($basis['organization_id'] ?? $basis['organizationId'])
            : null,
        'status' => $status,
        'quote_number' => (string)($basis['quote_number'] ?? $basis['quoteNumber'] ?? ''),
        'customer_type' => normalize_customer_type((string)($basis['customer_type'] ?? $basis['customerType'] ?? 'private')),
        'billing_vat_mode' => normalize_billing_vat_mode(
            (string)($basis['billing_vat_mode'] ?? $basis['billingVatMode'] ?? 'standard_vat'),
            normalize_customer_type((string)($basis['customer_type'] ?? $basis['customerType'] ?? 'private'))
        ),
        'invoice_customer_name' => (string)($basis['invoice_customer_name'] ?? $basis['invoiceCustomerName'] ?? $basis['customer_name'] ?? $basis['customerName'] ?? ''),
        'contact_person' => (string)($basis['contact_person'] ?? $basis['contactPerson'] ?? ''),
        'personal_number' => (string)($basis['personal_number'] ?? $basis['personalNumber'] ?? ''),
        'organization_number' => (string)($basis['organization_number'] ?? $basis['organizationNumber'] ?? ''),
        'vat_number' => (string)($basis['vat_number'] ?? $basis['vatNumber'] ?? ''),
        'email' => (string)($basis['email'] ?? ''),
        'phone' => (string)($basis['phone'] ?? ''),
        'service_address' => (string)($basis['service_address'] ?? $basis['serviceAddress'] ?? ''),
        'service_postal_code' => (string)($basis['service_postal_code'] ?? $basis['servicePostalCode'] ?? ''),
        'service_city' => (string)($basis['service_city'] ?? $basis['serviceCity'] ?? ''),
        'invoice_address_1' => (string)($basis['invoice_address_1'] ?? $basis['billing_address'] ?? $basis['billingAddress'] ?? ''),
        'invoice_address_2' => (string)($basis['invoice_address_2'] ?? ''),
        'invoice_postcode' => (string)($basis['invoice_postcode'] ?? $basis['billing_postal_code'] ?? $basis['billingPostalCode'] ?? ''),
        'invoice_city' => (string)($basis['invoice_city'] ?? $basis['billing_city'] ?? $basis['billingCity'] ?? ''),
        'invoice_date' => (string)($basis['invoice_date'] ?? $basis['invoiceDate'] ?? date('Y-m-d')),
        'due_date' => (string)($basis['due_date'] ?? $basis['dueDate'] ?? date('Y-m-d')),
        'service_type' => (string)($basis['service_type'] ?? $basis['serviceType'] ?? ''),
        'description' => (string)($basis['description'] ?? ''),
        'subtotal' => to_float($basis['subtotal'] ?? $basis['total_amount_ex_vat'] ?? $basis['totalAmountExVat'] ?? 0),
        'labor_amount_ex_vat' => to_float($basis['labor_amount_ex_vat'] ?? $basis['laborAmountExVat'] ?? 0),
        'material_amount_ex_vat' => to_float($basis['material_amount_ex_vat'] ?? $basis['materialAmountExVat'] ?? 0),
        'other_amount_ex_vat' => to_float($basis['other_amount_ex_vat'] ?? $basis['otherAmountExVat'] ?? 0),
        'total_amount_ex_vat' => to_float($basis['total_amount_ex_vat'] ?? $basis['totalAmountExVat'] ?? $basis['subtotal'] ?? 0),
        'vat_amount' => to_float($basis['vat_amount'] ?? $basis['vatAmount'] ?? 0),
        'total_amount_inc_vat' => to_float($basis['total_amount_inc_vat'] ?? $basis['totalAmountIncVat'] ?? 0),
        'rut_enabled' => (bool)($basis['rut_enabled'] ?? $basis['rutEnabled'] ?? false),
        'rut_basis_amount' => to_float($basis['rut_basis_amount'] ?? $basis['rutBasisAmount'] ?? 0),
        'rut_amount' => to_float($basis['rut_amount'] ?? $basis['rutAmount'] ?? 0),
        'amount_to_pay' => to_float($basis['amount_to_pay'] ?? $basis['amountToPay'] ?? 0),
        'reverse_charge_text' => (string)($basis['reverse_charge_text'] ?? $basis['reverseChargeText'] ?? ''),
        'ready_for_invoicing' => (bool)($basis['ready_for_invoicing'] ?? $basis['readyForInvoicing'] ?? false),
        'fortnox_customer_number' => (string)($basis['fortnox_customer_number'] ?? $basis['fortnoxCustomerNumber'] ?? ''),
        'fortnox_document_number' => (string)($basis['fortnox_document_number'] ?? $basis['fortnoxDocumentNumber'] ?? ''),
        'fortnox_invoice_number' => (string)($basis['fortnox_invoice_number'] ?? $basis['fortnoxInvoiceNumber'] ?? ''),
        'fortnox_last_sync_at' => (string)($basis['fortnox_last_sync_at'] ?? $basis['fortnoxLastSyncAt'] ?? ''),
        'fortnox_sync_error' => (string)($basis['fortnox_sync_error'] ?? $basis['fortnoxSyncError'] ?? ''),
        'export_error' => (string)($basis['export_error'] ?? $basis['exportError'] ?? ''),
        'exported_at' => (string)($basis['exported_at'] ?? $basis['exportedAt'] ?? ''),
        'created_at' => (string)($basis['created_at'] ?? $basis['createdAt'] ?? now_iso()),
        'updated_at' => (string)($basis['updated_at'] ?? $basis['updatedAt'] ?? now_iso()),
    ];
}

function normalize_invoice_base_status(string $status): string
{
    $value = trim(strtolower($status));

    return match ($value) {
        'exporting', 'exported', 'failed', 'pending' => $value,
        'ready', 'draft', 'created', '' => 'pending',
        'invoiced' => 'exported',
        default => 'pending',
    };
}

function invoice_base_is_locked_for_export(array $basis): bool
{
    $status = normalize_invoice_base_status((string)($basis['status'] ?? 'pending'));
    $hasReference = trim((string)($basis['fortnox_document_number'] ?? '')) !== ''
        || trim((string)($basis['fortnox_invoice_number'] ?? '')) !== '';

    return $status === 'exporting' || $status === 'exported' || $hasReference;
}

function normalize_invoice_base_item(array $item): array
{
    $quantity = to_float($item['quantity'] ?? 1);
    $unitPrice = to_float($item['unit_price'] ?? $item['unitPrice'] ?? 0);
    $lineTotal = isset($item['line_total']) || isset($item['lineTotal'])
        ? to_float($item['line_total'] ?? $item['lineTotal'])
        : round($quantity * $unitPrice, 2);
    $itemType = (string)($item['item_type'] ?? $item['itemType'] ?? 'service');
    if (!in_array($itemType, ['labor', 'material', 'service', 'text'], true)) {
        $itemType = 'service';
    }

    return [
        'id' => (int)($item['id'] ?? 0),
        'invoice_base_id' => (int)($item['invoice_base_id'] ?? $item['invoiceBaseId'] ?? 0),
        'job_item_id' => ($item['job_item_id'] ?? $item['jobItemId'] ?? null) !== null && ($item['job_item_id'] ?? $item['jobItemId']) !== ''
            ? (int)($item['job_item_id'] ?? $item['jobItemId'])
            : null,
        'sort_order' => (int)($item['sort_order'] ?? $item['sortOrder'] ?? 0),
        'item_type' => $itemType,
        'description' => (string)($item['description'] ?? ''),
        'quantity' => $quantity,
        'unit' => (string)($item['unit'] ?? 'st'),
        'unit_price' => $unitPrice,
        'vat_rate' => to_float($item['vat_rate'] ?? $item['vatRate'] ?? 0.25),
        'is_rut_eligible' => (bool)($item['is_rut_eligible'] ?? $item['isRutEligible'] ?? false),
        'line_total' => $lineTotal,
        'created_at' => (string)($item['created_at'] ?? $item['createdAt'] ?? now_iso()),
        'updated_at' => (string)($item['updated_at'] ?? $item['updatedAt'] ?? now_iso()),
    ];
}

function build_invoice_base_items_from_job_items(int $invoiceBaseId, array $jobItems): array
{
    $items = [];
    $nextId = 1;

    foreach ($jobItems as $jobItem) {
        $items[] = normalize_invoice_base_item([
            'id' => $nextId++,
            'invoice_base_id' => $invoiceBaseId,
            'job_item_id' => (int)($jobItem['id'] ?? 0) ?: null,
            'sort_order' => (int)($jobItem['sort_order'] ?? 0),
            'item_type' => (string)($jobItem['item_type'] ?? 'service'),
            'description' => (string)($jobItem['description'] ?? ''),
            'quantity' => (float)($jobItem['quantity'] ?? 1),
            'unit' => (string)($jobItem['unit'] ?? 'st'),
            'unit_price' => (float)($jobItem['unit_price'] ?? 0),
            'vat_rate' => (float)($jobItem['vat_rate'] ?? 0.25),
            'is_rut_eligible' => !empty($jobItem['is_rut_eligible']),
            'line_total' => (float)($jobItem['line_total'] ?? 0),
            'created_at' => (string)($jobItem['created_at'] ?? now_iso()),
            'updated_at' => (string)($jobItem['updated_at'] ?? now_iso()),
        ]);
    }

    return $items;
}

function normalize_invoice_base_items(array $invoiceBaseItems, array $invoiceBases, array $jobItems): array
{
    if ($invoiceBaseItems !== []) {
        $normalized = array_map('normalize_invoice_base_item', $invoiceBaseItems);
        $result = [];
        $nextId = 1;
        $seenIds = [];

        foreach ($normalized as $item) {
            if ($item['id'] <= 0 || isset($seenIds[$item['id']])) {
                $item['id'] = $nextId;
            }

            $seenIds[$item['id']] = true;
            $nextId = max($nextId, $item['id'] + 1);
            $result[] = $item;
        }

        return $result;
    }

    $items = [];
    $nextId = 1;

    foreach ($invoiceBases as $basis) {
        $basisId = (int)($basis['id'] ?? 0);
        $jobId = (int)($basis['job_id'] ?? 0);
        $jobRows = array_values(array_filter($jobItems, static fn(array $jobItem): bool => (int)($jobItem['job_id'] ?? 0) === $jobId));

        foreach (build_invoice_base_items_from_job_items($basisId, $jobRows) as $item) {
            $item['id'] = $nextId++;
            $items[] = $item;
        }
    }

    return $items;
}

function build_invoice_bases_from_jobs(array $jobs, array $customers, array $quotes): array
{
    $invoiceBases = [];
    $nextId = 1;

    foreach ($jobs as $job) {
        $jobStatus = (string)($job['status'] ?? '');
        $isInvoiceRelevant = !empty($job['ready_for_invoicing']) || in_array($jobStatus, ['completed', 'invoiced'], true);
        if (!$isInvoiceRelevant) {
            continue;
        }

        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
        if (!$customer) {
            continue;
        }

        $quoteId = (int)($job['quote_id'] ?? 0);
        $quote = $quoteId > 0 ? find_by_id($quotes, $quoteId) : null;
        $basis = buildInvoiceBasisFromJob($job, $customer, $quote);

        $invoiceBases[] = normalize_invoice_basis([
            'id' => $nextId++,
            'jobId' => (int)($job['id'] ?? 0),
            'quoteId' => $quoteId > 0 ? $quoteId : null,
            'customerId' => (int)($customer['id'] ?? 0),
            'status' => 'pending',
            'quoteNumber' => (string)($quote['quote_number'] ?? $basis['quoteNumber'] ?? ''),
            'customerType' => (string)($basis['customerType'] ?? 'private'),
            'billingVatMode' => (string)($basis['billingVatMode'] ?? 'standard_vat'),
            'invoiceCustomerName' => (string)($basis['customerName'] ?? ''),
            'contactPerson' => (string)($basis['contactPerson'] ?? ''),
            'personalNumber' => (string)($basis['personalNumber'] ?? ''),
            'organizationNumber' => (string)($basis['organizationNumber'] ?? ''),
            'vatNumber' => (string)($basis['vatNumber'] ?? ''),
            'email' => (string)($basis['email'] ?? ''),
            'phone' => (string)($basis['phone'] ?? ''),
            'serviceAddress' => (string)($basis['serviceAddress'] ?? ''),
            'servicePostalCode' => (string)($basis['servicePostalCode'] ?? ''),
            'serviceCity' => (string)($basis['serviceCity'] ?? ''),
            'billingAddress' => (string)($basis['billingAddress'] ?? ''),
            'billingPostalCode' => (string)($basis['billingPostalCode'] ?? ''),
            'billingCity' => (string)($basis['billingCity'] ?? ''),
            'invoiceDate' => (string)($basis['invoiceDate'] ?? ''),
            'dueDate' => (string)($basis['dueDate'] ?? ''),
            'serviceType' => (string)($basis['serviceType'] ?? ''),
            'description' => (string)($basis['description'] ?? ''),
            'subtotal' => (float)($basis['totalAmountExVat'] ?? 0),
            'laborAmountExVat' => (float)($basis['laborAmountExVat'] ?? 0),
            'materialAmountExVat' => (float)($basis['materialAmountExVat'] ?? 0),
            'otherAmountExVat' => (float)($basis['otherAmountExVat'] ?? 0),
            'totalAmountExVat' => (float)($basis['totalAmountExVat'] ?? 0),
            'vatAmount' => (float)($basis['vatAmount'] ?? 0),
            'totalAmountIncVat' => (float)($basis['totalAmountIncVat'] ?? 0),
            'rutEnabled' => (bool)($basis['rutEnabled'] ?? false),
            'rutBasisAmount' => (float)($basis['rutBasisAmount'] ?? 0),
            'rutAmount' => (float)($basis['rutAmount'] ?? 0),
            'amountToPay' => (float)($basis['amountToPay'] ?? 0),
            'reverseChargeText' => (string)($basis['reverseChargeText'] ?? ''),
            'readyForInvoicing' => (bool)($basis['readyForInvoicing'] ?? false),
            'createdAt' => (string)($basis['createdAt'] ?? now_iso()),
            'updatedAt' => (string)($basis['updatedAt'] ?? now_iso()),
        ]);
    }

    return $invoiceBases;
}

function normalize_invoice_bases(array $invoiceBases, array $jobs, array $customers, array $quotes): array
{
    if ($invoiceBases !== []) {
        $normalized = array_map('normalize_invoice_basis', $invoiceBases);
        $result = [];
        $nextId = 1;
        $seenIds = [];

        foreach ($normalized as $basis) {
            if ($basis['id'] <= 0 || isset($seenIds[$basis['id']])) {
                $basis['id'] = $nextId;
            }

            $seenIds[$basis['id']] = true;
            $nextId = max($nextId, $basis['id'] + 1);
            $result[] = $basis;
        }

        return $result;
    }

    return build_invoice_bases_from_jobs($jobs, $customers, $quotes);
}

function normalize_job(array $job, array $customers, array $jobItemsByJobId = []): array
{
    $customer = find_by_id($customers, (int)($job['customer_id'] ?? $job['customerId'] ?? 0)) ?? ['customer_type' => 'private', 'billing_vat_mode' => 'standard_vat', 'rut_enabled' => false];
    $customerType = (string)($customer['customer_type'] ?? 'private');
    $billingVatMode = (string)($customer['billing_vat_mode'] ?? 'standard_vat');
    $jobId = (int)($job['id'] ?? 0);
    $jobItems = $jobItemsByJobId[$jobId] ?? [];
    $vatRate = isset($job['final_vat_rate']) || isset($job['finalVatRate'])
        ? to_float($job['final_vat_rate'] ?? $job['finalVatRate'])
        : calculateVatRate($customerType, $billingVatMode);
    $rowSummary = [];

    if ($jobItems !== []) {
        $rows = invoice_basis_rows_from_items($jobItems);
        $rowSummary = invoice_basis_summary_from_rows($rows, $customerType, $billingVatMode, (bool)($customer['rut_enabled'] ?? false), to_float($customer['rut_used_amount_this_year'] ?? 0));
    }

    $labor = $rowSummary !== []
        ? $rowSummary['laborAmountExVat']
        : to_float($job['final_labor_amount_ex_vat'] ?? $job['finalLaborAmountExVat'] ?? $job['final_labor_amount'] ?? 0);
    $material = $rowSummary !== []
        ? $rowSummary['materialAmountExVat']
        : to_float($job['final_material_amount_ex_vat'] ?? $job['finalMaterialAmountExVat'] ?? $job['final_material_amount'] ?? 0);
    $other = $rowSummary !== []
        ? $rowSummary['otherAmountExVat']
        : to_float($job['final_other_amount_ex_vat'] ?? $job['finalOtherAmountExVat'] ?? $job['final_other_amount'] ?? 0);
    $totalExVat = $rowSummary !== []
        ? $rowSummary['totalAmountExVat']
        : calculateTotalExVat($labor, $material, $other);
    $vatAmount = $rowSummary !== []
        ? $rowSummary['vatAmount']
        : (isset($job['final_vat_amount']) || isset($job['finalVatAmount'])
            ? to_float($job['final_vat_amount'] ?? $job['finalVatAmount'])
            : calculateVat($totalExVat, $billingVatMode, $customerType));
    $totalIncVat = $rowSummary !== []
        ? $rowSummary['totalAmountIncVat']
        : (isset($job['final_total_amount_inc_vat']) || isset($job['finalTotalAmountIncVat'])
            ? to_float($job['final_total_amount_inc_vat'] ?? $job['finalTotalAmountIncVat'])
            : calculateTotalIncVat($totalExVat, $vatAmount));
    $rutAmount = $rowSummary !== []
        ? $rowSummary['rutAmount']
        : (isset($job['final_rut_amount']) || isset($job['finalRutAmount'])
            ? to_float($job['final_rut_amount'] ?? $job['finalRutAmount'])
            : calculateRutAmountWithUsedAmount($labor, (bool)($customer['rut_enabled'] ?? false), $customerType, to_float($customer['rut_used_amount_this_year'] ?? 0)));

    return [
        'id' => (int)($job['id'] ?? 0),
        'customer_id' => (int)($job['customer_id'] ?? $job['customerId'] ?? 0),
        'quote_id' => ($job['quote_id'] ?? $job['quoteId'] ?? 0) !== '' ? (int)($job['quote_id'] ?? $job['quoteId'] ?? 0) : 0,
        'organization_id' => ($job['organization_id'] ?? $job['organizationId'] ?? $customer['organization_id'] ?? null) !== null
            && ($job['organization_id'] ?? $job['organizationId'] ?? $customer['organization_id']) !== ''
            ? (int)($job['organization_id'] ?? $job['organizationId'] ?? $customer['organization_id'])
            : null,
        'region_id' => ($job['region_id'] ?? $job['regionId'] ?? $customer['region_id'] ?? null) !== null
            && ($job['region_id'] ?? $job['regionId'] ?? $customer['region_id']) !== ''
            ? (int)($job['region_id'] ?? $job['regionId'] ?? $customer['region_id'])
            : null,
        'planned_start_date' => (string)($job['planned_start_date'] ?? $job['plannedStartDate'] ?? $job['scheduled_date'] ?? $job['scheduledDate'] ?? ''),
        'planned_end_date' => (string)($job['planned_end_date'] ?? $job['plannedEndDate'] ?? ''),
        'completed_at' => (string)($job['completed_at'] ?? $job['completedAt'] ?? ''),
        'work_address_1' => (string)($job['work_address_1'] ?? $job['workAddress1'] ?? ''),
        'work_address_2' => (string)($job['work_address_2'] ?? $job['workAddress2'] ?? ''),
        'work_postcode' => (string)($job['work_postcode'] ?? $job['workPostcode'] ?? ''),
        'work_city' => (string)($job['work_city'] ?? $job['workCity'] ?? ''),
        'internal_notes' => (string)($job['internal_notes'] ?? $job['internalNotes'] ?? ''),
        'customer_notes' => (string)($job['customer_notes'] ?? $job['customerNotes'] ?? ''),
        'service_type' => (string)($job['service_type'] ?? $job['serviceType'] ?? $job['service'] ?? ''),
        'description' => (string)($job['description'] ?? ''),
        'scheduled_date' => (string)($job['scheduled_date'] ?? $job['scheduledDate'] ?? $job['date'] ?? ''),
        'scheduled_time' => trim((string)($job['scheduled_time'] ?? $job['scheduledTime'] ?? '')) !== ''
            ? substr((string)($job['scheduled_time'] ?? $job['scheduledTime'] ?? ''), 0, 5)
            : '',
        'completed_date' => (string)($job['completed_date'] ?? $job['completedDate'] ?? $job['completed_at'] ?? ''),
        'assigned_to' => (string)($job['assigned_to'] ?? $job['assignedTo'] ?? $job['assignee'] ?? ''),
        'status' => normalize_status((string)($job['status'] ?? 'planned'), 'job'),
        'final_labor_amount_ex_vat' => $labor,
        'final_material_amount_ex_vat' => $material,
        'final_other_amount_ex_vat' => $other,
        'final_vat_rate' => $vatRate,
        'final_vat_amount' => $vatAmount,
        'final_total_amount_ex_vat' => isset($job['final_total_amount_ex_vat']) || isset($job['finalTotalAmountExVat']) ? to_float($job['final_total_amount_ex_vat'] ?? $job['finalTotalAmountExVat']) : $totalExVat,
        'final_total_amount_inc_vat' => $totalIncVat,
        'final_rut_amount' => $rutAmount,
        'final_reverse_charge_text' => (string)($job['final_reverse_charge_text'] ?? $job['finalReverseChargeText'] ?? reverseChargeText($customerType, $billingVatMode)),
        'ready_for_invoicing' => (bool)($job['ready_for_invoicing'] ?? $job['readyForInvoicing'] ?? false),
        'invoice_status' => (string)($job['invoice_status'] ?? $job['invoiceStatus'] ?? ''),
        'notes' => (string)($job['notes'] ?? $job['note'] ?? ''),
        'created_at' => (string)($job['created_at'] ?? $job['createdAt'] ?? now_iso()),
        'updated_at' => (string)($job['updated_at'] ?? $job['updatedAt'] ?? now_iso()),
        'job_items' => $jobItems,
    ];
}

function normalize_data(array $data): array
{
    $regions = array_map('normalize_region', $data['regions'] ?? []);
    $organizations = array_map('normalize_organization', $data['organizations'] ?? []);
    $organizationMemberships = array_map('normalize_organization_membership', $data['organization_memberships'] ?? $data['organizationMemberships'] ?? []);
    $customers = array_map('normalize_customer', $data['customers'] ?? []);
    $quotes = ensure_quote_numbers($data['quotes'] ?? []);
    $quoteItems = normalize_quote_items($quotes, $data['quote_items'] ?? $data['quoteItems'] ?? []);
    $rawJobs = $data['jobs'] ?? [];
    $jobItems = normalize_job_items($rawJobs, $data['job_items'] ?? $data['jobItems'] ?? []);
    $jobItemsByJobId = [];
    foreach ($jobItems as $jobItem) {
        $jobItemsByJobId[(int)($jobItem['job_id'] ?? 0)][] = $jobItem;
    }
    $jobs = array_map(static fn(array $job): array => normalize_job($job, $customers, $jobItemsByJobId), $rawJobs);
    $normalizedQuotes = array_map(static fn(array $quote): array => normalize_quote($quote, $customers), $quotes);
    $invoiceBases = normalize_invoice_bases(
        $data['invoice_bases'] ?? $data['invoiceBases'] ?? [],
        $jobs,
        $customers,
        $normalizedQuotes
    );
    $invoiceBaseItems = normalize_invoice_base_items(
        $data['invoice_base_items'] ?? $data['invoiceBaseItems'] ?? [],
        $invoiceBases,
        $jobItems
    );

    $invoiceBaseItemsByBasisId = [];
    foreach ($invoiceBaseItems as $invoiceBaseItem) {
        $invoiceBaseItemsByBasisId[(int)($invoiceBaseItem['invoice_base_id'] ?? 0)][] = $invoiceBaseItem;
    }

    $invoiceBases = array_map(static function (array $basis) use ($invoiceBaseItemsByBasisId): array {
        $basis['row_items'] = $invoiceBaseItemsByBasisId[(int)($basis['id'] ?? 0)] ?? [];
        return $basis;
    }, $invoiceBases);

    $users = array_map(static function (array $user) use ($organizationMemberships, $organizations): array {
        $normalizedUser = normalize_user($user);
        $userMemberships = array_values(array_filter(
            $organizationMemberships,
            static fn(array $membership): bool => (int)($membership['user_id'] ?? 0) === (int)($normalizedUser['id'] ?? 0)
        ));

        if ($userMemberships !== []) {
            usort($userMemberships, static function (array $a, array $b): int {
                $primaryCompare = ((int)!empty($b['is_primary'])) <=> ((int)!empty($a['is_primary']));
                if ($primaryCompare !== 0) {
                    return $primaryCompare;
                }

                return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
            });

            $normalizedUser['effective_roles'] = normalize_role_list(array_map(
                static fn(array $membership): string => (string)($membership['role'] ?? USER_ROLE_WORKER),
                $userMemberships
            ));
            $primaryMembership = $userMemberships[0];
            $normalizedUser['role'] = $normalizedUser['effective_roles'][0] ?? $normalizedUser['role'];
            $normalizedUser['organization_id'] = (int)($primaryMembership['organization_id'] ?? 0) ?: $normalizedUser['organization_id'];
        }

        if (($normalizedUser['organization_id'] ?? null) !== null) {
            foreach ($organizations as $organization) {
                if ((int)($organization['id'] ?? 0) === (int)$normalizedUser['organization_id']) {
                    $normalizedUser['organization_name'] = (string)($organization['name'] ?? '');
                    if (($normalizedUser['region_id'] ?? null) === null && ($organization['region_id'] ?? null) !== null) {
                        $normalizedUser['region_id'] = (int)$organization['region_id'];
                    }
                    break;
                }
            }
        }

        return $normalizedUser;
    }, $data['users'] ?? []);

    return [
        'regions' => $regions,
        'organizations' => $organizations,
        'organization_memberships' => $organizationMemberships,
        'users' => $users,
        'products' => array_map('normalize_product', $data['products'] ?? []),
        'service_packages' => array_map('normalize_service_package', $data['service_packages'] ?? []),
        'service_package_items' => array_map('normalize_service_package_item', $data['service_package_items'] ?? []),
        'customers' => $customers,
        'quotes' => $normalizedQuotes,
        'quote_items' => $quoteItems,
        'jobs' => $jobs,
        'job_items' => $jobItems,
        'web_quote_requests' => array_map('normalize_web_quote_request', $data['web_quote_requests'] ?? $data['webQuoteRequests'] ?? []),
        'invoice_bases' => $invoiceBases,
        'invoice_base_items' => $invoiceBaseItems,
        'entity_logs' => array_values(array_filter(
            array_map(static function (array $log): array {
                return [
                    'id' => (int)($log['id'] ?? 0),
                    'entity_type' => (string)($log['entity_type'] ?? $log['entityType'] ?? ''),
                    'entity_id' => (int)($log['entity_id'] ?? $log['entityId'] ?? 0),
                    'action' => (string)($log['action'] ?? ''),
                    'message' => (string)($log['message'] ?? ''),
                    'created_at' => (string)($log['created_at'] ?? $log['createdAt'] ?? now_iso()),
                ];
            }, $data['entity_logs'] ?? $data['entityLogs'] ?? []),
            static fn(array $log): bool => (int)($log['id'] ?? 0) > 0
        )),
    ];
}

function ensure_data_file(): void
{
    $dir = dirname(ADMIN_DATA_FILE);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    if (!file_exists(ADMIN_DATA_FILE)) {
        file_put_contents(ADMIN_DATA_FILE, json_encode(seed_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function load_data(): array
{
    if (mysql_is_configured()) {
        return load_data_mysql();
    }

    ensure_data_file();
    $contents = file_get_contents(ADMIN_DATA_FILE);
    $data = json_decode($contents ?: '', true);
    $normalized = is_array($data) ? normalize_data($data) : seed_data();

    if ($normalized !== $data) {
        save_data($normalized);
    }

    return $normalized;
}

function save_data(array $data): void
{
    if (mysql_is_configured()) {
        throw new RuntimeException('save_data() är avstängt i MySQL-läge. Använd per-entity writes.');
    }

    file_put_contents(ADMIN_DATA_FILE, json_encode(normalize_data($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function mysql_next_table_id(PDO $pdo, string $table): int
{
    $allowedTables = ['regions', 'organizations', 'organization_memberships', 'users', 'products', 'service_packages', 'service_package_items', 'customers', 'quotes', 'quote_items', 'jobs', 'job_items', 'web_quote_requests', 'invoice_bases', 'invoice_base_items'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Otillåten tabell för id-generering: ' . $table);
    }

    $statement = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM ' . $table);
    $value = $statement ? $statement->fetchColumn() : 1;

    return max(1, (int)$value);
}

function mysql_reserve_next_quote_number(PDO $pdo, ?string $createdAt = null): string
{
    $year = quote_year_from_date($createdAt);

    $select = $pdo->prepare('SELECT last_number FROM quote_number_sequences WHERE year = :year FOR UPDATE');
    $select->execute(['year' => (int)$year]);
    $current = $select->fetchColumn();

    if ($current === false) {
        try {
            $insert = $pdo->prepare('INSERT INTO quote_number_sequences (year, last_number, updated_at) VALUES (:year, 0, NOW())');
            $insert->execute(['year' => (int)$year]);
        } catch (Throwable) {
            // Another transaction may have created the row; re-read below.
        }

        $select->execute(['year' => (int)$year]);
        $current = $select->fetchColumn();
        if ($current === false) {
            throw new RuntimeException('Kunde inte reservera offertnummer.');
        }
    }

    $nextSequence = ((int)$current) + 1;
    $update = $pdo->prepare('UPDATE quote_number_sequences SET last_number = :last_number, updated_at = NOW() WHERE year = :year');
    $update->execute([
        'year' => (int)$year,
        'last_number' => $nextSequence,
    ]);

    return format_quote_number($year, $nextSequence);
}

function mysql_customer_params(array $record): array
{
    [$firstName, $lastName] = mysql_split_name((string)($record['name'] ?? ''));
    [$billingAddress1, $billingAddress2] = mysql_split_address((string)($record['billing_address'] ?? ''));
    [$propertyAddress1, $propertyAddress2] = mysql_split_address((string)($record['service_address'] ?? $record['address'] ?? ''));

    return [
        'id' => $record['id'],
        'customer_type' => $record['customer_type'],
        'billing_vat_mode' => $record['billing_vat_mode'],
        'name' => $record['name'],
        'service_type' => normalize_customer_service_type((string)($record['service_type'] ?? 'single')),
        'maintenance_plan_deck' => !empty($record['maintenance_plan_deck']) ? 1 : 0,
        'maintenance_plan_stone' => !empty($record['maintenance_plan_stone']) ? 1 : 0,
        'organization_id' => ($record['organization_id'] ?? null) !== null ? (int)$record['organization_id'] : null,
        'region_id' => ($record['region_id'] ?? null) !== null ? (int)$record['region_id'] : null,
        'first_name' => $record['customer_type'] === 'private' ? ((string)($record['first_name'] ?? '') !== '' ? $record['first_name'] : $firstName) : null,
        'last_name' => $record['customer_type'] === 'private' ? ((string)($record['last_name'] ?? '') !== '' ? $record['last_name'] : $lastName) : null,
        'company_name' => $record['company_name'],
        'association_name' => $record['association_name'],
        'contact_person' => $record['contact_person'],
        'phone' => $record['phone'],
        'email' => $record['email'],
        'billing_address_1' => $billingAddress1,
        'billing_address_2' => $billingAddress2,
        'billing_postcode' => $record['billing_postal_code'],
        'billing_city' => $record['billing_city'],
        'property_address_1' => $propertyAddress1,
        'property_address_2' => $propertyAddress2,
        'property_postcode' => $record['service_postal_code'],
        'property_city' => $record['service_city'],
        'property_designation' => $record['property_designation'] ?? '',
        'personal_number' => $record['personal_number'],
        'organization_number' => $record['organization_number'],
        'vat_number' => $record['vat_number'],
        'rut_enabled' => $record['rut_enabled'] ? 1 : 0,
        'rut_used_amount_this_year' => $record['rut_used_amount_this_year'] ?? 0,
        'last_service_date' => ($record['last_service_date'] ?? '') !== '' ? $record['last_service_date'] : null,
        'next_service_date' => ($record['next_service_date'] ?? '') !== '' ? $record['next_service_date'] : null,
        'notes' => $record['notes'],
        'created_at' => $record['created_at'],
        'updated_at' => $record['updated_at'],
        'last_activity' => $record['last_activity'],
    ];
}

function mysql_user_params(array $record): array
{
    return [
        'id' => (int)($record['id'] ?? 0),
        'username' => (string)($record['username'] ?? ''),
        'name' => (string)($record['name'] ?? ''),
        'phone' => (string)($record['phone'] ?? ''),
        'email' => (string)($record['email'] ?? ''),
        'role' => normalize_user_role((string)($record['role'] ?? USER_ROLE_WORKER)),
        'organization_id' => ($record['organization_id'] ?? null) !== null ? (int)$record['organization_id'] : null,
        'region_id' => ($record['region_id'] ?? null) !== null ? (int)$record['region_id'] : null,
        'is_active' => !empty($record['is_active']) ? 1 : 0,
        'failed_login_attempts' => max(0, (int)($record['failed_login_attempts'] ?? 0)),
        'locked_until' => mysql_nullable_string($record['locked_until'] ?? null),
        'last_login_at' => mysql_nullable_string($record['last_login_at'] ?? null),
        'two_factor_enabled' => !empty($record['two_factor_enabled']) ? 1 : 0,
        'two_factor_secret' => (string)($record['two_factor_secret'] ?? ''),
        'two_factor_confirmed_at' => mysql_nullable_string($record['two_factor_confirmed_at'] ?? null),
        'password_hash' => (string)($record['password_hash'] ?? ''),
        'created_at' => (string)($record['created_at'] ?? now_iso()),
        'updated_at' => (string)($record['updated_at'] ?? now_iso()),
    ];
}

function mysql_product_params(array $record): array
{
    return [
        'id' => (int)($record['id'] ?? 0),
        'name' => (string)($record['name'] ?? ''),
        'description' => (string)($record['description'] ?? ''),
        'category' => (string)($record['category'] ?? ''),
        'item_type' => normalize_product_item_type((string)($record['item_type'] ?? 'service')),
        'price_model' => normalize_product_price_model((string)($record['price_model'] ?? 'fixed')),
        'default_quantity' => to_float($record['default_quantity'] ?? 1),
        'unit' => (string)($record['unit'] ?? 'st'),
        'default_unit_price' => to_float($record['default_unit_price'] ?? 0),
        'vat_rate' => to_float($record['vat_rate'] ?? 0.25),
        'is_rut_eligible' => !empty($record['is_rut_eligible']) ? 1 : 0,
        'is_active' => !empty($record['is_active']) ? 1 : 0,
        'created_at' => (string)($record['created_at'] ?? now_iso()),
        'updated_at' => (string)($record['updated_at'] ?? now_iso()),
    ];
}

function mysql_service_package_params(array $record): array
{
    return [
        'id' => (int)($record['id'] ?? 0),
        'name' => (string)($record['name'] ?? ''),
        'service_family' => normalize_service_family((string)($record['service_family'] ?? 'general')),
        'description' => (string)($record['description'] ?? ''),
        'is_active' => !empty($record['is_active']) ? 1 : 0,
        'sort_order' => (int)($record['sort_order'] ?? 0),
        'created_at' => (string)($record['created_at'] ?? now_iso()),
        'updated_at' => (string)($record['updated_at'] ?? now_iso()),
    ];
}

function mysql_service_package_item_params(array $record): array
{
    return [
        'id' => (int)($record['id'] ?? 0),
        'package_id' => (int)($record['package_id'] ?? 0),
        'product_id' => (int)($record['product_id'] ?? 0),
        'sort_order' => (int)($record['sort_order'] ?? 0),
        'quantity_mode' => normalize_package_quantity_mode((string)($record['quantity_mode'] ?? 'product_default')),
        'quantity_value' => to_float($record['quantity_value'] ?? 1),
        'unit_price_override' => $record['unit_price_override'],
        'notes' => (string)($record['notes'] ?? ''),
        'created_at' => (string)($record['created_at'] ?? now_iso()),
        'updated_at' => (string)($record['updated_at'] ?? now_iso()),
    ];
}

function mysql_quote_params(array $record): array
{
    $year = null;
    $sequence = null;
    if (preg_match('/^(\d{4})-(\d{4,})$/', (string)($record['quote_number'] ?? ''), $matches) === 1) {
        $year = (int)$matches[1];
        $sequence = (int)$matches[2];
    }

    return [
        'id' => $record['id'],
        'customer_id' => $record['customer_id'],
        'organization_id' => ($record['organization_id'] ?? null) !== null ? (int)$record['organization_id'] : null,
        'quote_year' => $year,
        'quote_sequence' => $sequence,
        'quote_number' => $record['quote_number'] !== '' ? $record['quote_number'] : null,
        'created_by_username' => ($record['created_by_username'] ?? '') !== '' ? $record['created_by_username'] : null,
        'status' => $record['status'],
        'issue_date' => $record['issue_date'] !== '' ? $record['issue_date'] : null,
        'valid_until' => $record['valid_until'] !== '' ? $record['valid_until'] : null,
        'work_description' => $record['work_description'],
        'service_type' => $record['service_type'],
        'description' => $record['description'],
        'labor_amount_ex_vat' => $record['labor_amount_ex_vat'],
        'material_amount_ex_vat' => $record['material_amount_ex_vat'],
        'other_amount_ex_vat' => $record['other_amount_ex_vat'],
        'subtotal' => $record['subtotal'],
        'vat_rate' => $record['vat_rate'],
        'vat_amount' => $record['vat_amount'],
        'total_amount_ex_vat' => $record['total_amount_ex_vat'],
        'total_amount_inc_vat' => $record['total_amount_inc_vat'],
        'rut_amount' => $record['rut_amount'],
        'amount_after_rut' => $record['amount_after_rut'],
        'total_amount' => $record['total_amount'],
        'is_rut_job' => $record['is_rut_job'] ? 1 : 0,
        'reverse_charge_text' => $record['reverse_charge_text'],
        'approved_at' => $record['approved_at'] !== '' ? $record['approved_at'] : null,
        'converted_to_job_at' => $record['converted_to_job_at'] !== '' ? $record['converted_to_job_at'] : null,
        'notes' => $record['notes'],
        'created_at' => $record['created_at'],
        'updated_at' => $record['updated_at'],
    ];
}

function mysql_job_params(array $record): array
{
    return [
        'id' => $record['id'],
        'customer_id' => $record['customer_id'],
        'quote_id' => (int)($record['quote_id'] ?? 0),
        'organization_id' => ($record['organization_id'] ?? null) !== null ? (int)$record['organization_id'] : null,
        'region_id' => ($record['region_id'] ?? null) !== null ? (int)$record['region_id'] : null,
        'status' => $record['status'],
        'planned_start_date' => $record['planned_start_date'] !== '' ? $record['planned_start_date'] : null,
        'planned_end_date' => $record['planned_end_date'] !== '' ? $record['planned_end_date'] : null,
        'completed_at' => $record['completed_at'] !== '' ? $record['completed_at'] : null,
        'work_address_1' => $record['work_address_1'] !== '' ? $record['work_address_1'] : null,
        'work_address_2' => $record['work_address_2'] !== '' ? $record['work_address_2'] : null,
        'work_postcode' => $record['work_postcode'] !== '' ? $record['work_postcode'] : null,
        'work_city' => $record['work_city'] !== '' ? $record['work_city'] : null,
        'internal_notes' => $record['internal_notes'] !== '' ? $record['internal_notes'] : null,
        'customer_notes' => $record['customer_notes'] !== '' ? $record['customer_notes'] : null,
        'service_type' => $record['service_type'],
        'description' => $record['description'],
        'scheduled_date' => $record['scheduled_date'] !== '' ? $record['scheduled_date'] : null,
        'scheduled_time' => ($record['scheduled_time'] ?? '') !== '' ? $record['scheduled_time'] : null,
        'completed_date' => $record['completed_date'] !== '' ? $record['completed_date'] : null,
        'assigned_to' => $record['assigned_to'],
        'ready_for_invoicing' => $record['ready_for_invoicing'] ? 1 : 0,
        'invoice_status' => $record['invoice_status'] !== '' ? $record['invoice_status'] : null,
        'notes' => $record['notes'],
        'created_at' => $record['created_at'],
        'updated_at' => $record['updated_at'],
    ];
}

function mysql_persist_customer_record(PDO $pdo, array $record, bool $isInsert): array
{
    $params = mysql_customer_params($record);

    if ($isInsert) {
        $statement = $pdo->prepare(
            'INSERT INTO customers (
                id, customer_type, billing_vat_mode, service_type, maintenance_plan_deck, maintenance_plan_stone, name, organization_id, region_id, first_name, last_name, company_name, association_name, contact_person,
                phone, email, billing_address_1, billing_address_2, billing_postcode, billing_city,
                property_address_1, property_address_2, property_postcode, property_city, property_designation,
                personal_number, organization_number, vat_number, rut_enabled, rut_used_amount_this_year, last_service_date, next_service_date, notes, created_at, updated_at, last_activity
             ) VALUES (
                :id, :customer_type, :billing_vat_mode, :service_type, :maintenance_plan_deck, :maintenance_plan_stone, :name, :organization_id, :region_id, :first_name, :last_name, :company_name, :association_name, :contact_person,
                :phone, :email, :billing_address_1, :billing_address_2, :billing_postcode, :billing_city,
                :property_address_1, :property_address_2, :property_postcode, :property_city, :property_designation,
                :personal_number, :organization_number, :vat_number, :rut_enabled, :rut_used_amount_this_year, :last_service_date, :next_service_date, :notes, :created_at, :updated_at, :last_activity
             )'
        );
    } else {
        $statement = $pdo->prepare(
            'UPDATE customers SET
                customer_type = :customer_type,
                billing_vat_mode = :billing_vat_mode,
                service_type = :service_type,
                maintenance_plan_deck = :maintenance_plan_deck,
                maintenance_plan_stone = :maintenance_plan_stone,
                name = :name,
                organization_id = :organization_id,
                region_id = :region_id,
                first_name = :first_name,
                last_name = :last_name,
                company_name = :company_name,
                association_name = :association_name,
                contact_person = :contact_person,
                phone = :phone,
                email = :email,
                billing_address_1 = :billing_address_1,
                billing_address_2 = :billing_address_2,
                billing_postcode = :billing_postcode,
                billing_city = :billing_city,
                property_address_1 = :property_address_1,
                property_address_2 = :property_address_2,
                property_postcode = :property_postcode,
                property_city = :property_city,
                property_designation = :property_designation,
                personal_number = :personal_number,
                organization_number = :organization_number,
                vat_number = :vat_number,
                rut_enabled = :rut_enabled,
                rut_used_amount_this_year = :rut_used_amount_this_year,
                last_service_date = :last_service_date,
                next_service_date = :next_service_date,
                notes = :notes,
                created_at = :created_at,
                updated_at = :updated_at,
                last_activity = :last_activity
             WHERE id = :id'
        );
    }

    $statement->execute($params);
    return $record;
}

function mysql_persist_user_record(PDO $pdo, array $record, bool $isInsert): array
{
    $params = mysql_user_params($record);

    if ($isInsert) {
        if (user_contact_schema_ready()) {
            $statement = $pdo->prepare(
                'INSERT INTO users (
                    id, username, name, phone, email, role, organization_id, region_id, is_active,
                    failed_login_attempts, locked_until, last_login_at,
                    two_factor_enabled, two_factor_secret, two_factor_confirmed_at,
                    password_hash, created_at, updated_at
                ) VALUES (
                    :id, :username, :name, :phone, :email, :role, :organization_id, :region_id, :is_active,
                    :failed_login_attempts, :locked_until, :last_login_at,
                    :two_factor_enabled, :two_factor_secret, :two_factor_confirmed_at,
                    :password_hash, :created_at, :updated_at
                )'
            );
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO users (
                    id, username, name, role, organization_id, region_id, is_active,
                    failed_login_attempts, locked_until, last_login_at,
                    two_factor_enabled, two_factor_secret, two_factor_confirmed_at,
                    password_hash, created_at, updated_at
                ) VALUES (
                    :id, :username, :name, :role, :organization_id, :region_id, :is_active,
                    :failed_login_attempts, :locked_until, :last_login_at,
                    :two_factor_enabled, :two_factor_secret, :two_factor_confirmed_at,
                    :password_hash, :created_at, :updated_at
                )'
            );
        }
    } else {
        if (user_contact_schema_ready()) {
            $statement = $pdo->prepare(
                'UPDATE users SET
                    username = :username,
                    name = :name,
                    phone = :phone,
                    email = :email,
                    role = :role,
                    organization_id = :organization_id,
                    region_id = :region_id,
                    is_active = :is_active,
                    failed_login_attempts = :failed_login_attempts,
                    locked_until = :locked_until,
                    last_login_at = :last_login_at,
                    two_factor_enabled = :two_factor_enabled,
                    two_factor_secret = :two_factor_secret,
                    two_factor_confirmed_at = :two_factor_confirmed_at,
                    password_hash = :password_hash,
                    created_at = :created_at,
                    updated_at = :updated_at
                 WHERE id = :id'
            );
        } else {
            $statement = $pdo->prepare(
                'UPDATE users SET
                    username = :username,
                    name = :name,
                    role = :role,
                    organization_id = :organization_id,
                    region_id = :region_id,
                    is_active = :is_active,
                    failed_login_attempts = :failed_login_attempts,
                    locked_until = :locked_until,
                    last_login_at = :last_login_at,
                    two_factor_enabled = :two_factor_enabled,
                    two_factor_secret = :two_factor_secret,
                    two_factor_confirmed_at = :two_factor_confirmed_at,
                    password_hash = :password_hash,
                    created_at = :created_at,
                    updated_at = :updated_at
                 WHERE id = :id'
            );
        }
    }

    $statement->execute($params);

    return $record;
}

function mysql_region_params(array $record): array
{
    return [
        'id' => (int)($record['id'] ?? 0),
        'name' => (string)($record['name'] ?? ''),
        'slug' => (string)($record['slug'] ?? ''),
        'is_active' => !empty($record['is_active']) ? 1 : 0,
        'created_at' => (string)($record['created_at'] ?? now_iso()),
        'updated_at' => (string)($record['updated_at'] ?? now_iso()),
    ];
}

function mysql_organization_params(array $record): array
{
    return [
        'id' => (int)($record['id'] ?? 0),
        'name' => (string)($record['name'] ?? ''),
        'slug' => (string)($record['slug'] ?? ''),
        'organization_type' => normalize_organization_type((string)($record['organization_type'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT)),
        'parent_organization_id' => ($record['parent_organization_id'] ?? null) !== null ? (int)$record['parent_organization_id'] : null,
        'region_id' => ($record['region_id'] ?? null) !== null ? (int)$record['region_id'] : null,
        'service_postcode_prefixes' => (string)($record['service_postcode_prefixes'] ?? ''),
        'service_cities' => (string)($record['service_cities'] ?? ''),
        'is_active' => !empty($record['is_active']) ? 1 : 0,
        'created_at' => (string)($record['created_at'] ?? now_iso()),
        'updated_at' => (string)($record['updated_at'] ?? now_iso()),
    ];
}

function mysql_persist_organization_record(PDO $pdo, array $record, bool $isInsert): array
{
    $params = mysql_organization_params($record);

    if ($isInsert) {
        $statement = $pdo->prepare(
            'INSERT INTO organizations (
                id, name, slug, organization_type, parent_organization_id, region_id, service_postcode_prefixes, service_cities, is_active, created_at, updated_at
            ) VALUES (
                :id, :name, :slug, :organization_type, :parent_organization_id, :region_id, :service_postcode_prefixes, :service_cities, :is_active, :created_at, :updated_at
            )'
        );
    } else {
        $statement = $pdo->prepare(
            'UPDATE organizations SET
                name = :name,
                slug = :slug,
                organization_type = :organization_type,
                parent_organization_id = :parent_organization_id,
                region_id = :region_id,
                service_postcode_prefixes = :service_postcode_prefixes,
                service_cities = :service_cities,
                is_active = :is_active,
                created_at = :created_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
    }

    $statement->execute($params);

    return $record;
}

function mysql_web_quote_request_params(array $record): array
{
    return [
        'id' => (int)($record['id'] ?? 0),
        'name' => (string)($record['name'] ?? ''),
        'phone' => (string)($record['phone'] ?? ''),
        'email' => (string)($record['email'] ?? ''),
        'service_address' => (string)($record['service_address'] ?? ''),
        'service_postcode' => (string)($record['service_postcode'] ?? ''),
        'service_city' => (string)($record['service_city'] ?? ''),
        'message' => (string)($record['message'] ?? ''),
        'source_page' => (string)($record['source_page'] ?? ''),
        'region_id' => ($record['region_id'] ?? null) !== null ? (int)$record['region_id'] : null,
        'requested_region_name' => (string)($record['requested_region_name'] ?? ''),
        'organization_id' => ($record['organization_id'] ?? null) !== null ? (int)$record['organization_id'] : null,
        'assignment_basis' => (string)($record['assignment_basis'] ?? ''),
        'status' => in_array((string)($record['status'] ?? 'new'), ['new', 'handled', 'archived'], true) ? (string)($record['status'] ?? 'new') : 'new',
        'handled_by_username' => (string)($record['handled_by_username'] ?? ''),
        'handled_at' => mysql_nullable_string($record['handled_at'] ?? null),
        'created_at' => (string)($record['created_at'] ?? now_iso()),
        'updated_at' => (string)($record['updated_at'] ?? now_iso()),
    ];
}

function mysql_persist_web_quote_request_record(PDO $pdo, array $record, bool $isInsert): array
{
    $params = mysql_web_quote_request_params($record);

    if ($isInsert) {
        $statement = $pdo->prepare(
            'INSERT INTO web_quote_requests (
                id, name, phone, email, service_address, service_postcode, service_city, message, source_page,
                region_id, requested_region_name, organization_id, assignment_basis, status, handled_by_username, handled_at, created_at, updated_at
            ) VALUES (
                :id, :name, :phone, :email, :service_address, :service_postcode, :service_city, :message, :source_page,
                :region_id, :requested_region_name, :organization_id, :assignment_basis, :status, :handled_by_username, :handled_at, :created_at, :updated_at
            )'
        );
    } else {
        $statement = $pdo->prepare(
            'UPDATE web_quote_requests SET
                name = :name,
                phone = :phone,
                email = :email,
                service_address = :service_address,
                service_postcode = :service_postcode,
                service_city = :service_city,
                message = :message,
                source_page = :source_page,
                region_id = :region_id,
                requested_region_name = :requested_region_name,
                organization_id = :organization_id,
                assignment_basis = :assignment_basis,
                status = :status,
                handled_by_username = :handled_by_username,
                handled_at = :handled_at,
                created_at = :created_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
    }

    $statement->execute($params);

    return $record;
}

function mysql_sync_user_memberships(PDO $pdo, array $userRecord, array $roles): void
{
    $delete = $pdo->prepare('DELETE FROM organization_memberships WHERE user_id = :user_id');
    $delete->execute(['user_id' => (int)($userRecord['id'] ?? 0)]);

    $organizationId = ($userRecord['organization_id'] ?? null) !== null ? (int)$userRecord['organization_id'] : 0;
    if ($organizationId <= 0) {
        return;
    }

    $roles = normalize_role_list($roles);
    $nextId = mysql_next_table_id($pdo, 'organization_memberships');
    $insert = $pdo->prepare(
        'INSERT INTO organization_memberships (
            id, user_id, organization_id, role, is_primary, created_at, updated_at
        ) VALUES (
            :id, :user_id, :organization_id, :role, :is_primary, :created_at, :updated_at
        )'
    );

    foreach (array_values($roles) as $index => $role) {
        $insert->execute([
            'id' => $nextId++,
            'user_id' => (int)($userRecord['id'] ?? 0),
            'organization_id' => $organizationId,
            'role' => normalize_user_role($role),
            'is_primary' => $index === 0 ? 1 : 0,
            'created_at' => (string)($userRecord['created_at'] ?? now_iso()),
            'updated_at' => (string)($userRecord['updated_at'] ?? now_iso()),
        ]);
    }
}

function mysql_persist_region_record(PDO $pdo, array $record, bool $isInsert): array
{
    $params = mysql_region_params($record);

    if ($isInsert) {
        $statement = $pdo->prepare(
            'INSERT INTO regions (id, name, slug, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :is_active, :created_at, :updated_at)'
        );
    } else {
        $statement = $pdo->prepare(
            'UPDATE regions SET
                name = :name,
                slug = :slug,
                is_active = :is_active,
                created_at = :created_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
    }

    $statement->execute($params);

    return $record;
}

function mysql_persist_product_record(PDO $pdo, array $record, bool $isInsert): array
{
    $params = mysql_product_params($record);

    if ($isInsert) {
        $statement = $pdo->prepare(
            'INSERT INTO products (
                id, name, description, category, item_type, price_model, default_quantity, unit,
                default_unit_price, vat_rate, is_rut_eligible, is_active, created_at, updated_at
            ) VALUES (
                :id, :name, :description, :category, :item_type, :price_model, :default_quantity, :unit,
                :default_unit_price, :vat_rate, :is_rut_eligible, :is_active, :created_at, :updated_at
            )'
        );
    } else {
        unset($params['created_at']);
        $statement = $pdo->prepare(
            'UPDATE products SET
                name = :name,
                description = :description,
                category = :category,
                item_type = :item_type,
                price_model = :price_model,
                default_quantity = :default_quantity,
                unit = :unit,
                default_unit_price = :default_unit_price,
                vat_rate = :vat_rate,
                is_rut_eligible = :is_rut_eligible,
                is_active = :is_active,
                updated_at = :updated_at
             WHERE id = :id'
        );
    }

    $statement->execute($params);

    return $record;
}

function mysql_persist_service_package_record(PDO $pdo, array $record, bool $isInsert): array
{
    $params = mysql_service_package_params($record);

    if ($isInsert) {
        $statement = $pdo->prepare(
            'INSERT INTO service_packages (
                id, name, service_family, description, is_active, sort_order, created_at, updated_at
            ) VALUES (
                :id, :name, :service_family, :description, :is_active, :sort_order, :created_at, :updated_at
            )'
        );
    } else {
        unset($params['created_at']);
        $statement = $pdo->prepare(
            'UPDATE service_packages SET
                name = :name,
                service_family = :service_family,
                description = :description,
                is_active = :is_active,
                sort_order = :sort_order,
                updated_at = :updated_at
             WHERE id = :id'
        );
    }

    $statement->execute($params);

    return $record;
}

function mysql_sync_service_package_items(PDO $pdo, int $packageId, array $items): void
{
    $delete = $pdo->prepare('DELETE FROM service_package_items WHERE package_id = :package_id');
    $delete->execute(['package_id' => $packageId]);

    if ($items === []) {
        return;
    }

    $nextId = mysql_next_table_id($pdo, 'service_package_items');
    $insert = $pdo->prepare(
        'INSERT INTO service_package_items (
            id, package_id, product_id, sort_order, quantity_mode, quantity_value, unit_price_override, notes, created_at, updated_at
        ) VALUES (
            :id, :package_id, :product_id, :sort_order, :quantity_mode, :quantity_value, :unit_price_override, :notes, :created_at, :updated_at
        )'
    );

    foreach ($items as $item) {
        $params = mysql_service_package_item_params($item);
        $params['id'] = $nextId++;
        $insert->execute($params);
    }
}

function mysql_sync_quote_items(PDO $pdo, array $quoteRecord): void
{
    $delete = $pdo->prepare('DELETE FROM quote_items WHERE quote_id = :quote_id');
    $delete->execute(['quote_id' => (int)$quoteRecord['id']]);

    $items = build_quote_items_from_quote($quoteRecord);
    if ($items === []) {
        return;
    }

    $nextId = mysql_next_table_id($pdo, 'quote_items');
    $insert = $pdo->prepare(
        'INSERT INTO quote_items (
            id, quote_id, sort_order, item_type, description, quantity, unit, unit_price, vat_rate, is_rut_eligible, line_total, created_at, updated_at
         ) VALUES (
            :id, :quote_id, :sort_order, :item_type, :description, :quantity, :unit, :unit_price, :vat_rate, :is_rut_eligible, :line_total, :created_at, :updated_at
         )'
    );

    foreach ($items as $item) {
        $item['id'] = $nextId++;
        $insert->execute([
            'id' => $item['id'],
            'quote_id' => $item['quote_id'],
            'sort_order' => $item['sort_order'],
            'item_type' => $item['item_type'],
            'description' => $item['description'],
            'quantity' => $item['quantity'],
            'unit' => $item['unit'],
            'unit_price' => $item['unit_price'],
            'vat_rate' => $item['vat_rate'],
            'is_rut_eligible' => $item['is_rut_eligible'] ? 1 : 0,
            'line_total' => $item['line_total'],
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at'],
        ]);
    }
}

function mysql_persist_quote_record(PDO $pdo, array $quoteRecord, bool $isInsert): array
{
    $params = mysql_quote_params($quoteRecord);

    if ($isInsert) {
        $statement = $pdo->prepare(
            'INSERT INTO quotes (
                id, customer_id, organization_id, quote_year, quote_sequence, quote_number, created_by_username, status, issue_date, valid_until, work_description,
                service_type, description, labor_amount_ex_vat, material_amount_ex_vat, other_amount_ex_vat,
                subtotal, vat_rate, vat_amount, total_amount_ex_vat, total_amount_inc_vat,
                rut_amount, amount_after_rut, total_amount, is_rut_job, reverse_charge_text,
                approved_at, converted_to_job_at, notes, created_at, updated_at
             ) VALUES (
                :id, :customer_id, :organization_id, :quote_year, :quote_sequence, :quote_number, :created_by_username, :status, :issue_date, :valid_until, :work_description,
                :service_type, :description, :labor_amount_ex_vat, :material_amount_ex_vat, :other_amount_ex_vat,
                :subtotal, :vat_rate, :vat_amount, :total_amount_ex_vat, :total_amount_inc_vat,
                :rut_amount, :amount_after_rut, :total_amount, :is_rut_job, :reverse_charge_text,
                :approved_at, :converted_to_job_at, :notes, :created_at, :updated_at
             )'
        );
    } else {
        $statement = $pdo->prepare(
            'UPDATE quotes SET
                customer_id = :customer_id,
                organization_id = :organization_id,
                quote_year = :quote_year,
                quote_sequence = :quote_sequence,
                quote_number = :quote_number,
                created_by_username = :created_by_username,
                status = :status,
                issue_date = :issue_date,
                valid_until = :valid_until,
                work_description = :work_description,
                service_type = :service_type,
                description = :description,
                labor_amount_ex_vat = :labor_amount_ex_vat,
                material_amount_ex_vat = :material_amount_ex_vat,
                other_amount_ex_vat = :other_amount_ex_vat,
                subtotal = :subtotal,
                vat_rate = :vat_rate,
                vat_amount = :vat_amount,
                total_amount_ex_vat = :total_amount_ex_vat,
                total_amount_inc_vat = :total_amount_inc_vat,
                rut_amount = :rut_amount,
                amount_after_rut = :amount_after_rut,
                total_amount = :total_amount,
                is_rut_job = :is_rut_job,
                reverse_charge_text = :reverse_charge_text,
                approved_at = :approved_at,
                converted_to_job_at = :converted_to_job_at,
                notes = :notes,
                created_at = :created_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
    }

    $statement->execute($params);
    mysql_sync_quote_items($pdo, $quoteRecord);
    return $quoteRecord;
}

function mysql_sync_job_items(PDO $pdo, array $jobRecord): void
{
    $delete = $pdo->prepare('DELETE FROM job_items WHERE job_id = :job_id');
    $delete->execute(['job_id' => (int)$jobRecord['id']]);

    $items = build_job_items_from_job($jobRecord);
    if ($items === []) {
        return;
    }

    $nextId = mysql_next_table_id($pdo, 'job_items');
    $insert = $pdo->prepare(
        'INSERT INTO job_items (
            id, job_id, quote_item_id, sort_order, item_type, description, quantity, unit, unit_price, vat_rate, is_rut_eligible, line_total, created_at, updated_at
         ) VALUES (
            :id, :job_id, :quote_item_id, :sort_order, :item_type, :description, :quantity, :unit, :unit_price, :vat_rate, :is_rut_eligible, :line_total, :created_at, :updated_at
         )'
    );

    foreach ($items as $item) {
        $item['id'] = $nextId++;
        $item['quote_item_id'] = null;
        $insert->execute([
            'id' => $item['id'],
            'job_id' => $item['job_id'],
            'quote_item_id' => $item['quote_item_id'],
            'sort_order' => $item['sort_order'],
            'item_type' => $item['item_type'],
            'description' => $item['description'],
            'quantity' => $item['quantity'],
            'unit' => $item['unit'],
            'unit_price' => $item['unit_price'],
            'vat_rate' => $item['vat_rate'],
            'is_rut_eligible' => $item['is_rut_eligible'] ? 1 : 0,
            'line_total' => $item['line_total'],
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at'],
        ]);
    }
}

function recalculateJobTotals(PDO $pdo, int $jobId): array
{
    $result = JobTotalsService::recalculateJobTotals($pdo, $jobId);
    mysql_sync_invoice_basis_for_job($pdo, $jobId);

    return $result;
}

function recalculateInvoiceBaseTotals(PDO $pdo, int $invoiceBaseId): array
{
    return InvoiceBaseTotalsService::recalculateInvoiceBaseTotals($pdo, $invoiceBaseId);
}

function mysql_find_persisted_invoice_basis_by_job_id(PDO $pdo, int $jobId): ?array
{
    if (!mysql_table_exists($pdo, 'invoice_bases')) {
        return null;
    }

    $statement = $pdo->prepare('SELECT * FROM invoice_bases WHERE job_id = :job_id LIMIT 1');
    $statement->execute(['job_id' => $jobId]);
    $record = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($record) ? normalize_invoice_basis($record) : null;
}

function mysql_sync_invoice_basis_for_job(PDO $pdo, int $jobId): void
{
    $data = load_data_mysql();
    $existingBasis = mysql_find_persisted_invoice_basis_by_job_id($pdo, $jobId);

    $job = find_by_id($data['jobs'] ?? [], $jobId);
    if ($job === null) {
        if ($existingBasis === null || !invoice_base_is_locked_for_export($existingBasis)) {
            $delete = $pdo->prepare('DELETE FROM invoice_bases WHERE job_id = :job_id');
            $delete->execute(['job_id' => $jobId]);
        }
        return;
    }

    $customer = find_by_id($data['customers'] ?? [], (int)($job['customer_id'] ?? 0));
    if ($customer === null) {
        return;
    }

    $jobStatus = (string)($job['status'] ?? '');
    $isInvoiceRelevant = !empty($job['ready_for_invoicing']) || in_array($jobStatus, ['completed', 'invoiced'], true);
    if (!$isInvoiceRelevant) {
        if ($existingBasis === null || !invoice_base_is_locked_for_export($existingBasis)) {
            $delete = $pdo->prepare('DELETE FROM invoice_bases WHERE job_id = :job_id');
            $delete->execute(['job_id' => $jobId]);
        }
        return;
    }

    if ($existingBasis !== null && invoice_base_is_locked_for_export($existingBasis)) {
        return;
    }

    $quote = ((int)($job['quote_id'] ?? 0)) > 0 ? find_by_id($data['quotes'] ?? [], (int)$job['quote_id']) : null;
    $basisModel = buildInvoiceBasisFromJob($job, $customer, $quote);

    $basisRecord = normalize_invoice_basis([
        'id' => (int)($existingBasis['id'] ?? 0),
        'jobId' => $jobId,
        'quoteId' => $quote ? (int)($quote['id'] ?? 0) : null,
        'customerId' => (int)($customer['id'] ?? 0),
        'organizationId' => (int)($job['organization_id'] ?? $customer['organization_id'] ?? 0) ?: null,
        'status' => (string)($existingBasis['status'] ?? 'pending'),
        'quoteNumber' => (string)($quote['quote_number'] ?? $basisModel['quoteNumber'] ?? ''),
        'customerType' => (string)($basisModel['customerType'] ?? 'private'),
        'billingVatMode' => (string)($basisModel['billingVatMode'] ?? 'standard_vat'),
        'invoiceCustomerName' => (string)($basisModel['customerName'] ?? ''),
        'contactPerson' => (string)($basisModel['contactPerson'] ?? ''),
        'personalNumber' => (string)($basisModel['personalNumber'] ?? ''),
        'organizationNumber' => (string)($basisModel['organizationNumber'] ?? ''),
        'vatNumber' => (string)($basisModel['vatNumber'] ?? ''),
        'email' => (string)($basisModel['email'] ?? ''),
        'phone' => (string)($basisModel['phone'] ?? ''),
        'serviceAddress' => (string)($basisModel['serviceAddress'] ?? ''),
        'servicePostalCode' => (string)($basisModel['servicePostalCode'] ?? ''),
        'serviceCity' => (string)($basisModel['serviceCity'] ?? ''),
        'billingAddress' => (string)($basisModel['billingAddress'] ?? ''),
        'billingPostalCode' => (string)($basisModel['billingPostalCode'] ?? ''),
        'billingCity' => (string)($basisModel['billingCity'] ?? ''),
        'invoiceDate' => (string)($basisModel['invoiceDate'] ?? ''),
        'dueDate' => (string)($basisModel['dueDate'] ?? ''),
        'serviceType' => (string)($basisModel['serviceType'] ?? ''),
        'description' => (string)($basisModel['description'] ?? ''),
        'subtotal' => 0.0,
        'laborAmountExVat' => 0.0,
        'materialAmountExVat' => 0.0,
        'otherAmountExVat' => 0.0,
        'totalAmountExVat' => 0.0,
        'vatAmount' => 0.0,
        'totalAmountIncVat' => 0.0,
        'rutEnabled' => (bool)($basisModel['rutEnabled'] ?? false),
        'rutBasisAmount' => 0.0,
        'rutAmount' => 0.0,
        'amountToPay' => 0.0,
        'reverseChargeText' => (string)($basisModel['reverseChargeText'] ?? ''),
        'readyForInvoicing' => (bool)($basisModel['readyForInvoicing'] ?? false),
        'fortnoxCustomerNumber' => (string)($existingBasis['fortnox_customer_number'] ?? ''),
        'fortnoxDocumentNumber' => (string)($existingBasis['fortnox_document_number'] ?? ''),
        'fortnoxInvoiceNumber' => (string)($existingBasis['fortnox_invoice_number'] ?? ''),
        'fortnoxLastSyncAt' => (string)($existingBasis['fortnox_last_sync_at'] ?? ''),
        'fortnoxSyncError' => (string)($existingBasis['fortnox_sync_error'] ?? ''),
        'exportError' => (string)($existingBasis['export_error'] ?? ''),
        'exportedAt' => (string)($existingBasis['exported_at'] ?? ''),
        'createdAt' => (string)($existingBasis['created_at'] ?? $basisModel['createdAt'] ?? now_iso()),
        'updatedAt' => now_iso(),
    ]);

    if ((int)$basisRecord['id'] <= 0) {
        $basisRecord['id'] = mysql_next_table_id($pdo, 'invoice_bases');
        $statement = $pdo->prepare(
            'INSERT INTO invoice_bases (
                id, customer_id, job_id, quote_id, organization_id, status, quote_number, customer_type, billing_vat_mode,
                invoice_customer_name, contact_person, personal_number, organization_number, vat_number, email, phone,
                service_address, service_postal_code, service_city,
                invoice_address_1, invoice_address_2, invoice_postcode, invoice_city,
                invoice_date, due_date, service_type, description,
                subtotal, labor_amount_ex_vat, material_amount_ex_vat, other_amount_ex_vat, total_amount_ex_vat,
                rut_enabled, rut_basis_amount, rut_amount, vat_amount, total_amount_inc_vat, amount_to_pay,
                reverse_charge_text, ready_for_invoicing, fortnox_customer_number, fortnox_document_number,
                fortnox_invoice_number, fortnox_last_sync_at, fortnox_sync_error, export_error, exported_at, created_at, updated_at
             ) VALUES (
                :id, :customer_id, :job_id, :quote_id, :organization_id, :status, :quote_number, :customer_type, :billing_vat_mode,
                :invoice_customer_name, :contact_person, :personal_number, :organization_number, :vat_number, :email, :phone,
                :service_address, :service_postal_code, :service_city,
                :invoice_address_1, :invoice_address_2, :invoice_postcode, :invoice_city,
                :invoice_date, :due_date, :service_type, :description,
                :subtotal, :labor_amount_ex_vat, :material_amount_ex_vat, :other_amount_ex_vat, :total_amount_ex_vat,
                :rut_enabled, :rut_basis_amount, :rut_amount, :vat_amount, :total_amount_inc_vat, :amount_to_pay,
                :reverse_charge_text, :ready_for_invoicing, :fortnox_customer_number, :fortnox_document_number,
                :fortnox_invoice_number, :fortnox_last_sync_at, :fortnox_sync_error, :export_error, :exported_at, :created_at, :updated_at
             )'
        );
    } else {
        $statement = $pdo->prepare(
            'UPDATE invoice_bases SET
                customer_id = :customer_id, job_id = :job_id, quote_id = :quote_id, organization_id = :organization_id, status = :status, quote_number = :quote_number,
                customer_type = :customer_type, billing_vat_mode = :billing_vat_mode, invoice_customer_name = :invoice_customer_name,
                contact_person = :contact_person, personal_number = :personal_number, organization_number = :organization_number,
                vat_number = :vat_number,
                email = :email, phone = :phone, service_address = :service_address, service_postal_code = :service_postal_code,
                service_city = :service_city, invoice_address_1 = :invoice_address_1, invoice_address_2 = :invoice_address_2,
                invoice_postcode = :invoice_postcode, invoice_city = :invoice_city, invoice_date = :invoice_date,
                due_date = :due_date, service_type = :service_type, description = :description, subtotal = :subtotal,
                labor_amount_ex_vat = :labor_amount_ex_vat, material_amount_ex_vat = :material_amount_ex_vat,
                other_amount_ex_vat = :other_amount_ex_vat, total_amount_ex_vat = :total_amount_ex_vat,
                rut_enabled = :rut_enabled, rut_basis_amount = :rut_basis_amount, rut_amount = :rut_amount,
                vat_amount = :vat_amount, total_amount_inc_vat = :total_amount_inc_vat, amount_to_pay = :amount_to_pay,
                reverse_charge_text = :reverse_charge_text, ready_for_invoicing = :ready_for_invoicing,
                fortnox_customer_number = :fortnox_customer_number, fortnox_document_number = :fortnox_document_number,
                fortnox_invoice_number = :fortnox_invoice_number, fortnox_last_sync_at = :fortnox_last_sync_at,
                fortnox_sync_error = :fortnox_sync_error, export_error = :export_error, exported_at = :exported_at,
                created_at = :created_at, updated_at = :updated_at
             WHERE id = :id'
        );
    }

    $statement->execute([
        'id' => $basisRecord['id'],
        'customer_id' => $basisRecord['customer_id'],
        'job_id' => $basisRecord['job_id'],
        'quote_id' => $basisRecord['quote_id'],
        'organization_id' => $basisRecord['organization_id'],
        'status' => $basisRecord['status'],
        'quote_number' => $basisRecord['quote_number'],
        'customer_type' => $basisRecord['customer_type'],
        'billing_vat_mode' => $basisRecord['billing_vat_mode'],
        'invoice_customer_name' => $basisRecord['invoice_customer_name'],
        'contact_person' => $basisRecord['contact_person'],
        'personal_number' => $basisRecord['personal_number'],
        'organization_number' => $basisRecord['organization_number'],
        'vat_number' => $basisRecord['vat_number'],
        'email' => $basisRecord['email'],
        'phone' => $basisRecord['phone'],
        'service_address' => $basisRecord['service_address'],
        'service_postal_code' => $basisRecord['service_postal_code'],
        'service_city' => $basisRecord['service_city'],
        'invoice_address_1' => $basisRecord['invoice_address_1'],
        'invoice_address_2' => $basisRecord['invoice_address_2'],
        'invoice_postcode' => $basisRecord['invoice_postcode'],
        'invoice_city' => $basisRecord['invoice_city'],
        'invoice_date' => $basisRecord['invoice_date'] !== '' ? $basisRecord['invoice_date'] : null,
        'due_date' => $basisRecord['due_date'] !== '' ? $basisRecord['due_date'] : null,
        'service_type' => $basisRecord['service_type'],
        'description' => $basisRecord['description'],
        'subtotal' => $basisRecord['subtotal'],
        'labor_amount_ex_vat' => $basisRecord['labor_amount_ex_vat'],
        'material_amount_ex_vat' => $basisRecord['material_amount_ex_vat'],
        'other_amount_ex_vat' => $basisRecord['other_amount_ex_vat'],
        'total_amount_ex_vat' => $basisRecord['total_amount_ex_vat'],
        'rut_enabled' => $basisRecord['rut_enabled'] ? 1 : 0,
        'rut_basis_amount' => $basisRecord['rut_basis_amount'],
        'rut_amount' => $basisRecord['rut_amount'],
        'vat_amount' => $basisRecord['vat_amount'],
        'total_amount_inc_vat' => $basisRecord['total_amount_inc_vat'],
        'amount_to_pay' => $basisRecord['amount_to_pay'],
        'reverse_charge_text' => $basisRecord['reverse_charge_text'],
        'ready_for_invoicing' => $basisRecord['ready_for_invoicing'] ? 1 : 0,
        'fortnox_customer_number' => $basisRecord['fortnox_customer_number'] !== '' ? $basisRecord['fortnox_customer_number'] : null,
        'fortnox_document_number' => $basisRecord['fortnox_document_number'] !== '' ? $basisRecord['fortnox_document_number'] : null,
        'fortnox_invoice_number' => $basisRecord['fortnox_invoice_number'] !== '' ? $basisRecord['fortnox_invoice_number'] : null,
        'fortnox_last_sync_at' => $basisRecord['fortnox_last_sync_at'] !== '' ? $basisRecord['fortnox_last_sync_at'] : null,
        'fortnox_sync_error' => $basisRecord['fortnox_sync_error'] !== '' ? $basisRecord['fortnox_sync_error'] : null,
        'export_error' => $basisRecord['export_error'] !== '' ? $basisRecord['export_error'] : null,
        'exported_at' => $basisRecord['exported_at'] !== '' ? $basisRecord['exported_at'] : null,
        'created_at' => $basisRecord['created_at'],
        'updated_at' => $basisRecord['updated_at'],
    ]);

    $deleteItems = $pdo->prepare('DELETE FROM invoice_base_items WHERE invoice_base_id = :invoice_base_id');
    $deleteItems->execute(['invoice_base_id' => (int)$basisRecord['id']]);
    $nextItemId = mysql_next_table_id($pdo, 'invoice_base_items');
    $insertItem = $pdo->prepare(
        'INSERT INTO invoice_base_items (
            id, invoice_base_id, job_item_id, sort_order, item_type, description, quantity, unit, unit_price, vat_rate, is_rut_eligible, line_total, created_at, updated_at
         ) VALUES (
            :id, :invoice_base_id, :job_item_id, :sort_order, :item_type, :description, :quantity, :unit, :unit_price, :vat_rate, :is_rut_eligible, :line_total, :created_at, :updated_at
         )'
    );

    foreach (($job['job_items'] ?? []) as $jobItem) {
        $row = normalize_invoice_base_item([
            'id' => $nextItemId++,
            'invoice_base_id' => (int)$basisRecord['id'],
            'job_item_id' => (int)($jobItem['id'] ?? 0) ?: null,
            'sort_order' => (int)($jobItem['sort_order'] ?? 0),
            'item_type' => (string)($jobItem['item_type'] ?? 'service'),
            'description' => (string)($jobItem['description'] ?? ''),
            'quantity' => (float)($jobItem['quantity'] ?? 1),
            'unit' => (string)($jobItem['unit'] ?? 'st'),
            'unit_price' => (float)($jobItem['unit_price'] ?? 0),
            'vat_rate' => (float)($jobItem['vat_rate'] ?? 0),
            'is_rut_eligible' => !empty($jobItem['is_rut_eligible']),
            'line_total' => (float)($jobItem['line_total'] ?? 0),
            'created_at' => (string)($jobItem['created_at'] ?? now_iso()),
            'updated_at' => (string)($jobItem['updated_at'] ?? now_iso()),
        ]);

        $insertItem->execute([
            'id' => $row['id'],
            'invoice_base_id' => $row['invoice_base_id'],
            'job_item_id' => $row['job_item_id'],
            'sort_order' => $row['sort_order'],
            'item_type' => $row['item_type'],
            'description' => $row['description'],
            'quantity' => $row['quantity'],
            'unit' => $row['unit'],
            'unit_price' => $row['unit_price'],
            'vat_rate' => $row['vat_rate'],
            'is_rut_eligible' => $row['is_rut_eligible'] ? 1 : 0,
            'line_total' => $row['line_total'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }

    recalculateInvoiceBaseTotals($pdo, (int)$basisRecord['id']);
}

function mysql_sync_invoice_bases_for_customer(PDO $pdo, int $customerId): void
{
    if ($customerId <= 0) {
        return;
    }

    $statement = $pdo->prepare('SELECT id FROM jobs WHERE customer_id = :customer_id');
    $statement->execute(['customer_id' => $customerId]);

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $jobId) {
        $jobId = (int)$jobId;
        if ($jobId > 0) {
            mysql_sync_invoice_basis_for_job($pdo, $jobId);
        }
    }
}

function mysql_persist_job_record(PDO $pdo, array $jobRecord, bool $isInsert): array
{
    $params = mysql_job_params($jobRecord);

    if ($isInsert) {
        $statement = $pdo->prepare(
            'INSERT INTO jobs (
                id, customer_id, quote_id, organization_id, region_id, status, planned_start_date, planned_end_date, completed_at,
                work_address_1, work_address_2, work_postcode, work_city, internal_notes, customer_notes,
                service_type, description, scheduled_date, scheduled_time, completed_date, assigned_to,
                ready_for_invoicing, invoice_status, notes, created_at, updated_at
             ) VALUES (
                :id, :customer_id, :quote_id, :organization_id, :region_id, :status, :planned_start_date, :planned_end_date, :completed_at,
                :work_address_1, :work_address_2, :work_postcode, :work_city, :internal_notes, :customer_notes,
                :service_type, :description, :scheduled_date, :scheduled_time, :completed_date, :assigned_to,
                :ready_for_invoicing, :invoice_status, :notes, :created_at, :updated_at
             )'
        );
    } else {
        $statement = $pdo->prepare(
            'UPDATE jobs SET
                customer_id = :customer_id,
                quote_id = :quote_id,
                organization_id = :organization_id,
                region_id = :region_id,
                status = :status,
                planned_start_date = :planned_start_date,
                planned_end_date = :planned_end_date,
                completed_at = :completed_at,
                work_address_1 = :work_address_1,
                work_address_2 = :work_address_2,
                work_postcode = :work_postcode,
                work_city = :work_city,
                internal_notes = :internal_notes,
                customer_notes = :customer_notes,
                service_type = :service_type,
                description = :description,
                scheduled_date = :scheduled_date,
                scheduled_time = :scheduled_time,
                completed_date = :completed_date,
                assigned_to = :assigned_to,
                ready_for_invoicing = :ready_for_invoicing,
                invoice_status = :invoice_status,
                notes = :notes,
                created_at = :created_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
    }

    $statement->execute($params);
    mysql_sync_job_items($pdo, $jobRecord);
    $recalculated = recalculateJobTotals($pdo, (int)$jobRecord['id']);
    $jobRecord['final_labor_amount_ex_vat'] = $recalculated['final_labor_amount_ex_vat'];
    $jobRecord['final_material_amount_ex_vat'] = $recalculated['final_material_amount_ex_vat'];
    $jobRecord['final_other_amount_ex_vat'] = $recalculated['final_other_amount_ex_vat'];
    $jobRecord['final_total_amount_ex_vat'] = $recalculated['final_total_amount_ex_vat'];
    $jobRecord['final_vat_amount'] = $recalculated['final_vat_amount'];
    $jobRecord['final_total_amount_inc_vat'] = $recalculated['final_total_amount_inc_vat'];
    $jobRecord['final_rut_amount'] = $recalculated['final_rut_amount'];
    $jobRecord['final_reverse_charge_text'] = $recalculated['final_reverse_charge_text'];
    return $jobRecord;
}

function mysql_touch_customer_activity_by_id(PDO $pdo, int $customerId): void
{
    $statement = $pdo->prepare('UPDATE customers SET last_activity = NOW(), updated_at = NOW() WHERE id = :id');
    $statement->execute(['id' => $customerId]);
}

function mysql_upsert_customer(array $payload, ?int $preferredCustomerId = null): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $existingCustomer = null;
        $customerType = normalize_customer_type((string)($payload['customerType'] ?? 'private'));
        $payloadIdentifier = in_array($customerType, ['company', 'association'], true)
            ? trim((string)($payload['organizationNumber'] ?? ''))
            : trim((string)($payload['personalNumber'] ?? ''));

        if ($preferredCustomerId !== null && $preferredCustomerId > 0) {
            $preferredCustomer = find_by_id($data['customers'], $preferredCustomerId);
            if ($preferredCustomer !== null) {
                $preferredType = (string)($preferredCustomer['customer_type'] ?? 'private');
                $preferredIdentifier = customer_primary_identifier($preferredCustomer);
                $identifierMatches = $payloadIdentifier === '' || $preferredIdentifier === '' || $preferredIdentifier === $payloadIdentifier;

                if ($preferredType === $customerType && $identifierMatches) {
                    $existingCustomer = $preferredCustomer;
                }
            }
        }

        if ($existingCustomer === null) {
            $existingCustomer = find_matching_customer($data['customers'], $payload);
        }

        $record = build_customer_record($payload, $existingCustomer);
        if ($existingCustomer === null) {
            $record['id'] = mysql_next_table_id($pdo, 'customers');
            mysql_persist_customer_record($pdo, $record, true);
        } else {
            $record['last_activity'] = $existingCustomer['last_activity'] ?? now_iso();
            mysql_persist_customer_record($pdo, $record, false);
        }

        $pdo->commit();
        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function mysql_save_customer(array $payload, ?array $existingCustomer = null): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $record = build_customer_record($payload, $existingCustomer);
        if (($record['customer_type'] ?? 'private') === 'private') {
            $duplicateCustomer = find_customer_by_personal_number(
                $data['customers'] ?? [],
                (string)($record['personal_number'] ?? ''),
                $existingCustomer ? (int)($existingCustomer['id'] ?? 0) : null
            );

            if ($duplicateCustomer !== null) {
                throw new RuntimeException('Det finns redan en kund med samma personnummer.');
            }
        }

        if ($existingCustomer === null) {
            $record['id'] = mysql_next_table_id($pdo, 'customers');
            mysql_persist_customer_record($pdo, $record, true);
        } else {
            $record['last_activity'] = $existingCustomer['last_activity'] ?? now_iso();
            mysql_persist_customer_record($pdo, $record, false);
        }
        mysql_sync_invoice_bases_for_customer($pdo, (int)$record['id']);
        $pdo->commit();
        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function mysql_update_customer_maintenance_dates_for_completed_job(array $customer, array $job): void
{
    if (!customer_has_maintenance_plan($customer)) {
        return;
    }

    $customerId = (int)($customer['id'] ?? 0);
    if ($customerId <= 0) {
        return;
    }

    $completedDate = trim((string)($job['completed_date'] ?? $job['completedDate'] ?? ''));
    if ($completedDate === '') {
        $completedDate = date('Y-m-d');
    }

    try {
        $nextMaintenanceJobDate = (new DateTimeImmutable($completedDate))->modify('+11 months')->format('Y-m-d');
    } catch (Throwable) {
        $nextMaintenanceJobDate = date('Y-m-d', strtotime('+11 months'));
    }

    $pdo = admin_pdo();
    mysql_schedule_next_maintenance_job($pdo, $customer, $job, $nextMaintenanceJobDate);
    mysql_refresh_customer_maintenance_summary($pdo, $customerId);
}

function is_maintenance_job_service_type(string $serviceType): bool
{
    return in_array(trim($serviceType), [
        'Årligt underhåll stenytor',
        'Årligt underhåll trädäck',
        'Årligt underhåll',
    ], true);
}

function mysql_refresh_customer_maintenance_summary(PDO $pdo, int $customerId): void
{
    if ($customerId <= 0) {
        return;
    }

    $latestCompletedStatement = $pdo->prepare(
        'SELECT completed_date
         FROM jobs
         WHERE customer_id = :customer_id
           AND completed_date IS NOT NULL
           AND completed_date <> ""
           AND service_type IN ("Årligt underhåll stenytor", "Årligt underhåll trädäck", "Årligt underhåll")
           AND status IN ("completed", "invoiced")
         ORDER BY completed_date DESC, id DESC
         LIMIT 1'
    );
    $latestCompletedStatement->execute([
        'customer_id' => $customerId,
    ]);
    $lastServiceDate = $latestCompletedStatement->fetchColumn();

    $nextPlannedStatement = $pdo->prepare(
        'SELECT scheduled_date
         FROM jobs
         WHERE customer_id = :customer_id
           AND scheduled_date IS NOT NULL
           AND scheduled_date <> ""
           AND service_type IN ("Årligt underhåll stenytor", "Årligt underhåll trädäck", "Årligt underhåll")
           AND status IN ("planned", "scheduled", "in_progress")
         ORDER BY scheduled_date ASC, id ASC
         LIMIT 1'
    );
    $nextPlannedStatement->execute([
        'customer_id' => $customerId,
    ]);
    $nextServiceDate = $nextPlannedStatement->fetchColumn();

    $statement = $pdo->prepare(
        'UPDATE customers
         SET last_service_date = :last_service_date,
             next_service_date = :next_service_date,
             updated_at = NOW()
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $customerId,
        'last_service_date' => $lastServiceDate !== false ? $lastServiceDate : null,
        'next_service_date' => $nextServiceDate !== false ? $nextServiceDate : null,
    ]);
}

function infer_maintenance_job_service_type(array $job): string
{
    $serviceType = mb_strtolower(trim((string)($job['service_type'] ?? $job['serviceType'] ?? '')));
    $description = mb_strtolower(trim((string)($job['description'] ?? '')));
    $haystack = trim($serviceType . ' ' . $description);

    if (
        str_contains($haystack, 'sten')
        || str_contains($haystack, 'marksten')
        || str_contains($haystack, 'stentvätt')
        || str_contains($haystack, 'stentvatt')
        || str_contains($haystack, 'stenrengöring')
        || str_contains($haystack, 'stenrengoring')
    ) {
        return 'Årligt underhåll stenytor';
    }

    if (
        str_contains($haystack, 'altan')
        || str_contains($haystack, 'trädäck')
        || str_contains($haystack, 'tradack')
        || str_contains($haystack, 'trädack')
        || str_contains($haystack, 'altantvätt')
        || str_contains($haystack, 'altantvatt')
    ) {
        return 'Årligt underhåll trädäck';
    }

    return 'Årligt underhåll';
}

function infer_maintenance_job_description(array $job, string $serviceType): string
{
    return match ($serviceType) {
        'Årligt underhåll stenytor' => 'Planerat återkommande underhåll. Årlig tvätt och genomgång av stenytor enligt tecknat upplägg.',
        'Årligt underhåll trädäck' => 'Planerat återkommande underhåll. Årlig tvätt och genomgång av trädäck enligt tecknat upplägg.',
        default => 'Planerat återkommande underhåll enligt tecknat upplägg.',
    };
}

function maintenance_service_type_kind(string $serviceType): string
{
    return match (trim($serviceType)) {
        'Årligt underhåll trädäck' => 'deck',
        'Årligt underhåll stenytor' => 'stone',
        default => 'any',
    };
}

function mysql_has_future_maintenance_job(PDO $pdo, int $customerId, string $serviceType, string $scheduledDate): bool
{
    $statement = $pdo->prepare(
        'SELECT id
         FROM jobs
         WHERE customer_id = :customer_id
           AND service_type = :service_type
           AND scheduled_date >= :scheduled_date
           AND status IN ("planned", "scheduled", "in_progress")
         LIMIT 1'
    );
    $statement->execute([
        'customer_id' => $customerId,
        'service_type' => $serviceType,
        'scheduled_date' => $scheduledDate,
    ]);

    return (bool)$statement->fetchColumn();
}

function mysql_schedule_next_maintenance_job(PDO $pdo, array $customer, array $job, string $scheduledDate): void
{
    $customerId = (int)($customer['id'] ?? 0);
    if ($customerId <= 0 || $scheduledDate === '') {
        return;
    }

    $serviceType = infer_maintenance_job_service_type($job);
    if ($serviceType === '') {
        return;
    }

    if (!customer_has_maintenance_plan($customer, maintenance_service_type_kind($serviceType))) {
        return;
    }

    if (mysql_has_future_maintenance_job($pdo, $customerId, $serviceType, $scheduledDate)) {
        return;
    }

    $payload = [
        'customerId' => $customerId,
        'quoteId' => '',
        'organizationId' => (string)($customer['organization_id'] ?? ''),
        'regionId' => (string)($customer['region_id'] ?? ''),
        'serviceType' => $serviceType,
        'description' => infer_maintenance_job_description($job, $serviceType),
        'scheduledDate' => $scheduledDate,
        'scheduledTime' => '',
        'completedDate' => '',
        'assignedTo' => '',
        'status' => 'planned',
        'finalLaborAmountExVat' => 0,
        'finalMaterialAmountExVat' => 0,
        'finalOtherAmountExVat' => 0,
        'readyForInvoicing' => false,
        'notes' => 'Skapat automatiskt från tecknat årligt underhåll.',
    ];

    $nextJob = build_job_record($payload, $customer, null);
    $nextJob['id'] = mysql_next_table_id($pdo, 'jobs');
    mysql_persist_job_record($pdo, $nextJob, true);
}

function mysql_save_quote(array $payload, array $customerPayload, ?array $existingQuote = null, ?int $preferredCustomerId = null): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $customers = $data['customers'] ?? [];
        $existingCustomer = null;
        $customerType = normalize_customer_type((string)($customerPayload['customerType'] ?? 'private'));
        $payloadIdentifier = in_array($customerType, ['company', 'association'], true)
            ? trim((string)($customerPayload['organizationNumber'] ?? ''))
            : trim((string)($customerPayload['personalNumber'] ?? ''));

        if ($preferredCustomerId !== null && $preferredCustomerId > 0) {
            $preferredCustomer = find_by_id($customers, $preferredCustomerId);
            if ($preferredCustomer !== null) {
                $preferredType = (string)($preferredCustomer['customer_type'] ?? 'private');
                $preferredIdentifier = customer_primary_identifier($preferredCustomer);
                $identifierMatches = $payloadIdentifier === '' || $preferredIdentifier === '' || $preferredIdentifier === $payloadIdentifier;
                if ($preferredType === $customerType && $identifierMatches) {
                    $existingCustomer = $preferredCustomer;
                }
            }
        }

        if ($existingCustomer === null) {
            $existingCustomer = find_matching_customer($customers, $customerPayload);
        }

        $customer = build_customer_record($customerPayload, $existingCustomer);
        if ($existingCustomer === null) {
            $customer['id'] = mysql_next_table_id($pdo, 'customers');
            mysql_persist_customer_record($pdo, $customer, true);
        } else {
            $customer['last_activity'] = $existingCustomer['last_activity'] ?? now_iso();
            mysql_persist_customer_record($pdo, $customer, false);
        }

        $payload['customerId'] = (int)$customer['id'];
        if ($existingQuote === null) {
            $payload['quoteNumber'] = mysql_reserve_next_quote_number($pdo);
        }
        $quote = build_quote_record($payload, $customer, $existingQuote);
        if ($existingQuote === null) {
            $quote['id'] = mysql_next_table_id($pdo, 'quotes');
            mysql_persist_quote_record($pdo, $quote, true);
        } else {
            mysql_persist_quote_record($pdo, $quote, false);
        }

        mysql_touch_customer_activity_by_id($pdo, (int)$customer['id']);
        $pdo->commit();
        return $quote;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function mysql_save_job(array $payload, array $customer, ?array $existingJob = null): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $job = build_job_record($payload, $customer, $existingJob);
        if ($existingJob === null) {
            $job['id'] = mysql_next_table_id($pdo, 'jobs');
            mysql_persist_job_record($pdo, $job, true);
        } else {
            mysql_persist_job_record($pdo, $job, false);
        }

        mysql_touch_customer_activity_by_id($pdo, (int)$customer['id']);
        $pdo->commit();
        return $job;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function next_id(array $items): int
{
    $ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);

    return ($ids ? max($ids) : 0) + 1;
}

function quote_year_from_date(?string $date): string
{
    $timestamp = $date ? strtotime($date) : false;

    return $timestamp ? date('Y', $timestamp) : date('Y');
}

function format_quote_number(string $year, int $sequence): string
{
    return sprintf('%s-%04d', $year, $sequence);
}

function next_quote_number(array $quotes, ?string $createdAt = null): string
{
    $year = quote_year_from_date($createdAt);
    $maxSequence = 0;

    foreach ($quotes as $quote) {
        $quoteNumber = (string)($quote['quote_number'] ?? $quote['quoteNumber'] ?? '');
        if (preg_match('/^' . preg_quote($year, '/') . '-(\d{4,})$/', $quoteNumber, $matches) === 1) {
            $maxSequence = max($maxSequence, (int)$matches[1]);
        }
    }

    return format_quote_number($year, $maxSequence + 1);
}

function ensure_quote_numbers(array $quotes): array
{
    usort($quotes, static function (array $left, array $right): int {
        $leftCreated = (string)($left['created_at'] ?? $left['createdAt'] ?? '');
        $rightCreated = (string)($right['created_at'] ?? $right['createdAt'] ?? '');
        $byCreated = strcmp($leftCreated, $rightCreated);

        if ($byCreated !== 0) {
            return $byCreated;
        }

        return ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
    });

    $result = [];
    $seen = [];

    foreach ($quotes as $quote) {
        $quoteNumber = (string)($quote['quote_number'] ?? $quote['quoteNumber'] ?? '');
        $year = quote_year_from_date((string)($quote['created_at'] ?? $quote['createdAt'] ?? now_iso()));
        $hasValidFormat = preg_match('/^' . preg_quote($year, '/') . '-\d{4,}$/', $quoteNumber) === 1;

        if ($quoteNumber === '' || !$hasValidFormat || isset($seen[$quoteNumber])) {
            $quoteNumber = next_quote_number($result, (string)($quote['created_at'] ?? $quote['createdAt'] ?? now_iso()));
            $quote['quote_number'] = $quoteNumber;
        }

        $seen[$quoteNumber] = true;
        $result[] = $quote;
    }

    return $result;
}

function find_by_id(array $items, int $id): ?array
{
    foreach ($items as $item) {
        if ((int)($item['id'] ?? 0) === $id) {
            return $item;
        }
    }

    return null;
}

function customer_primary_identifier(array $customer): string
{
    if (in_array(($customer['customer_type'] ?? 'private'), ['company', 'association'], true)) {
        return trim((string)($customer['organization_number'] ?? ''));
    }

    return trim((string)($customer['personal_number'] ?? ''));
}

function canonical_personal_number(string $value): string
{
    $digits = preg_replace('/\D+/', '', trim($value));
    if ($digits === null) {
        return '';
    }

    if (strlen($digits) === 12) {
        return substr($digits, -10);
    }

    return $digits;
}

function find_customer_by_personal_number(array $customers, string $personalNumber, ?int $ignoreId = null): ?array
{
    $candidate = canonical_personal_number($personalNumber);
    if ($candidate === '') {
        return null;
    }

    foreach ($customers as $customer) {
        if ($ignoreId !== null && (int)($customer['id'] ?? 0) === $ignoreId) {
            continue;
        }

        if (($customer['customer_type'] ?? 'private') !== 'private') {
            continue;
        }

        $existing = canonical_personal_number((string)($customer['personal_number'] ?? ''));
        if ($existing !== '' && $existing === $candidate) {
            return $customer;
        }
    }

    return null;
}

function find_matching_customer(array $customers, array $payload, ?int $ignoreId = null): ?array
{
    $customerType = normalize_customer_type((string)($payload['customerType'] ?? 'private'));
    $primaryIdentifier = in_array($customerType, ['company', 'association'], true)
        ? trim((string)($payload['organizationNumber'] ?? ''))
        : canonical_personal_number((string)($payload['personalNumber'] ?? ''));
    $email = mb_strtolower(trim((string)($payload['email'] ?? '')), 'UTF-8');
    $name = mb_strtolower(trim((string)($payload['name'] ?? '')), 'UTF-8');
    $companyName = mb_strtolower(trim((string)($payload['companyName'] ?? '')), 'UTF-8');
    $associationName = mb_strtolower(trim((string)($payload['associationName'] ?? '')), 'UTF-8');

    foreach ($customers as $customer) {
        if ($ignoreId !== null && (int)($customer['id'] ?? 0) === $ignoreId) {
            continue;
        }

        if (($customer['customer_type'] ?? 'private') !== $customerType) {
            continue;
        }

        if ($primaryIdentifier !== '') {
            $existingIdentifier = in_array($customerType, ['company', 'association'], true)
                ? customer_primary_identifier($customer)
                : canonical_personal_number(customer_primary_identifier($customer));
            if ($existingIdentifier === $primaryIdentifier) {
                return $customer;
            }
        }

        if (in_array($customerType, ['company', 'association'], true) && trim((string)($payload['vatNumber'] ?? '')) !== '') {
            if (trim((string)($customer['vat_number'] ?? '')) === trim((string)($payload['vatNumber'] ?? ''))) {
                return $customer;
            }
        }

        $customerEmail = mb_strtolower(trim((string)($customer['email'] ?? '')), 'UTF-8');
        if ($email !== '' && $customerEmail === $email) {
            if ($customerType === 'company') {
                $existingCompanyName = mb_strtolower(trim((string)($customer['company_name'] ?? '')), 'UTF-8');
                if ($companyName === '' || $existingCompanyName === $companyName) {
                    return $customer;
                }
            } elseif ($customerType === 'association') {
                $existingAssociationName = mb_strtolower(trim((string)($customer['association_name'] ?? '')), 'UTF-8');
                if ($associationName === '' || $existingAssociationName === $associationName) {
                    return $customer;
                }
            } else {
                $existingName = mb_strtolower(trim((string)($customer['name'] ?? '')), 'UTF-8');
                if ($name === '' || $existingName === $name) {
                    return $customer;
                }
            }
        }
    }

    return null;
}

function upsert_customer(array &$data, array $payload, ?int $preferredCustomerId = null): array
{
    $existingCustomer = null;
    $customerType = normalize_customer_type((string)($payload['customerType'] ?? 'private'));
    $payloadIdentifier = in_array($customerType, ['company', 'association'], true)
        ? trim((string)($payload['organizationNumber'] ?? ''))
        : trim((string)($payload['personalNumber'] ?? ''));

    if ($preferredCustomerId !== null && $preferredCustomerId > 0) {
        $preferredCustomer = find_by_id($data['customers'], $preferredCustomerId);
        if ($preferredCustomer !== null) {
            $preferredType = (string)($preferredCustomer['customer_type'] ?? 'private');
            $preferredIdentifier = customer_primary_identifier($preferredCustomer);
            $identifierMatches = $payloadIdentifier === '' || $preferredIdentifier === '' || $preferredIdentifier === $payloadIdentifier;

            if ($preferredType === $customerType && $identifierMatches) {
                $existingCustomer = $preferredCustomer;
            }
        }
    }

    if ($existingCustomer === null) {
        $existingCustomer = find_matching_customer($data['customers'], $payload);
    }

    $record = build_customer_record($payload, $existingCustomer);

    if ($existingCustomer === null) {
        $record['id'] = next_id($data['customers']);
        $data['customers'][] = $record;

        return $record;
    }

    foreach ($data['customers'] as $index => $entry) {
        if ((int)$entry['id'] === (int)$existingCustomer['id']) {
            $record['last_activity'] = $entry['last_activity'] ?? now_iso();
            $data['customers'][$index] = $record;
            break;
        }
    }

    return $record;
}

function customer_name(array $data, int $customerId): string
{
    $customer = find_by_id($data['customers'] ?? [], $customerId);
    if (!$customer) {
        return 'Okänd kund';
    }

    if (($customer['customer_type'] ?? 'private') === 'company' && ($customer['company_name'] ?? '') !== '') {
        return (string)$customer['company_name'];
    }

    if (($customer['customer_type'] ?? 'private') === 'association' && ($customer['association_name'] ?? '') !== '') {
        return (string)$customer['association_name'];
    }

    return (string)$customer['name'];
}

function update_customer_activity(array &$data, int $customerId): void
{
    foreach ($data['customers'] as &$customer) {
        if ((int)$customer['id'] === $customerId) {
            $customer['last_activity'] = now_iso();
            $customer['updated_at'] = now_iso();
            break;
        }
    }
    unset($customer);
}

function find_user_by_username(array $data, string $username): ?array
{
    foreach ($data['users'] ?? [] as $user) {
        if (($user['username'] ?? '') === $username) {
            return $user;
        }
    }

    return null;
}

function find_user_by_id(array $data, int $id): ?array
{
    foreach ($data['users'] ?? [] as $user) {
        if ((int)($user['id'] ?? 0) === $id) {
            return $user;
        }
    }

    return null;
}

function find_region_by_id(array $data, int $id): ?array
{
    foreach ($data['regions'] ?? [] as $region) {
        if ((int)($region['id'] ?? 0) === $id) {
            return $region;
        }
    }

    return null;
}

function find_organization_by_id(array $data, int $id): ?array
{
    foreach ($data['organizations'] ?? [] as $organization) {
        if ((int)($organization['id'] ?? 0) === $id) {
            return $organization;
        }
    }

    return null;
}

function find_web_quote_request_by_id(array $data, int $id): ?array
{
    foreach ($data['web_quote_requests'] ?? [] as $request) {
        if ((int)($request['id'] ?? 0) === $id) {
            return $request;
        }
    }

    return null;
}

function memberships_for_user(array $data, int $userId): array
{
    return array_values(array_filter(
        $data['organization_memberships'] ?? [],
        static fn(array $membership): bool => (int)($membership['user_id'] ?? 0) === $userId
    ));
}

function find_product_by_id(array $data, int $id): ?array
{
    foreach ($data['products'] ?? [] as $product) {
        if ((int)($product['id'] ?? 0) === $id) {
            return $product;
        }
    }

    return null;
}

function find_service_package_by_id(array $data, int $id): ?array
{
    foreach ($data['service_packages'] ?? [] as $package) {
        if ((int)($package['id'] ?? 0) === $id) {
            return $package;
        }
    }

    return null;
}

function package_items_for_package(array $data, int $packageId): array
{
    $items = array_values(array_filter(
        $data['service_package_items'] ?? [],
        static fn(array $item): bool => (int)($item['package_id'] ?? 0) === $packageId
    ));

    usort($items, static fn(array $a, array $b): int => (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0));

    return $items;
}

function mysql_save_user(array $payload, ?array $existingUser = null): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $username = trim((string)($payload['username'] ?? ''));

        foreach ($data['users'] ?? [] as $user) {
            if ((string)($user['username'] ?? '') !== $username) {
                continue;
            }

            if ($existingUser !== null && (int)($user['id'] ?? 0) === (int)($existingUser['id'] ?? 0)) {
                continue;
            }

            throw new RuntimeException('Användarnamnet används redan.');
        }

        $record = build_user_record($payload, $existingUser);
        if ($existingUser === null) {
            $record['id'] = mysql_next_table_id($pdo, 'users');
            mysql_persist_user_record($pdo, $record, true);
        } else {
            mysql_persist_user_record($pdo, $record, false);
        }
        mysql_sync_user_memberships($pdo, $record, $record['effective_roles'] ?? [$record['role'] ?? USER_ROLE_WORKER]);

        $pdo->commit();

        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_save_organization(array $payload, ?array $existingOrganization = null): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $name = trim((string)($payload['name'] ?? ''));
        $slug = normalize_region_slug((string)($payload['slug'] ?? ''), $name);

        foreach ($data['organizations'] ?? [] as $organization) {
            $sameRecord = $existingOrganization !== null && (int)($organization['id'] ?? 0) === (int)($existingOrganization['id'] ?? 0);
            if ($sameRecord) {
                continue;
            }

            if (mb_strtolower(trim((string)($organization['name'] ?? '')), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                throw new RuntimeException('Organisationsnamnet används redan.');
            }

            if ((string)($organization['slug'] ?? '') === $slug) {
                throw new RuntimeException('Organisationssluggen används redan.');
            }
        }

        $record = build_organization_record($payload, $existingOrganization);
        if ($existingOrganization === null) {
            $record['id'] = mysql_next_table_id($pdo, 'organizations');
            mysql_persist_organization_record($pdo, $record, true);
        } else {
            if ((int)($record['parent_organization_id'] ?? 0) === (int)($record['id'] ?? 0)) {
                throw new RuntimeException('En organisation kan inte vara sin egen förälder.');
            }
            mysql_persist_organization_record($pdo, $record, false);
        }

        $pdo->commit();

        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_create_web_quote_request(array $payload): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $regions = $data['regions'] ?? [];
        $organizations = $data['organizations'] ?? [];
        $selectedRegion = infer_region_from_postcode(
            trim((string)($payload['servicePostalCode'] ?? '')),
            $regions
        );
        $matchedOrganization = null;
        $assignmentBasis = '';

        if ($selectedRegion !== null && !empty($selectedRegion['is_active'])) {
            $matchedOrganization = find_active_organization_for_region($organizations, (int)($selectedRegion['id'] ?? 0));
            if ($matchedOrganization !== null) {
                $assignmentBasis = 'region';
            }
        }

        if ($matchedOrganization === null) {
            $matchedOrganization = find_dalarna_fallback_organization($organizations, $regions);
            if ($matchedOrganization !== null) {
                $assignmentBasis = 'fallback';
            }
        }

        $now = now_iso();

        $record = normalize_web_quote_request([
            'id' => mysql_next_table_id($pdo, 'web_quote_requests'),
            'name' => trim((string)($payload['name'] ?? '')),
            'phone' => trim((string)($payload['phone'] ?? '')),
            'email' => trim((string)($payload['email'] ?? '')),
            'service_address' => trim((string)($payload['serviceAddress'] ?? '')),
            'service_postcode' => trim((string)($payload['servicePostalCode'] ?? '')),
            'service_city' => trim((string)($payload['serviceCity'] ?? '')),
            'message' => trim((string)($payload['message'] ?? '')),
            'source_page' => trim((string)($payload['sourcePage'] ?? 'website')),
            'region_id' => $selectedRegion !== null ? (int)($selectedRegion['id'] ?? 0) : null,
            'requested_region_name' => $selectedRegion !== null ? (string)($selectedRegion['name'] ?? '') : '',
            'organization_id' => $matchedOrganization !== null ? (int)($matchedOrganization['id'] ?? 0) : null,
            'assignment_basis' => $assignmentBasis,
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        mysql_persist_web_quote_request_record($pdo, $record, true);
        $pdo->commit();

        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_update_web_quote_request_status(int $requestId, string $status, string $handledByUsername = ''): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $existingRequest = find_web_quote_request_by_id($data, $requestId);
        if ($existingRequest === null) {
            throw new RuntimeException('Förfrågan kunde inte hittas.');
        }

        $normalizedStatus = in_array($status, ['new', 'handled', 'archived'], true) ? $status : 'new';
        $record = normalize_web_quote_request(array_merge($existingRequest, [
            'status' => $normalizedStatus,
            'handled_by_username' => $normalizedStatus === 'new' ? '' : $handledByUsername,
            'handled_at' => $normalizedStatus === 'new' ? '' : now_iso(),
            'updated_at' => now_iso(),
        ]));

        mysql_persist_web_quote_request_record($pdo, $record, false);
        $pdo->commit();

        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_save_region(array $payload, ?array $existingRegion = null): array
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $name = trim((string)($payload['name'] ?? ''));
        $slug = normalize_region_slug((string)($payload['slug'] ?? ''), $name);

        foreach ($data['regions'] ?? [] as $region) {
            $sameRecord = $existingRegion !== null && (int)($region['id'] ?? 0) === (int)($existingRegion['id'] ?? 0);
            if ($sameRecord) {
                continue;
            }

            if (mb_strtolower(trim((string)($region['name'] ?? '')), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                throw new RuntimeException('Regionsnamnet används redan.');
            }

            if ((string)($region['slug'] ?? '') === $slug) {
                throw new RuntimeException('Regionssluggen används redan.');
            }
        }

        $record = build_region_record($payload, $existingRegion);
        if ($existingRegion === null) {
            $record['id'] = mysql_next_table_id($pdo, 'regions');
            mysql_persist_region_record($pdo, $record, true);
        } else {
            mysql_persist_region_record($pdo, $record, false);
        }

        $pdo->commit();

        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_delete_region(int $regionId): void
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $region = find_region_by_id($data, $regionId);
        if ($region === null) {
            throw new RuntimeException('Regionen kunde inte hittas.');
        }

        foreach (($data['users'] ?? []) as $user) {
            if ((int)($user['region_id'] ?? 0) === $regionId) {
                throw new RuntimeException('Regionen används av en eller flera användare.');
            }
        }
        foreach (($data['customers'] ?? []) as $customer) {
            if ((int)($customer['region_id'] ?? 0) === $regionId) {
                throw new RuntimeException('Regionen används av en eller flera kunder.');
            }
        }
        foreach (($data['jobs'] ?? []) as $job) {
            if ((int)($job['region_id'] ?? 0) === $regionId) {
                throw new RuntimeException('Regionen används av ett eller flera jobb.');
            }
        }

        $statement = $pdo->prepare('DELETE FROM regions WHERE id = :id');
        $statement->execute(['id' => $regionId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_delete_organization(int $organizationId): void
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $organization = find_organization_by_id($data, $organizationId);

        if ($organization === null) {
            throw new RuntimeException('Organisationen kunde inte hittas.');
        }

        foreach (['users', 'customers', 'quotes', 'jobs', 'invoice_bases'] as $entityKey) {
            foreach ($data[$entityKey] ?? [] as $record) {
                if ((int)($record['organization_id'] ?? 0) === $organizationId) {
                    throw new RuntimeException('Organisationen används redan och kan inte raderas.');
                }
            }
        }

        foreach ($data['organization_memberships'] ?? [] as $membership) {
            if ((int)($membership['organization_id'] ?? 0) === $organizationId) {
                throw new RuntimeException('Organisationen används redan och kan inte raderas.');
            }
        }

        foreach ($data['organizations'] ?? [] as $childOrganization) {
            if ((int)($childOrganization['parent_organization_id'] ?? 0) === $organizationId) {
                throw new RuntimeException('Organisationen har underenheter och kan inte raderas.');
            }
        }

        $statement = $pdo->prepare('DELETE FROM organizations WHERE id = :id');
        $statement->execute(['id' => $organizationId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function active_admin_count(array $users): int
{
    return count(array_filter($users, static fn(array $user): bool => in_array(USER_ROLE_ADMIN, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true) && !empty($user['is_active'])));
}

function mysql_set_user_active(int $userId, bool $isActive): void
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $user = find_user_by_id($data, $userId);

        if ($user === null) {
            throw new RuntimeException('Användaren kunde inte hittas.');
        }

        if ((int)($user['id'] ?? 0) === current_user_id() && !$isActive) {
            throw new RuntimeException('Du kan inte blockera ditt eget konto.');
        }

        if (!$isActive && in_array(USER_ROLE_ADMIN, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true) && active_admin_count($data['users'] ?? []) <= 1) {
            throw new RuntimeException('Det måste finnas minst en aktiv admin.');
        }

        $record = $user;
        $record['is_active'] = $isActive;
        $record['updated_at'] = now_iso();
        mysql_persist_user_record($pdo, normalize_user($record), false);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_delete_user(int $userId): void
{
    $pdo = admin_pdo();
    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $user = find_user_by_id($data, $userId);

        if ($user === null) {
            throw new RuntimeException('Användaren kunde inte hittas.');
        }

        if ((int)($user['id'] ?? 0) === current_user_id()) {
            throw new RuntimeException('Du kan inte radera ditt eget konto.');
        }

        if (in_array(USER_ROLE_ADMIN, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true) && active_admin_count($data['users'] ?? []) <= 1) {
            throw new RuntimeException('Det måste finnas minst en aktiv admin.');
        }

        $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_products_available(): bool
{
    $pdo = admin_pdo();

    return mysql_table_exists($pdo, 'products');
}

function mysql_service_packages_available(): bool
{
    $pdo = admin_pdo();

    return mysql_table_exists($pdo, 'service_packages') && mysql_table_exists($pdo, 'service_package_items');
}

function mysql_save_product(array $payload, ?array $existingProduct = null): array
{
    $pdo = admin_pdo();

    if (!mysql_table_exists($pdo, 'products')) {
        throw new RuntimeException('Produkter-tabellen saknas. Kör databasuppgraderingen först.');
    }

    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $name = trim((string)($payload['name'] ?? ''));

        foreach ($data['products'] ?? [] as $product) {
            if (mb_strtolower((string)($product['name'] ?? ''), 'UTF-8') !== mb_strtolower($name, 'UTF-8')) {
                continue;
            }

            if ($existingProduct !== null && (int)($product['id'] ?? 0) === (int)($existingProduct['id'] ?? 0)) {
                continue;
            }

            throw new RuntimeException('Det finns redan en produkt med samma namn.');
        }

        $record = build_product_record($payload, $existingProduct);
        if ($existingProduct === null) {
            $record['id'] = mysql_next_table_id($pdo, 'products');
            mysql_persist_product_record($pdo, $record, true);
        } else {
            mysql_persist_product_record($pdo, $record, false);
        }

        $pdo->commit();

        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_delete_product(int $productId): void
{
    $pdo = admin_pdo();

    if (!mysql_table_exists($pdo, 'products')) {
        throw new RuntimeException('Produkter-tabellen saknas. Kör databasuppgraderingen först.');
    }

    $statement = $pdo->prepare('DELETE FROM products WHERE id = :id');
    $statement->execute(['id' => $productId]);
}

function mysql_save_service_package(array $payload, ?array $existingPackage = null): array
{
    $pdo = admin_pdo();

    if (!mysql_service_packages_available()) {
        throw new RuntimeException('Paket-tabellerna saknas. Kör databasuppgraderingen först.');
    }

    $pdo->beginTransaction();

    try {
        $data = load_data_mysql();
        $name = trim((string)($payload['name'] ?? ''));

        foreach ($data['service_packages'] ?? [] as $package) {
            if (mb_strtolower((string)($package['name'] ?? ''), 'UTF-8') !== mb_strtolower($name, 'UTF-8')) {
                continue;
            }

            if ($existingPackage !== null && (int)($package['id'] ?? 0) === (int)($existingPackage['id'] ?? 0)) {
                continue;
            }

            throw new RuntimeException('Det finns redan ett paket med samma namn.');
        }

        $record = build_service_package_record($payload, $existingPackage);
        if ($existingPackage === null) {
            $record['id'] = mysql_next_table_id($pdo, 'service_packages');
            mysql_persist_service_package_record($pdo, $record, true);
        } else {
            mysql_persist_service_package_record($pdo, $record, false);
        }

        $existingItems = $existingPackage ? package_items_for_package($data, (int)$existingPackage['id']) : [];
        $itemRecords = build_service_package_item_records((int)$record['id'], $payload['packageItems'] ?? [], $existingItems);
        mysql_sync_service_package_items($pdo, (int)$record['id'], $itemRecords);

        $pdo->commit();

        return $record;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mysql_delete_service_package(int $packageId): void
{
    $pdo = admin_pdo();

    if (!mysql_service_packages_available()) {
        throw new RuntimeException('Paket-tabellerna saknas. Kör databasuppgraderingen först.');
    }

    if ($packageId <= 0) {
        throw new RuntimeException('Ogiltigt paket.');
    }

    $existsStatement = $pdo->prepare('SELECT id FROM service_packages WHERE id = :id LIMIT 1');
    $existsStatement->execute(['id' => $packageId]);

    if (!$existsStatement->fetchColumn()) {
        throw new RuntimeException('Paketet kunde inte hittas.');
    }

    $pdo->beginTransaction();

    try {
        $deleteItems = $pdo->prepare('DELETE FROM service_package_items WHERE package_id = :package_id');
        $deleteItems->execute(['package_id' => $packageId]);

        $deletePackage = $pdo->prepare('DELETE FROM service_packages WHERE id = :id');
        $deletePackage->execute(['id' => $packageId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
