<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/services/fortnox_export.php';
require_once __DIR__ . '/services/invoice_base_validation.php';
require_once __DIR__ . '/services/quote_approval.php';
require_once __DIR__ . '/services/quote_delivery.php';
require_once __DIR__ . '/services/quote_signature.php';

require_login();

$data = load_data();
$invoiceBases = $data['invoice_bases'] ?? [];
$invoiceBaseItems = $data['invoice_base_items'] ?? [];
$invoiceBasesByJobId = [];
foreach ($invoiceBases as $invoiceBasisRecord) {
    $jobId = (int)($invoiceBasisRecord['job_id'] ?? 0);
    if ($jobId > 0) {
        $basisId = (int)($invoiceBasisRecord['id'] ?? 0);
        $invoiceBasisRecord['row_items'] = array_values(array_filter(
            $invoiceBaseItems,
            static fn(array $item): bool => (int)($item['invoice_base_id'] ?? 0) === $basisId
        ));
        $invoiceBasesByJobId[$jobId] = $invoiceBasisRecord;
    }
}

$currentUserRecord = null;
if (current_user_id() > 0) {
    $currentUserRecord = find_user_by_id($data, current_user_id());
}
if ($currentUserRecord === null && current_user_username() !== '') {
    $currentUserRecord = find_user_by_username($data, current_user_username());
}
$currentUserRegionId = ($currentUserRecord['region_id'] ?? null) !== null ? (int)$currentUserRecord['region_id'] : null;
$currentUserRegion = $currentUserRegionId !== null ? find_region_by_id($data, $currentUserRegionId) : null;
$currentUserOrganizationId = ($currentUserRecord['organization_id'] ?? null) !== null ? (int)$currentUserRecord['organization_id'] : current_user_organization_id();
$currentUserOrganization = $currentUserOrganizationId !== null ? find_organization_by_id($data, $currentUserOrganizationId) : null;

function admin_url(string $page, array $params = []): string
{
    $query = array_merge(['page' => $page], $params);

    return 'index.php?' . http_build_query($query);
}

function google_maps_url_for_address(array $parts): string
{
    $query = trim(implode(', ', array_values(array_filter(
        array_map(static fn(mixed $value): string => trim((string)$value), $parts),
        static fn(string $value): bool => $value !== ''
    ))));

    if ($query === '') {
        return '';
    }

    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
}

function render_google_maps_link(string $label, array $parts, string $className = ''): string
{
    $url = google_maps_url_for_address($parts);
    if ($url === '') {
        return h($label);
    }

    $classAttribute = trim($className) !== '' ? ' class="' . h($className) . '"' : '';

    return '<a' . $classAttribute . ' href="' . h($url) . '" target="_blank" rel="noopener noreferrer">' . h($label) . '</a>';
}

function record_matches_region(array $record, int $regionId): bool
{
    return (int)($record['region_id'] ?? 0) === $regionId;
}

function record_matches_organization(array $record, int $organizationId): bool
{
    return (int)($record['organization_id'] ?? 0) === $organizationId;
}

function normalize_organization_scope_ids(int|array $organizationScope): array
{
    $scopeIds = is_array($organizationScope) ? $organizationScope : [$organizationScope];

    return array_values(array_unique(array_filter(
        array_map(static fn($value): int => (int)$value, $scopeIds),
        static fn(int $value): bool => $value > 0
    )));
}

function customer_matches_organization_scope(array $customer, int|array $organizationScope): bool
{
    $scopeIds = normalize_organization_scope_ids($organizationScope);
    if ($scopeIds === [] || ($customer['organization_id'] ?? null) === null || ($customer['organization_id'] ?? '') === '') {
        return false;
    }

    return in_array((int)($customer['organization_id'] ?? 0), $scopeIds, true);
}

function quote_matches_organization_scope(array $quote, array $customers, int|array $organizationScope): bool
{
    $scopeIds = normalize_organization_scope_ids($organizationScope);
    if ($scopeIds === []) {
        return false;
    }

    if (($quote['organization_id'] ?? null) !== null && ($quote['organization_id'] ?? '') !== '') {
        return in_array((int)($quote['organization_id'] ?? 0), $scopeIds, true);
    }

    $customer = find_by_id($customers, (int)($quote['customer_id'] ?? 0));

    return is_array($customer) && customer_matches_organization_scope($customer, $scopeIds);
}

function job_matches_organization_scope(array $job, array $customers, int|array $organizationScope): bool
{
    $scopeIds = normalize_organization_scope_ids($organizationScope);
    if ($scopeIds === []) {
        return false;
    }

    if (($job['organization_id'] ?? null) !== null && ($job['organization_id'] ?? '') !== '') {
        return in_array((int)($job['organization_id'] ?? 0), $scopeIds, true);
    }

    $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));

    return is_array($customer) && customer_matches_organization_scope($customer, $scopeIds);
}

function organization_tree_sort(array $organizations): array
{
    $childrenByParent = [];
    foreach ($organizations as $organization) {
        $parentId = ($organization['parent_organization_id'] ?? null) !== null ? (int)$organization['parent_organization_id'] : 0;
        $childrenByParent[$parentId][] = $organization;
    }

    foreach ($childrenByParent as &$children) {
        usort($children, static fn(array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    }
    unset($children);

    $sorted = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, &$sorted, $childrenByParent): void {
        foreach ($childrenByParent[$parentId] ?? [] as $organization) {
            $organization['_tree_depth'] = $depth;
            $sorted[] = $organization;
            $walk((int)($organization['id'] ?? 0), $depth + 1);
        }
    };

    $walk(0, 0);

    return $sorted;
}

function organization_tree_label(array $organization): string
{
    $depth = max(0, (int)($organization['_tree_depth'] ?? 0));
    return str_repeat('— ', $depth) . (string)($organization['name'] ?? '');
}

function organization_descendant_ids(array $organizations, int $organizationId): array
{
    $childrenByParent = [];
    foreach ($organizations as $organization) {
        $parentId = ($organization['parent_organization_id'] ?? null) !== null ? (int)$organization['parent_organization_id'] : 0;
        $childrenByParent[$parentId][] = (int)($organization['id'] ?? 0);
    }

    $ids = [];
    $walk = static function (int $parentId) use (&$walk, &$ids, $childrenByParent): void {
        $ids[] = $parentId;
        foreach ($childrenByParent[$parentId] ?? [] as $childId) {
            if (!in_array($childId, $ids, true)) {
                $walk($childId);
            }
        }
    };

    $walk($organizationId);

    return $ids;
}

function organization_type_label(string $type): string
{
    return match ($type) {
        ORGANIZATION_TYPE_HQ => 'Huvudbolag',
        ORGANIZATION_TYPE_REGIONAL_COMPANY => 'Regionbolag',
        default => 'Franchiseenhet',
    };
}

function page_capability(string $page): string
{
    return match ($page) {
        'customers', 'customer' => 'customers.view',
        'quotes' => 'quotes.view',
        'jobs', 'calendar' => 'jobs.view',
        'invoices' => 'invoices.view',
        'reports' => 'dashboard.view',
        'settings' => 'settings.view',
        default => 'dashboard.view',
    };
}

function badge_class(string $status): string
{
    $map = [
        'draft' => 'badge-neutral',
        'sent' => 'badge-blue',
        'approved' => 'badge-green',
        'rejected' => 'badge-red',
        'planned' => 'badge-neutral',
        'scheduled' => 'badge-blue',
        'in_progress' => 'badge-amber',
        'completed' => 'badge-green',
        'cancelled' => 'badge-red',
        'invoiced' => 'badge-dark',
    ];

    return $map[$status] ?? 'badge-neutral';
}

function quote_status_label(string $status): string
{
    if ($status === 'cancelled') {
        return 'Makulerad';
    }

    return status_label($status);
}

function append_status_reason_note(string $existingNotes, string $prefix, string $reason): string
{
    $reason = trim($reason);
    if ($reason === '') {
        return $existingNotes;
    }

    $entry = $prefix . ': ' . $reason;
    $existingNotes = trim($existingNotes);

    if ($existingNotes === '') {
        return $entry;
    }

    return $existingNotes . PHP_EOL . $entry;
}

function latest_job_for_quote(array $jobs, int $quoteId): ?array
{
    $matches = array_values(array_filter(
        $jobs,
        static fn(array $job): bool => (int)($job['quote_id'] ?? 0) === $quoteId
    ));

    if ($matches === []) {
        return null;
    }

    usort($matches, static function (array $left, array $right): int {
        $leftUpdated = (string)($left['updated_at'] ?? '');
        $rightUpdated = (string)($right['updated_at'] ?? '');
        $byUpdated = strcmp($rightUpdated, $leftUpdated);
        if ($byUpdated !== 0) {
            return $byUpdated;
        }

        return ((int)($right['id'] ?? 0)) <=> ((int)($left['id'] ?? 0));
    });

    return $matches[0] ?? null;
}

function quote_display_number(?array $quote): string
{
    if (!is_array($quote)) {
        return '';
    }

    $quoteNumber = trim((string)($quote['quote_number'] ?? $quote['quoteNumber'] ?? ''));
    if ($quoteNumber !== '') {
        return $quoteNumber;
    }

    return 'Offert #' . (int)($quote['id'] ?? 0);
}

function cancellation_billing_mode_label(string $mode): string
{
    return match ($mode) {
        'fee' => 'Avbokningsavgift',
        'actual_cost' => 'Faktisk kostnad',
        default => 'Ingen debitering',
    };
}

function vat_mode_label(string $mode): string
{
    return $mode === 'reverse_charge' ? 'Omvänd moms' : 'Vanlig moms';
}

function product_item_type_label(string $itemType): string
{
    return match ($itemType) {
        'labor' => 'Arbete',
        'material' => 'Material',
        'discount' => 'Rabatt',
        default => 'Övrigt',
    };
}

function quote_can_progress_with_amount(array $quote): bool
{
    return (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0) > 0;
}

function product_price_model_label(string $priceModel): string
{
    return match ($priceModel) {
        'per_sqm' => 'Per kvm',
        'per_mil' => 'Per mil',
        'per_unit' => 'Per styck',
        default => 'Fast pris',
    };
}

function service_family_label(string $serviceFamily): string
{
    return match ($serviceFamily) {
        'stone' => 'Sten',
        'deck' => 'Altan',
        default => 'Allmänt',
    };
}

function package_quantity_mode_label(string $mode): string
{
    return match ($mode) {
        'fixed' => 'Fast antal',
        'per_sqm' => 'Per kvm',
        'per_mil' => 'Per mil',
        default => 'Produktens standard',
    };
}

function customer_identifier_label(array $customer): string
{
    return in_array(($customer['customer_type'] ?? 'private'), ['company', 'association'], true) ? 'Organisationsnummer' : 'Personnummer';
}

function customer_identifier_value(array $customer): string
{
    return in_array(($customer['customer_type'] ?? 'private'), ['company', 'association'], true)
        ? (string)($customer['organization_number'] ?? '')
        : (string)($customer['personal_number'] ?? '');
}

function customer_service_type_label(string $serviceType): string
{
    return $serviceType === 'maintenance' ? 'Underhåll' : 'Engång';
}

function follow_up_date_state(string $date, string $today): ?string
{
    if ($date === '') {
        return null;
    }

    if ($date < $today) {
        return 'overdue';
    }

    $limit = date('Y-m-d', strtotime($today . ' +30 days'));
    if ($date <= $limit) {
        return 'upcoming';
    }

    return null;
}

function billing_mode_meta(array $customer): string
{
    if (($customer['customer_type'] ?? 'private') === 'private') {
        return 'Privatkund med 25 % moms';
    }

    return vat_mode_label((string)($customer['billing_vat_mode'] ?? 'standard_vat'));
}

function invoice_basis_for_job(array $invoiceBasesByJobId, array $job, ?array $customer, ?array $quote = null): array
{
    $jobId = (int)($job['id'] ?? 0);
    $record = $invoiceBasesByJobId[$jobId] ?? null;

    if (!is_array($record)) {
        throw new RuntimeException('Invoice base missing for job ' . $jobId);
    }

    return invoiceBasisFromRecord($record);
}

function try_invoice_basis_for_job(array $invoiceBasesByJobId, array $job, ?array $customer, ?array $quote = null): ?array
{
    try {
        return invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote);
    } catch (Throwable) {
        return null;
    }
}

function invoice_basis_error_for_job(array $invoiceBasesByJobId, array $job, ?array $customer, ?array $quote = null): string
{
    try {
        validateInvoiceBaseForExport((int)($job['id'] ?? 0), $invoiceBasesByJobId);
        invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote);

        return '';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

function fortnox_export_error_for_job(array $invoiceBasesByJobId, array $job): string
{
    try {
        validateInvoiceBaseForFortnoxExport((int)($job['id'] ?? 0), $invoiceBasesByJobId);

        return '';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

function fortnox_reference_summary(?array $invoiceBasis): string
{
    if (!is_array($invoiceBasis)) {
        return '';
    }

    $parts = [];
    $customerNumber = trim((string)($invoiceBasis['fortnoxCustomerNumber'] ?? ''));
    $documentNumber = trim((string)($invoiceBasis['fortnoxDocumentNumber'] ?? ''));
    $invoiceNumber = trim((string)($invoiceBasis['fortnoxInvoiceNumber'] ?? ''));
    $lastSyncAt = trim((string)($invoiceBasis['fortnoxLastSyncAt'] ?? ''));

    if ($customerNumber !== '') {
        $parts[] = 'Kund ' . $customerNumber;
    }
    if ($documentNumber !== '') {
        $parts[] = 'Dokument ' . $documentNumber;
    }
    if ($invoiceNumber !== '') {
        $parts[] = 'Faktura ' . $invoiceNumber;
    }
    if ($lastSyncAt !== '') {
        $timestamp = strtotime($lastSyncAt);
        if ($timestamp !== false) {
            $parts[] = 'Synkad ' . date('Y-m-d H:i', $timestamp);
        }
    }

    return implode(' · ', $parts);
}

function find_invoice_base_record_by_job_id(array $invoiceBases, int $jobId): ?array
{
    foreach ($invoiceBases as $invoiceBase) {
        if ((int)($invoiceBase['job_id'] ?? 0) === $jobId) {
            return $invoiceBase;
        }
    }

    return null;
}

function fortnox_export_error_action_link(string $message, ?array $customer = null): string
{
    $normalized = mb_strtolower(trim($message), 'UTF-8');
    if ($normalized === '' || !is_array($customer) || (int)($customer['id'] ?? 0) <= 0) {
        return '';
    }

    $customerFieldHints = [
        'vat-nummer',
        'organisationsnummer',
        'personnummer',
        'fakturaadress',
        'kundnamn',
    ];

    foreach ($customerFieldHints as $hint) {
        if (str_contains($normalized, $hint)) {
            return admin_url('customer', ['id' => (int)$customer['id'], 'edit' => 1]) . '#edit-customer';
        }
    }

    return '';
}

function job_display_number(array $job): string
{
    $jobNumber = trim((string)($job['job_number'] ?? $job['jobNumber'] ?? ''));
    if ($jobNumber !== '') {
        return $jobNumber;
    }

    $jobYearSource = trim((string)($job['created_at'] ?? $job['createdAt'] ?? $job['scheduled_date'] ?? $job['scheduledDate'] ?? ''));
    $timestamp = $jobYearSource !== '' ? strtotime($jobYearSource) : false;
    $year = $timestamp ? date('Y', $timestamp) : date('Y');

    return sprintf('J-%s-%04d', $year, (int)($job['id'] ?? 0));
}

function compare_jobs_by_schedule(array $left, array $right): int
{
    $leftDate = (string)($left['scheduled_date'] ?? '');
    $rightDate = (string)($right['scheduled_date'] ?? '');

    if ($leftDate === '' && $rightDate !== '') {
        return 1;
    }

    if ($leftDate !== '' && $rightDate === '') {
        return -1;
    }

    $byDate = strcmp($leftDate, $rightDate);
    if ($byDate !== 0) {
        return $byDate;
    }

    $leftTime = trim((string)($left['scheduled_time'] ?? ''));
    $rightTime = trim((string)($right['scheduled_time'] ?? ''));

    if ($leftTime === '' && $rightTime !== '') {
        return 1;
    }

    if ($leftTime !== '' && $rightTime === '') {
        return -1;
    }

    $byTime = strcmp($leftTime, $rightTime);
    if ($byTime !== 0) {
        return $byTime;
    }

    return ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
}

function compact_quote_document_description(string $serviceType, string $description, bool $hasPackageItems = false): string
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $description) ?? '');
    if ($normalized === '') {
        return '';
    }

    $serviceType = trim($serviceType);
    $normalizedServiceType = mb_strtolower($serviceType);
    $normalizedDescription = mb_strtolower($normalized);

    if (
        str_contains($normalizedServiceType, 'stenrengöring plus')
        || str_contains($normalizedServiceType, 'stenrengoring plus')
    ) {
        $firstSentence = 'Rengöring med impregnering och möjlighet till 15 års garanti vid årligt underhåll.';
        return $hasPackageItems ? rtrim($firstSentence, '. ') . '. Se omfattning nedan.' : $firstSentence;
    }

    if (
        str_contains($normalizedServiceType, 'stenrengöring premium')
        || str_contains($normalizedServiceType, 'stenrengoring premium')
    ) {
        $firstSentence = 'Rengöring, impregnering och fördjupad behandling med möjlighet till 15 års garanti vid årligt underhåll.';
        return $hasPackageItems ? rtrim($firstSentence, '. ') . '. Se omfattning nedan.' : $firstSentence;
    }

    if (
        str_contains($normalizedServiceType, 'komplett premium')
        && str_contains($normalizedDescription, '15 års garanti')
    ) {
        $firstSentence = 'Komplett behandling med möjlighet till 15 års garanti vid årligt underhåll.';
        return $hasPackageItems ? rtrim($firstSentence, '. ') . '. Se omfattning nedan.' : $firstSentence;
    }

    if ($serviceType !== '') {
        $servicePrefix = preg_quote($serviceType, '/');
        $normalized = trim((string)preg_replace('/^' . $servicePrefix . '[\.\:\-\s]*/iu', '', $normalized));
    }

    $firstSentence = preg_split('/(?<=[\.\!\?])\s+/u', $normalized, 2)[0] ?? $normalized;
    $firstSentence = trim($firstSentence);
    if ($firstSentence === '') {
        $firstSentence = $normalized;
    }

    if (mb_strlen($firstSentence) > 180) {
        $firstSentence = rtrim(mb_substr($firstSentence, 0, 177)) . '...';
    }

    if ($hasPackageItems) {
        $firstSentence = rtrim($firstSentence, '. ') . '. Se omfattning nedan.';
    }

    return $firstSentence;
}

function compact_quote_package_descriptions(array $items, int $limit = 6): array
{
    $descriptions = [];
    foreach ($items as $item) {
        $description = trim((string)($item['description'] ?? ''));
        if ($description === '') {
            continue;
        }
        $normalized = mb_strtolower($description);

        if (
            str_contains($normalized, 'trädäck') ||
            str_contains($normalized, 'trädack') ||
            str_contains($normalized, 'damm') ||
            str_contains($normalized, 'pollen') ||
            str_contains($normalized, 'jord')
        ) {
            $descriptions[] = 'Grundrengöring av trädäck';
            continue;
        }

        if (
            str_contains($normalized, 'alger') ||
            str_contains($normalized, 'mögel') ||
            str_contains($normalized, 'mogel') ||
            str_contains($normalized, 'svart påväxt') ||
            str_contains($normalized, 'svart pav') ||
            str_contains($normalized, 'svår påväxt') ||
            str_contains($normalized, 'svar pav')
        ) {
            $descriptions[] = 'Djupbehandling mot alger, mögel och svart påväxt';
            continue;
        }

        if (str_contains($normalized, 'nanosilica') || str_contains($normalized, 'langtidsskydd') || str_contains($normalized, 'långtidsskydd')) {
            $descriptions[] = 'Långtidsskydd med nanosilica-impregnering';
            continue;
        }

        if (str_contains($normalized, 'slipning') || str_contains($normalized, 'detaljarbete') || str_contains($normalized, 'finish')) {
            $descriptions[] = 'Djuprengöring, lätt slipning och detaljarbete';
            continue;
        }

        if (str_contains($normalized, 'servicebil')) {
            continue;
        }

        if (str_contains($normalized, 'resekostnad') || str_contains($normalized, 'mil')) {
            continue;
        }

        if (str_contains($normalized, 'avfall')) {
            continue;
        }

        $descriptions[] = $description;
    }

    $descriptions = array_values(array_unique($descriptions));

    if (count($descriptions) <= $limit) {
        return $descriptions;
    }

    $visible = array_slice($descriptions, 0, $limit - 1);
    $remaining = count($descriptions) - count($visible);
    $visible[] = sprintf('Ytterligare %d moment enligt paketets innehall.', $remaining);

    return $visible;
}

function customer_form_defaults(?array $customer = null): array
{
    $firstName = (string)($customer['first_name'] ?? '');
    $lastName = (string)($customer['last_name'] ?? '');

    return [
        'customerType' => (string)($customer['customer_type'] ?? 'private'),
        'billingVatMode' => (string)($customer['billing_vat_mode'] ?? 'standard_vat'),
        'organizationId' => ($customer['organization_id'] ?? null) !== null ? (string)($customer['organization_id']) : '',
        'regionId' => ($customer['region_id'] ?? null) !== null ? (string)($customer['region_id']) : '',
        'serviceType' => (string)($customer['service_type'] ?? 'single'),
        'name' => (string)($customer['name'] ?? ''),
        'firstName' => $firstName,
        'lastName' => $lastName,
        'companyName' => (string)($customer['company_name'] ?? ''),
        'associationName' => (string)($customer['association_name'] ?? ''),
        'contactPerson' => (string)($customer['contact_person'] ?? ''),
        'phone' => (string)($customer['phone'] ?? ''),
        'email' => (string)($customer['email'] ?? ''),
        'serviceAddress' => (string)($customer['service_address'] ?? $customer['address'] ?? ''),
        'servicePostalCode' => (string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? ''),
        'serviceCity' => (string)($customer['service_city'] ?? $customer['city'] ?? ''),
        'propertyDesignation' => (string)($customer['property_designation'] ?? ''),
        'rutUsedAmountThisYear' => isset($customer['rut_used_amount_this_year']) ? (string)(float)$customer['rut_used_amount_this_year'] : '0',
        'billingAddress' => (string)($customer['billing_address'] ?? $customer['service_address'] ?? $customer['address'] ?? ''),
        'billingPostalCode' => (string)($customer['billing_postal_code'] ?? $customer['service_postal_code'] ?? $customer['postal_code'] ?? ''),
        'billingCity' => (string)($customer['billing_city'] ?? $customer['service_city'] ?? $customer['city'] ?? ''),
        'billingSameAsProperty' => (string)(
            (
                (string)($customer['billing_address'] ?? $customer['service_address'] ?? $customer['address'] ?? '') ===
                (string)($customer['service_address'] ?? $customer['address'] ?? '')
            ) &&
            (
                (string)($customer['billing_postal_code'] ?? $customer['service_postal_code'] ?? $customer['postal_code'] ?? '') ===
                (string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? '')
            ) &&
            (
                (string)($customer['billing_city'] ?? $customer['service_city'] ?? $customer['city'] ?? '') ===
                (string)($customer['service_city'] ?? $customer['city'] ?? '')
            )
            ? '1'
            : '0'
        ),
        'personalNumber' => (string)($customer['personal_number'] ?? ''),
        'organizationNumber' => (string)($customer['organization_number'] ?? ''),
        'vatNumber' => (string)($customer['vat_number'] ?? ''),
        'rutEnabled' => !empty($customer['rut_enabled']) ? '1' : '0',
        'lastServiceDate' => (string)($customer['last_service_date'] ?? ''),
        'nextServiceDate' => (string)($customer['next_service_date'] ?? ''),
        'notes' => (string)($customer['notes'] ?? ''),
    ];
}

function quote_form_defaults(?array $quote = null): array
{
    $customer = null;
    global $data;
    if ($quote !== null && isset($quote['customer_id'])) {
        $customer = find_by_id($data['customers'] ?? [], (int)$quote['customer_id']);
    }
    $quoteItems = [];
    if ($quote !== null && isset($quote['id'])) {
        $quoteItems = array_values(array_filter(
            $data['quote_items'] ?? [],
            static fn(array $item): bool => (int)($item['quote_id'] ?? 0) === (int)$quote['id']
        ));
    }

    return [
        'quoteNumber' => (string)($quote['quote_number'] ?? ''),
        'quoteDate' => (string)($quote['issue_date'] ?? date('Y-m-d')),
        'customerId' => (string)($quote['customer_id'] ?? ''),
        'existingCustomerId' => (string)($quote['customer_id'] ?? ''),
        'customerType' => (string)($customer['customer_type'] ?? 'private'),
        'billingVatMode' => (string)($customer['billing_vat_mode'] ?? 'standard_vat'),
        'organizationId' => ($customer['organization_id'] ?? ($quote['organization_id'] ?? null)) !== null ? (string)($customer['organization_id'] ?? $quote['organization_id']) : '',
        'regionId' => ($customer['region_id'] ?? null) !== null ? (string)($customer['region_id']) : '',
        'name' => (string)($customer['name'] ?? ''),
        'firstName' => (string)($customer['first_name'] ?? ''),
        'lastName' => (string)($customer['last_name'] ?? ''),
        'companyName' => (string)($customer['company_name'] ?? ''),
        'associationName' => (string)($customer['association_name'] ?? ''),
        'contactPerson' => (string)($customer['contact_person'] ?? ''),
        'phone' => (string)($customer['phone'] ?? ''),
        'email' => (string)($customer['email'] ?? ''),
        'serviceAddress' => (string)($customer['service_address'] ?? $customer['address'] ?? ''),
        'servicePostalCode' => (string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? ''),
        'serviceCity' => (string)($customer['service_city'] ?? $customer['city'] ?? ''),
        'propertyDesignation' => (string)($customer['property_designation'] ?? ''),
        'rutUsedAmountThisYear' => isset($customer['rut_used_amount_this_year']) ? (string)(float)$customer['rut_used_amount_this_year'] : '0',
        'billingAddress' => (string)($customer['billing_address'] ?? $customer['service_address'] ?? $customer['address'] ?? ''),
        'billingPostalCode' => (string)($customer['billing_postal_code'] ?? $customer['service_postal_code'] ?? $customer['postal_code'] ?? ''),
        'billingCity' => (string)($customer['billing_city'] ?? $customer['service_city'] ?? $customer['city'] ?? ''),
        'billingSameAsProperty' => (string)(
            $quote !== null
                ? (
                    (string)($customer['billing_address'] ?? $customer['service_address'] ?? $customer['address'] ?? '') ===
                    (string)($customer['service_address'] ?? $customer['address'] ?? '')
                    && (string)($customer['billing_postal_code'] ?? $customer['service_postal_code'] ?? $customer['postal_code'] ?? '') ===
                    (string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? '')
                    && (string)($customer['billing_city'] ?? $customer['service_city'] ?? $customer['city'] ?? '') ===
                    (string)($customer['service_city'] ?? $customer['city'] ?? '')
                )
                : true
        ) ? '1' : '0',
        'personalNumber' => (string)($customer['personal_number'] ?? ''),
        'organizationNumber' => (string)($customer['organization_number'] ?? ''),
        'vatNumber' => (string)($customer['vat_number'] ?? ''),
        'rutEnabled' => !empty($customer['rut_enabled']) ? '1' : '0',
        'customerNotes' => (string)($customer['notes'] ?? ''),
        'serviceType' => (string)($quote['service_type'] ?? ''),
        'description' => (string)($quote['description'] ?? ''),
        'laborAmountExVat' => isset($quote['labor_amount_ex_vat']) ? (string)(float)$quote['labor_amount_ex_vat'] : '',
        'materialAmountExVat' => isset($quote['material_amount_ex_vat']) ? (string)(float)$quote['material_amount_ex_vat'] : '0',
        'otherAmountExVat' => isset($quote['other_amount_ex_vat']) ? (string)(float)$quote['other_amount_ex_vat'] : '0',
        'quoteItemsJson' => $quoteItems !== []
            ? json_encode(array_map(static function (array $item): array {
                return [
                    'item_type' => (string)($item['item_type'] ?? 'service'),
                    'description' => (string)($item['description'] ?? ''),
                    'quantity' => (float)($item['quantity'] ?? 1),
                    'unit' => (string)($item['unit'] ?? 'st'),
                    'unit_price' => (float)($item['unit_price'] ?? 0),
                    'vat_rate' => (float)($item['vat_rate'] ?? 0.25),
                    'is_rut_eligible' => !empty($item['is_rut_eligible']),
                ];
            }, $quoteItems), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '',
        'status' => (string)($quote['status'] ?? 'draft'),
        'validUntil' => (string)($quote['valid_until'] ?? date('Y-m-d', strtotime('+20 days'))),
        'notes' => (string)($quote['notes'] ?? ''),
    ];
}

function job_form_defaults(?array $job = null): array
{
    return [
        'customerId' => (string)($job['customer_id'] ?? ''),
        'quoteId' => ($job['quote_id'] ?? null) !== null ? (string)($job['quote_id'] ?? '') : '',
        'organizationId' => ($job['organization_id'] ?? null) !== null ? (string)($job['organization_id']) : '',
        'regionId' => ($job['region_id'] ?? null) !== null ? (string)($job['region_id']) : '',
        'serviceType' => (string)($job['service_type'] ?? ''),
        'description' => (string)($job['description'] ?? ''),
        'scheduledDate' => (string)($job['scheduled_date'] ?? ''),
        'scheduledTime' => trim((string)($job['scheduled_time'] ?? '')) !== '' ? substr((string)($job['scheduled_time'] ?? ''), 0, 5) : '',
        'completedDate' => (string)($job['completed_date'] ?? ''),
        'assignedTo' => (string)($job['assigned_to'] ?? ($job === null && current_user_role() === USER_ROLE_WORKER ? current_user_username() : '')),
        'status' => (string)($job['status'] ?? 'planned'),
        'finalLaborAmountExVat' => isset($job['final_labor_amount_ex_vat']) ? (string)(float)$job['final_labor_amount_ex_vat'] : '0',
        'finalMaterialAmountExVat' => isset($job['final_material_amount_ex_vat']) ? (string)(float)$job['final_material_amount_ex_vat'] : '0',
        'finalOtherAmountExVat' => isset($job['final_other_amount_ex_vat']) ? (string)(float)$job['final_other_amount_ex_vat'] : '0',
        'readyForInvoicing' => !empty($job['ready_for_invoicing']) ? '1' : '0',
        'notes' => (string)($job['notes'] ?? ''),
    ];
}

function quote_customer_payload_from_request(): array
{
    $customerType = (string)($_POST['customerType'] ?? 'private');
    $companyName = trim((string)($_POST['companyName'] ?? ''));
    $associationName = trim((string)($_POST['associationName'] ?? ''));
    $firstName = trim((string)($_POST['firstName'] ?? ''));
    $lastName = trim((string)($_POST['lastName'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));

    if ($customerType === 'company' && $name === '') {
        $name = $companyName;
    }
    if ($customerType === 'association' && $name === '') {
        $name = $associationName;
    }
    if ($customerType === 'private' && $name === '') {
        $name = trim(implode(' ', array_filter([$firstName, $lastName], static fn(string $value): bool => $value !== '')));
    }

    return [
        'customerType' => $customerType,
        'billingVatMode' => (string)($_POST['billingVatMode'] ?? 'standard_vat'),
        'organizationId' => trim((string)($_POST['organizationId'] ?? '')),
        'regionId' => trim((string)($_POST['regionId'] ?? '')),
        'serviceType' => trim((string)($_POST['serviceType'] ?? 'single')),
        'name' => $name,
        'firstName' => $firstName,
        'lastName' => $lastName,
        'companyName' => $companyName,
        'associationName' => $associationName,
        'contactPerson' => trim((string)($_POST['contactPerson'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'serviceAddress' => trim((string)($_POST['serviceAddress'] ?? '')),
        'servicePostalCode' => trim((string)($_POST['servicePostalCode'] ?? '')),
        'serviceCity' => trim((string)($_POST['serviceCity'] ?? '')),
        'propertyDesignation' => trim((string)($_POST['propertyDesignation'] ?? '')),
        'rutUsedAmountThisYear' => trim((string)($_POST['rutUsedAmountThisYear'] ?? '0')),
        'billingAddress' => trim((string)($_POST['billingAddress'] ?? '')),
        'billingPostalCode' => trim((string)($_POST['billingPostalCode'] ?? '')),
        'billingCity' => trim((string)($_POST['billingCity'] ?? '')),
        'billingSameAsProperty' => (string)($_POST['billingSameAsProperty'] ?? '0') === '1',
        'personalNumber' => trim((string)($_POST['personalNumber'] ?? '')),
        'organizationNumber' => trim((string)($_POST['organizationNumber'] ?? '')),
        'vatNumber' => trim((string)($_POST['vatNumber'] ?? '')),
        'rutEnabled' => (string)($_POST['rutEnabled'] ?? '0') === '1',
        'notes' => trim((string)($_POST['customerNotes'] ?? '')),
    ];
}

function customer_payload_from_request(): array
{
    $customerType = (string)($_POST['customerType'] ?? 'private');
    $companyName = trim((string)($_POST['companyName'] ?? ''));
    $associationName = trim((string)($_POST['associationName'] ?? ''));
    $firstName = trim((string)($_POST['firstName'] ?? ''));
    $lastName = trim((string)($_POST['lastName'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));

    if ($customerType === 'private' && $name === '') {
        $name = trim(implode(' ', array_filter([$firstName, $lastName], static fn(string $value): bool => $value !== '')));
    }
    if ($customerType === 'company' && $name === '') {
        $name = $companyName;
    }
    if ($customerType === 'association' && $name === '') {
        $name = $associationName;
    }

    return [
        'customerType' => $customerType,
        'billingVatMode' => (string)($_POST['billingVatMode'] ?? 'standard_vat'),
        'organizationId' => trim((string)($_POST['organizationId'] ?? '')),
        'regionId' => trim((string)($_POST['regionId'] ?? '')),
        'serviceType' => trim((string)($_POST['serviceType'] ?? 'single')),
        'name' => $name,
        'firstName' => $firstName,
        'lastName' => $lastName,
        'companyName' => $companyName,
        'associationName' => $associationName,
        'contactPerson' => trim((string)($_POST['contactPerson'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'serviceAddress' => trim((string)($_POST['serviceAddress'] ?? '')),
        'servicePostalCode' => trim((string)($_POST['servicePostalCode'] ?? '')),
        'serviceCity' => trim((string)($_POST['serviceCity'] ?? '')),
        'propertyDesignation' => trim((string)($_POST['propertyDesignation'] ?? '')),
        'rutUsedAmountThisYear' => trim((string)($_POST['rutUsedAmountThisYear'] ?? '0')),
        'billingAddress' => trim((string)($_POST['billingAddress'] ?? '')),
        'billingPostalCode' => trim((string)($_POST['billingPostalCode'] ?? '')),
        'billingCity' => trim((string)($_POST['billingCity'] ?? '')),
        'billingSameAsProperty' => (string)($_POST['billingSameAsProperty'] ?? '0') === '1',
        'personalNumber' => trim((string)($_POST['personalNumber'] ?? '')),
        'organizationNumber' => trim((string)($_POST['organizationNumber'] ?? '')),
        'vatNumber' => trim((string)($_POST['vatNumber'] ?? '')),
        'rutEnabled' => (string)($_POST['rutEnabled'] ?? '0') === '1',
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
}

function form_state_values(?array $state, array $defaults): array
{
    $values = $state['values'] ?? [];

    return array_merge($defaults, is_array($values) ? $values : []);
}

function form_state_errors(?array $state): array
{
    return is_array($state['errors'] ?? null) ? $state['errors'] : [];
}

function field_value(array $values, string $field): string
{
    return (string)($values[$field] ?? '');
}

function field_error(array $errors, string $field): ?string
{
    $value = $errors[$field] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
}

function field_class(array $errors, string $field): string
{
    return field_error($errors, $field) ? ' field-error' : '';
}

function is_selected(array $values, string $field, string $expected): string
{
    return field_value($values, $field) === $expected ? ' selected' : '';
}

function is_checked(array $values, string $field): string
{
    return !empty($values[$field]) ? ' checked' : '';
}

function render_field_error(array $errors, string $field): string
{
    $message = field_error($errors, $field);

    return $message ? '<small class="field-message">' . h($message) . '</small>' : '';
}

function persist_form_error(string $key, array $values, array $errors, string $redirectTo, ?string $message = null): never
{
    set_form_state($key, [
        'values' => $values,
        'errors' => $errors,
    ]);

    if ($message !== null && $message !== '') {
        set_flash('error', $message);
    }

    redirect($redirectTo);
}

function job_return_context_from_get(): array
{
    return [
        'page' => trim((string)($_GET['return_page'] ?? '')),
        'view' => trim((string)($_GET['return_view'] ?? '')),
        'week' => (int)($_GET['return_week'] ?? 0),
        'calendar_organization' => trim((string)($_GET['return_calendar_organization'] ?? '')),
        'calendar_region' => trim((string)($_GET['return_calendar_region'] ?? '')),
        'calendar_worker' => trim((string)($_GET['return_calendar_worker'] ?? '')),
    ];
}

function job_return_context_from_post(): array
{
    return [
        'page' => trim((string)($_POST['returnPage'] ?? 'jobs')),
        'view' => trim((string)($_POST['returnView'] ?? 'all')),
        'week' => (int)($_POST['returnWeek'] ?? 0),
        'calendar_organization' => trim((string)($_POST['returnCalendarOrganization'] ?? '')),
        'calendar_region' => trim((string)($_POST['returnCalendarRegion'] ?? '')),
        'calendar_worker' => trim((string)($_POST['returnCalendarWorker'] ?? '')),
    ];
}

function job_return_params(int $jobId, array $context): array
{
    $returnPage = trim((string)($context['page'] ?? 'jobs'));

    return array_filter([
        'view' => 'edit',
        'job_edit_id' => $jobId,
        'return_page' => $returnPage,
        'return_view' => trim((string)($context['view'] ?? 'all')),
        'return_week' => $returnPage === 'calendar' ? (string)(int)($context['week'] ?? 0) : '',
        'return_calendar_organization' => $returnPage === 'calendar' ? trim((string)($context['calendar_organization'] ?? '')) : '',
        'return_calendar_region' => $returnPage === 'calendar' ? trim((string)($context['calendar_region'] ?? '')) : '',
        'return_calendar_worker' => $returnPage === 'calendar' ? trim((string)($context['calendar_worker'] ?? '')) : '',
    ], static fn($value): bool => $value !== '');
}

function job_return_back_url(array $context): string
{
    $returnPage = trim((string)($context['page'] ?? 'jobs'));
    $returnView = trim((string)($context['view'] ?? 'all'));

    if ($returnPage === 'calendar') {
        return admin_url('calendar', array_filter([
            'view' => $returnView !== '' ? $returnView : 'week',
            'week' => (string)(int)($context['week'] ?? 0),
            'calendar_organization' => trim((string)($context['calendar_organization'] ?? '')),
            'calendar_region' => trim((string)($context['calendar_region'] ?? '')),
            'calendar_worker' => trim((string)($context['calendar_worker'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
    }

    return admin_url('jobs', ['view' => $returnView !== '' ? $returnView : 'all']);
}

function render_job_return_hidden_inputs(array $context): string
{
    $returnPage = trim((string)($context['page'] ?? 'jobs'));
    $returnView = trim((string)($context['view'] ?? 'all'));
    $returnWeek = (int)($context['week'] ?? 0);
    $returnCalendarOrganization = trim((string)($context['calendar_organization'] ?? ''));
    $returnCalendarRegion = trim((string)($context['calendar_region'] ?? ''));
    $returnCalendarWorker = trim((string)($context['calendar_worker'] ?? ''));

    return implode("\n", [
        '<input type="hidden" name="returnPage" value="' . h($returnPage !== '' ? $returnPage : 'jobs') . '" />',
        '<input type="hidden" name="returnView" value="' . h($returnView !== '' ? $returnView : 'all') . '" />',
        '<input type="hidden" name="returnWeek" value="' . h((string)$returnWeek) . '" />',
        '<input type="hidden" name="returnCalendarOrganization" value="' . h($returnCalendarOrganization) . '" />',
        '<input type="hidden" name="returnCalendarRegion" value="' . h($returnCalendarRegion) . '" />',
        '<input type="hidden" name="returnCalendarWorker" value="' . h($returnCalendarWorker) . '" />',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_customer' || $action === 'update_customer') {
        if (!current_user_can('customers.manage')) {
            set_flash('error', 'Du har inte behörighet att hantera kunder.');
            redirect(admin_url('dashboard'));
        }

        $customerId = (int)($_POST['customerId'] ?? 0);
        $existingCustomer = $action === 'update_customer' ? find_by_id($data['customers'], $customerId) : null;

        if ($action === 'update_customer' && $existingCustomer === null) {
            set_flash('error', 'Kunden kunde inte hittas.');
            redirect(admin_url('customers'));
        }

        $payload = array_merge(['id' => $customerId], customer_payload_from_request());
        if ($currentUserOrganizationId !== null) {
            $payload['organizationId'] = (string)$currentUserOrganizationId;
        } elseif (($payload['organizationId'] ?? '') === '' && !empty($data['organizations'][0]['id'])) {
            $payload['organizationId'] = (string)$data['organizations'][0]['id'];
        }
        if (current_user_role() === USER_ROLE_SALES && $currentUserRegionId !== null) {
            $payload['regionId'] = (string)$currentUserRegionId;
        }
        if (($payload['regionId'] ?? '') === '' && ($payload['organizationId'] ?? '') !== '') {
            $organization = find_organization_by_id($data, (int)$payload['organizationId']);
            if ($organization !== null && ($organization['region_id'] ?? null) !== null) {
                $payload['regionId'] = (string)$organization['region_id'];
            }
        }
        $errors = validate_customer_payload($payload);

        if ($errors !== []) {
            $formKey = $action === 'update_customer' ? 'customer_edit_' . $customerId : 'customer_create';
            $redirectTo = $action === 'update_customer'
                ? admin_url('customer', ['id' => $customerId, 'edit' => 1])
                : admin_url('customers', ['view' => 'create']);
            persist_form_error($formKey, $payload, $errors, $redirectTo, 'Rätta de markerade kundfälten.');
        }

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect($action === 'update_customer'
                ? admin_url('customer', ['id' => $customerId, 'edit' => 1])
                : admin_url('customers', ['view' => 'create']));
        }

        $record = mysql_save_customer($payload, $existingCustomer);
        if ($action === 'create_customer') {
            $requestId = (int)($_POST['requestId'] ?? 0);
            if ($requestId > 0) {
                mysql_update_web_quote_request_status($requestId, 'handled', current_user_username());
            }
            clear_form_state('customer_create');
            set_flash('success', 'Kunden skapades.');
            if ((string)($_POST['nextAction'] ?? '') === 'create_quote') {
                $redirectParams = ['view' => 'create', 'customer_id' => (int)$record['id']];
                if ($requestId > 0) {
                    $redirectParams['request_id'] = $requestId;
                }
                redirect(admin_url('quotes', $redirectParams));
            }
            redirect(admin_url('customer', ['id' => (int)$record['id']]));
        }

        clear_form_state('customer_edit_' . $customerId);
        set_flash('success', 'Kunden uppdaterades.');
        redirect(admin_url('customer', ['id' => $customerId]));
    }

    if ($action === 'create_quote' || $action === 'update_quote') {
        if (!current_user_can('quotes.manage')) {
            set_flash('error', 'Du har inte behörighet att hantera offerter.');
            redirect(admin_url('dashboard'));
        }

        $quoteId = (int)($_POST['quoteId'] ?? 0);
        $existingQuote = $action === 'update_quote' ? find_by_id($data['quotes'], $quoteId) : null;
        $existingCustomerId = (int)($_POST['existingCustomerId'] ?? 0);

        if ($action === 'update_quote' && $existingQuote === null) {
            set_flash('error', 'Offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }

        $customerPayload = quote_customer_payload_from_request();
        if ($currentUserOrganizationId !== null) {
            $customerPayload['organizationId'] = (string)$currentUserOrganizationId;
        } elseif (($customerPayload['organizationId'] ?? '') === '' && !empty($data['organizations'][0]['id'])) {
            $customerPayload['organizationId'] = (string)$data['organizations'][0]['id'];
        }
        if (current_user_role() === USER_ROLE_SALES && $currentUserRegionId !== null) {
            $customerPayload['regionId'] = (string)$currentUserRegionId;
        }
        if (($customerPayload['regionId'] ?? '') === '' && ($customerPayload['organizationId'] ?? '') !== '') {
            $organization = find_organization_by_id($data, (int)$customerPayload['organizationId']);
            if ($organization !== null && ($organization['region_id'] ?? null) !== null) {
                $customerPayload['regionId'] = (string)$organization['region_id'];
            }
        }

        $requestedStatus = trim((string)($_POST['status'] ?? 'draft'));
        if (current_user_role() !== USER_ROLE_ADMIN && in_array($requestedStatus, ['rejected', 'cancelled'], true)) {
            set_flash('error', 'Bara admin kan markera en offert som nekad eller makulerad.');
            redirect($action === 'update_quote'
                ? admin_url('quotes', ['edit_id' => $quoteId])
                : admin_url('quotes', ['view' => 'create']));
        }

        $payload = [
            'id' => $quoteId,
            'createdByUsername' => $action === 'update_quote'
                ? (string)($existingQuote['created_by_username'] ?? current_user_username())
                : current_user_username(),
            'customerId' => 0,
            'existingCustomerId' => $existingCustomerId,
            'customerType' => $customerPayload['customerType'],
            'billingVatMode' => $customerPayload['billingVatMode'],
            'organizationId' => $customerPayload['organizationId'],
            'name' => $customerPayload['name'],
            'companyName' => $customerPayload['companyName'],
            'associationName' => $customerPayload['associationName'],
            'contactPerson' => $customerPayload['contactPerson'],
            'phone' => $customerPayload['phone'],
            'email' => $customerPayload['email'],
            'serviceAddress' => $customerPayload['serviceAddress'],
            'servicePostalCode' => $customerPayload['servicePostalCode'],
            'serviceCity' => $customerPayload['serviceCity'],
            'billingAddress' => $customerPayload['billingAddress'],
            'billingPostalCode' => $customerPayload['billingPostalCode'],
            'billingCity' => $customerPayload['billingCity'],
            'personalNumber' => $customerPayload['personalNumber'],
            'organizationNumber' => $customerPayload['organizationNumber'],
            'vatNumber' => $customerPayload['vatNumber'],
            'rutEnabled' => $customerPayload['rutEnabled'],
            'customerNotes' => $customerPayload['notes'],
            'serviceType' => trim((string)($_POST['serviceType'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'quoteDate' => trim((string)($_POST['quoteDate'] ?? '')),
            'laborAmountExVat' => $_POST['laborAmountExVat'] ?? 0,
            'materialAmountExVat' => $_POST['materialAmountExVat'] ?? 0,
            'otherAmountExVat' => $_POST['otherAmountExVat'] ?? 0,
            'quoteItems' => trim((string)($_POST['quoteItemsJson'] ?? '')),
            'status' => $requestedStatus,
            'validUntil' => trim((string)($_POST['validUntil'] ?? '')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ];

        $customerErrors = validate_customer_payload($customerPayload);
        $quoteErrors = [];
        $billingCustomer = build_customer_record($customerPayload);
        $derivedQuoteItems = quote_items_from_payload($payload, $billingCustomer);
        if (trim((string)($payload['serviceType'] ?? '')) === '' && $derivedQuoteItems !== []) {
            $payload['serviceType'] = trim((string)($derivedQuoteItems[0]['description'] ?? ''));
        }
        if (trim((string)($payload['description'] ?? '')) === '' && $derivedQuoteItems !== []) {
            $derivedDescriptions = array_values(array_filter(array_map(
                static fn(array $item): string => trim((string)($item['description'] ?? '')),
                $derivedQuoteItems
            ), static fn(string $itemDescription): bool => $itemDescription !== ''));

            if ($derivedDescriptions !== []) {
                $payload['description'] = implode("\n", $derivedDescriptions);
            }
        }
        $quoteErrors = validate_quote_payload($payload, $billingCustomer);
        $errors = array_merge($customerErrors, $quoteErrors);
        if ($errors !== []) {
            $formKey = $action === 'update_quote' ? 'quote_edit_' . $quoteId : 'quote_create';
            $redirectTo = $action === 'update_quote'
                ? admin_url('quotes', ['edit_id' => $quoteId])
                : admin_url('quotes', ['view' => 'create']);
            persist_form_error($formKey, $payload, $errors, $redirectTo, 'Rätta de markerade offertfälten.');
        }

        $preferredCustomerId = $existingCustomerId > 0 ? $existingCustomerId : ($action === 'update_quote' ? (int)($existingQuote['customer_id'] ?? 0) : null);

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect($action === 'update_quote'
                ? admin_url('quotes', ['edit_id' => $quoteId])
                : admin_url('quotes', ['view' => 'create']));
        }

        $quote = mysql_save_quote($payload, $customerPayload, $existingQuote, $preferredCustomerId);
        if ($action === 'create_quote') {
            clear_form_state('quote_create');
            set_flash('success', 'Offerten skapades.');
            redirect(admin_url('quotes'));
        }

        clear_form_state('quote_edit_' . $quoteId);
        set_flash('success', 'Offerten uppdaterades.');
        redirect(admin_url('quotes', ['edit_id' => (int)$quote['id']]));
    }

    if ($action === 'cancel_quote') {
        if (current_user_role() !== USER_ROLE_ADMIN) {
            set_flash('error', 'Bara admin kan makulera offerter.');
            redirect(admin_url('dashboard'));
        }

        $quoteId = (int)($_POST['quoteId'] ?? 0);
        $quote = find_by_id($data['quotes'], $quoteId);
        if ($quote === null) {
            set_flash('error', 'Offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }

        foreach ($data['jobs'] ?? [] as $job) {
            if ((int)($job['quote_id'] ?? 0) === $quoteId && !in_array((string)($job['status'] ?? ''), ['cancelled'], true)) {
                set_flash('error', 'Offerten kan inte makuleras när ett aktivt jobb redan finns kopplat.');
                redirect(admin_url('quotes', ['edit_id' => $quoteId]));
            }
        }

        $customer = find_by_id($data['customers'], (int)($quote['customer_id'] ?? 0));
        if ($customer === null) {
            set_flash('error', 'Kunden för offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }

        $quoteItemsForCancel = array_values(array_filter(
            $data['quote_items'] ?? [],
            static fn(array $item): bool => (int)($item['quote_id'] ?? 0) === $quoteId
        ));

        $payload = [
            'id' => $quoteId,
            'createdByUsername' => (string)($quote['created_by_username'] ?? ''),
            'existingCustomerId' => (int)($quote['customer_id'] ?? 0),
            'customerId' => (int)($quote['customer_id'] ?? 0),
            'customerType' => (string)($customer['customer_type'] ?? 'private'),
            'billingVatMode' => (string)($customer['billing_vat_mode'] ?? 'standard_vat'),
            'name' => (string)($customer['name'] ?? ''),
            'companyName' => (string)($customer['company_name'] ?? ''),
            'associationName' => (string)($customer['association_name'] ?? ''),
            'contactPerson' => (string)($customer['contact_person'] ?? ''),
            'phone' => (string)($customer['phone'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'serviceAddress' => (string)($customer['service_address'] ?? $customer['address'] ?? ''),
            'servicePostalCode' => (string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? ''),
            'serviceCity' => (string)($customer['service_city'] ?? $customer['city'] ?? ''),
            'billingAddress' => (string)($customer['billing_address'] ?? ''),
            'billingPostalCode' => (string)($customer['billing_postal_code'] ?? ''),
            'billingCity' => (string)($customer['billing_city'] ?? ''),
            'personalNumber' => (string)($customer['personal_number'] ?? ''),
            'organizationNumber' => (string)($customer['organization_number'] ?? ''),
            'vatNumber' => (string)($customer['vat_number'] ?? ''),
            'rutEnabled' => !empty($customer['rut_enabled']),
            'customerNotes' => (string)($customer['notes'] ?? ''),
            'serviceType' => (string)($quote['service_type'] ?? ''),
            'description' => (string)($quote['description'] ?? ''),
            'quoteDate' => (string)($quote['issue_date'] ?? ''),
            'laborAmountExVat' => (string)($quote['labor_amount_ex_vat'] ?? 0),
            'materialAmountExVat' => (string)($quote['material_amount_ex_vat'] ?? 0),
            'otherAmountExVat' => (string)($quote['other_amount_ex_vat'] ?? 0),
            'quoteItems' => json_encode($quoteItemsForCancel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'cancelled',
            'validUntil' => (string)($quote['valid_until'] ?? ''),
            'notes' => append_status_reason_note((string)($quote['notes'] ?? ''), 'Makulerad', trim((string)($_POST['cancelReason'] ?? ''))),
        ];

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect(admin_url('quotes', ['edit_id' => $quoteId]));
        }

        mysql_save_quote($payload, build_customer_record([
            'customerType' => $customer['customer_type'] ?? 'private',
            'billingVatMode' => $customer['billing_vat_mode'] ?? 'standard_vat',
            'name' => $customer['name'] ?? '',
            'companyName' => $customer['company_name'] ?? '',
            'associationName' => $customer['association_name'] ?? '',
            'contactPerson' => $customer['contact_person'] ?? '',
            'phone' => $customer['phone'] ?? '',
            'email' => $customer['email'] ?? '',
            'serviceAddress' => $customer['service_address'] ?? $customer['address'] ?? '',
            'servicePostalCode' => $customer['service_postal_code'] ?? $customer['postal_code'] ?? '',
            'serviceCity' => $customer['service_city'] ?? $customer['city'] ?? '',
            'billingAddress' => $customer['billing_address'] ?? '',
            'billingPostalCode' => $customer['billing_postal_code'] ?? '',
            'billingCity' => $customer['billing_city'] ?? '',
            'personalNumber' => $customer['personal_number'] ?? '',
            'organizationNumber' => $customer['organization_number'] ?? '',
            'vatNumber' => $customer['vat_number'] ?? '',
            'rutEnabled' => !empty($customer['rut_enabled']),
            'rutUsedAmountThisYear' => $customer['rut_used_amount_this_year'] ?? 0,
            'notes' => $customer['notes'] ?? '',
            'regionId' => (string)($customer['region_id'] ?? ''),
            'firstName' => $customer['first_name'] ?? '',
            'lastName' => $customer['last_name'] ?? '',
        ], $customer), $quote, (int)($quote['customer_id'] ?? 0));

        set_flash('success', 'Offerten makulerades.');
        redirect(admin_url('quotes', ['edit_id' => $quoteId]));
    }

    if ($action === 'reject_quote') {
        if (current_user_role() !== USER_ROLE_ADMIN) {
            set_flash('error', 'Bara admin kan markera en offert som nekad.');
            redirect(admin_url('dashboard'));
        }

        $quoteId = (int)($_POST['quoteId'] ?? 0);
        $quote = find_by_id($data['quotes'], $quoteId);
        if ($quote === null) {
            set_flash('error', 'Offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }

        $customer = find_by_id($data['customers'], (int)($quote['customer_id'] ?? 0));
        if ($customer === null) {
            set_flash('error', 'Kunden för offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }

        $quoteItemsForReject = array_values(array_filter(
            $data['quote_items'] ?? [],
            static fn(array $item): bool => (int)($item['quote_id'] ?? 0) === $quoteId
        ));

        $payload = [
            'id' => $quoteId,
            'createdByUsername' => (string)($quote['created_by_username'] ?? ''),
            'existingCustomerId' => (int)($quote['customer_id'] ?? 0),
            'customerId' => (int)($quote['customer_id'] ?? 0),
            'customerType' => (string)($customer['customer_type'] ?? 'private'),
            'billingVatMode' => (string)($customer['billing_vat_mode'] ?? 'standard_vat'),
            'name' => (string)($customer['name'] ?? ''),
            'companyName' => (string)($customer['company_name'] ?? ''),
            'associationName' => (string)($customer['association_name'] ?? ''),
            'contactPerson' => (string)($customer['contact_person'] ?? ''),
            'phone' => (string)($customer['phone'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'serviceAddress' => (string)($customer['service_address'] ?? $customer['address'] ?? ''),
            'servicePostalCode' => (string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? ''),
            'serviceCity' => (string)($customer['service_city'] ?? $customer['city'] ?? ''),
            'billingAddress' => (string)($customer['billing_address'] ?? ''),
            'billingPostalCode' => (string)($customer['billing_postal_code'] ?? ''),
            'billingCity' => (string)($customer['billing_city'] ?? ''),
            'personalNumber' => (string)($customer['personal_number'] ?? ''),
            'organizationNumber' => (string)($customer['organization_number'] ?? ''),
            'vatNumber' => (string)($customer['vat_number'] ?? ''),
            'rutEnabled' => !empty($customer['rut_enabled']),
            'customerNotes' => (string)($customer['notes'] ?? ''),
            'serviceType' => (string)($quote['service_type'] ?? ''),
            'description' => (string)($quote['description'] ?? ''),
            'quoteDate' => (string)($quote['issue_date'] ?? ''),
            'laborAmountExVat' => (string)($quote['labor_amount_ex_vat'] ?? 0),
            'materialAmountExVat' => (string)($quote['material_amount_ex_vat'] ?? 0),
            'otherAmountExVat' => (string)($quote['other_amount_ex_vat'] ?? 0),
            'quoteItems' => json_encode($quoteItemsForReject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'rejected',
            'validUntil' => (string)($quote['valid_until'] ?? ''),
            'notes' => append_status_reason_note((string)($quote['notes'] ?? ''), 'Kunden tackade nej', trim((string)($_POST['rejectReason'] ?? ''))),
        ];

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect(admin_url('quotes', ['edit_id' => $quoteId]));
        }

        mysql_save_quote($payload, build_customer_record([
            'customerType' => $customer['customer_type'] ?? 'private',
            'billingVatMode' => $customer['billing_vat_mode'] ?? 'standard_vat',
            'name' => $customer['name'] ?? '',
            'companyName' => $customer['company_name'] ?? '',
            'associationName' => $customer['association_name'] ?? '',
            'contactPerson' => $customer['contact_person'] ?? '',
            'phone' => $customer['phone'] ?? '',
            'email' => $customer['email'] ?? '',
            'serviceAddress' => $customer['service_address'] ?? $customer['address'] ?? '',
            'servicePostalCode' => $customer['service_postal_code'] ?? $customer['postal_code'] ?? '',
            'serviceCity' => $customer['service_city'] ?? $customer['city'] ?? '',
            'billingAddress' => $customer['billing_address'] ?? '',
            'billingPostalCode' => $customer['billing_postal_code'] ?? '',
            'billingCity' => $customer['billing_city'] ?? '',
            'personalNumber' => $customer['personal_number'] ?? '',
            'organizationNumber' => $customer['organization_number'] ?? '',
            'vatNumber' => $customer['vat_number'] ?? '',
            'rutEnabled' => !empty($customer['rut_enabled']),
            'rutUsedAmountThisYear' => $customer['rut_used_amount_this_year'] ?? 0,
            'notes' => $customer['notes'] ?? '',
            'regionId' => (string)($customer['region_id'] ?? ''),
            'firstName' => $customer['first_name'] ?? '',
            'lastName' => $customer['last_name'] ?? '',
        ], $customer), $quote, (int)($quote['customer_id'] ?? 0));

        set_flash('success', 'Offerten markerades som nekad.');
        redirect(admin_url('quotes', ['edit_id' => $quoteId]));
    }

    if ($action === 'create_job') {
        if (!current_user_can('jobs.manage')) {
            set_flash('error', 'Du har inte behörighet att hantera jobb.');
            redirect(admin_url('dashboard'));
        }

        $customerId = (int)($_POST['customerId'] ?? 0);
        $customer = find_by_id($data['customers'], $customerId);
        if ($customer === null) {
            set_flash('error', 'Kunden kunde inte hittas.');
            redirect(admin_url('jobs', ['view' => 'create']));
        }
        $payload = [
            'customerId' => $customerId,
            'quoteId' => trim((string)($_POST['quoteId'] ?? '')),
            'organizationId' => trim((string)($_POST['organizationId'] ?? ($customer['organization_id'] ?? ''))),
            'regionId' => trim((string)($_POST['regionId'] ?? ($customer['region_id'] ?? ''))),
            'serviceType' => trim((string)($_POST['serviceType'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'scheduledDate' => trim((string)($_POST['scheduledDate'] ?? '')),
            'scheduledTime' => trim((string)($_POST['scheduledTime'] ?? '')),
            'assignedTo' => trim((string)($_POST['assignedTo'] ?? '')),
            'status' => trim((string)($_POST['status'] ?? 'planned')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ];
        if (current_user_role() === USER_ROLE_WORKER && $currentUserRegionId !== null) {
            $payload['regionId'] = (string)$currentUserRegionId;
        }
        if (($payload['organizationId'] ?? '') === '' && !empty($data['organizations'][0]['id'])) {
            $payload['organizationId'] = (string)$data['organizations'][0]['id'];
        }
        if (($payload['regionId'] ?? '') === '' && ($payload['organizationId'] ?? '') !== '') {
            $organization = find_organization_by_id($data, (int)$payload['organizationId']);
            if ($organization !== null && ($organization['region_id'] ?? null) !== null) {
                $payload['regionId'] = (string)$organization['region_id'];
            }
        }
        $errors = validate_job_payload($payload, $customer, false);
        if ($errors !== []) {
            persist_form_error('job_create', $payload, $errors, admin_url('jobs', ['view' => 'create', 'customer_id' => $customerId]), 'Rätta de markerade jobbfälten.');
        }
        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect(admin_url('jobs', ['view' => 'create', 'customer_id' => $customerId]));
        }

        mysql_save_job($payload, $customer);
        clear_form_state('job_create');
        set_flash('success', 'Jobbet skapades.');
        redirect(admin_url('jobs'));
    }

    if ($action === 'create_job_from_quote') {
        if (!current_user_can('quotes.approve')) {
            set_flash('error', 'Du har inte behörighet att godkänna offerter.');
            redirect(admin_url('dashboard'));
        }

        $quoteId = (int)($_POST['quoteId'] ?? 0);
        $quote = find_by_id($data['quotes'], $quoteId);
        if ($quote === null) {
            set_flash('error', 'Offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }
        if ((string)($quote['status'] ?? '') === 'cancelled') {
            set_flash('error', 'En makulerad offert kan inte omvandlas till jobb.');
            redirect(admin_url('quotes', ['edit_id' => $quoteId]));
        }
        $customer = find_by_id($data['customers'], (int)$quote['customer_id']);
        if ($customer === null) {
            set_flash('error', 'Kunden för offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }
        if (!quote_can_progress_with_amount($quote)) {
            set_flash('error', 'Offerten måste ha ett belopp över 0 för att kunna bli ett jobb.');
            redirect(admin_url('quotes', ['edit_id' => $quoteId]));
        }

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect(admin_url('quotes'));
        }

        $assignedTo = trim((string)($_POST['assignedTo'] ?? ''));

        try {
            $jobId = (int)(QuoteApprovalService::approveAndCreateJob(admin_pdo(), $quoteId, $assignedTo)['job_id'] ?? 0);
            set_flash('success', 'Jobb skapades från offert.');
            redirect(admin_url('jobs', ['view' => 'edit', 'job_edit_id' => $jobId]));
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte skapa jobb från offert.');
            redirect(admin_url('quotes'));
        }
    }

    if ($action === 'send_quote') {
        if (!current_user_can('quotes.send')) {
            set_flash('error', 'Du har inte behörighet att skicka offerter.');
            redirect(admin_url('dashboard'));
        }

        $quoteId = (int)($_POST['quoteId'] ?? 0);
        if ($quoteId <= 0) {
            set_flash('error', 'Offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }
        $quote = find_by_id($data['quotes'], $quoteId);
        if ($quote === null) {
            set_flash('error', 'Offerten kunde inte hittas.');
            redirect(admin_url('quotes'));
        }
        if (!quote_can_progress_with_amount($quote)) {
            set_flash('error', 'Offerten måste ha ett belopp över 0 för att kunna skickas.');
            redirect(admin_url('quotes', ['edit_id' => $quoteId]));
        }

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att skicka offert.');
            redirect(admin_url('quotes', ['edit_id' => $quoteId]));
        }

        try {
            send_quote_email(admin_pdo(), $quoteId);
            set_flash('success', 'Offerten skickades via e-post.');
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte skicka offert.');
        }

        redirect(admin_url('quotes', ['edit_id' => $quoteId]));
    }

    if ($action === 'update_job') {
        if (!current_user_can('jobs.manage')) {
            set_flash('error', 'Du har inte behörighet att hantera jobb.');
            redirect(admin_url('dashboard'));
        }

        $jobReturnContext = job_return_context_from_post();
        $jobReturnParams = static fn(int $jobId): array => job_return_params($jobId, $jobReturnContext);
        $jobReturnBackUrl = job_return_back_url($jobReturnContext);

        $jobId = (int)($_POST['jobId'] ?? 0);
        $job = find_by_id($data['jobs'], $jobId);
        if ($job === null) {
            set_flash('error', 'Jobbet kunde inte hittas.');
            redirect(admin_url('jobs'));
        }
        $customer = find_by_id($data['customers'], (int)$job['customer_id']);
        if ($customer === null) {
            set_flash('error', 'Kunden för jobbet kunde inte hittas.');
            redirect(admin_url('jobs'));
        }
        $payload = [
            'id' => $jobId,
            'customerId' => (int)$job['customer_id'],
            'quoteId' => $job['quote_id'] ?? '',
            'organizationId' => trim((string)($_POST['organizationId'] ?? ($job['organization_id'] ?? $customer['organization_id'] ?? ''))),
            'regionId' => trim((string)($_POST['regionId'] ?? ($job['region_id'] ?? $customer['region_id'] ?? ''))),
            'serviceType' => trim((string)($_POST['serviceType'] ?? $job['service_type'])),
            'description' => trim((string)($_POST['description'] ?? $job['description'])),
            'scheduledDate' => trim((string)($_POST['scheduledDate'] ?? $job['scheduled_date'])),
            'scheduledTime' => trim((string)($_POST['scheduledTime'] ?? ($job['scheduled_time'] ?? ''))),
            'completedDate' => trim((string)($_POST['completedDate'] ?? '')),
            'assignedTo' => trim((string)($_POST['assignedTo'] ?? $job['assigned_to'])),
            'status' => trim((string)($_POST['status'] ?? $job['status'])),
            'finalLaborAmountExVat' => $_POST['finalLaborAmountExVat'] ?? 0,
            'finalMaterialAmountExVat' => $_POST['finalMaterialAmountExVat'] ?? 0,
            'finalOtherAmountExVat' => $_POST['finalOtherAmountExVat'] ?? 0,
            'readyForInvoicing' => isset($_POST['readyForInvoicing']),
            'notes' => trim((string)($_POST['notes'] ?? $job['notes'])),
        ];
        if (current_user_role() === USER_ROLE_WORKER && $currentUserRegionId !== null) {
            $payload['regionId'] = (string)$currentUserRegionId;
        }
        if (($payload['organizationId'] ?? '') === '' && !empty($data['organizations'][0]['id'])) {
            $payload['organizationId'] = (string)$data['organizations'][0]['id'];
        }
        if (($payload['regionId'] ?? '') === '' && ($payload['organizationId'] ?? '') !== '') {
            $organization = find_organization_by_id($data, (int)$payload['organizationId']);
            if ($organization !== null && ($organization['region_id'] ?? null) !== null) {
                $payload['regionId'] = (string)$organization['region_id'];
            }
        }
        $errors = validate_job_payload($payload, $customer, true);
        if ($errors !== []) {
            persist_form_error('job_edit_' . $jobId, $payload, $errors, admin_url('jobs', $jobReturnParams($jobId)), 'Rätta de markerade jobbfälten.');
        }
        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect(admin_url('jobs', $jobReturnParams($jobId)));
        }

        mysql_save_job($payload, $customer, $job);
        if ((string)($payload['status'] ?? '') === 'completed') {
            mysql_update_customer_maintenance_dates_for_completed_job($customer, ['completed_date' => (string)($payload['completedDate'] ?? '')]);
        }
        clear_form_state('job_edit_' . $jobId);
        set_flash('success', 'Jobbet uppdaterades.');
        redirect($jobReturnBackUrl);
    }

    if ($action === 'update_job_progress') {
        if (!current_user_can('jobs.progress')) {
            set_flash('error', 'Du har inte behörighet att uppdatera jobbstatus.');
            redirect(admin_url('dashboard'));
        }

        $jobReturnContext = job_return_context_from_post();
        $jobId = (int)($_POST['jobId'] ?? 0);
        $job = find_by_id($data['jobs'], $jobId);
        $jobReturnParams = static fn(int $jobId): array => job_return_params($jobId, $jobReturnContext);
        $jobReturnBackUrl = job_return_back_url($jobReturnContext);

        if ($job === null) {
            set_flash('error', 'Jobbet kunde inte hittas.');
            redirect(admin_url('jobs'));
        }

        if (current_user_role() === USER_ROLE_WORKER && trim((string)($job['assigned_to'] ?? '')) !== current_user_username()) {
            set_flash('error', 'Du kan bara uppdatera jobb som är tilldelade dig.');
            redirect(admin_url('jobs', $jobReturnParams($jobId)));
        }

        $customer = find_by_id($data['customers'], (int)$job['customer_id']);
        if ($customer === null) {
            set_flash('error', 'Kunden för jobbet kunde inte hittas.');
            redirect(admin_url('jobs', $jobReturnParams($jobId)));
        }

        $status = trim((string)($_POST['status'] ?? (string)($job['status'] ?? 'planned')));
        if (!in_array($status, ['planned', 'scheduled', 'in_progress', 'completed'], true)) {
            $status = (string)($job['status'] ?? 'planned');
        }

        $completedDate = trim((string)($_POST['completedDate'] ?? ''));
        if ($status === 'completed' && $completedDate === '') {
            $completedDate = date('Y-m-d');
        }
        if ($status !== 'completed') {
            $completedDate = '';
        }

        $payload = [
            'id' => $jobId,
            'customerId' => (int)$job['customer_id'],
            'quoteId' => $job['quote_id'] ?? '',
            'organizationId' => (string)($job['organization_id'] ?? $customer['organization_id'] ?? ''),
            'regionId' => (string)($job['region_id'] ?? $customer['region_id'] ?? ''),
            'serviceType' => (string)($job['service_type'] ?? ''),
            'description' => (string)($job['description'] ?? ''),
            'scheduledDate' => trim((string)($_POST['scheduledDate'] ?? (string)($job['scheduled_date'] ?? ''))),
            'scheduledTime' => trim((string)($_POST['scheduledTime'] ?? (trim((string)($job['scheduled_time'] ?? '')) !== '' ? substr((string)($job['scheduled_time'] ?? ''), 0, 5) : ''))),
            'completedDate' => $completedDate,
            'assignedTo' => (string)($job['assigned_to'] ?? ''),
            'status' => $status,
            'finalLaborAmountExVat' => $job['final_labor_amount_ex_vat'] ?? 0,
            'finalMaterialAmountExVat' => $job['final_material_amount_ex_vat'] ?? 0,
            'finalOtherAmountExVat' => $job['final_other_amount_ex_vat'] ?? 0,
            'readyForInvoicing' => !empty($job['ready_for_invoicing']),
            'notes' => trim((string)($_POST['notes'] ?? (string)($job['notes'] ?? ''))),
        ];

        $errors = validate_job_payload($payload, $customer, $status === 'completed');
        if ($errors !== []) {
            persist_form_error('job_edit_' . $jobId, $payload, $errors, admin_url('jobs', $jobReturnParams($jobId)), 'Rätta de markerade jobbfälten.');
        }

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect(admin_url('jobs', $jobReturnParams($jobId)));
        }

        mysql_save_job($payload, $customer, $job);
        if ($status === 'completed') {
            mysql_update_customer_maintenance_dates_for_completed_job($customer, ['completed_date' => $completedDate]);
        }
        clear_form_state('job_edit_' . $jobId);
        set_flash('success', 'Arbetsstatusen uppdaterades.');
        redirect($jobReturnBackUrl);
    }

    if ($action === 'cancel_job') {
        if (!current_user_can('jobs.manage')) {
            set_flash('error', 'Du har inte behörighet att hantera jobb.');
            redirect(admin_url('dashboard'));
        }

        $jobId = (int)($_POST['jobId'] ?? 0);
        $job = find_by_id($data['jobs'], $jobId);
        if ($job === null) {
            set_flash('error', 'Jobbet kunde inte hittas.');
            redirect(admin_url('jobs'));
        }

        $customer = find_by_id($data['customers'], (int)($job['customer_id'] ?? 0));
        if ($customer === null) {
            set_flash('error', 'Kunden för jobbet kunde inte hittas.');
            redirect(admin_url('jobs', ['view' => 'edit', 'job_edit_id' => $jobId]));
        }

        $cancellationBillingMode = trim((string)($_POST['cancellationBillingMode'] ?? 'none'));
        if (!in_array($cancellationBillingMode, ['none', 'fee', 'actual_cost'], true)) {
            $cancellationBillingMode = 'none';
        }
        $cancelReason = trim((string)($_POST['cancelReason'] ?? ''));
        $cancelLabor = to_float($_POST['cancelLaborAmountExVat'] ?? 0);
        $cancelMaterial = to_float($_POST['cancelMaterialAmountExVat'] ?? 0);
        $cancelOther = to_float($_POST['cancelOtherAmountExVat'] ?? 0);

        if ($cancellationBillingMode === 'none') {
            $cancelLabor = 0.0;
            $cancelMaterial = 0.0;
            $cancelOther = 0.0;
        }

        $cancelReadyForInvoicing = $cancellationBillingMode !== 'none' && ($cancelLabor > 0 || $cancelMaterial > 0 || $cancelOther > 0);
        $cancelNote = append_status_reason_note((string)($job['notes'] ?? ''), 'Avbrutet', $cancelReason);
        $cancelNote = append_status_reason_note($cancelNote, 'Debitering vid avbrott', cancellation_billing_mode_label($cancellationBillingMode));

        $payload = [
            'id' => $jobId,
            'customerId' => (int)($job['customer_id'] ?? 0),
            'quoteId' => (string)($job['quote_id'] ?? ''),
            'regionId' => (string)($job['region_id'] ?? $customer['region_id'] ?? ''),
            'serviceType' => (string)($job['service_type'] ?? ''),
            'description' => (string)($job['description'] ?? ''),
            'scheduledDate' => (string)($job['scheduled_date'] ?? ''),
            'scheduledTime' => trim((string)($job['scheduled_time'] ?? '')) !== '' ? substr((string)($job['scheduled_time'] ?? ''), 0, 5) : '',
            'completedDate' => (string)($job['completed_date'] ?? ''),
            'assignedTo' => (string)($job['assigned_to'] ?? ''),
            'status' => 'cancelled',
            'finalLaborAmountExVat' => $cancelLabor,
            'finalMaterialAmountExVat' => $cancelMaterial,
            'finalOtherAmountExVat' => $cancelOther,
            'readyForInvoicing' => $cancelReadyForInvoicing,
            'notes' => $cancelNote,
        ];

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect(admin_url('jobs', ['view' => 'edit', 'job_edit_id' => $jobId]));
        }

        mysql_save_job($payload, $customer, $job);
        set_flash('success', 'Jobbet avbröts.');
        redirect(admin_url('jobs', ['view' => 'edit', 'job_edit_id' => $jobId]));
    }

    if ($action === 'complete_job_and_invoice_now') {
        if (!current_user_can('jobs.complete_and_invoice')) {
            set_flash('error', 'Du har inte behörighet att slutföra och fakturera jobb.');
            redirect(admin_url('dashboard'));
        }

        $jobReturnContext = job_return_context_from_post();
        $jobId = (int)($_POST['jobId'] ?? 0);
        $job = find_by_id($data['jobs'], $jobId);
        $jobReturnParams = static fn(int $jobId): array => job_return_params($jobId, $jobReturnContext);

        if ($job === null) {
            set_flash('error', 'Jobbet kunde inte hittas.');
            redirect(admin_url('jobs'));
        }

        $customer = find_by_id($data['customers'], (int)$job['customer_id']);
        if ($customer === null) {
            set_flash('error', 'Kunden för jobbet kunde inte hittas.');
            redirect(admin_url('jobs', $jobReturnParams($jobId)));
        }

        $completedDate = trim((string)($_POST['completedDate'] ?? ''));
        if ($completedDate === '') {
            $completedDate = date('Y-m-d');
        }

        $payload = [
            'id' => $jobId,
            'customerId' => (int)$job['customer_id'],
            'quoteId' => $job['quote_id'] ?? '',
            'organizationId' => trim((string)($_POST['organizationId'] ?? ($job['organization_id'] ?? $customer['organization_id'] ?? ''))),
            'regionId' => trim((string)($_POST['regionId'] ?? ($job['region_id'] ?? $customer['region_id'] ?? ''))),
            'serviceType' => trim((string)($_POST['serviceType'] ?? $job['service_type'])),
            'description' => trim((string)($_POST['description'] ?? $job['description'])),
            'scheduledDate' => trim((string)($_POST['scheduledDate'] ?? $job['scheduled_date'])),
            'scheduledTime' => trim((string)($_POST['scheduledTime'] ?? ($job['scheduled_time'] ?? ''))),
            'completedDate' => $completedDate,
            'assignedTo' => trim((string)($_POST['assignedTo'] ?? $job['assigned_to'])),
            'status' => 'completed',
            'finalLaborAmountExVat' => $_POST['finalLaborAmountExVat'] ?? ($job['final_labor_amount_ex_vat'] ?? 0),
            'finalMaterialAmountExVat' => $_POST['finalMaterialAmountExVat'] ?? ($job['final_material_amount_ex_vat'] ?? 0),
            'finalOtherAmountExVat' => $_POST['finalOtherAmountExVat'] ?? ($job['final_other_amount_ex_vat'] ?? 0),
            'readyForInvoicing' => true,
            'notes' => trim((string)($_POST['notes'] ?? $job['notes'])),
        ];

        $errors = validate_job_payload($payload, $customer, true);
        if ($errors !== []) {
            persist_form_error('job_edit_' . $jobId, $payload, $errors, admin_url('jobs', $jobReturnParams($jobId)), 'Rätta de markerade jobbfälten innan fakturering.');
        }

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
            redirect(admin_url('jobs', $jobReturnParams($jobId)));
        }

        try {
            mysql_save_job($payload, $customer, $job);
            mysql_update_customer_maintenance_dates_for_completed_job($customer, ['completed_date' => $completedDate]);
            clear_form_state('job_edit_' . $jobId);

            $freshData = load_data_mysql();
            $invoiceBaseRecord = find_invoice_base_record_by_job_id($freshData['invoice_bases'] ?? [], $jobId);

            if (!is_array($invoiceBaseRecord) || (int)($invoiceBaseRecord['id'] ?? 0) <= 0) {
                throw new RuntimeException('Fakturaunderlag kunde inte skapas för jobbet.');
            }

            $result = createFortnoxInvoiceFromInvoiceBasis((int)$invoiceBaseRecord['id']);
            $reference = trim((string)($result['fortnox_document_number'] ?? $result['fortnox_invoice_number'] ?? ''));

            set_flash(
                'success',
                (use_mock_fortnox() ? 'Jobbet markerades klart och mockfakturerades' : 'Jobbet markerades klart och fakturerades')
                . ($reference !== '' ? ' (' . $reference . ')' : '')
                . '.'
            );
        } catch (Throwable $exception) {
            set_flash(
                'error',
                'Jobbet markerades som klart, men fakturan skapades inte: '
                . ($exception->getMessage() !== '' ? $exception->getMessage() : 'okänt fel') 
            );
        }

        redirect(admin_url('jobs', $jobReturnParams($jobId)));
    }

    if ($action === 'assign_job_worker') {
        if (!current_user_can('jobs.assign')) {
            set_flash('error', 'Du har inte behörighet att tilldela jobb.');
            redirect(admin_url('dashboard'));
        }

        $jobReturnContext = job_return_context_from_post();
        $jobId = (int)($_POST['jobId'] ?? 0);
        $job = find_by_id($data['jobs'], $jobId);

        if ($job === null) {
            set_flash('error', 'Jobbet kunde inte hittas.');
            redirect(admin_url('jobs'));
        }

        $customer = find_by_id($data['customers'], (int)$job['customer_id']);
        if ($customer === null) {
            set_flash('error', 'Kunden för jobbet kunde inte hittas.');
            redirect(admin_url('jobs'));
        }

        $assignedTo = trim((string)($_POST['assignedTo'] ?? ''));
        $payload = [
            'id' => $jobId,
            'customerId' => (int)$job['customer_id'],
            'quoteId' => $job['quote_id'] ?? '',
            'regionId' => (string)($job['region_id'] ?? $customer['region_id'] ?? ''),
            'serviceType' => (string)($job['service_type'] ?? ''),
            'description' => (string)($job['description'] ?? ''),
            'scheduledDate' => (string)($job['scheduled_date'] ?? ''),
            'scheduledTime' => trim((string)($job['scheduled_time'] ?? '')) !== '' ? substr((string)($job['scheduled_time'] ?? ''), 0, 5) : '',
            'completedDate' => (string)($job['completed_date'] ?? ''),
            'assignedTo' => $assignedTo,
            'status' => (string)($job['status'] ?? 'planned'),
            'finalLaborAmountExVat' => $job['final_labor_amount_ex_vat'] ?? 0,
            'finalMaterialAmountExVat' => $job['final_material_amount_ex_vat'] ?? 0,
            'finalOtherAmountExVat' => $job['final_other_amount_ex_vat'] ?? 0,
            'readyForInvoicing' => !empty($job['ready_for_invoicing']),
            'notes' => (string)($job['notes'] ?? ''),
        ];

        if (!mysql_is_configured()) {
            set_flash('error', 'MySQL måste vara konfigurerat för att spara i adminsystemet.');
        } else {
            mysql_save_job($payload, $customer, $job);
            set_flash('success', 'Ansvarig arbetare uppdaterades.');
        }

        $redirectUrl = job_return_back_url($jobReturnContext);

        redirect($redirectUrl);
    }

    if ($action === 'export_invoice_basis') {
        require_capability('invoices.manage');

        $invoiceBaseId = (int)($_POST['invoiceBaseId'] ?? 0);
        $returnView = trim((string)($_POST['returnView'] ?? 'ready'));
        $redirectUrl = admin_url('invoices', ['view' => $returnView !== '' ? $returnView : 'ready']);

        if ($invoiceBaseId <= 0) {
            set_flash('error', 'Fakturaunderlaget kunde inte hittas.');
            redirect($redirectUrl);
        }

        $invoiceBaseRecord = null;
        foreach ($invoiceBases as $candidate) {
            if ((int)($candidate['id'] ?? 0) === $invoiceBaseId) {
                $invoiceBaseRecord = $candidate;
                break;
            }
        }

        if (!is_array($invoiceBaseRecord)) {
            set_flash('error', 'Fakturaunderlaget kunde inte hittas.');
            redirect($redirectUrl);
        }

        $jobId = (int)($invoiceBaseRecord['job_id'] ?? 0);

        try {
            validateInvoiceBaseForFortnoxExport($jobId, $invoiceBasesByJobId);
            $result = createFortnoxInvoiceFromInvoiceBasis($invoiceBaseId);

            $reference = trim((string)($result['fortnox_document_number'] ?? $result['fortnox_invoice_number'] ?? ''));
            $successMessage = use_mock_fortnox()
                ? 'Mockexport till Fortnox genomförd' . ($reference !== '' ? ' (' . $reference . ')' : '') . '.'
                : 'Exporterad till Fortnox' . ($reference !== '' ? ' (' . $reference . ')' : '') . '.';
            set_flash('success', $successMessage);
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte exportera till Fortnox.');
        }

        redirect($redirectUrl);
    }

    if ($action === 'create_user' || $action === 'update_user') {
        require_capability('users.manage');

        $userId = (int)($_POST['userId'] ?? 0);
        $existingUser = $action === 'update_user' ? find_user_by_id($data, $userId) : null;

        if ($action === 'update_user' && $existingUser === null) {
            set_flash('error', 'Användaren kunde inte hittas.');
            redirect(admin_url('settings', ['view' => 'users']));
        }

        $payload = [
            'id' => $userId,
            'username' => trim((string)($_POST['username'] ?? '')),
            'name' => trim((string)($_POST['name'] ?? '')),
            'role' => normalize_user_role((string)($_POST['role'] ?? USER_ROLE_WORKER)),
            'roles' => array_values(array_filter((array)($_POST['roles'] ?? []), static fn(mixed $value): bool => trim((string)$value) !== '')),
            'organizationId' => trim((string)($_POST['organizationId'] ?? '')),
            'regionId' => trim((string)($_POST['regionId'] ?? '')),
            'isActive' => (string)($_POST['isActive'] ?? '1'),
            'password' => (string)($_POST['password'] ?? ''),
            'passwordConfirm' => (string)($_POST['passwordConfirm'] ?? ''),
        ];

        $errors = validate_user_payload($payload, $existingUser !== null);
        if ($errors !== []) {
            $formKey = $existingUser !== null ? 'user_edit_' . $userId : 'user_create';
            $redirectParams = ['view' => 'users'];
            if ($userId > 0) {
                $redirectParams['edit_user_id'] = $userId;
            }
            persist_form_error($formKey, $payload, $errors, admin_url('settings', $redirectParams), 'Rätta de markerade användarfälten.');
        }

        try {
            $savedUser = mysql_save_user($payload, $existingUser);
            clear_form_state($existingUser !== null ? 'user_edit_' . $userId : 'user_create');
            set_flash('success', $existingUser !== null ? 'Användaren uppdaterades.' : 'Användaren skapades.');
            redirect(admin_url('settings', ['view' => 'users', 'edit_user_id' => (int)$savedUser['id']]));
        } catch (Throwable $exception) {
            $formKey = $existingUser !== null ? 'user_edit_' . $userId : 'user_create';
            $redirectParams = ['view' => 'users'];
            if ($userId > 0) {
                $redirectParams['edit_user_id'] = $userId;
            }
            persist_form_error($formKey, $payload, ['username' => $exception->getMessage()], admin_url('settings', $redirectParams), $exception->getMessage());
        }
    }

    if ($action === 'toggle_user_active') {
        require_capability('users.manage');

        $userId = (int)($_POST['userId'] ?? 0);
        $isActive = (string)($_POST['isActive'] ?? '1') === '1';

        try {
            mysql_set_user_active($userId, $isActive);
            set_flash('success', $isActive ? 'Användaren aktiverades.' : 'Användaren blockerades.');
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte uppdatera användaren.');
        }

        redirect(admin_url('settings', ['view' => 'users', 'edit_user_id' => $userId]));
    }

    if ($action === 'delete_user') {
        require_capability('users.manage');

        $userId = (int)($_POST['userId'] ?? 0);

        try {
            mysql_delete_user($userId);
            set_flash('success', 'Användaren raderades.');
            redirect(admin_url('settings', ['view' => 'users']));
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte radera användaren.');
            redirect(admin_url('settings', ['view' => 'users', 'edit_user_id' => $userId]));
        }
    }

    if ($action === 'create_region' || $action === 'update_region') {
        require_capability('settings.manage');

        $regionId = (int)($_POST['regionId'] ?? 0);
        $existingRegion = $action === 'update_region' ? find_region_by_id($data, $regionId) : null;

        if ($action === 'update_region' && $existingRegion === null) {
            set_flash('error', 'Regionen kunde inte hittas.');
            redirect(admin_url('settings', ['view' => 'regions']));
        }

        $payload = [
            'id' => $regionId,
            'name' => trim((string)($_POST['name'] ?? '')),
            'slug' => trim((string)($_POST['slug'] ?? '')),
            'isActive' => (string)($_POST['isActive'] ?? '1'),
        ];

        $errors = validate_region_payload($payload);
        if ($errors !== []) {
            $formKey = $existingRegion !== null ? 'region_edit_' . $regionId : 'region_create';
            $redirectParams = ['view' => 'regions'];
            if ($regionId > 0) {
                $redirectParams['edit_region_id'] = $regionId;
            }
            persist_form_error($formKey, $payload, $errors, admin_url('settings', $redirectParams), 'Rätta de markerade regionfälten.');
        }

        try {
            $savedRegion = mysql_save_region($payload, $existingRegion);
            clear_form_state($existingRegion !== null ? 'region_edit_' . $regionId : 'region_create');
            set_flash('success', $existingRegion !== null ? 'Regionen uppdaterades.' : 'Regionen skapades.');
            redirect(admin_url('settings', ['view' => 'regions', 'edit_region_id' => (int)$savedRegion['id']]));
        } catch (Throwable $exception) {
            $formKey = $existingRegion !== null ? 'region_edit_' . $regionId : 'region_create';
            $redirectParams = ['view' => 'regions'];
            if ($regionId > 0) {
                $redirectParams['edit_region_id'] = $regionId;
            }
            persist_form_error($formKey, $payload, ['name' => $exception->getMessage()], admin_url('settings', $redirectParams), $exception->getMessage());
        }
    }

    if ($action === 'create_organization' || $action === 'update_organization') {
        require_capability('settings.manage');

        $organizationId = (int)($_POST['organizationId'] ?? 0);
        $existingOrganization = $action === 'update_organization' ? find_organization_by_id($data, $organizationId) : null;

        if ($action === 'update_organization' && $existingOrganization === null) {
            set_flash('error', 'Organisationen kunde inte hittas.');
            redirect(admin_url('settings', ['view' => 'organizations']));
        }

        $payload = [
            'id' => $organizationId,
            'name' => trim((string)($_POST['name'] ?? '')),
            'slug' => trim((string)($_POST['slug'] ?? '')),
            'organizationType' => trim((string)($_POST['organizationType'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT)),
            'parentOrganizationId' => trim((string)($_POST['parentOrganizationId'] ?? '')),
            'regionId' => trim((string)($_POST['regionId'] ?? '')),
            'servicePostcodePrefixes' => trim((string)($_POST['servicePostcodePrefixes'] ?? '')),
            'serviceCities' => trim((string)($_POST['serviceCities'] ?? '')),
            'isActive' => (string)($_POST['isActive'] ?? '1'),
        ];

        $errors = validate_organization_payload($payload);
        if ($errors !== []) {
            $formKey = $existingOrganization !== null ? 'organization_edit_' . $organizationId : 'organization_create';
            $redirectParams = ['view' => 'organizations'];
            if ($organizationId > 0) {
                $redirectParams['edit_organization_id'] = $organizationId;
            }
            persist_form_error($formKey, $payload, $errors, admin_url('settings', $redirectParams), 'Rätta de markerade organisationsfälten.');
        }

        try {
            $savedOrganization = mysql_save_organization($payload, $existingOrganization);
            clear_form_state($existingOrganization !== null ? 'organization_edit_' . $organizationId : 'organization_create');
            set_flash('success', $existingOrganization !== null ? 'Organisationen uppdaterades.' : 'Organisationen skapades.');
            redirect(admin_url('settings', ['view' => 'organizations', 'edit_organization_id' => (int)$savedOrganization['id']]));
        } catch (Throwable $exception) {
            $formKey = $existingOrganization !== null ? 'organization_edit_' . $organizationId : 'organization_create';
            $redirectParams = ['view' => 'organizations'];
            if ($organizationId > 0) {
                $redirectParams['edit_organization_id'] = $organizationId;
            }
            persist_form_error($formKey, $payload, ['name' => $exception->getMessage()], admin_url('settings', $redirectParams), $exception->getMessage());
        }
    }

    if ($action === 'delete_organization') {
        require_capability('settings.manage');

        $organizationId = (int)($_POST['organizationId'] ?? 0);

        try {
            mysql_delete_organization($organizationId);
            set_flash('success', 'Organisationen raderades.');
            redirect(admin_url('settings', ['view' => 'organizations']));
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte radera organisationen.');
            redirect(admin_url('settings', ['view' => 'organizations', 'edit_organization_id' => $organizationId]));
        }
    }

    if ($action === 'delete_region') {
        require_capability('settings.manage');

        $regionId = (int)($_POST['regionId'] ?? 0);

        try {
            mysql_delete_region($regionId);
            set_flash('success', 'Regionen raderades.');
            redirect(admin_url('settings', ['view' => 'regions']));
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte radera regionen.');
            redirect(admin_url('settings', ['view' => 'regions', 'edit_region_id' => $regionId]));
        }
    }

    if ($action === 'update_web_quote_request_status') {
        require_capability('requests.manage');

        $requestId = (int)($_POST['requestId'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'new'));

        try {
            mysql_update_web_quote_request_status($requestId, $status, current_user_username());
            set_flash('success', 'Förfrågan uppdaterades.');
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte uppdatera förfrågan.');
        }

        redirect(admin_url('requests', ['view' => in_array($view, ['all', 'new', 'handled', 'archived'], true) ? $view : 'all']));
    }

    if ($action === 'create_product' || $action === 'update_product') {
        require_capability('products.manage');

        $productId = (int)($_POST['productId'] ?? 0);
        $existingProduct = $action === 'update_product' ? find_product_by_id($data, $productId) : null;

        if ($action === 'update_product' && $existingProduct === null) {
            set_flash('error', 'Produkten kunde inte hittas.');
            redirect(admin_url('settings', ['view' => 'products']));
        }

        $payload = [
            'id' => $productId,
            'name' => trim((string)($_POST['name'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'category' => trim((string)($_POST['category'] ?? '')),
            'itemType' => (string)($_POST['itemType'] ?? 'service'),
            'priceModel' => (string)($_POST['priceModel'] ?? 'fixed'),
            'defaultQuantity' => (string)($_POST['defaultQuantity'] ?? '1'),
            'unit' => trim((string)($_POST['unit'] ?? '')),
            'defaultUnitPrice' => (string)($_POST['defaultUnitPrice'] ?? '0'),
            'vatRatePercent' => (string)($_POST['vatRatePercent'] ?? '25'),
            'isRutEligible' => isset($_POST['isRutEligible']) ? '1' : '0',
            'isActive' => (string)($_POST['isActive'] ?? '1'),
        ];

        $errors = validate_product_payload($payload);
        if ($errors !== []) {
            $formKey = $existingProduct !== null ? 'product_edit_' . $productId : 'product_create';
            $redirectParams = ['view' => 'products'];
            if ($productId > 0) {
                $redirectParams['edit_product_id'] = $productId;
            }
            persist_form_error($formKey, $payload, $errors, admin_url('settings', $redirectParams), 'Rätta de markerade produktfälten.');
        }

        try {
            $savedProduct = mysql_save_product($payload, $existingProduct);
            clear_form_state($existingProduct !== null ? 'product_edit_' . $productId : 'product_create');
            set_flash('success', $existingProduct !== null ? 'Produkten uppdaterades.' : 'Produkten skapades.');
            redirect(admin_url('settings', ['view' => 'products', 'edit_product_id' => (int)$savedProduct['id']]) . '#products-editor');
        } catch (Throwable $exception) {
            $formKey = $existingProduct !== null ? 'product_edit_' . $productId : 'product_create';
            $redirectParams = ['view' => 'products'];
            if ($productId > 0) {
                $redirectParams['edit_product_id'] = $productId;
            }
            persist_form_error($formKey, $payload, ['name' => $exception->getMessage()], admin_url('settings', $redirectParams), $exception->getMessage());
        }
    }

    if ($action === 'delete_product') {
        require_capability('products.manage');

        $productId = (int)($_POST['productId'] ?? 0);

        try {
            mysql_delete_product($productId);
            set_flash('success', 'Produkten raderades.');
            redirect(admin_url('settings', ['view' => 'products']));
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte radera produkten.');
            redirect(admin_url('settings', ['view' => 'products', 'edit_product_id' => $productId]));
        }
    }

    if ($action === 'create_package' || $action === 'update_package') {
        require_capability('packages.manage');

        $packageId = (int)($_POST['packageId'] ?? 0);
        $existingPackage = $action === 'update_package' ? find_service_package_by_id($data, $packageId) : null;

        if ($action === 'update_package' && $existingPackage === null) {
            set_flash('error', 'Paketet kunde inte hittas.');
            redirect(admin_url('settings', ['view' => 'packages']));
        }

        $payload = [
            'id' => $packageId,
            'name' => trim((string)($_POST['name'] ?? '')),
            'serviceFamily' => (string)($_POST['serviceFamily'] ?? 'general'),
            'description' => trim((string)($_POST['description'] ?? '')),
            'isActive' => (string)($_POST['isActive'] ?? '1'),
            'sortOrder' => (string)($_POST['sortOrder'] ?? '0'),
            'packageItems' => build_package_items_payload($_POST),
        ];

        $errors = validate_package_payload($payload, $data['products'] ?? []);
        if ($errors !== []) {
            $formKey = $existingPackage !== null ? 'package_edit_' . $packageId : 'package_create';
            $redirectParams = ['view' => 'packages'];
            if ($packageId > 0) {
                $redirectParams['edit_package_id'] = $packageId;
            }
            persist_form_error($formKey, $payload, $errors, admin_url('settings', $redirectParams) . '#packages-editor', 'Rätta de markerade paketfälten.');
        }

        try {
            $savedPackage = mysql_save_service_package($payload, $existingPackage);
            clear_form_state($existingPackage !== null ? 'package_edit_' . $packageId : 'package_create');
            set_flash('success', $existingPackage !== null ? 'Paketet uppdaterades.' : 'Paketet skapades.');
            redirect(admin_url('settings', ['view' => 'packages', 'edit_package_id' => (int)$savedPackage['id']]) . '#packages-editor');
        } catch (Throwable $exception) {
            $formKey = $existingPackage !== null ? 'package_edit_' . $packageId : 'package_create';
            $redirectParams = ['view' => 'packages'];
            if ($packageId > 0) {
                $redirectParams['edit_package_id'] = $packageId;
            }
            persist_form_error($formKey, $payload, ['name' => $exception->getMessage()], admin_url('settings', $redirectParams) . '#packages-editor', $exception->getMessage());
        }
    }

    if ($action === 'delete_package') {
        require_capability('packages.manage');

        $packageId = (int)($_POST['packageId'] ?? 0);

        try {
            mysql_delete_service_package($packageId);
            set_flash('success', 'Paketet raderades.');
            redirect(admin_url('settings', ['view' => 'packages']));
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Kunde inte radera paketet.');
            redirect(admin_url('settings', ['view' => 'packages', 'edit_package_id' => $packageId]) . '#packages-editor');
        }
    }
}

$page = (string)($_GET['page'] ?? 'dashboard');
$view = (string)($_GET['view'] ?? '');
$search = trim((string)($_GET['q'] ?? ''));
$customerOrganizationFilter = trim((string)($_GET['customer_organization'] ?? ''));
$customerTypeFilter = trim((string)($_GET['customer_type'] ?? ''));
$customerVatModeFilter = trim((string)($_GET['customer_vat_mode'] ?? ''));
$customerRutFilter = trim((string)($_GET['customer_rut'] ?? ''));
$quoteSearch = trim((string)($_GET['quote_q'] ?? ''));
$quoteOrganizationFilter = trim((string)($_GET['quote_organization'] ?? ''));
$quoteCustomerTypeFilter = trim((string)($_GET['quote_customer_type'] ?? ''));
$quoteVatModeFilter = trim((string)($_GET['quote_vat_mode'] ?? ''));
$jobSearch = trim((string)($_GET['job_q'] ?? ''));
$jobOrganizationFilter = trim((string)($_GET['job_organization'] ?? ''));
$jobCustomerTypeFilter = trim((string)($_GET['job_customer_type'] ?? ''));
$jobInvoiceReadyFilter = trim((string)($_GET['job_invoice_ready'] ?? ''));
$invoiceSearch = trim((string)($_GET['invoice_q'] ?? ''));
$invoiceOrganizationFilter = trim((string)($_GET['invoice_organization'] ?? ''));
$invoiceCustomerTypeFilter = trim((string)($_GET['invoice_customer_type'] ?? ''));
$invoiceStatusFilter = trim((string)($_GET['invoice_status_filter'] ?? ''));
$requestSearch = trim((string)($_GET['request_q'] ?? ''));
$requestOrganizationFilter = trim((string)($_GET['request_organization'] ?? ''));
$reportPeriod = trim((string)($_GET['report_period'] ?? 'month'));
$reportOrganizationFilter = trim((string)($_GET['report_organization'] ?? ''));
$productSearch = trim((string)($_GET['product_q'] ?? ''));
$productCategoryFilter = trim((string)($_GET['product_category'] ?? ''));
$productStatusFilter = trim((string)($_GET['product_status'] ?? ''));
$productItemTypeFilter = trim((string)($_GET['product_item_type'] ?? ''));
$productPriceModelFilter = trim((string)($_GET['product_price_model'] ?? ''));
$packageSearch = trim((string)($_GET['package_q'] ?? ''));
$dashboardOrganizationFilter = trim((string)($_GET['dashboard_organization'] ?? ''));
$calendarOrganizationFilter = trim((string)($_GET['calendar_organization'] ?? ''));
$calendarRegionFilter = trim((string)($_GET['calendar_region'] ?? ''));
$calendarWorkerFilter = trim((string)($_GET['calendar_worker'] ?? ''));
$jobReturnContext = job_return_context_from_get();
$returnPage = $jobReturnContext['page'];
$returnView = $jobReturnContext['view'];
$returnWeek = $jobReturnContext['week'];
$returnCalendarOrganization = $jobReturnContext['calendar_organization'];
$returnCalendarRegion = $jobReturnContext['calendar_region'];
$returnCalendarWorker = $jobReturnContext['calendar_worker'];
$customerId = (int)($_GET['id'] ?? 0);
$weekOffset = (int)($_GET['week'] ?? 0);
$editCustomer = ((int)($_GET['edit'] ?? 0)) === 1;
$editQuoteId = (int)($_GET['edit_id'] ?? 0);
$editJobId = (int)($_GET['job_edit_id'] ?? 0);
$editUserId = (int)($_GET['edit_user_id'] ?? 0);
$editProductId = (int)($_GET['edit_product_id'] ?? 0);
$editPackageId = (int)($_GET['edit_package_id'] ?? 0);
$editRegionId = (int)($_GET['edit_region_id'] ?? 0);
$editOrganizationId = (int)($_GET['edit_organization_id'] ?? 0);

if (in_array(current_user_role(), [USER_ROLE_SALES, USER_ROLE_WORKER], true) && ($currentUserOrganizationId !== null || $currentUserRegionId !== null)) {
    $data['customers'] = array_values(array_filter(
        $data['customers'] ?? [],
        static function (array $customer) use ($currentUserOrganizationId, $currentUserRegionId): bool {
            if ($currentUserOrganizationId !== null) {
                if (($customer['organization_id'] ?? null) !== null) {
                    return record_matches_organization($customer, $currentUserOrganizationId);
                }

                return $currentUserRegionId !== null && record_matches_region($customer, $currentUserRegionId);
            }

            return $currentUserRegionId !== null && record_matches_region($customer, $currentUserRegionId);
        }
    ));
    $allowedCustomerIds = array_map(static fn(array $customer): int => (int)($customer['id'] ?? 0), $data['customers']);

    $data['quotes'] = array_values(array_filter(
        $data['quotes'] ?? [],
        static function (array $quote) use ($allowedCustomerIds, $currentUserOrganizationId): bool {
            if ($currentUserOrganizationId !== null && ($quote['organization_id'] ?? null) !== null && $quote['organization_id'] !== '') {
                return record_matches_organization($quote, $currentUserOrganizationId);
            }

            return in_array((int)($quote['customer_id'] ?? 0), $allowedCustomerIds, true);
        }
    ));
    $allowedQuoteIds = array_map(static fn(array $quote): int => (int)($quote['id'] ?? 0), $data['quotes']);
    $data['quote_items'] = array_values(array_filter(
        $data['quote_items'] ?? [],
        static fn(array $item): bool => in_array((int)($item['quote_id'] ?? 0), $allowedQuoteIds, true)
    ));

    $data['jobs'] = array_values(array_filter(
        $data['jobs'] ?? [],
        static function (array $job) use ($currentUserOrganizationId, $currentUserRegionId, $allowedCustomerIds): bool {
            $jobOrganizationId = $job['organization_id'] ?? null;
            if ($currentUserOrganizationId !== null && $jobOrganizationId !== null && $jobOrganizationId !== '') {
                return record_matches_organization($job, $currentUserOrganizationId);
            }

            $jobRegionId = $job['region_id'] ?? null;

            if ($jobRegionId !== null && $jobRegionId !== '') {
                return $currentUserRegionId !== null && record_matches_region($job, $currentUserRegionId);
            }

            return in_array((int)($job['customer_id'] ?? 0), $allowedCustomerIds, true);
        }
    ));
    $allowedJobIds = array_map(static fn(array $job): int => (int)($job['id'] ?? 0), $data['jobs']);
    $data['job_items'] = array_values(array_filter(
        $data['job_items'] ?? [],
        static fn(array $item): bool => in_array((int)($item['job_id'] ?? 0), $allowedJobIds, true)
    ));

    $data['invoice_bases'] = array_values(array_filter(
        $data['invoice_bases'] ?? [],
        static fn(array $basis): bool => in_array((int)($basis['job_id'] ?? 0), $allowedJobIds, true)
    ));
    $allowedInvoiceBaseIds = array_map(static fn(array $basis): int => (int)($basis['id'] ?? 0), $data['invoice_bases']);
    $data['invoice_base_items'] = array_values(array_filter(
        $data['invoice_base_items'] ?? [],
        static fn(array $item): bool => in_array((int)($item['invoice_base_id'] ?? 0), $allowedInvoiceBaseIds, true)
    ));
}

$defaultViews = [
    'customers' => 'all',
    'quotes' => 'all',
    'jobs' => 'all',
    'calendar' => 'week',
    'invoices' => 'ready',
    'reports' => 'overview',
    'settings' => 'general',
];

if ($view === '' && isset($defaultViews[$page])) {
    $view = $defaultViews[$page];
}

if ($editQuoteId > 0) {
    $page = 'quotes';
    $view = 'edit';
}

if ($editJobId > 0) {
    $page = 'jobs';
    $view = 'edit';
}

if (!current_user_can(page_capability($page))) {
    set_flash('error', 'Du har inte behörighet till den sidan.');
    redirect(admin_url('dashboard'));
}

if ($page === 'reports' && current_user_role() !== USER_ROLE_ADMIN) {
    set_flash('error', 'Rapporter är bara tillgängligt för admin.');
    redirect(admin_url('dashboard'));
}

if ($page === 'jobs' && $view === 'create' && !current_user_can('jobs.manage')) {
    set_flash('error', 'Du kan se jobb men inte skapa nya.');
    redirect(admin_url('calendar'));
}

$customers = $data['customers'];
$quotes = $data['quotes'];
$jobs = $data['jobs'];

usort($customers, static fn(array $a, array $b): int => strcmp($b['last_activity'], $a['last_activity']));
usort($quotes, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
usort($jobs, static fn(array $a, array $b): int => strcmp($a['scheduled_date'], $b['scheduled_date']));

$filteredCustomers = array_values(array_filter($customers, static function (array $customer) use ($search): bool {
    if ($search === '') {
        return true;
    }
    $haystack = mb_strtolower(implode(' ', [
        $customer['name'],
        $customer['company_name'],
        $customer['association_name'] ?? '',
        $customer['contact_person'] ?? '',
        $customer['phone'],
        $customer['email'],
        $customer['service_postal_code'] ?? $customer['postal_code'],
        $customer['service_city'] ?? $customer['city'],
        $customer['billing_postal_code'] ?? '',
        $customer['billing_city'] ?? '',
        customer_type_label($customer['customer_type']),
        vat_mode_label($customer['billing_vat_mode']),
        customer_identifier_value($customer),
        $customer['vat_number'],
    ]), 'UTF-8');

    return str_contains($haystack, mb_strtolower($search, 'UTF-8'));
}));
if ($customerOrganizationFilter !== '' && current_user_role() === USER_ROLE_ADMIN) {
    $filteredCustomers = array_values(array_filter(
        $filteredCustomers,
        static fn(array $customer): bool => customer_matches_organization_scope($customer, (int)$customerOrganizationFilter)
    ));
}
if ($customerTypeFilter !== '') {
    $filteredCustomers = array_values(array_filter($filteredCustomers, static fn(array $customer): bool => (string)($customer['customer_type'] ?? 'private') === $customerTypeFilter));
}
if ($customerVatModeFilter !== '') {
    $filteredCustomers = array_values(array_filter($filteredCustomers, static fn(array $customer): bool => (string)($customer['billing_vat_mode'] ?? 'standard_vat') === $customerVatModeFilter));
}
if ($customerRutFilter !== '') {
    $filteredCustomers = array_values(array_filter($filteredCustomers, static function (array $customer) use ($customerRutFilter): bool {
        $rutEnabled = !empty($customer['rut_enabled']);
        return $customerRutFilter === '1' ? $rutEnabled : !$rutEnabled;
    }));
}

$today = date('Y-m-d');
$followUpCustomers = array_values(array_filter($filteredCustomers, static function (array $customer) use ($today): bool {
    if ((string)($customer['service_type'] ?? 'single') !== 'maintenance') {
        return false;
    }

    return follow_up_date_state((string)($customer['next_service_date'] ?? ''), $today) !== null;
}));

$selectedCustomer = $customerId > 0 ? find_by_id($customers, $customerId) : null;
$customerQuotes = $selectedCustomer ? array_values(array_filter($quotes, static fn(array $quote): bool => (int)$quote['customer_id'] === (int)$selectedCustomer['id'])) : [];
$customerJobs = $selectedCustomer ? array_values(array_filter($jobs, static fn(array $job): bool => (int)$job['customer_id'] === (int)$selectedCustomer['id'])) : [];
$selectedQuote = $editQuoteId > 0 ? find_by_id($quotes, $editQuoteId) : null;
$selectedQuoteCustomer = $selectedQuote ? find_by_id($customers, (int)($selectedQuote['customer_id'] ?? 0)) : null;
$selectedQuoteItems = $selectedQuote ? array_values(array_filter(
    $data['quote_items'] ?? [],
    static fn(array $item): bool => (int)($item['quote_id'] ?? 0) === (int)($selectedQuote['id'] ?? 0)
)) : [];
$selectedQuoteSignature = null;
if ($selectedQuote !== null && mysql_is_configured()) {
    try {
        $selectedQuoteSignature = latest_quote_signature(admin_pdo(), (int)$selectedQuote['id']);
    } catch (Throwable) {
        $selectedQuoteSignature = null;
    }
}
$selectedJob = $editJobId > 0 ? find_by_id($jobs, $editJobId) : null;

$soonThreshold = date('Y-m-d', strtotime('+10 days'));

if (current_user_role() === USER_ROLE_WORKER) {
    $workerIdentityTokens = array_values(array_filter([
        normalize_assignment_token(current_user_username()),
        normalize_assignment_token(current_user_name()),
    ], static fn(string $value): bool => $value !== ''));

    $jobs = array_values(array_filter($jobs, static function (array $job) use ($workerIdentityTokens): bool {
        $assignedTo = normalize_assignment_token((string)($job['assigned_to'] ?? $job['assignedTo'] ?? ''));
        return $assignedTo !== '' && in_array($assignedTo, $workerIdentityTokens, true);
    }));
}

$isAdminUser = current_user_role() === USER_ROLE_ADMIN;
$isSalesUser = current_user_role() === USER_ROLE_SALES;
$isWorkerUser = current_user_role() === USER_ROLE_WORKER;
$currentUsernameToken = normalize_assignment_token(current_user_username());
$sellerOwnedQuotes = $isSalesUser
    ? array_values(array_filter($quotes, static function (array $quote) use ($currentUsernameToken): bool {
        return $currentUsernameToken !== ''
            && normalize_assignment_token((string)($quote['created_by_username'] ?? '')) === $currentUsernameToken;
    }))
    : [];

$dashboardScopedCustomers = $customers;
$dashboardScopedQuotes = $quotes;
$dashboardScopedJobs = $jobs;
if ($dashboardOrganizationFilter !== '' && $isAdminUser) {
    $dashboardOrganizationId = (int)$dashboardOrganizationFilter;
    $dashboardScopedCustomers = array_values(array_filter(
        $customers,
        static fn(array $customer): bool => customer_matches_organization_scope($customer, $dashboardOrganizationId)
    ));
    $dashboardScopedQuotes = array_values(array_filter(
        $quotes,
        static fn(array $quote): bool => quote_matches_organization_scope($quote, $customers, $dashboardOrganizationId)
    ));
    $dashboardScopedJobs = array_values(array_filter(
        $jobs,
        static fn(array $job): bool => job_matches_organization_scope($job, $customers, $dashboardOrganizationId)
    ));
}

$todayJobs = array_values(array_filter($dashboardScopedJobs, static fn(array $job): bool => $job['scheduled_date'] === $today));
usort($todayJobs, static function (array $a, array $b): int {
    $timeA = trim((string)($a['scheduled_time'] ?? ''));
    $timeB = trim((string)($b['scheduled_time'] ?? ''));

    if ($timeA === '' && $timeB === '') {
        return strcmp((string)($a['service_type'] ?? ''), (string)($b['service_type'] ?? ''));
    }
    if ($timeA === '') {
        return 1;
    }
    if ($timeB === '') {
        return -1;
    }

    return strcmp($timeA, $timeB);
});

$webQuoteRequests = $data['web_quote_requests'] ?? [];
if ($currentUserOrganizationId !== null && current_user_role() !== USER_ROLE_ADMIN) {
    $webQuoteRequests = array_values(array_filter(
        $webQuoteRequests,
        static fn(array $request): bool => (int)($request['organization_id'] ?? 0) === $currentUserOrganizationId
    ));
}

$stats = $isSalesUser
    ? [
        'new_requests' => count(array_filter($webQuoteRequests, static fn(array $request): bool => (string)($request['status'] ?? 'new') === 'new')),
        'pending_quotes' => count(array_filter($sellerOwnedQuotes, static fn(array $quote): bool => in_array($quote['status'], ['draft', 'sent'], true))),
        'approved_quotes' => count(array_filter($sellerOwnedQuotes, static fn(array $quote): bool => (string)($quote['status'] ?? '') === 'approved')),
        'expiring_quotes' => count(array_filter($sellerOwnedQuotes, static fn(array $quote): bool => quote_is_expiring_soon($quote, $today, $soonThreshold))),
        'monthly_revenue' => 0,
    ]
    : [
        'new_requests' => count(array_filter($webQuoteRequests, static fn(array $request): bool => (string)($request['status'] ?? 'new') === 'new')),
        'pending_quotes' => count(array_filter($dashboardScopedQuotes, static fn(array $quote): bool => in_array($quote['status'], ['draft', 'sent'], true))),
        'booked_jobs' => count(array_filter($dashboardScopedJobs, static fn(array $job): bool => in_array($job['status'], ['planned', 'scheduled', 'in_progress'], true))),
        'completed_jobs' => count(array_filter($dashboardScopedJobs, static fn(array $job): bool => $job['status'] === 'completed')),
        'invoice_ready_jobs' => 0,
    ];

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$upcomingWindowEnd = date('Y-m-d', strtotime('+6 days'));

$quotesCreatedThisMonth = array_values(array_filter($dashboardScopedQuotes, static fn(array $quote): bool => date_in_range((string)($quote['created_at'] ?? ''), $monthStart, $monthEnd)));
$approvedQuotesThisMonth = array_values(array_filter($dashboardScopedQuotes, static fn(array $quote): bool => date_in_range(quote_effective_approved_date($quote), $monthStart, $monthEnd)));
$monthlyApprovedRevenue = array_reduce($approvedQuotesThisMonth, static fn(float $carry, array $quote): float => $carry + (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0), 0.0);
$conversionCandidatesThisMonth = array_values(array_filter($quotesCreatedThisMonth, static fn(array $quote): bool => in_array((string)($quote['status'] ?? ''), ['sent', 'approved', 'rejected', 'expired'], true)));
$quoteConversionRate = $conversionCandidatesThisMonth === []
    ? null
    : round((count(array_filter($conversionCandidatesThisMonth, static fn(array $quote): bool => (string)($quote['status'] ?? '') === 'approved')) / count($conversionCandidatesThisMonth)) * 100);

$upcomingJobsNextWeek = array_values(array_filter($dashboardScopedJobs, static fn(array $job): bool => !in_array((string)($job['status'] ?? ''), ['completed', 'cancelled', 'invoiced'], true)
    && (($job['scheduled_date'] ?? '') !== '')
    && (string)$job['scheduled_date'] >= $today
    && (string)$job['scheduled_date'] <= $upcomingWindowEnd));
$upcomingJobsRevenue = array_reduce($upcomingJobsNextWeek, function (float $carry, array $job) use ($invoiceBasesByJobId, $customers, $quotes): float {
    $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
    $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
    $invoiceBasis = try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote);

    if (is_array($invoiceBasis) && isset($invoiceBasis['amountToPay'])) {
        return $carry + (float)$invoiceBasis['amountToPay'];
    }

    if (is_array($quote) && isset($quote['amount_after_rut'])) {
        return $carry + (float)$quote['amount_after_rut'];
    }

    return $carry + (float)($job['final_total_amount_inc_vat'] ?? 0);
}, 0.0);
$unplannedJobs = array_values(array_filter($dashboardScopedJobs, static fn(array $job): bool => in_array((string)($job['status'] ?? ''), ['planned', 'scheduled'], true) && trim((string)($job['scheduled_date'] ?? '')) === ''));
$completedJobsThisMonth = array_values(array_filter($dashboardScopedJobs, static fn(array $job): bool => date_in_range((string)($job['completed_date'] ?? ''), $monthStart, $monthEnd)));
$invoiceReadyJobs = array_values(array_filter($dashboardScopedJobs, function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
    $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
    $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
    return job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)) === 'pending';
}));
$stats['invoice_ready_jobs'] = count($invoiceReadyJobs);

$approvedQuotesWithoutJob = array_values(array_filter($dashboardScopedQuotes, static function (array $quote) use ($dashboardScopedJobs): bool {
    if ((string)($quote['status'] ?? '') !== 'approved' && trim((string)($quote['converted_to_job_at'] ?? '')) === '') {
        return false;
    }

    foreach ($dashboardScopedJobs as $job) {
        if ((int)($job['quote_id'] ?? 0) === (int)($quote['id'] ?? 0)) {
            return false;
        }
    }

    return (string)($quote['status'] ?? '') === 'approved';
}));
usort($approvedQuotesWithoutJob, static fn(array $a, array $b): int => strcmp((string)($a['valid_until'] ?? ''), (string)($b['valid_until'] ?? '')));

$expiringQuotes = array_values(array_filter($dashboardScopedQuotes, static fn(array $quote): bool => quote_is_expiring_soon($quote, $today, $soonThreshold)));
usort($expiringQuotes, static fn(array $a, array $b): int => strcmp((string)($a['valid_until'] ?? ''), (string)($b['valid_until'] ?? '')));

$uninvoicedCompletedJobs = array_values(array_filter($dashboardScopedJobs, function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
    $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
    $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
    $invoiceStatus = job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote));
    return (($job['status'] ?? '') === 'completed' || !empty($job['ready_for_invoicing']))
        && !in_array($invoiceStatus, ['exporting', 'exported', 'invoiced', 'exported_invoiced'], true);
}));
usort($uninvoicedCompletedJobs, static fn(array $a, array $b): int => strcmp((string)($a['completed_date'] ?? ''), (string)($b['completed_date'] ?? '')));

$dashboardAlerts = [];
if ($isAdminUser) {
    foreach (array_slice($approvedQuotesWithoutJob, 0, 3) as $quote) {
        $dashboardAlerts[] = [
            'label' => 'Godkänd offert utan jobb',
            'text' => ($quote['quote_number'] ?: ('Offert #' . (int)$quote['id'])) . ' · ' . customer_name($data, (int)($quote['customer_id'] ?? 0)),
            'href' => admin_url('quotes', ['edit_id' => (int)$quote['id']]) . '#edit-quote',
            'tone' => 'neutral',
        ];
    }
    foreach (array_slice($expiringQuotes, 0, 3) as $quote) {
        $dashboardAlerts[] = [
            'label' => 'Snart utgående offert',
            'text' => ($quote['quote_number'] ?: ('Offert #' . (int)$quote['id'])) . ' · ' . customer_name($data, (int)($quote['customer_id'] ?? 0)) . ' · ' . format_date((string)($quote['valid_until'] ?? '')),
            'href' => admin_url('quotes', ['edit_id' => (int)$quote['id']]) . '#edit-quote',
            'tone' => 'attention',
        ];
    }
    foreach (array_slice($uninvoicedCompletedJobs, 0, 3) as $job) {
        $dashboardAlerts[] = [
            'label' => 'Klar för fakturaflöde',
            'text' => customer_name($data, (int)($job['customer_id'] ?? 0)) . ' · ' . ((string)($job['service_type'] ?? '') !== '' ? (string)$job['service_type'] : 'Jobb'),
            'href' => admin_url('jobs', ['view' => 'edit', 'job_edit_id' => (int)$job['id']]),
            'tone' => 'success',
        ];
    }
} elseif ($isSalesUser) {
    $sellerApprovedQuotesWithoutJob = array_values(array_filter($approvedQuotesWithoutJob, static function (array $quote) use ($currentUsernameToken): bool {
        return $currentUsernameToken !== ''
            && normalize_assignment_token((string)($quote['created_by_username'] ?? '')) === $currentUsernameToken;
    }));
    $sellerExpiringQuotes = array_values(array_filter($expiringQuotes, static function (array $quote) use ($currentUsernameToken): bool {
        return $currentUsernameToken !== ''
            && normalize_assignment_token((string)($quote['created_by_username'] ?? '')) === $currentUsernameToken;
    }));

    foreach (array_slice($sellerApprovedQuotesWithoutJob, 0, 3) as $quote) {
        $dashboardAlerts[] = [
            'label' => 'Min godkända offert utan jobb',
            'text' => ($quote['quote_number'] ?: ('Offert #' . (int)$quote['id'])) . ' · ' . customer_name($data, (int)($quote['customer_id'] ?? 0)),
            'href' => admin_url('quotes', ['edit_id' => (int)$quote['id']]) . '#edit-quote',
            'tone' => 'neutral',
        ];
    }
    foreach (array_slice($sellerExpiringQuotes, 0, 3) as $quote) {
        $dashboardAlerts[] = [
            'label' => 'Min snart utgående offert',
            'text' => ($quote['quote_number'] ?: ('Offert #' . (int)$quote['id'])) . ' · ' . customer_name($data, (int)($quote['customer_id'] ?? 0)) . ' · ' . format_date((string)($quote['valid_until'] ?? '')),
            'href' => admin_url('quotes', ['edit_id' => (int)$quote['id']]) . '#edit-quote',
            'tone' => 'attention',
        ];
    }
}
$dashboardAlerts = array_slice($dashboardAlerts, 0, 6);

$dashboardMonthStats = [];
if ($isAdminUser) {
    $dashboardMonthStats = [
        ['label' => 'Skapade offerter', 'value' => (string)count($quotesCreatedThisMonth)],
        ['label' => 'Godkända offerter', 'value' => (string)count($approvedQuotesThisMonth)],
        ['label' => 'Försäljning', 'value' => format_currency($monthlyApprovedRevenue)],
        ['label' => 'Konverteringsgrad', 'value' => $quoteConversionRate === null ? '–' : $quoteConversionRate . ' %'],
        ['label' => 'Kommande jobb 7 dagar', 'value' => (string)count($upcomingJobsNextWeek)],
        ['label' => 'Värde kommande jobb', 'value' => format_currency($upcomingJobsRevenue)],
        ['label' => 'Klara denna månad', 'value' => (string)count($completedJobsThisMonth)],
        ['label' => 'Ej planerade jobb', 'value' => (string)count($unplannedJobs)],
        ['label' => 'Redo att fakturera', 'value' => (string)count($invoiceReadyJobs)],
    ];
} elseif ($isSalesUser) {
    $sellerQuotesCreatedThisMonth = array_values(array_filter($sellerOwnedQuotes, static fn(array $quote): bool => date_in_range((string)($quote['created_at'] ?? ''), $monthStart, $monthEnd)));
    $sellerApprovedQuotesThisMonth = array_values(array_filter($sellerOwnedQuotes, static fn(array $quote): bool => date_in_range(quote_effective_approved_date($quote), $monthStart, $monthEnd)));
    $sellerMonthlyRevenue = array_reduce($sellerApprovedQuotesThisMonth, static fn(float $carry, array $quote): float => $carry + (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0), 0.0);
    $sellerConversionCandidates = array_values(array_filter($sellerQuotesCreatedThisMonth, static fn(array $quote): bool => in_array((string)($quote['status'] ?? ''), ['sent', 'approved', 'rejected', 'expired'], true)));
    $sellerConversionRate = $sellerConversionCandidates === []
        ? null
        : round((count(array_filter($sellerConversionCandidates, static fn(array $quote): bool => (string)($quote['status'] ?? '') === 'approved')) / count($sellerConversionCandidates)) * 100);

    $stats['monthly_revenue'] = $sellerMonthlyRevenue;
    $dashboardMonthStats = [
        ['label' => 'Mina skapade offerter', 'value' => (string)count($sellerQuotesCreatedThisMonth)],
        ['label' => 'Mina godkända offerter', 'value' => (string)count($sellerApprovedQuotesThisMonth)],
        ['label' => 'Min försäljning', 'value' => format_currency($sellerMonthlyRevenue)],
        ['label' => 'Min konverteringsgrad', 'value' => $sellerConversionRate === null ? '–' : $sellerConversionRate . ' %'],
        ['label' => 'Mina väntande offerter', 'value' => (string)$stats['pending_quotes']],
        ['label' => 'Mina snart utgångna', 'value' => (string)$stats['expiring_quotes']],
    ];
}

$dashboardOrganizationStats = [];
$dashboardOrganizationsSource = array_values(array_filter($data['organizations'] ?? [], static fn(array $organization): bool => !empty($organization['is_active'])));
$dashboardOrganizationsTree = organization_tree_sort($data['organizations'] ?? []);
if ($isAdminUser && $dashboardOrganizationsSource !== []) {
    $dashboardOrganizationsToShow = array_values(array_filter(
        $dashboardOrganizationsSource,
        static fn(array $organization): bool => (string)($organization['organization_type'] ?? '') !== ORGANIZATION_TYPE_HQ
    ));
    if ($dashboardOrganizationsToShow === []) {
        $dashboardOrganizationsToShow = $dashboardOrganizationsSource;
    }

    foreach ($dashboardOrganizationsToShow as $organization) {
        $organizationId = (int)($organization['id'] ?? 0);
        if ($organizationId <= 0) {
            continue;
        }

        $organizationScopeIds = organization_descendant_ids($dashboardOrganizationsTree, $organizationId);
        $organizationQuotes = array_values(array_filter(
            $quotes,
            static function (array $quote) use ($organizationScopeIds, $customers): bool {
                $quoteOrganizationId = ($quote['organization_id'] ?? null) !== null ? (int)$quote['organization_id'] : null;
                if ($quoteOrganizationId !== null) {
                    return in_array($quoteOrganizationId, $organizationScopeIds, true);
                }

                $customer = find_by_id($customers, (int)($quote['customer_id'] ?? 0));
                $customerOrganizationId = is_array($customer) && ($customer['organization_id'] ?? null) !== null ? (int)$customer['organization_id'] : null;

                return $customerOrganizationId !== null && in_array($customerOrganizationId, $organizationScopeIds, true);
            }
        ));
        $organizationJobs = array_values(array_filter(
            $jobs,
            static function (array $job) use ($organizationScopeIds, $customers): bool {
                $jobOrganizationId = ($job['organization_id'] ?? null) !== null ? (int)$job['organization_id'] : null;
                if ($jobOrganizationId !== null) {
                    return in_array($jobOrganizationId, $organizationScopeIds, true);
                }

                $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
                $customerOrganizationId = is_array($customer) && ($customer['organization_id'] ?? null) !== null ? (int)$customer['organization_id'] : null;

                return $customerOrganizationId !== null && in_array($customerOrganizationId, $organizationScopeIds, true);
            }
        ));

        $organizationApprovedQuotesThisMonth = array_values(array_filter(
            $organizationQuotes,
            static fn(array $quote): bool => date_in_range(quote_effective_approved_date($quote), $monthStart, $monthEnd)
        ));
        $organizationRevenue = array_reduce(
            $organizationApprovedQuotesThisMonth,
            static fn(float $carry, array $quote): float => $carry + (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0),
            0.0
        );
        $organizationUpcomingJobs = array_values(array_filter(
            $organizationJobs,
            static fn(array $job): bool => !in_array((string)($job['status'] ?? ''), ['completed', 'cancelled', 'invoiced'], true)
                && (($job['scheduled_date'] ?? '') !== '')
                && (string)$job['scheduled_date'] >= $today
                && (string)$job['scheduled_date'] <= $upcomingWindowEnd
        ));
        $organizationPendingInvoiceJobs = array_values(array_filter(
            $organizationJobs,
            function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
                $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
                $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
                return job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)) === 'pending';
            }
        ));

        if ($organizationRevenue <= 0 && $organizationUpcomingJobs === [] && $organizationPendingInvoiceJobs === [] && $organizationApprovedQuotesThisMonth === []) {
            continue;
        }

        $dashboardOrganizationStats[] = [
            'id' => $organizationId,
            'name' => (string)($organization['name'] ?? ''),
            'tree_label' => organization_tree_label($organization),
            'type_label' => organization_type_label((string)($organization['organization_type'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT)),
            'approved_quotes' => count($organizationApprovedQuotesThisMonth),
            'revenue' => $organizationRevenue,
            'upcoming_jobs' => count($organizationUpcomingJobs),
            'pending_invoices' => count($organizationPendingInvoiceJobs),
        ];
    }

    usort($dashboardOrganizationStats, static function (array $a, array $b): int {
        $revenueComparison = ((float)($b['revenue'] ?? 0)) <=> ((float)($a['revenue'] ?? 0));
        if ($revenueComparison !== 0) {
            return $revenueComparison;
        }

        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
}

$dashboardSellerStats = [];
$dashboardWorkerStats = [];
if ($isAdminUser) {
    $dashboardScopeOrganizationIds = $dashboardOrganizationFilter !== ''
        ? organization_descendant_ids($dashboardOrganizationsTree, (int)$dashboardOrganizationFilter)
        : [];
    $dashboardUsers = $data['users'] ?? [];

    $salesUsers = array_values(array_filter(
        $dashboardUsers,
        static fn(array $user): bool => !empty($user['is_active']) && in_array(USER_ROLE_SALES, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true)
    ));
    $workerUsersForDashboard = array_values(array_filter(
        $dashboardUsers,
        static fn(array $user): bool => !empty($user['is_active']) && in_array(USER_ROLE_WORKER, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true)
    ));

    if ($dashboardScopeOrganizationIds !== []) {
        $salesUsers = array_values(array_filter(
            $salesUsers,
            static function (array $user) use ($dashboardScopeOrganizationIds): bool {
                $organizationId = ($user['organization_id'] ?? null) !== null ? (int)$user['organization_id'] : null;
                return $organizationId !== null && in_array($organizationId, $dashboardScopeOrganizationIds, true);
            }
        ));
        $workerUsersForDashboard = array_values(array_filter(
            $workerUsersForDashboard,
            static function (array $user) use ($dashboardScopeOrganizationIds): bool {
                $organizationId = ($user['organization_id'] ?? null) !== null ? (int)$user['organization_id'] : null;
                return $organizationId !== null && in_array($organizationId, $dashboardScopeOrganizationIds, true);
            }
        ));
    }

    foreach ($salesUsers as $sellerUser) {
        $sellerUsernameToken = normalize_assignment_token((string)($sellerUser['username'] ?? ''));
        if ($sellerUsernameToken === '') {
            continue;
        }

        $sellerQuotes = array_values(array_filter(
            $dashboardScopedQuotes,
            static fn(array $quote): bool => normalize_assignment_token((string)($quote['created_by_username'] ?? '')) === $sellerUsernameToken
        ));
        if ($sellerQuotes === []) {
            continue;
        }

        $sellerQuotesCreatedThisMonth = array_values(array_filter(
            $sellerQuotes,
            static fn(array $quote): bool => date_in_range((string)($quote['created_at'] ?? ''), $monthStart, $monthEnd)
        ));
        $sellerApprovedQuotesThisMonth = array_values(array_filter(
            $sellerQuotes,
            static fn(array $quote): bool => date_in_range(quote_effective_approved_date($quote), $monthStart, $monthEnd)
        ));
        $sellerRevenue = array_reduce(
            $sellerApprovedQuotesThisMonth,
            static fn(float $carry, array $quote): float => $carry + (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0),
            0.0
        );
        $sellerConversionCandidates = array_values(array_filter(
            $sellerQuotesCreatedThisMonth,
            static fn(array $quote): bool => in_array((string)($quote['status'] ?? ''), ['sent', 'approved', 'rejected', 'expired'], true)
        ));
        $sellerConversionRate = $sellerConversionCandidates === []
            ? null
            : round((count(array_filter($sellerConversionCandidates, static fn(array $quote): bool => (string)($quote['status'] ?? '') === 'approved')) / count($sellerConversionCandidates)) * 100);

        if ($sellerQuotesCreatedThisMonth === [] && $sellerApprovedQuotesThisMonth === [] && $sellerRevenue <= 0) {
            continue;
        }

        $dashboardSellerStats[] = [
            'name' => (string)($sellerUser['name'] ?? $sellerUser['username'] ?? ''),
            'organization_name' => (string)($sellerUser['organization_name'] ?? ''),
            'created_quotes' => count($sellerQuotesCreatedThisMonth),
            'approved_quotes' => count($sellerApprovedQuotesThisMonth),
            'revenue' => $sellerRevenue,
            'conversion_rate' => $sellerConversionRate,
        ];
    }

    foreach ($workerUsersForDashboard as $workerUser) {
        $workerJobs = array_values(array_filter(
            $dashboardScopedJobs,
            static fn(array $job): bool => job_assignee_matches_user($job, $workerUser)
        ));
        if ($workerJobs === []) {
            continue;
        }

        $workerUpcomingJobs = array_values(array_filter(
            $workerJobs,
            static fn(array $job): bool => !in_array((string)($job['status'] ?? ''), ['completed', 'cancelled', 'invoiced'], true)
                && (($job['scheduled_date'] ?? '') !== '')
                && (string)$job['scheduled_date'] >= $today
                && (string)$job['scheduled_date'] <= $upcomingWindowEnd
        ));
        $workerTodayJobs = array_values(array_filter(
            $workerJobs,
            static fn(array $job): bool => (string)($job['scheduled_date'] ?? '') === $today
        ));
        $workerCompletedThisMonth = array_values(array_filter(
            $workerJobs,
            static fn(array $job): bool => date_in_range((string)($job['completed_date'] ?? ''), $monthStart, $monthEnd)
        ));

        if ($workerUpcomingJobs === [] && $workerTodayJobs === [] && $workerCompletedThisMonth === []) {
            continue;
        }

        $dashboardWorkerStats[] = [
            'name' => (string)($workerUser['name'] ?? $workerUser['username'] ?? ''),
            'organization_name' => (string)($workerUser['organization_name'] ?? ''),
            'today_jobs' => count($workerTodayJobs),
            'upcoming_jobs' => count($workerUpcomingJobs),
            'completed_jobs' => count($workerCompletedThisMonth),
        ];
    }

    usort($dashboardSellerStats, static function (array $a, array $b): int {
        $revenueComparison = ((float)($b['revenue'] ?? 0)) <=> ((float)($a['revenue'] ?? 0));
        if ($revenueComparison !== 0) {
            return $revenueComparison;
        }

        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    usort($dashboardWorkerStats, static function (array $a, array $b): int {
        $todayComparison = ((int)($b['today_jobs'] ?? 0)) <=> ((int)($a['today_jobs'] ?? 0));
        if ($todayComparison !== 0) {
            return $todayComparison;
        }

        $upcomingComparison = ((int)($b['upcoming_jobs'] ?? 0)) <=> ((int)($a['upcoming_jobs'] ?? 0));
        if ($upcomingComparison !== 0) {
            return $upcomingComparison;
        }

        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
}

$reportPeriodOptions = [
    'month' => 'Den här månaden',
    'quarter' => 'Det här kvartalet',
    'year' => 'Det här året',
];
if (!isset($reportPeriodOptions[$reportPeriod])) {
    $reportPeriod = 'month';
}

$reportPeriodLabel = $reportPeriodOptions[$reportPeriod];
$reportRangeStart = $monthStart;
$reportRangeEnd = $monthEnd;

if ($reportPeriod === 'quarter') {
    $currentMonthNumber = (int)date('n');
    $quarterStartMonth = ((int)floor(($currentMonthNumber - 1) / 3) * 3) + 1;
    $reportRangeStart = date('Y-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
    $reportRangeEnd = date('Y-m-t', strtotime($reportRangeStart . ' +2 months'));
} elseif ($reportPeriod === 'year') {
    $reportRangeStart = date('Y-01-01');
    $reportRangeEnd = date('Y-12-31');
}

$reportOrganizationsTree = organization_tree_sort($data['organizations'] ?? []);
$reportScopeOrganizationIds = $reportOrganizationFilter !== ''
    ? organization_descendant_ids($reportOrganizationsTree, (int)$reportOrganizationFilter)
    : [];

$reportScopedCustomers = $customers;
$reportScopedQuotes = $quotes;
$reportScopedJobs = $jobs;
$reportScopedInvoiceBases = $data['invoice_bases'] ?? [];

if ($reportScopeOrganizationIds !== []) {
    $reportScopedCustomers = array_values(array_filter(
        $reportScopedCustomers,
        static fn(array $customer): bool => customer_matches_organization_scope($customer, $reportScopeOrganizationIds)
    ));
    $reportScopedQuotes = array_values(array_filter(
        $reportScopedQuotes,
        static fn(array $quote): bool => quote_matches_organization_scope($quote, $reportScopedCustomers, $reportScopeOrganizationIds)
    ));
    $reportScopedJobs = array_values(array_filter(
        $reportScopedJobs,
        static fn(array $job): bool => job_matches_organization_scope($job, $reportScopedCustomers, $reportScopeOrganizationIds)
    ));
    $reportScopedJobIds = array_map(static fn(array $job): int => (int)($job['id'] ?? 0), $reportScopedJobs);
    $reportScopedInvoiceBases = array_values(array_filter(
        $reportScopedInvoiceBases,
        static fn(array $basis): bool => in_array((int)($basis['job_id'] ?? 0), $reportScopedJobIds, true)
    ));
}

$reportQuotesCreated = array_values(array_filter(
    $reportScopedQuotes,
    static fn(array $quote): bool => date_in_range((string)($quote['created_at'] ?? ''), $reportRangeStart, $reportRangeEnd)
));
$reportApprovedQuotes = array_values(array_filter(
    $reportScopedQuotes,
    static fn(array $quote): bool => date_in_range(quote_effective_approved_date($quote), $reportRangeStart, $reportRangeEnd)
));
$reportCompletedJobs = array_values(array_filter(
    $reportScopedJobs,
    static fn(array $job): bool => date_in_range((string)($job['completed_date'] ?? ''), $reportRangeStart, $reportRangeEnd)
));
$reportScheduledJobs = array_values(array_filter(
    $reportScopedJobs,
    static fn(array $job): bool => !in_array((string)($job['status'] ?? ''), ['cancelled', 'completed', 'invoiced'], true)
        && (($job['scheduled_date'] ?? '') !== '')
        && date_in_range((string)($job['scheduled_date'] ?? ''), $reportRangeStart, $reportRangeEnd)
));
$reportRevenue = array_reduce(
    $reportApprovedQuotes,
    static fn(float $carry, array $quote): float => $carry + (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0),
    0.0
);
$reportConversionCandidates = array_values(array_filter(
    $reportQuotesCreated,
    static fn(array $quote): bool => in_array((string)($quote['status'] ?? ''), ['sent', 'approved', 'rejected', 'expired'], true)
));
$reportConversionRate = $reportConversionCandidates === []
    ? null
    : round((count(array_filter($reportConversionCandidates, static fn(array $quote): bool => (string)($quote['status'] ?? '') === 'approved')) / count($reportConversionCandidates)) * 100);
$reportReadyToInvoiceJobs = array_values(array_filter(
    $reportScopedJobs,
    function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        return job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)) === 'pending';
    }
));
$reportInvoicableValue = array_reduce(
    $reportReadyToInvoiceJobs,
    function (float $carry, array $job) use ($invoiceBasesByJobId, $customers, $quotes): float {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        $invoiceBasis = try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote);

        return $carry + (float)($invoiceBasis['amountToPay'] ?? 0);
    },
    0.0
);

$reportSummaryStats = [
    ['label' => 'Skapade offerter', 'value' => (string)count($reportQuotesCreated)],
    ['label' => 'Godkända offerter', 'value' => (string)count($reportApprovedQuotes)],
    ['label' => 'Försäljning', 'value' => format_currency($reportRevenue)],
    ['label' => 'Konvertering', 'value' => $reportConversionRate === null ? '–' : ($reportConversionRate . ' %')],
    ['label' => 'Schemalagda jobb', 'value' => (string)count($reportScheduledJobs)],
    ['label' => 'Klara jobb', 'value' => (string)count($reportCompletedJobs)],
    ['label' => 'Redo att fakturera', 'value' => (string)count($reportReadyToInvoiceJobs)],
    ['label' => 'Fakturerbart värde', 'value' => format_currency($reportInvoicableValue)],
];

$reportOrganizationRows = [];
foreach (array_values(array_filter($reportOrganizationsTree, static fn(array $organization): bool => !empty($organization['is_active']))) as $organization) {
    $organizationId = (int)($organization['id'] ?? 0);
    if ($organizationId <= 0) {
        continue;
    }

    $organizationScopeIds = organization_descendant_ids($reportOrganizationsTree, $organizationId);
    if ($reportScopeOrganizationIds !== [] && !in_array($organizationId, $reportScopeOrganizationIds, true)) {
        continue;
    }

    $organizationQuotes = array_values(array_filter(
        $reportScopedQuotes,
        static fn(array $quote): bool => quote_matches_organization_scope($quote, $reportScopedCustomers, $organizationScopeIds)
    ));
    $organizationJobs = array_values(array_filter(
        $reportScopedJobs,
        static fn(array $job): bool => job_matches_organization_scope($job, $reportScopedCustomers, $organizationScopeIds)
    ));
    $organizationApproved = array_values(array_filter(
        $organizationQuotes,
        static fn(array $quote): bool => date_in_range(quote_effective_approved_date($quote), $reportRangeStart, $reportRangeEnd)
    ));
    $organizationRevenue = array_reduce(
        $organizationApproved,
        static fn(float $carry, array $quote): float => $carry + (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0),
        0.0
    );
    $organizationCompletedJobs = array_values(array_filter(
        $organizationJobs,
        static fn(array $job): bool => date_in_range((string)($job['completed_date'] ?? ''), $reportRangeStart, $reportRangeEnd)
    ));
    $organizationReadyJobs = array_values(array_filter(
        $organizationJobs,
        function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
            $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
            $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
            return job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)) === 'pending';
        }
    ));

    if ($organizationApproved === [] && $organizationCompletedJobs === [] && $organizationReadyJobs === [] && $organizationRevenue <= 0) {
        continue;
    }

    $reportOrganizationRows[] = [
        'name' => organization_tree_label($organization),
        'type_label' => organization_type_label((string)($organization['organization_type'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT)),
        'approved_quotes' => count($organizationApproved),
        'revenue' => $organizationRevenue,
        'completed_jobs' => count($organizationCompletedJobs),
        'ready_jobs' => count($organizationReadyJobs),
    ];
}
usort($reportOrganizationRows, static function (array $a, array $b): int {
    $revenueComparison = ((float)($b['revenue'] ?? 0)) <=> ((float)($a['revenue'] ?? 0));
    if ($revenueComparison !== 0) {
        return $revenueComparison;
    }

    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$reportSellerRows = [];
foreach (array_values(array_filter($data['users'] ?? [], static fn(array $user): bool => !empty($user['is_active']) && in_array(USER_ROLE_SALES, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true))) as $sellerUser) {
    $sellerUsernameToken = normalize_assignment_token((string)($sellerUser['username'] ?? ''));
    if ($sellerUsernameToken === '') {
        continue;
    }
    if ($reportScopeOrganizationIds !== []) {
        $sellerOrganizationId = ($sellerUser['organization_id'] ?? null) !== null ? (int)$sellerUser['organization_id'] : null;
        if ($sellerOrganizationId === null || !in_array($sellerOrganizationId, $reportScopeOrganizationIds, true)) {
            continue;
        }
    }

    $sellerQuotes = array_values(array_filter(
        $reportScopedQuotes,
        static fn(array $quote): bool => normalize_assignment_token((string)($quote['created_by_username'] ?? '')) === $sellerUsernameToken
    ));
    if ($sellerQuotes === []) {
        continue;
    }

    $sellerCreatedQuotes = array_values(array_filter(
        $sellerQuotes,
        static fn(array $quote): bool => date_in_range((string)($quote['created_at'] ?? ''), $reportRangeStart, $reportRangeEnd)
    ));
    $sellerApprovedQuotes = array_values(array_filter(
        $sellerQuotes,
        static fn(array $quote): bool => date_in_range(quote_effective_approved_date($quote), $reportRangeStart, $reportRangeEnd)
    ));
    $sellerRevenue = array_reduce(
        $sellerApprovedQuotes,
        static fn(float $carry, array $quote): float => $carry + (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0),
        0.0
    );
    $sellerConversionCandidates = array_values(array_filter(
        $sellerCreatedQuotes,
        static fn(array $quote): bool => in_array((string)($quote['status'] ?? ''), ['sent', 'approved', 'rejected', 'expired'], true)
    ));
    $sellerConversionRate = $sellerConversionCandidates === []
        ? null
        : round((count(array_filter($sellerConversionCandidates, static fn(array $quote): bool => (string)($quote['status'] ?? '') === 'approved')) / count($sellerConversionCandidates)) * 100);

    if ($sellerCreatedQuotes === [] && $sellerApprovedQuotes === [] && $sellerRevenue <= 0) {
        continue;
    }

    $reportSellerRows[] = [
        'name' => (string)($sellerUser['name'] ?? $sellerUser['username'] ?? ''),
        'organization_name' => (string)($sellerUser['organization_name'] ?? ''),
        'created_quotes' => count($sellerCreatedQuotes),
        'approved_quotes' => count($sellerApprovedQuotes),
        'revenue' => $sellerRevenue,
        'conversion_rate' => $sellerConversionRate,
    ];
}
usort($reportSellerRows, static function (array $a, array $b): int {
    $revenueComparison = ((float)($b['revenue'] ?? 0)) <=> ((float)($a['revenue'] ?? 0));
    if ($revenueComparison !== 0) {
        return $revenueComparison;
    }

    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$reportWorkerRows = [];
foreach (array_values(array_filter($data['users'] ?? [], static fn(array $user): bool => !empty($user['is_active']) && in_array(USER_ROLE_WORKER, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true))) as $workerUser) {
    if ($reportScopeOrganizationIds !== []) {
        $workerOrganizationId = ($workerUser['organization_id'] ?? null) !== null ? (int)$workerUser['organization_id'] : null;
        if ($workerOrganizationId === null || !in_array($workerOrganizationId, $reportScopeOrganizationIds, true)) {
            continue;
        }
    }

    $workerJobs = array_values(array_filter(
        $reportScopedJobs,
        static fn(array $job): bool => job_assignee_matches_user($job, $workerUser)
    ));
    if ($workerJobs === []) {
        continue;
    }

    $workerScheduled = array_values(array_filter(
        $workerJobs,
        static fn(array $job): bool => !in_array((string)($job['status'] ?? ''), ['cancelled', 'completed', 'invoiced'], true)
            && (($job['scheduled_date'] ?? '') !== '')
            && date_in_range((string)($job['scheduled_date'] ?? ''), $reportRangeStart, $reportRangeEnd)
    ));
    $workerCompleted = array_values(array_filter(
        $workerJobs,
        static fn(array $job): bool => date_in_range((string)($job['completed_date'] ?? ''), $reportRangeStart, $reportRangeEnd)
    ));
    $workerReady = array_values(array_filter(
        $workerJobs,
        function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
            $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0));
            $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
            return job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)) === 'pending';
        }
    ));

    if ($workerScheduled === [] && $workerCompleted === [] && $workerReady === []) {
        continue;
    }

    $reportWorkerRows[] = [
        'name' => (string)($workerUser['name'] ?? $workerUser['username'] ?? ''),
        'organization_name' => (string)($workerUser['organization_name'] ?? ''),
        'scheduled_jobs' => count($workerScheduled),
        'completed_jobs' => count($workerCompleted),
        'ready_jobs' => count($workerReady),
    ];
}
usort($reportWorkerRows, static function (array $a, array $b): int {
    $completedComparison = ((int)($b['completed_jobs'] ?? 0)) <=> ((int)($a['completed_jobs'] ?? 0));
    if ($completedComparison !== 0) {
        return $completedComparison;
    }

    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$reportServiceRows = [];
foreach ($reportScopedQuotes as $quote) {
    $serviceName = trim((string)($quote['service_type'] ?? ''));
    if ($serviceName === '' || !date_in_range(quote_effective_approved_date($quote), $reportRangeStart, $reportRangeEnd)) {
        continue;
    }

    if (!isset($reportServiceRows[$serviceName])) {
        $reportServiceRows[$serviceName] = [
            'service' => $serviceName,
            'approved_quotes' => 0,
            'scheduled_jobs' => 0,
            'revenue' => 0.0,
        ];
    }

    $reportServiceRows[$serviceName]['approved_quotes']++;
    $reportServiceRows[$serviceName]['revenue'] += (float)($quote['amount_after_rut'] ?? $quote['total_amount_inc_vat'] ?? 0);
}
foreach ($reportScopedJobs as $job) {
    $serviceName = trim((string)($job['service_type'] ?? ''));
    if (
        $serviceName === ''
        || in_array((string)($job['status'] ?? ''), ['cancelled', 'completed', 'invoiced'], true)
        || !date_in_range((string)($job['scheduled_date'] ?? ''), $reportRangeStart, $reportRangeEnd)
    ) {
        continue;
    }

    if (!isset($reportServiceRows[$serviceName])) {
        $reportServiceRows[$serviceName] = [
            'service' => $serviceName,
            'approved_quotes' => 0,
            'scheduled_jobs' => 0,
            'revenue' => 0.0,
        ];
    }

    $reportServiceRows[$serviceName]['scheduled_jobs']++;
}
$reportServiceRows = array_values($reportServiceRows);
usort($reportServiceRows, static function (array $a, array $b): int {
    $revenueComparison = ((float)($b['revenue'] ?? 0)) <=> ((float)($a['revenue'] ?? 0));
    if ($revenueComparison !== 0) {
        return $revenueComparison;
    }

    return strcmp((string)($a['service'] ?? ''), (string)($b['service'] ?? ''));
});

function quote_is_expired(array $quote, string $today): bool
{
    return ($quote['valid_until'] ?? '') !== ''
        && $quote['valid_until'] < $today
        && !in_array((string)($quote['status'] ?? ''), ['approved', 'cancelled'], true);
}

function quote_is_expiring_soon(array $quote, string $today, string $threshold): bool
{
    $status = (string)($quote['status'] ?? '');
    $validUntil = (string)($quote['valid_until'] ?? '');

    return $validUntil !== ''
        && $validUntil >= $today
        && $validUntil <= $threshold
        && !in_array($status, ['approved', 'rejected', 'cancelled'], true);
}

function quote_days_until_valid_until(array $quote, string $today): ?int
{
    $validUntil = (string)($quote['valid_until'] ?? '');
    if ($validUntil === '' || $validUntil < $today) {
        return null;
    }

    $todayDate = date_create_immutable($today);
    $validUntilDate = date_create_immutable($validUntil);

    if (!$todayDate || !$validUntilDate) {
        return null;
    }

    return (int)$todayDate->diff($validUntilDate)->format('%a');
}

function date_in_range(?string $date, string $from, string $to): bool
{
    $value = trim((string)$date);
    if ($value === '') {
        return false;
    }

    $normalized = strlen($value) >= 10 ? substr($value, 0, 10) : $value;

    return $normalized >= $from && $normalized <= $to;
}

function quote_effective_approved_date(array $quote): string
{
    $approvedAt = trim((string)($quote['approved_at'] ?? ''));
    if ($approvedAt !== '') {
        return substr($approvedAt, 0, 10);
    }

    if ((string)($quote['status'] ?? '') === 'approved') {
        $updatedAt = trim((string)($quote['updated_at'] ?? ''));
        if ($updatedAt !== '') {
            return substr($updatedAt, 0, 10);
        }
    }

    return '';
}

function job_invoice_status(array $job, ?array $invoiceBasis = null): string
{
    if (is_array($invoiceBasis)) {
        $basisStatus = (string)($invoiceBasis['invoiceStatus'] ?? $invoiceBasis['status'] ?? '');
        if ($basisStatus !== '') {
            return $basisStatus;
        }
    }

    $value = (string)($job['invoice_status'] ?? '');

    if ($value !== '') {
        return $value;
    }

    if (($job['status'] ?? '') === 'invoiced') {
        return 'invoiced';
    }

    if (!empty($job['ready_for_invoicing'])) {
        return 'pending';
    }

    return '';
}

function normalize_assignment_token(string $value): string
{
    return mb_strtolower(trim($value), 'UTF-8');
}

function job_assignee_matches_user(array $job, array $user): bool
{
    $assignedTo = normalize_assignment_token((string)($job['assigned_to'] ?? $job['assignedTo'] ?? ''));
    if ($assignedTo === '') {
        return false;
    }

    $username = normalize_assignment_token((string)($user['username'] ?? ''));
    $name = normalize_assignment_token((string)($user['name'] ?? ''));

    return ($username !== '' && $assignedTo === $username)
        || ($name !== '' && $assignedTo === $name);
}

function job_assignee_label(array $job, array $workerUsers): string
{
    $assignedTo = trim((string)($job['assigned_to'] ?? $job['assignedTo'] ?? ''));
    if ($assignedTo === '') {
        return '';
    }

    foreach ($workerUsers as $workerUser) {
        if (job_assignee_matches_user($job, $workerUser)) {
            return (string)($workerUser['name'] ?? $assignedTo);
        }
    }

    return $assignedTo;
}

$filteredQuotesByView = match ($view) {
    'archived' => array_values(array_filter($quotes, static fn(array $quote): bool => in_array((string)($quote['status'] ?? ''), ['rejected', 'cancelled', 'expired'], true) || quote_is_expired($quote, $today))),
    'sent' => array_values(array_filter($quotes, static fn(array $quote): bool => $quote['status'] === 'sent')),
    'approved' => array_values(array_filter($quotes, static fn(array $quote): bool => $quote['status'] === 'approved')),
    'rejected' => array_values(array_filter($quotes, static fn(array $quote): bool => $quote['status'] === 'rejected')),
    'expired' => array_values(array_filter($quotes, static fn(array $quote): bool => quote_is_expired($quote, $today))),
    'expiring' => array_values(array_filter($quotes, static fn(array $quote): bool => quote_is_expiring_soon($quote, $today, $soonThreshold))),
    default => array_values(array_filter($quotes, static fn(array $quote): bool => !in_array((string)($quote['status'] ?? ''), ['rejected', 'cancelled', 'expired'], true) && !quote_is_expired($quote, $today))),
};
if ($quoteOrganizationFilter !== '' && current_user_role() === USER_ROLE_ADMIN) {
    $filteredQuotesByView = array_values(array_filter(
        $filteredQuotesByView,
        static fn(array $quote): bool => quote_matches_organization_scope($quote, $customers, (int)$quoteOrganizationFilter)
    ));
}
if ($quoteSearch !== '') {
    $needle = mb_strtolower($quoteSearch, 'UTF-8');
    $filteredQuotesByView = array_values(array_filter($filteredQuotesByView, static function (array $quote) use ($needle, $customers): bool {
        $customer = find_by_id($customers, (int)($quote['customer_id'] ?? 0)) ?? [];
        $haystack = mb_strtolower(implode(' ', [
            (string)($quote['quote_number'] ?? ''),
            (string)($quote['service_type'] ?? ''),
            (string)($quote['description'] ?? ''),
            customer_name(['customers' => $customers], (int)($quote['customer_id'] ?? 0)),
            customer_type_label((string)($customer['customer_type'] ?? 'private')),
            vat_mode_label((string)($customer['billing_vat_mode'] ?? 'standard_vat')),
            status_label((string)($quote['status'] ?? 'draft')),
        ]), 'UTF-8');

        return str_contains($haystack, $needle);
    }));
}
if ($quoteCustomerTypeFilter !== '') {
    $filteredQuotesByView = array_values(array_filter($filteredQuotesByView, static function (array $quote) use ($customers, $quoteCustomerTypeFilter): bool {
        $customer = find_by_id($customers, (int)($quote['customer_id'] ?? 0)) ?? [];
        return (string)($customer['customer_type'] ?? 'private') === $quoteCustomerTypeFilter;
    }));
}
if ($quoteVatModeFilter !== '') {
    $filteredQuotesByView = array_values(array_filter($filteredQuotesByView, static function (array $quote) use ($customers, $quoteVatModeFilter): bool {
        $customer = find_by_id($customers, (int)($quote['customer_id'] ?? 0)) ?? [];
        return (string)($customer['billing_vat_mode'] ?? 'standard_vat') === $quoteVatModeFilter;
    }));
}

$filteredJobsByView = match ($view) {
    'archived' => array_values(array_filter($jobs, static fn(array $job): bool => (string)($job['status'] ?? '') === 'cancelled')),
    'upcoming' => array_values(array_filter($jobs, static fn(array $job): bool => ($job['scheduled_date'] ?? '') >= $today && !in_array((string)($job['status'] ?? ''), ['completed', 'cancelled', 'invoiced'], true))),
    'in_progress' => array_values(array_filter($jobs, static fn(array $job): bool => $job['status'] === 'in_progress')),
    'done' => array_values(array_filter($jobs, static fn(array $job): bool => $job['status'] === 'completed')),
    default => array_values(array_filter($jobs, static fn(array $job): bool => (string)($job['status'] ?? '') !== 'cancelled')),
};
if ($jobOrganizationFilter !== '' && current_user_role() === USER_ROLE_ADMIN) {
    $filteredJobsByView = array_values(array_filter(
        $filteredJobsByView,
        static fn(array $job): bool => job_matches_organization_scope($job, $customers, (int)$jobOrganizationFilter)
    ));
}
if ($jobSearch !== '') {
    $needle = mb_strtolower($jobSearch, 'UTF-8');
    $filteredJobsByView = array_values(array_filter($filteredJobsByView, static function (array $job) use ($needle, $customers): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        $haystack = mb_strtolower(implode(' ', [
            customer_name(['customers' => $customers], (int)($job['customer_id'] ?? 0)),
            (string)($job['service_type'] ?? ''),
            (string)($job['description'] ?? ''),
            (string)($job['assigned_to'] ?? $job['assignedTo'] ?? ''),
            status_label((string)($job['status'] ?? 'planned')),
            vat_mode_label((string)($customer['billing_vat_mode'] ?? 'standard_vat')),
            customer_type_label((string)($customer['customer_type'] ?? 'private')),
        ]), 'UTF-8');

        return str_contains($haystack, $needle);
    }));
}
if (in_array($view, ['upcoming', 'in_progress', 'done'], true)) {
    usort($filteredJobsByView, 'compare_jobs_by_schedule');
}
if ($jobCustomerTypeFilter !== '') {
    $filteredJobsByView = array_values(array_filter($filteredJobsByView, static function (array $job) use ($customers, $jobCustomerTypeFilter): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        return (string)($customer['customer_type'] ?? 'private') === $jobCustomerTypeFilter;
    }));
}
if ($jobInvoiceReadyFilter !== '') {
    $filteredJobsByView = array_values(array_filter($filteredJobsByView, static function (array $job) use ($jobInvoiceReadyFilter): bool {
        $ready = !empty($job['ready_for_invoicing']);
        return $jobInvoiceReadyFilter === '1' ? $ready : !$ready;
    }));
}

$invoiceJobsByView = match ($view) {
    'created' => array_values(array_filter($jobs, function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        return in_array(job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)), ['exporting', 'exported'], true);
    })),
    'invoiced' => array_values(array_filter($jobs, function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        return in_array(job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)), ['invoiced', 'exported_invoiced'], true)
            || ($job['status'] ?? '') === 'invoiced';
    })),
    default => array_values(array_filter($jobs, function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        return job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)) === 'pending';
    })),
};
if ($invoiceOrganizationFilter !== '' && current_user_role() === USER_ROLE_ADMIN) {
    $invoiceJobsByView = array_values(array_filter(
        $invoiceJobsByView,
        static fn(array $job): bool => job_matches_organization_scope($job, $customers, (int)$invoiceOrganizationFilter)
    ));
}
if ($invoiceSearch !== '') {
    $needle = mb_strtolower($invoiceSearch, 'UTF-8');
    $invoiceJobsByView = array_values(array_filter($invoiceJobsByView, static function (array $job) use ($needle, $customers, $quotes, $invoiceBasesByJobId): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        $invoiceStatus = job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote));
        $haystack = mb_strtolower(implode(' ', [
            customer_name(['customers' => $customers], (int)($job['customer_id'] ?? 0)),
            (string)($job['service_type'] ?? ''),
            (string)($job['description'] ?? ''),
            status_label((string)($job['status'] ?? 'planned')),
            $invoiceStatus,
            customer_type_label((string)($customer['customer_type'] ?? 'private')),
        ]), 'UTF-8');

        return str_contains($haystack, $needle);
    }));
}
if ($invoiceCustomerTypeFilter !== '') {
    $invoiceJobsByView = array_values(array_filter($invoiceJobsByView, static function (array $job) use ($customers, $invoiceCustomerTypeFilter): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        return (string)($customer['customer_type'] ?? 'private') === $invoiceCustomerTypeFilter;
    }));
}
if ($invoiceStatusFilter !== '') {
    $invoiceJobsByView = array_values(array_filter($invoiceJobsByView, static function (array $job) use ($customers, $quotes, $invoiceBasesByJobId, $invoiceStatusFilter): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        return job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)) === $invoiceStatusFilter;
    }));
}

$webQuoteRequests = $data['web_quote_requests'] ?? [];
if (in_array(current_user_role(), [USER_ROLE_SALES, USER_ROLE_WORKER], true) && $currentUserOrganizationId !== null) {
    $webQuoteRequests = array_values(array_filter(
        $webQuoteRequests,
        static fn(array $request): bool => (int)($request['organization_id'] ?? 0) === $currentUserOrganizationId
    ));
}

$filteredRequestsByView = match ($view) {
    'new' => array_values(array_filter($webQuoteRequests, static fn(array $request): bool => (string)($request['status'] ?? 'new') === 'new')),
    'handled' => array_values(array_filter($webQuoteRequests, static fn(array $request): bool => (string)($request['status'] ?? '') === 'handled')),
    'archived' => array_values(array_filter($webQuoteRequests, static fn(array $request): bool => (string)($request['status'] ?? '') === 'archived')),
    default => $webQuoteRequests,
};
if ($requestOrganizationFilter !== '' && current_user_role() === USER_ROLE_ADMIN) {
    $filteredRequestsByView = array_values(array_filter(
        $filteredRequestsByView,
        static fn(array $request): bool => (string)($request['organization_id'] ?? '') === $requestOrganizationFilter
    ));
}
if ($requestSearch !== '') {
    $needle = mb_strtolower($requestSearch, 'UTF-8');
    $filteredRequestsByView = array_values(array_filter($filteredRequestsByView, static function (array $request) use ($needle, $data): bool {
        $organization = ($request['organization_id'] ?? null) !== null ? find_organization_by_id($data, (int)$request['organization_id']) : null;
        $haystack = mb_strtolower(implode(' ', [
            (string)($request['name'] ?? ''),
            (string)($request['phone'] ?? ''),
            (string)($request['email'] ?? ''),
            (string)($request['service_address'] ?? ''),
            (string)($request['service_postcode'] ?? ''),
            (string)($request['service_city'] ?? ''),
            (string)($request['message'] ?? ''),
            (string)($organization['name'] ?? ''),
            (string)($request['status'] ?? ''),
        ]), 'UTF-8');

        return str_contains($haystack, $needle);
    }));
}
usort($filteredRequestsByView, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

$navCounts = [
    'quotes_expiring' => count(array_filter($quotes, static fn(array $quote): bool => quote_is_expiring_soon($quote, $today, $soonThreshold))),
    'quotes_expired' => count(array_filter($quotes, static fn(array $quote): bool => quote_is_expired($quote, $today))),
    'jobs_uninvoiced' => count(array_filter($jobs, function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        $invoiceStatus = job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote));
        return (($job['status'] ?? '') === 'completed' || !empty($job['ready_for_invoicing']))
            && !in_array($invoiceStatus, ['exporting', 'exported', 'invoiced'], true);
    })),
    'invoices_ready' => count(array_filter($jobs, function (array $job) use ($invoiceBasesByJobId, $customers, $quotes): bool {
        $customer = find_by_id($customers, (int)($job['customer_id'] ?? 0)) ?? [];
        $quote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
        return job_invoice_status($job, try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote)) === 'pending';
    })),
    'requests_new' => count(array_filter($webQuoteRequests, static fn(array $request): bool => (string)($request['status'] ?? 'new') === 'new')),
];

$calendarJobs = $jobs;
if ($calendarOrganizationFilter !== '' && $isAdminUser) {
    $calendarJobs = array_values(array_filter(
        $calendarJobs,
        static fn(array $job): bool => job_matches_organization_scope($job, $customers, (int)$calendarOrganizationFilter)
    ));
}
if ($calendarRegionFilter !== '' && current_user_role() === USER_ROLE_ADMIN) {
    $calendarJobs = array_values(array_filter(
        $calendarJobs,
        static fn(array $job): bool => (string)($job['region_id'] ?? '') === $calendarRegionFilter
    ));
}
if ($calendarWorkerFilter !== '') {
    $calendarJobs = array_values(array_filter(
        $calendarJobs,
        static fn(array $job): bool => (string)($job['assigned_to'] ?? '') === $calendarWorkerFilter
    ));
}

$weekStart = strtotime('monday this week');
if ($weekOffset !== 0) {
    $weekStart = strtotime(($weekOffset > 0 ? '+' : '') . $weekOffset . ' week', $weekStart);
}

$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime('+' . $i . ' day', $weekStart));
    $dayJobs = array_values(array_filter($calendarJobs, static fn(array $job): bool => $job['scheduled_date'] === $date));
    usort($dayJobs, static function (array $a, array $b): int {
        $timeA = trim((string)($a['scheduled_time'] ?? ''));
        $timeB = trim((string)($b['scheduled_time'] ?? ''));

        if ($timeA === '' && $timeB === '') {
            return strcmp((string)($a['service_type'] ?? ''), (string)($b['service_type'] ?? ''));
        }
        if ($timeA === '') {
            return 1;
        }
        if ($timeB === '') {
            return -1;
        }

        return strcmp($timeA, $timeB);
    });

    $weekDays[] = [
        'label' => date('D j M', strtotime($date)),
        'date' => $date,
        'jobs' => $dayJobs,
    ];
}

$flash = get_flash();
$pageLabels = [
    'dashboard' => 'Dashboard',
    'reports' => 'Rapporter',
    'requests' => 'Förfrågningar',
    'customers' => 'Kunder',
    'customer' => 'Kundkort',
    'quotes' => 'Offerter',
    'jobs' => 'Jobb',
    'calendar' => 'Kalender',
    'invoices' => 'Fakturaunderlag',
    'settings' => 'Inställningar',
];
$prefillCustomerId = (int)($_GET['customer_id'] ?? 0);
$prefillRequestId = (int)($_GET['request_id'] ?? 0);
$currentUserName = current_user_name();
$currentUsername = current_user_username();
$regions = $data['regions'] ?? [];
usort($regions, static fn(array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
$activeRegions = array_values(array_filter($regions, static fn(array $region): bool => !empty($region['is_active'])));
$organizations = organization_tree_sort($data['organizations'] ?? []);
$activeOrganizations = array_values(array_filter($organizations, static fn(array $organization): bool => !empty($organization['is_active'])));
$users = $data['users'] ?? [];
usort($users, static fn(array $a, array $b): int => strcmp((string)($a['name'] ?? $a['username'] ?? ''), (string)($b['name'] ?? $b['username'] ?? '')));
$workerUsers = array_values(array_filter($users, static fn(array $user): bool => in_array(USER_ROLE_WORKER, normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)), true) && !empty($user['is_active'])));
if (in_array(current_user_role(), [USER_ROLE_SALES, USER_ROLE_WORKER], true) && ($currentUserOrganizationId !== null || $currentUserRegionId !== null)) {
    $workerUsers = array_values(array_filter(
        $workerUsers,
        static function (array $user) use ($currentUserOrganizationId, $currentUserRegionId): bool {
            if ($currentUserOrganizationId !== null && ($user['organization_id'] ?? null) !== null) {
                return (int)($user['organization_id'] ?? 0) === $currentUserOrganizationId;
            }

            return $currentUserRegionId !== null && (int)($user['region_id'] ?? 0) === $currentUserRegionId;
        }
    ));
}
$products = $data['products'] ?? [];
usort($products, static fn(array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
$productCategoryOptions = array_values(array_unique(array_filter(array_map(
    static fn(array $product): string => trim((string)($product['category'] ?? '')),
    $data['products'] ?? []
))));
sort($productCategoryOptions);
$productUsageCounts = [];
foreach (($data['service_package_items'] ?? []) as $packageItem) {
    $productId = (int)($packageItem['product_id'] ?? 0);
    if ($productId <= 0) {
        continue;
    }
    $productUsageCounts[$productId] = ($productUsageCounts[$productId] ?? 0) + 1;
}
if ($productSearch !== '') {
    $needle = mb_strtolower($productSearch, 'UTF-8');
    $products = array_values(array_filter($products, static function (array $product) use ($needle): bool {
        $haystack = mb_strtolower(implode(' ', [
            (string)($product['name'] ?? ''),
            (string)($product['category'] ?? ''),
            (string)($product['description'] ?? ''),
        ]), 'UTF-8');

        return str_contains($haystack, $needle);
    }));
}
if ($productCategoryFilter !== '') {
    $products = array_values(array_filter($products, static fn(array $product): bool => (string)($product['category'] ?? '') === $productCategoryFilter));
}
if ($productStatusFilter !== '') {
    $products = array_values(array_filter($products, static function (array $product) use ($productStatusFilter): bool {
        $isActive = !empty($product['is_active']);
        return $productStatusFilter === 'active' ? $isActive : !$isActive;
    }));
}
if ($productItemTypeFilter !== '') {
    $products = array_values(array_filter($products, static fn(array $product): bool => (string)($product['item_type'] ?? 'service') === $productItemTypeFilter));
}
if ($productPriceModelFilter !== '') {
    $products = array_values(array_filter($products, static fn(array $product): bool => (string)($product['price_model'] ?? 'fixed') === $productPriceModelFilter));
}
$productsById = [];
foreach ($data['products'] ?? [] as $productRecord) {
    $productsById[(int)($productRecord['id'] ?? 0)] = $productRecord;
}
$servicePackages = $data['service_packages'] ?? [];
usort($servicePackages, static function (array $a, array $b): int {
    $sortComparison = ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
    if ($sortComparison !== 0) {
        return $sortComparison;
    }

    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});
if ($packageSearch !== '') {
    $needle = mb_strtolower($packageSearch, 'UTF-8');
    $servicePackages = array_values(array_filter($servicePackages, static function (array $package) use ($needle): bool {
        $haystack = mb_strtolower(implode(' ', [
            (string)($package['name'] ?? ''),
            (string)($package['service_family'] ?? ''),
            (string)($package['description'] ?? ''),
        ]), 'UTF-8');

        return str_contains($haystack, $needle);
    }));
}
$activeCalcPackages = array_values(array_filter(
    $data['service_packages'] ?? [],
    static fn(array $package): bool => !empty($package['is_active'])
));
$stoneCalcPackages = array_values(array_filter(
    $activeCalcPackages,
    static fn(array $package): bool => (string)($package['service_family'] ?? '') === 'stone'
));
usort($stoneCalcPackages, static fn(array $a, array $b): int => ((int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
$deckCalcPackages = array_values(array_filter(
    $activeCalcPackages,
    static fn(array $package): bool => (string)($package['service_family'] ?? '') === 'deck'
));
usort($deckCalcPackages, static fn(array $a, array $b): int => ((int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

$createCustomerState = pull_form_state('customer_create');
$createCustomerDefaults = customer_form_defaults();
if ($createCustomerState === null && $currentUserOrganizationId !== null) {
    $createCustomerDefaults['organizationId'] = (string)$currentUserOrganizationId;
}
if ($createCustomerState === null && in_array(current_user_role(), [USER_ROLE_SALES, USER_ROLE_WORKER], true) && $currentUserRegionId !== null) {
    $createCustomerDefaults['regionId'] = (string)$currentUserRegionId;
}
if ($prefillRequestId > 0 && $createCustomerState === null) {
    $prefillRequest = find_web_quote_request_by_id($data, $prefillRequestId);
    if ($prefillRequest !== null) {
        [$requestFirstName, $requestLastName] = mysql_split_name((string)($prefillRequest['name'] ?? ''));
        $prefillRequestRegionId = ($prefillRequest['region_id'] ?? null) !== null
            ? (int)$prefillRequest['region_id']
            : null;
        if ($prefillRequestRegionId === null) {
            $inferredRequestRegion = infer_region_from_postcode((string)($prefillRequest['service_postcode'] ?? ''), $regions);
            if ($inferredRequestRegion !== null) {
                $prefillRequestRegionId = (int)($inferredRequestRegion['id'] ?? 0);
            }
        }
        if ($prefillRequestRegionId === null && ($prefillRequest['organization_id'] ?? null) !== null) {
            $prefillRequestOrganization = find_organization_by_id($data, (int)$prefillRequest['organization_id']);
            if ($prefillRequestOrganization !== null && ($prefillRequestOrganization['region_id'] ?? null) !== null) {
                $prefillRequestRegionId = (int)$prefillRequestOrganization['region_id'];
            }
        }
        $createCustomerDefaults = array_merge($createCustomerDefaults, [
            'customerType' => 'private',
            'firstName' => $requestFirstName,
            'lastName' => $requestLastName,
            'name' => (string)($prefillRequest['name'] ?? ''),
            'phone' => (string)($prefillRequest['phone'] ?? ''),
            'email' => (string)($prefillRequest['email'] ?? ''),
            'serviceAddress' => (string)($prefillRequest['service_address'] ?? ''),
            'servicePostalCode' => (string)($prefillRequest['service_postcode'] ?? ''),
            'serviceCity' => (string)($prefillRequest['service_city'] ?? ''),
            'billingAddress' => (string)($prefillRequest['service_address'] ?? ''),
            'billingPostalCode' => (string)($prefillRequest['service_postcode'] ?? ''),
            'billingCity' => (string)($prefillRequest['service_city'] ?? ''),
            'billingSameAsProperty' => '1',
            'notes' => trim(implode("\n", array_filter([
                'Inkommande webbforfragan.',
                (string)($prefillRequest['message'] ?? ''),
        ], static fn(string $value): bool => trim($value) !== ''))),
        ]);
        if (($prefillRequest['organization_id'] ?? null) !== null) {
            $createCustomerDefaults['organizationId'] = (string)$prefillRequest['organization_id'];
        }
        if ($prefillRequestRegionId !== null && $prefillRequestRegionId > 0) {
            $createCustomerDefaults['regionId'] = (string)$prefillRequestRegionId;
        }
    }
}
$createCustomerValues = form_state_values($createCustomerState, $createCustomerDefaults);
$createCustomerErrors = form_state_errors($createCustomerState);

$editCustomerState = $selectedCustomer ? pull_form_state('customer_edit_' . (int)$selectedCustomer['id']) : null;
$editCustomerValues = form_state_values($editCustomerState, customer_form_defaults($selectedCustomer));
$editCustomerErrors = form_state_errors($editCustomerState);

$createQuoteState = pull_form_state('quote_create');
$createQuoteDefaults = quote_form_defaults();
if ($createQuoteState === null && $currentUserOrganizationId !== null) {
    $createQuoteDefaults['organizationId'] = (string)$currentUserOrganizationId;
}
if ($prefillCustomerId > 0 && $createQuoteState === null) {
    $prefillCustomer = find_by_id($customers, $prefillCustomerId);
    if ($prefillCustomer) {
        $createQuoteDefaults = array_merge($createQuoteDefaults, quote_form_defaults(['customer_id' => $prefillCustomerId]));
        $createQuoteDefaults['existingCustomerId'] = (string)$prefillCustomerId;
    }
}
if ($createQuoteState === null && in_array(current_user_role(), [USER_ROLE_SALES, USER_ROLE_WORKER], true) && $currentUserRegionId !== null) {
    $createQuoteDefaults['regionId'] = (string)$currentUserRegionId;
}
$createQuoteValues = form_state_values($createQuoteState, $createQuoteDefaults);
$createQuoteErrors = form_state_errors($createQuoteState);

$editQuoteState = $selectedQuote ? pull_form_state('quote_edit_' . (int)$selectedQuote['id']) : null;
$editQuoteValues = form_state_values($editQuoteState, quote_form_defaults($selectedQuote));
$editQuoteErrors = form_state_errors($editQuoteState);

$createJobState = pull_form_state('job_create');
$createJobDefaults = job_form_defaults();
if ($createJobState === null && $currentUserOrganizationId !== null) {
    $createJobDefaults['organizationId'] = (string)$currentUserOrganizationId;
}
if ($prefillCustomerId > 0 && $createJobState === null) {
    $createJobDefaults['customerId'] = (string)$prefillCustomerId;
    $prefillCustomer = find_by_id($customers, $prefillCustomerId);
    if ($prefillCustomer && ($prefillCustomer['organization_id'] ?? null) !== null) {
        $createJobDefaults['organizationId'] = (string)$prefillCustomer['organization_id'];
    }
    if ($prefillCustomer && ($prefillCustomer['region_id'] ?? null) !== null) {
        $createJobDefaults['regionId'] = (string)$prefillCustomer['region_id'];
    }
}
if ($createJobState === null && in_array(current_user_role(), [USER_ROLE_SALES, USER_ROLE_WORKER], true) && $currentUserRegionId !== null) {
    $createJobDefaults['regionId'] = (string)$currentUserRegionId;
}
$createJobValues = form_state_values($createJobState, $createJobDefaults);
$createJobErrors = form_state_errors($createJobState);

$editJobState = $selectedJob ? pull_form_state('job_edit_' . (int)$selectedJob['id']) : null;
$editJobValues = form_state_values($editJobState, job_form_defaults($selectedJob));
$editJobErrors = form_state_errors($editJobState);

$selectedUser = $editUserId > 0 ? find_user_by_id($data, $editUserId) : null;
$createUserState = pull_form_state('user_create');
$createUserValues = form_state_values($createUserState, user_form_defaults());
$createUserErrors = form_state_errors($createUserState);

$editUserState = $selectedUser ? pull_form_state('user_edit_' . (int)$selectedUser['id']) : null;
$editUserValues = form_state_values($editUserState, user_form_defaults($selectedUser));
$editUserErrors = form_state_errors($editUserState);

$selectedRegion = $editRegionId > 0 ? find_region_by_id($data, $editRegionId) : null;
$createRegionState = pull_form_state('region_create');
$createRegionValues = form_state_values($createRegionState, region_form_defaults());
$createRegionErrors = form_state_errors($createRegionState);

$editRegionState = $selectedRegion ? pull_form_state('region_edit_' . (int)$selectedRegion['id']) : null;
$editRegionValues = form_state_values($editRegionState, region_form_defaults($selectedRegion));
$editRegionErrors = form_state_errors($editRegionState);

$selectedOrganization = $editOrganizationId > 0 ? find_organization_by_id($data, $editOrganizationId) : null;
$createOrganizationState = pull_form_state('organization_create');
$createOrganizationValues = form_state_values($createOrganizationState, organization_form_defaults());
$createOrganizationErrors = form_state_errors($createOrganizationState);

$editOrganizationState = $selectedOrganization ? pull_form_state('organization_edit_' . (int)$selectedOrganization['id']) : null;
$editOrganizationValues = form_state_values($editOrganizationState, organization_form_defaults($selectedOrganization));
$editOrganizationErrors = form_state_errors($editOrganizationState);

$selectedProduct = $editProductId > 0 ? find_product_by_id($data, $editProductId) : null;
$createProductState = pull_form_state('product_create');
$createProductValues = form_state_values($createProductState, product_form_defaults());
$createProductErrors = form_state_errors($createProductState);

$editProductState = $selectedProduct ? pull_form_state('product_edit_' . (int)$selectedProduct['id']) : null;
$editProductValues = form_state_values($editProductState, product_form_defaults($selectedProduct));
$editProductErrors = form_state_errors($editProductState);
$productsAvailable = mysql_is_configured() ? mysql_products_available() : true;

$selectedPackage = $editPackageId > 0 ? find_service_package_by_id($data, $editPackageId) : null;
$selectedPackageItems = $selectedPackage ? package_items_for_package($data, (int)$selectedPackage['id']) : [];
$createPackageState = pull_form_state('package_create');
$createPackageValues = form_state_values($createPackageState, package_form_defaults());
$createPackageErrors = form_state_errors($createPackageState);
$createPackageItems = package_item_form_rows($createPackageState['values']['packageItems'] ?? []);

$editPackageState = $selectedPackage ? pull_form_state('package_edit_' . (int)$selectedPackage['id']) : null;
$editPackageValues = form_state_values($editPackageState, package_form_defaults($selectedPackage));
$editPackageErrors = form_state_errors($editPackageState);
$editPackageItems = package_item_form_rows($editPackageState['values']['packageItems'] ?? $selectedPackageItems);
$servicePackagesAvailable = mysql_is_configured() ? mysql_service_packages_available() : true;

$navigation = [
    [
        'label' => 'Dashboard',
        'page' => 'dashboard',
        'icon' => '▦',
    ],
];

if (current_user_can('customers.view')) {
    $navigation[] = [
        'label' => 'Kunder',
        'page' => 'customers',
        'icon' => '▤',
        'children' => [
            ['label' => 'Alla kunder', 'page' => 'customers', 'view' => 'all'],
            ['label' => 'Kunder att följa upp', 'page' => 'customers', 'view' => 'follow_up'],
            ['label' => 'Skapa kund', 'page' => 'customers', 'view' => 'create'],
        ],
    ];
}

if (current_user_can('quotes.view')) {
    $navigation[] = [
        'label' => 'Offerter',
        'page' => 'quotes',
        'icon' => '◫',
        'summary_count' => $navCounts['quotes_expiring'] + $navCounts['quotes_expired'],
        'children' => [
            ['label' => 'Alla offerter', 'page' => 'quotes', 'view' => 'all'],
            ['label' => 'Skickade', 'page' => 'quotes', 'view' => 'sent'],
            ['label' => 'Godkända', 'page' => 'quotes', 'view' => 'approved'],
            ['label' => 'Snart utgångna', 'page' => 'quotes', 'view' => 'expiring', 'badge' => $navCounts['quotes_expiring'], 'badge_tone' => 'warning'],
            ['label' => 'Arkiverade', 'page' => 'quotes', 'view' => 'archived', 'badge' => $navCounts['quotes_expired'], 'badge_tone' => 'danger'],
            ['label' => 'Skapa offert', 'page' => 'quotes', 'view' => 'create'],
        ],
    ];
}

if (current_user_can('requests.view')) {
    $navigation[] = [
        'label' => 'Förfrågningar',
        'page' => 'requests',
        'icon' => '◪',
        'summary_count' => $navCounts['requests_new'],
        'children' => [
            ['label' => 'Alla förfrågningar', 'page' => 'requests', 'view' => 'all'],
            ['label' => 'Ej hanterade', 'page' => 'requests', 'view' => 'new', 'badge' => $navCounts['requests_new'], 'badge_tone' => 'success'],
            ['label' => 'Hanterade', 'page' => 'requests', 'view' => 'handled'],
            ['label' => 'Arkiverade', 'page' => 'requests', 'view' => 'archived'],
        ],
    ];
}

if (current_user_can('jobs.view')) {
    $jobChildren = [
        ['label' => 'Alla jobb', 'page' => 'jobs', 'view' => 'all'],
        ['label' => 'Kommande', 'page' => 'jobs', 'view' => 'upcoming'],
        ['label' => 'Pågående', 'page' => 'jobs', 'view' => 'in_progress'],
        ['label' => 'Klara', 'page' => 'jobs', 'view' => 'done'],
        ['label' => 'Arkiverade', 'page' => 'jobs', 'view' => 'archived'],
    ];
    if (current_user_can('jobs.manage')) {
        $jobChildren[] = ['label' => 'Skapa jobb', 'page' => 'jobs', 'view' => 'create'];
    }

    $navigation[] = [
        'label' => 'Jobb',
        'page' => 'jobs',
        'icon' => '▣',
        'summary_count' => $navCounts['jobs_uninvoiced'],
        'children' => $jobChildren,
    ];
}

if (current_user_can('jobs.view')) {
    $navigation[] = [
        'label' => 'Kalender',
        'page' => 'calendar',
        'icon' => '☷',
    ];
}

if (current_user_can('invoices.view')) {
    $navigation[] = [
        'label' => 'Fakturaunderlag',
        'page' => 'invoices',
        'icon' => '◧',
        'summary_count' => $navCounts['invoices_ready'],
        'children' => [
            ['label' => 'Redo att fakturera', 'page' => 'invoices', 'view' => 'ready', 'badge' => $navCounts['invoices_ready'], 'badge_tone' => 'success'],
            ['label' => 'Skapade', 'page' => 'invoices', 'view' => 'created'],
            ['label' => 'Fakturerade', 'page' => 'invoices', 'view' => 'invoiced'],
        ],
    ];
}

if (current_user_role() === USER_ROLE_ADMIN) {
    $navigation[] = [
        'label' => 'Rapporter',
        'page' => 'reports',
        'icon' => '◩',
    ];
}

if (current_user_can('settings.view')) {
    $navigation[] = [
        'label' => 'Inställningar',
        'page' => 'settings',
        'icon' => '⚙',
        'children' => [
            ['label' => 'Allmänt', 'page' => 'settings', 'view' => 'general'],
            ['label' => 'Produkter & priser', 'page' => 'settings', 'view' => 'products'],
            ['label' => 'Paket', 'page' => 'settings', 'view' => 'packages'],
            ['label' => 'Organisationer', 'page' => 'settings', 'view' => 'organizations'],
            ['label' => 'Regioner', 'page' => 'settings', 'view' => 'regions'],
            ['label' => 'Användare', 'page' => 'settings', 'view' => 'users'],
        ],
    ];
}

$viewTitles = [
    'reports:overview' => 'Rapporter',
    'requests:all' => 'Alla förfrågningar',
    'requests:new' => 'Ej hanterade förfrågningar',
    'requests:handled' => 'Hanterade förfrågningar',
    'requests:archived' => 'Arkiverade förfrågningar',
    'customers:all' => 'Alla kunder',
    'customers:follow_up' => 'Kunder att följa upp',
    'customers:create' => 'Skapa kund',
    'quotes:all' => 'Alla offerter',
    'quotes:sent' => 'Skickade offerter',
    'quotes:approved' => 'Godkända offerter',
    'quotes:expiring' => 'Snart utgångna offerter',
    'quotes:archived' => 'Arkiverade offerter',
    'quotes:create' => 'Skapa offert',
    'quotes:edit' => 'Redigera offert',
    'jobs:all' => 'Alla jobb',
    'jobs:upcoming' => 'Kommande jobb',
    'jobs:in_progress' => 'Pågående jobb',
    'jobs:done' => 'Klara jobb',
    'jobs:archived' => 'Arkiverade jobb',
    'jobs:create' => 'Skapa jobb',
    'jobs:edit' => 'Redigera jobb',
    'calendar:week' => 'Kalender',
    'invoices:ready' => 'Redo att fakturera',
    'invoices:created' => 'Skapade fakturaunderlag',
    'invoices:invoiced' => 'Fakturerade',
    'settings:general' => 'Inställningar',
    'settings:products' => 'Produkter & priser',
    'settings:packages' => 'Paket',
    'settings:organizations' => 'Organisationer',
    'settings:regions' => 'Regioner',
    'settings:users' => 'Användare',
];

$viewDescriptions = [
    'reports:overview' => 'Fördjupad statistik över försäljning, utförande och fakturerbart värde.',
    'requests:all' => 'Nya webbformulär med adressuppgifter som kan styras till rätt organisation.',
    'requests:new' => 'Nya inkommande förfrågningar från hemsidan som ännu inte är hanterade.',
    'requests:handled' => 'Förfrågningar som någon redan tagit hand om.',
    'requests:archived' => 'Förfrågningar som arkiverats och inte längre ligger i aktiv uppföljning.',
    'customers:follow_up' => 'Underhållskunder där nästa planerade uppföljning ligger inom 30 dagar eller redan passerat.',
    'quotes:expiring' => 'Offerter som löper ut inom 10 dagar och behöver följas upp.',
    'quotes:archived' => 'Nekade, utgångna och makulerade offerter samlade på ett ställe.',
    'jobs:archived' => 'Avbrutna jobb som inte längre ligger i det aktiva arbetsflödet.',
    'invoices:ready' => 'Fakturaunderlag som är redo att exporteras eller faktureras.',
    'quotes:create' => 'Skapa en ny offert med kund-, moms- och RUT-logik i samma flöde.',
    'customers:create' => 'Lägg upp en ny kund med rätt kundtyp, adresser och momsmodell.',
    'jobs:create' => 'Skapa ett nytt jobb och koppla det till kund eller offert.',
    'jobs:edit' => 'Uppdatera status, kostnader, moms, RUT och faktureringsberedskap för ett befintligt jobb.',
    'calendar:week' => 'Veckoöversikt över planerade jobb per region och arbetare.',
    'settings:products' => 'Hantera produkter, prismodeller och standardpriser från adminen.',
    'settings:packages' => 'Bygg tjänstepaket av produkter och styr hur deras rader skapas i kalkyler.',
    'settings:organizations' => 'Bygg kedjan av huvudbolag, regionbolag och franchiseenheter.',
    'settings:regions' => 'Skapa regioner för säljare, arbetare, kunder och framtida kalenderplanering.',
    'settings:users' => 'Hantera inloggningar och roller för admin, säljare och arbetare.',
];

$activeViewKey = $page . ':' . ($page === 'dashboard' ? 'dashboard' : $view);
$headerTitle = $page === 'customer' && $selectedCustomer
    ? customer_name($data, (int)$selectedCustomer['id'])
    : ($viewTitles[$activeViewKey] ?? $pageLabels[$page] ?? 'Admin');
$headerDescription = $viewDescriptions[$activeViewKey] ?? '';

if ($activeViewKey === 'jobs:edit' && current_user_role() === USER_ROLE_WORKER) {
    $headerTitle = 'Jobb';
    $headerDescription = 'Se vad som ska göras hos kunden, uppdatera arbetsstatus och markera jobbet klart när arbetet är utfört.';
}
?>
<!DOCTYPE html>
<html lang="sv">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin | Nyskick Sten & Altan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;700;800&family=Source+Sans+3:wght@400;600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="style.css" />
  </head>
  <body class="admin-body">
    <div class="admin-shell">
      <aside class="admin-sidebar">
        <div>
          <p class="sidebar-kicker">Adminpanel</p>
          <div class="sidebar-brand">
            <img src="assets/nyskick-logo.jpeg" alt="Nyskick Sten & Altan" class="sidebar-brand-logo" />
            <h1>Nyskick Sten & Altan</h1>
          </div>
          <p class="sidebar-copy">Inloggad som <?= h($currentUserName) ?> (<?= h(mb_strtolower(role_label(current_user_role()), 'UTF-8')) ?>)<?php if ($currentUserOrganization): ?> · <?= h((string)($currentUserOrganization['name'] ?? '')) ?><?php elseif ($currentUserRegion): ?> · <?= h((string)($currentUserRegion['name'] ?? '')) ?><?php endif; ?>. Navigationen följer ditt arbetsflöde.</p>
        </div>
        <nav class="admin-nav" aria-label="Adminnavigering">
          <?php foreach ($navigation as $item): ?>
            <?php $isActiveGroup = ($item['page'] ?? '') === $page; ?>
            <?php if (!isset($item['children'])): ?>
              <a class="nav-link <?= $isActiveGroup ? 'active' : '' ?>" href="<?= h(admin_url($item['page'], $item['page'] === 'settings' ? ['view' => 'general'] : [])) ?>">
                <span class="nav-icon"><?= h($item['icon']) ?></span>
                <span><?= h($item['label']) ?></span>
              </a>
            <?php else: ?>
              <details class="nav-group"<?= $isActiveGroup ? ' open' : '' ?>>
                <summary class="nav-parent <?= $isActiveGroup ? 'active' : '' ?>">
                  <span class="nav-parent-label">
                    <span class="nav-icon"><?= h($item['icon']) ?></span>
                    <span><?= h($item['label']) ?></span>
                  </span>
                  <?php if (!empty($item['summary_count'])): ?>
                    <span class="nav-summary-pill"><?= (int)$item['summary_count'] ?></span>
                  <?php endif; ?>
                </summary>
                <div class="nav-submenu">
                  <?php foreach ($item['children'] as $child): ?>
                    <?php $isActiveChild = $page === $child['page'] && $view === $child['view']; ?>
                    <a class="nav-sublink <?= $isActiveChild ? 'active' : '' ?>" href="<?= h(admin_url($child['page'], ['view' => $child['view']])) ?>">
                      <span><?= h($child['label']) ?></span>
                      <?php if (!empty($child['badge'])): ?>
                        <span class="nav-badge nav-badge-<?= h($child['badge_tone'] ?? 'default') ?>"><?= (int)$child['badge'] ?></span>
                      <?php endif; ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </details>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
          <a class="button button-secondary" href="logout.php">Logga ut</a>
        </div>
      </aside>

      <main class="admin-main">
        <header class="admin-header">
          <div>
            <p class="page-eyebrow"><?= h($pageLabels[$page] ?? 'Admin') ?></p>
            <h2><?= h($headerTitle) ?></h2>
            <?php if ($headerDescription !== ''): ?>
              <p class="page-description"><?= h($headerDescription) ?></p>
            <?php endif; ?>
          </div>
        </header>

        <?php if ($flash): ?>
          <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($page === 'dashboard'): ?>
          <?php if ($isAdminUser && $activeOrganizations !== []): ?>
          <form method="get" class="inline-search inline-search-left" data-auto-submit-form>
            <input type="hidden" name="page" value="dashboard" />
            <select name="dashboard_organization">
              <option value="">Alla organisationer</option>
              <?php foreach ($activeOrganizations as $organization): ?>
                <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= $dashboardOrganizationFilter === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                  <?= h(organization_tree_label($organization)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="button button-secondary" type="submit">Filtrera</button>
            <?php if ($dashboardOrganizationFilter !== ''): ?>
              <a class="button button-secondary" href="<?= h(admin_url('dashboard')) ?>">Rensa</a>
            <?php endif; ?>
          </form>
          <?php endif; ?>
          <section class="stats-grid dashboard-stats-grid">
            <?php if ($isSalesUser): ?>
              <a class="stat-card" href="<?= h(admin_url('requests', ['view' => 'new'])) ?>"><span>Förfrågningar</span><strong><?= $stats['new_requests'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('quotes', ['view' => 'all'])) ?>"><span>Mina väntande offerter</span><strong><?= $stats['pending_quotes'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('quotes', ['view' => 'approved'])) ?>"><span>Mina godkända offerter</span><strong><?= $stats['approved_quotes'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('quotes', ['view' => 'expiring'])) ?>"><span>Mina snart utgångna</span><strong><?= $stats['expiring_quotes'] ?></strong></a>
              <div class="stat-card"><span>Min försäljning denna månad</span><strong><?= h(format_currency((float)($stats['monthly_revenue'] ?? 0))) ?></strong></div>
            <?php elseif ($isWorkerUser): ?>
              <a class="stat-card" href="<?= h(admin_url('jobs', ['view' => 'upcoming'])) ?>"><span>Mina bokade jobb</span><strong><?= $stats['booked_jobs'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('jobs', ['view' => 'done'])) ?>"><span>Mina klara jobb</span><strong><?= $stats['completed_jobs'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('invoices', ['view' => 'ready'])) ?>"><span>Mina att fakturera</span><strong><?= $stats['invoice_ready_jobs'] ?></strong></a>
            <?php else: ?>
              <a class="stat-card" href="<?= h(admin_url('requests', ['view' => 'new'])) ?>"><span>Förfrågningar</span><strong><?= $stats['new_requests'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('quotes', ['view' => 'expiring'])) ?>"><span>Väntande offerter</span><strong><?= $stats['pending_quotes'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('jobs', ['view' => 'upcoming'])) ?>"><span>Bokade jobb</span><strong><?= $stats['booked_jobs'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('jobs', ['view' => 'done'])) ?>"><span>Klara jobb</span><strong><?= $stats['completed_jobs'] ?></strong></a>
              <a class="stat-card" href="<?= h(admin_url('invoices', ['view' => 'ready'])) ?>"><span>Redo att fakturera</span><strong><?= $stats['invoice_ready_jobs'] ?></strong></a>
            <?php endif; ?>
          </section>
          <section class="admin-grid dashboard-grid">
            <article class="dashboard-actions-panel">
              <div class="panel-heading"><h3>Snabbåtgärder</h3></div>
              <div class="quick-actions">
                <a class="action-card" href="<?= h(admin_url('customers', ['view' => 'create'])) ?>">Ny kund</a>
                <a class="action-card" href="<?= h(admin_url('quotes', ['view' => 'create'])) ?>">Ny offert</a>
                <?php if (current_user_can('jobs.manage')): ?>
                  <a class="action-card" href="<?= h(admin_url('jobs', ['view' => 'create'])) ?>">Nytt jobb</a>
                <?php endif; ?>
                <?php if ($isAdminUser): ?>
                  <a class="action-card" href="<?= h(admin_url('reports', ['view' => 'overview'])) ?>">Rapporter</a>
                <?php endif; ?>
              </div>
            </article>
          </section>
          <?php if (!$isWorkerUser): ?>
            <section class="admin-grid dashboard-insights-grid">
              <article class="panel dashboard-panel">
                <div class="panel-heading">
                  <h3><?= h($isSalesUser ? 'Min försäljning den här månaden' : 'Den här månaden') ?></h3>
                  <p><?= h($isSalesUser ? 'Endast dina egna offerter och din egen konvertering visas här.' : 'Försäljning, jobbflöde och faktureringsläge i en snabb överblick.') ?></p>
                </div>
                <div class="dashboard-kpi-grid">
                  <?php foreach ($dashboardMonthStats as $kpi): ?>
                    <div class="dashboard-kpi-card">
                      <span><?= h($kpi['label']) ?></span>
                      <strong><?= h($kpi['value']) ?></strong>
                    </div>
                  <?php endforeach; ?>
                </div>
              </article>
              <article class="panel dashboard-panel">
                <div class="panel-heading">
                  <h3>Behöver ageras nu</h3>
                  <p><?= h($isSalesUser ? 'Bara sådant som rör dina egna offerter visas här.' : 'Sådant som riskerar att stanna upp om ingen tar tag i det.') ?></p>
                </div>
                <?php if ($dashboardAlerts === []): ?>
                  <p class="dashboard-empty">Inget akut att följa upp just nu.</p>
                <?php else: ?>
                  <div class="dashboard-alert-list">
                    <?php foreach ($dashboardAlerts as $alert): ?>
                      <a class="dashboard-alert dashboard-alert-<?= h($alert['tone']) ?>" href="<?= h($alert['href']) ?>">
                        <span><?= h($alert['label']) ?></span>
                        <strong><?= h($alert['text']) ?></strong>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </article>
            </section>
          <?php endif; ?>
          <section class="admin-grid dashboard-grid">
            <article class="panel dashboard-panel">
              <div class="panel-heading"><h3>Dagens jobb</h3><p><?= h(format_date($today)) ?></p></div>
              <?php if ($todayJobs === []): ?>
                <p class="dashboard-empty">Inga jobb planerade idag.</p>
              <?php else: ?>
                <div class="stack-md">
                  <?php foreach ($todayJobs as $job): ?>
                    <?php $customer = find_by_id($customers, (int)$job['customer_id']); ?>
                    <?php $jobTime = trim((string)($job['scheduled_time'] ?? '')) !== '' ? substr((string)$job['scheduled_time'], 0, 5) : 'Tid ej satt'; ?>
                    <?php $jobAssigneeLabel = job_assignee_label($job, $workerUsers); ?>
                    <a class="list-row dashboard-job-row" href="<?= h(admin_url('jobs', [
                      'view' => 'edit',
                      'job_edit_id' => (int)($job['id'] ?? 0),
                      'return_page' => 'dashboard',
                    ])) ?>">
                      <div class="dashboard-job-time"><?= h($jobTime) ?></div>
                      <div class="dashboard-job-copy">
                        <strong><?= h(customer_name($data, (int)$job['customer_id'])) ?></strong>
                        <p>
                          <?= h((string)($job['service_type'] ?? '')) ?><?php if (!empty($customer['service_city'] ?? $customer['city'] ?? '')): ?> · <?= h((string)($customer['service_city'] ?? $customer['city'])) ?><?php endif; ?>
                          <?php if (current_user_role() === USER_ROLE_ADMIN && $jobAssigneeLabel !== ''): ?> · <?= h($jobAssigneeLabel) ?><?php endif; ?>
                        </p>
                      </div>
                      <span class="badge <?= h(badge_class($job['status'])) ?>"><?= h(status_label($job['status'])) ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>
          </section>
        <?php elseif ($page === 'reports'): ?>
          <section class="workspace-stack">
            <article class="panel dashboard-panel">
              <div class="panel-heading">
                <h3>Rapporter</h3>
                <p>Fördjupad statistik för <?= h(mb_strtolower($reportPeriodLabel, 'UTF-8')) ?>.</p>
              </div>
              <form method="get" class="inline-search inline-search-left" data-auto-submit-form>
                <input type="hidden" name="page" value="reports" />
                <input type="hidden" name="view" value="overview" />
                <select name="report_period">
                  <?php foreach ($reportPeriodOptions as $periodKey => $periodLabel): ?>
                    <option value="<?= h($periodKey) ?>"<?= $reportPeriod === $periodKey ? ' selected' : '' ?>><?= h($periodLabel) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="report_organization">
                  <option value="">Alla organisationer</option>
                  <?php foreach ($activeOrganizations as $organization): ?>
                    <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= $reportOrganizationFilter === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                      <?= h(organization_tree_label($organization)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($reportOrganizationFilter !== '' || $reportPeriod !== 'month'): ?>
                  <a class="button button-secondary" href="<?= h(admin_url('reports', ['view' => 'overview'])) ?>">Rensa</a>
                <?php endif; ?>
              </form>
              <div class="dashboard-kpi-grid report-summary-grid">
                <?php foreach ($reportSummaryStats as $stat): ?>
                  <div class="dashboard-kpi-card">
                    <span><?= h($stat['label']) ?></span>
                    <strong><?= h($stat['value']) ?></strong>
                  </div>
                <?php endforeach; ?>
              </div>
            </article>

            <section class="admin-grid dashboard-insights-grid">
              <article class="panel dashboard-panel">
                <div class="panel-heading">
                  <h3>Försäljning per organisation</h3>
                  <p>Vad varje enhet driver i godkända offerter, jobb och fakturerbart flöde.</p>
                </div>
                <?php if ($reportOrganizationRows === []): ?>
                  <p class="dashboard-empty">Ingen organisationsdata i den valda perioden.</p>
                <?php else: ?>
                  <div class="dashboard-org-list report-list">
                    <?php foreach ($reportOrganizationRows as $row): ?>
                      <div class="dashboard-org-card">
                        <div class="dashboard-org-head">
                          <strong><?= h((string)($row['name'] ?? '')) ?></strong>
                          <span><?= h((string)($row['type_label'] ?? '')) ?></span>
                        </div>
                        <div class="dashboard-org-metrics">
                          <div><span>Försäljning</span><strong><?= h(format_currency((float)($row['revenue'] ?? 0))) ?></strong></div>
                          <div><span>Godkända</span><strong><?= h((string)($row['approved_quotes'] ?? 0)) ?></strong></div>
                          <div><span>Klara jobb</span><strong><?= h((string)($row['completed_jobs'] ?? 0)) ?></strong></div>
                          <div><span>Redo att fakturera</span><strong><?= h((string)($row['ready_jobs'] ?? 0)) ?></strong></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </article>
              <article class="panel dashboard-panel">
                <div class="panel-heading">
                  <h3>Säljare</h3>
                  <p>Vem som skapar offerter, får dem godkända och driver intäkt.</p>
                </div>
                <?php if ($reportSellerRows === []): ?>
                  <p class="dashboard-empty">Ingen säljarstatistik i den valda perioden.</p>
                <?php else: ?>
                  <div class="dashboard-person-list report-list">
                    <?php foreach ($reportSellerRows as $row): ?>
                      <div class="dashboard-person-card">
                        <div class="dashboard-person-head">
                          <strong><?= h((string)($row['name'] ?? '')) ?></strong>
                          <?php if ((string)($row['organization_name'] ?? '') !== ''): ?><span><?= h((string)$row['organization_name']) ?></span><?php endif; ?>
                        </div>
                        <div class="dashboard-person-metrics">
                          <div><span>Försäljning</span><strong><?= h(format_currency((float)($row['revenue'] ?? 0))) ?></strong></div>
                          <div><span>Skapade</span><strong><?= h((string)($row['created_quotes'] ?? 0)) ?></strong></div>
                          <div><span>Godkända</span><strong><?= h((string)($row['approved_quotes'] ?? 0)) ?></strong></div>
                          <div><span>Konvertering</span><strong><?= h(($row['conversion_rate'] ?? null) === null ? '–' : ((string)$row['conversion_rate'] . ' %')) ?></strong></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </article>
            </section>

            <section class="admin-grid dashboard-insights-grid">
              <article class="panel dashboard-panel">
                <div class="panel-heading">
                  <h3>Arbetare</h3>
                  <p>Belastning och leverans under den valda perioden.</p>
                </div>
                <?php if ($reportWorkerRows === []): ?>
                  <p class="dashboard-empty">Ingen arbetarstatistik i den valda perioden.</p>
                <?php else: ?>
                  <div class="dashboard-person-list report-list">
                    <?php foreach ($reportWorkerRows as $row): ?>
                      <div class="dashboard-person-card">
                        <div class="dashboard-person-head">
                          <strong><?= h((string)($row['name'] ?? '')) ?></strong>
                          <?php if ((string)($row['organization_name'] ?? '') !== ''): ?><span><?= h((string)$row['organization_name']) ?></span><?php endif; ?>
                        </div>
                        <div class="dashboard-person-metrics report-worker-metrics">
                          <div><span>Schemalagda</span><strong><?= h((string)($row['scheduled_jobs'] ?? 0)) ?></strong></div>
                          <div><span>Klara</span><strong><?= h((string)($row['completed_jobs'] ?? 0)) ?></strong></div>
                          <div><span>Redo att fakturera</span><strong><?= h((string)($row['ready_jobs'] ?? 0)) ?></strong></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </article>
              <article class="panel dashboard-panel">
                <div class="panel-heading">
                  <h3>Tjänster</h3>
                  <p>Vilka tjänster som säljs och bokas mest i perioden.</p>
                </div>
                <?php if ($reportServiceRows === []): ?>
                  <p class="dashboard-empty">Ingen tjänstedata i den valda perioden.</p>
                <?php else: ?>
                  <div class="dashboard-org-list report-list">
                    <?php foreach ($reportServiceRows as $row): ?>
                      <div class="dashboard-org-card">
                        <div class="dashboard-org-head">
                          <strong><?= h((string)($row['service'] ?? '')) ?></strong>
                        </div>
                        <div class="dashboard-org-metrics report-service-metrics">
                          <div><span>Försäljning</span><strong><?= h(format_currency((float)($row['revenue'] ?? 0))) ?></strong></div>
                          <div><span>Godkända offerter</span><strong><?= h((string)($row['approved_quotes'] ?? 0)) ?></strong></div>
                          <div><span>Schemalagda jobb</span><strong><?= h((string)($row['scheduled_jobs'] ?? 0)) ?></strong></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </article>
            </section>
          </section>
        <?php elseif ($page === 'calendar'): ?>
          <section class="workspace-stack">
            <article class="panel calendar-panel">
              <div class="panel-heading">
                <div>
                  <h3>Veckokalender</h3>
                  <p>Översikt över planerade jobb per dag, region och ansvarig.</p>
                </div>
                <div class="calendar-week-label">
                  Vecka <?= h((string)date('W', $weekStart)) ?>
                </div>
              </div>
              <form method="get" class="inline-search inline-search-left calendar-nav" data-auto-submit-form>
                <input type="hidden" name="page" value="calendar" />
                <input type="hidden" name="view" value="week" />
                <?php if (current_user_role() === USER_ROLE_ADMIN): ?>
                  <select name="calendar_organization">
                    <option value="">Alla organisationer</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= $calendarOrganizationFilter === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <select name="calendar_region">
                    <option value="">Alla regioner</option>
                    <?php foreach ($activeRegions as $region): ?>
                      <option value="<?= (int)($region['id'] ?? 0) ?>"<?= $calendarRegionFilter === (string)($region['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h((string)($region['name'] ?? '')) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="hidden" name="calendar_organization" value="<?= h($currentUserOrganizationId !== null ? (string)$currentUserOrganizationId : '') ?>" />
                  <input type="hidden" name="calendar_region" value="<?= h($currentUserRegionId !== null ? (string)$currentUserRegionId : '') ?>" />
                <?php endif; ?>
                <select name="calendar_worker">
                  <option value="">Alla arbetare</option>
                  <?php foreach ($workerUsers as $workerUser): ?>
                    <?php $workerUsername = (string)($workerUser['username'] ?? ''); ?>
                    <option value="<?= h($workerUsername) ?>"<?= $calendarWorkerFilter === $workerUsername ? ' selected' : '' ?>>
                      <?= h((string)($workerUser['name'] ?? $workerUsername)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="button button-secondary" type="submit">Filtrera</button>
                <a class="button button-secondary" href="<?= h(admin_url('calendar', ['view' => 'week', 'week' => $weekOffset - 1, 'calendar_organization' => current_user_role() === USER_ROLE_ADMIN ? $calendarOrganizationFilter : ($currentUserOrganizationId !== null ? (string)$currentUserOrganizationId : ''), 'calendar_region' => current_user_role() === USER_ROLE_ADMIN ? $calendarRegionFilter : ($currentUserRegionId !== null ? (string)$currentUserRegionId : ''), 'calendar_worker' => $calendarWorkerFilter])) ?>">Föregående vecka</a>
                <a class="button button-secondary" href="<?= h(admin_url('calendar', ['view' => 'week', 'week' => 0, 'calendar_organization' => current_user_role() === USER_ROLE_ADMIN ? $calendarOrganizationFilter : ($currentUserOrganizationId !== null ? (string)$currentUserOrganizationId : ''), 'calendar_region' => current_user_role() === USER_ROLE_ADMIN ? $calendarRegionFilter : ($currentUserRegionId !== null ? (string)$currentUserRegionId : ''), 'calendar_worker' => $calendarWorkerFilter])) ?>">Denna vecka</a>
                <a class="button button-secondary" href="<?= h(admin_url('calendar', ['view' => 'week', 'week' => $weekOffset + 1, 'calendar_organization' => current_user_role() === USER_ROLE_ADMIN ? $calendarOrganizationFilter : ($currentUserOrganizationId !== null ? (string)$currentUserOrganizationId : ''), 'calendar_region' => current_user_role() === USER_ROLE_ADMIN ? $calendarRegionFilter : ($currentUserRegionId !== null ? (string)$currentUserRegionId : ''), 'calendar_worker' => $calendarWorkerFilter])) ?>">Nästa vecka</a>
              </form>
              <div class="calendar-grid">
                <?php foreach ($weekDays as $day): ?>
                  <div class="calendar-day">
                    <div class="calendar-day-head">
                      <h4><?= h($day['label']) ?></h4>
                      <span class="calendar-day-count"><?= count($day['jobs']) ?> jobb</span>
                    </div>
                    <div class="stack-sm">
                      <?php foreach ($day['jobs'] as $job): ?>
                        <a class="calendar-item" href="<?= h(admin_url('jobs', array_filter([
                          'view' => 'edit',
                          'job_edit_id' => (int)($job['id'] ?? 0),
                          'return_page' => 'calendar',
                          'return_view' => 'week',
                          'return_week' => (string)$weekOffset,
                          'return_calendar_organization' => current_user_role() === USER_ROLE_ADMIN ? $calendarOrganizationFilter : ($currentUserOrganizationId !== null ? (string)$currentUserOrganizationId : ''),
                          'return_calendar_region' => current_user_role() === USER_ROLE_ADMIN ? $calendarRegionFilter : ($currentUserRegionId !== null ? (string)$currentUserRegionId : ''),
                          'return_calendar_worker' => $calendarWorkerFilter,
                        ], static fn($value): bool => $value !== ''))) ?>">
                          <strong><?= h(customer_name($data, (int)($job['customer_id'] ?? 0))) ?></strong>
                          <small><?= h(trim((string)($job['scheduled_time'] ?? '')) !== '' ? substr((string)$job['scheduled_time'], 0, 5) : 'Tid ej satt') ?></small>
                        </a>
                      <?php endforeach; ?>
                      <?php if (($day['jobs'] ?? []) === []): ?>
                        <p class="calendar-empty">Inga jobb.</p>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </article>
          </section>
        <?php elseif ($page === 'requests'): ?>
          <section class="workspace-stack">
            <article class="panel">
              <div class="panel-heading">
                <h3>Inkommande förfrågningar</h3>
                <p>Förfrågningar från hemsidan med adress, beräknad region och tilldelad organisation.</p>
              </div>
              <form method="get" class="inline-search inline-search-left" data-live-search-form data-auto-submit-form>
                <input type="hidden" name="page" value="requests" />
                <input type="hidden" name="view" value="<?= h(in_array($view, ['all', 'new', 'handled', 'archived'], true) ? $view : 'all') ?>" />
                <input type="search" name="request_q" value="<?= h($requestSearch) ?>" placeholder="Sök namn, telefon, e-post, adress eller region" data-live-search-input />
                <?php if ($isAdminUser): ?>
                  <select name="request_organization">
                    <option value="">Alla organisationer</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= $requestOrganizationFilter === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <button class="button button-secondary" type="submit">Sök</button>
                <?php if ($requestSearch !== '' || ($isAdminUser && $requestOrganizationFilter !== '')): ?>
                  <a class="button button-secondary" href="<?= h(admin_url('requests', ['view' => in_array($view, ['all', 'new', 'handled', 'archived'], true) ? $view : 'all'])) ?>">Rensa</a>
                <?php endif; ?>
              </form>
              <div class="stack-md" data-live-search-list>
                <?php foreach ($filteredRequestsByView as $request): ?>
                  <?php
                  $requestRegion = ($request['region_id'] ?? null) !== null ? find_region_by_id($data, (int)$request['region_id']) : null;
                  $requestOrganization = ($request['organization_id'] ?? null) !== null ? find_organization_by_id($data, (int)$request['organization_id']) : null;
                  $requestStatus = (string)($request['status'] ?? 'new');
                  $requestStatusLabel = match ($requestStatus) {
                      'handled' => 'Hanterad',
                      'archived' => 'Arkiverad',
                      default => 'Ej hanterad',
                  };
                  $requestStatusTone = match ($requestStatus) {
                      'handled' => 'neutral',
                      'archived' => 'warning',
                      default => 'success',
                  };
                  $requestAssignmentLabel = match ((string)($request['assignment_basis'] ?? '')) {
                      'fallback' => 'Fallback till Dalarna',
                      'region' => 'Beräknad på postnummer',
                      default => '',
                  };
                  $requestAddress = trim(implode(', ', array_filter([
                      (string)($request['service_address'] ?? ''),
                      trim(implode(' ', array_filter([(string)($request['service_postcode'] ?? ''), (string)($request['service_city'] ?? '')], static fn(string $value): bool => $value !== ''))),
                  ], static fn(string $value): bool => $value !== '')));
                  $requestSearchText = trim(implode(' ', array_filter([
                      (string)($request['name'] ?? ''),
                      (string)($request['phone'] ?? ''),
                      (string)($request['email'] ?? ''),
                      $requestAddress,
                      (string)($requestRegion['name'] ?? $request['requested_region_name'] ?? ''),
                      (string)($request['message'] ?? ''),
                      (string)($requestOrganization['name'] ?? ''),
                      $requestStatusLabel,
                  ], static fn(string $value): bool => $value !== '')));
                  ?>
                  <div class="list-card" data-live-search-item data-search-text="<?= h($requestSearchText) ?>">
                    <div class="list-row">
                      <div class="list-row-stack">
                        <div class="list-inline-strong">
                          <a class="list-row-main-link" href="<?= h(admin_url('customers', ['view' => 'create', 'request_id' => (int)($request['id'] ?? 0)])) ?>">
                            <strong><?= h((string)($request['name'] ?? '')) ?></strong>
                          </a>
                          <?php if ($requestAddress !== ''): ?><span><?= h($requestAddress) ?></span><?php endif; ?>
                        </div>
                        <div class="list-inline-muted">
                          <?php if ((string)($request['phone'] ?? '') !== ''): ?><span><?= h((string)$request['phone']) ?></span><?php endif; ?>
                          <?php if ((string)($request['email'] ?? '') !== ''): ?><span><?= h((string)$request['email']) ?></span><?php endif; ?>
                          <?php if ($requestRegion || (string)($request['requested_region_name'] ?? '') !== ''): ?><span><?= h((string)($requestRegion['name'] ?? $request['requested_region_name'] ?? '')) ?></span><?php endif; ?>
                          <?php if ($requestOrganization): ?><span><?= h((string)($requestOrganization['name'] ?? '')) ?></span><?php endif; ?>
                          <?php if ($requestAssignmentLabel !== ''): ?><span><?= h($requestAssignmentLabel) ?></span><?php endif; ?>
                          <?php if ((string)($request['created_at'] ?? '') !== ''): ?><span><?= h(format_datetime((string)$request['created_at'])) ?></span><?php endif; ?>
                        </div>
                        <?php if ((string)($request['message'] ?? '') !== ''): ?>
                          <p><?= nl2br(h((string)($request['message'] ?? ''))) ?></p>
                        <?php endif; ?>
                      </div>
                      <div class="list-row-actions">
                        <span class="badge badge-<?= h($requestStatusTone) ?>"><?= h($requestStatusLabel) ?></span>
                      </div>
                    </div>
                    <div class="form-actions">
                      <?php if ($requestStatus === 'new'): ?>
                        <a class="button button-primary" href="<?= h(admin_url('customers', ['view' => 'create', 'request_id' => (int)($request['id'] ?? 0)])) ?>">Skapa kund och offert</a>
                        <form method="post" class="inline-form">
                          <?= csrf_input() ?>
                          <input type="hidden" name="action" value="update_web_quote_request_status" />
                          <input type="hidden" name="requestId" value="<?= (int)($request['id'] ?? 0) ?>" />
                          <input type="hidden" name="status" value="handled" />
                          <button class="button button-secondary" type="submit">Markera hanterad</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($requestStatus === 'handled'): ?>
                        <form method="post" class="inline-form">
                          <?= csrf_input() ?>
                          <input type="hidden" name="action" value="update_web_quote_request_status" />
                          <input type="hidden" name="requestId" value="<?= (int)($request['id'] ?? 0) ?>" />
                          <input type="hidden" name="status" value="archived" />
                          <button class="button button-secondary" type="submit">Arkivera</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($requestStatus !== 'new'): ?>
                        <form method="post" class="inline-form">
                          <?= csrf_input() ?>
                          <input type="hidden" name="action" value="update_web_quote_request_status" />
                          <input type="hidden" name="requestId" value="<?= (int)($request['id'] ?? 0) ?>" />
                          <input type="hidden" name="status" value="new" />
                          <button class="button button-secondary" type="submit">Markera som ej hanterad</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <p data-live-search-empty<?= $filteredRequestsByView === [] ? '' : ' hidden' ?>><?= $requestSearch !== '' ? 'Ingen förfrågan matchade din sökning.' : 'Inga förfrågningar ännu.' ?></p>
              </div>
            </article>
          </section>
        <?php elseif ($page === 'customers'): ?>
          <section class="workspace-stack">
            <?php if (in_array($view, ['all', 'follow_up'], true)): ?>
            <article class="panel">
              <div class="panel-heading">
                <h3><?= h($view === 'follow_up' ? 'Kunder att följa upp' : 'Kundregister') ?></h3>
                <div class="header-actions">
                  <a class="button button-primary" href="<?= h(admin_url('customers', ['view' => 'create'])) ?>#new-customer">Ny kund</a>
                </div>
              </div>
              <form method="get" class="inline-search inline-search-left" data-live-search-form data-auto-submit-form>
                <input type="hidden" name="page" value="customers" />
                <input type="hidden" name="view" value="<?= h($view) ?>" />
                <input type="search" name="q" value="<?= h($search) ?>" placeholder="Sök kund, moms, ort eller identitetsnummer" data-live-search-input />
                <?php if ($isAdminUser): ?>
                  <select name="customer_organization">
                    <option value="">Alla organisationer</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= $customerOrganizationFilter === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <button class="button button-secondary" type="submit">Sök</button>
                <?php if ($search !== '' || ($isAdminUser && $customerOrganizationFilter !== '')): ?>
                  <a class="button button-secondary" href="<?= h(admin_url('customers', ['view' => $view])) ?>">Rensa</a>
                <?php endif; ?>
              </form>
              <div class="stack-md" data-live-search-list>
                <?php $customerListRecords = $view === 'follow_up' ? $followUpCustomers : $filteredCustomers; ?>
                <?php foreach ($customerListRecords as $customer): ?>
                  <?php
                  $customerFirstName = trim((string)($customer['first_name'] ?? ''));
                  $customerLastName = trim((string)($customer['last_name'] ?? ''));
                  $customerStreet = trim((string)($customer['service_address'] ?? $customer['address'] ?? ''));
                  $customerPostalCode = trim((string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? ''));
                  $customerCity = trim((string)($customer['service_city'] ?? $customer['city'] ?? ''));
                  $customerPhone = trim((string)($customer['phone'] ?? ''));
                  $customerPhoneDigits = preg_replace('/\D+/', '', $customerPhone) ?? '';
                  $customerAddress = trim(implode(', ', array_filter([
                      $customerStreet,
                      trim(implode(' ', array_filter([$customerPostalCode, $customerCity], static fn(string $value): bool => $value !== ''))),
                  ], static fn(string $value): bool => $value !== '')));
                  $customerAddressLink = $customerAddress !== ''
                      ? render_google_maps_link(
                          $customerAddress,
                          [$customerStreet, $customerPostalCode, $customerCity],
                          'address-link'
                      )
                      : '';
                  $customerFollowUpState = follow_up_date_state((string)($customer['next_service_date'] ?? ''), $today);
                  ?>
                  <?php $customerSearchText = trim(implode(' ', array_filter([
                      customer_name($data, (int)$customer['id']),
                      $customerFirstName,
                      $customerLastName,
                      $customerPhone,
                      $customerPhoneDigits,
                      (string)($customer['email'] ?? ''),
                      $customerStreet,
                      $customerAddress,
                      $customerCity,
                      $customerPostalCode,
                      (string)($customer['property_designation'] ?? ''),
                      customer_type_label((string)($customer['customer_type'] ?? 'private')),
                      vat_mode_label((string)($customer['billing_vat_mode'] ?? 'standard_vat')),
                      customer_identifier_value($customer),
                      !empty($customer['rut_enabled']) ? 'rut aktivt' : 'rut ej aktivt',
                      customer_service_type_label((string)($customer['service_type'] ?? 'single')),
                      (string)($customer['next_service_date'] ?? ''),
                  ], static fn(string $value): bool => $value !== ''))); ?>
                  <div class="list-card" data-live-search-item data-search-text="<?= h($customerSearchText) ?>">
                    <div class="list-row">
                      <div class="list-row-stack">
                        <a class="list-row-main-link" href="<?= h(admin_url('customer', ['id' => $customer['id']])) ?>">
                          <strong><?= h(customer_name($data, (int)$customer['id'])) ?></strong>
                        </a>
                        <?php if ($customerAddressLink !== ''): ?>
                          <div class="list-inline-muted"><?= $customerAddressLink ?></div>
                        <?php endif; ?>
                        <?php if ($view === 'follow_up'): ?>
                          <div class="list-inline-muted">
                            <span><?= h(customer_service_type_label((string)($customer['service_type'] ?? 'single'))) ?></span>
                            <?php if ((string)($customer['next_service_date'] ?? '') !== ''): ?><span>Nästa underhåll <?= h(format_date((string)$customer['next_service_date'])) ?></span><?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                      <?php if ($view === 'follow_up' && $customerFollowUpState !== null): ?>
                        <span class="badge <?= $customerFollowUpState === 'overdue' ? 'badge-red' : 'badge-amber' ?>"><?= h($customerFollowUpState === 'overdue' ? 'Förfallen' : 'Inom 30 dagar') ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <p data-live-search-empty<?= $customerListRecords === [] ? '' : ' hidden' ?>><?= $search !== '' ? 'Ingen kund matchade din sökning.' : ($view === 'follow_up' ? 'Inga kunder att följa upp just nu.' : 'Inga kunder i registret ännu.') ?></p>
              </div>
            </article>
            <?php elseif ($view === 'create'): ?>
            <article class="panel" id="new-customer">
              <div class="panel-heading"><h3>Ny kund</h3><p>Privatperson, vanlig moms eller omvänd moms</p></div>
              <form method="post" class="stack-md mobile-form-shell" data-customer-form data-company-lookup-url="company_lookup_api.php">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create_customer" />
                <?php if ($prefillRequestId > 0): ?>
                <input type="hidden" name="requestId" value="<?= (int)$prefillRequestId ?>" />
                <?php endif; ?>
                <input type="hidden" name="name" value="<?= h(field_value($createCustomerValues, 'name')) ?>" data-customer-name-hidden />
                <?php $createCustomerOrganization = trim(field_value($createCustomerValues, 'organizationId')) !== '' ? find_organization_by_id($data, (int)field_value($createCustomerValues, 'organizationId')) : null; ?>
                <label>
                  Kundtyp
                  <select name="customerType" data-customer-type-select class="<?= h(field_class($createCustomerErrors, 'customerType')) ?>">
                    <option value="private"<?= is_selected($createCustomerValues, 'customerType', 'private') ?>>Privatperson</option>
                    <option value="company"<?= is_selected($createCustomerValues, 'customerType', 'company') ?>>Företag</option>
                    <option value="association"<?= is_selected($createCustomerValues, 'customerType', 'association') ?>>Förening / BRF</option>
                  </select>
                  <?= render_field_error($createCustomerErrors, 'customerType') ?>
                </label>
                <?php if ($currentUserOrganizationId !== null && current_user_role() !== USER_ROLE_ADMIN): ?>
                <input type="hidden" name="organizationId" value="<?= h((string)$currentUserOrganizationId) ?>" data-organization-name="<?= h((string)($currentUserOrganization['name'] ?? ($createCustomerOrganization['name'] ?? ''))) ?>" />
                <label>
                  Organisation
                  <input type="text" value="<?= h((string)($currentUserOrganization['name'] ?? ($createCustomerOrganization['name'] ?? ''))) ?>" disabled />
                  <small>kunden sparas i din organisation</small>
                </label>
                <?php else: ?>
                <label>
                  Organisation
                  <select name="organizationId" class="<?= h(field_class($createCustomerErrors, 'organizationId')) ?>">
                    <option value="">Ingen organisation vald</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= field_value($createCustomerValues, 'organizationId') === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?= render_field_error($createCustomerErrors, 'organizationId') ?>
                </label>
                <?php endif; ?>
                <label>
                  Region
                  <select name="regionId" class="<?= h(field_class($createCustomerErrors, 'regionId')) ?>">
                    <option value="">Ingen region vald</option>
                    <?php foreach ($regions as $region): ?>
                      <option value="<?= (int)($region['id'] ?? 0) ?>"<?= field_value($createCustomerValues, 'regionId') === (string)($region['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h((string)($region['name'] ?? '')) ?><?= empty($region['is_active']) ? ' (inaktiv)' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?= render_field_error($createCustomerErrors, 'regionId') ?>
                </label>
                <label>
                  Typ av tjänst
                  <select name="serviceType" class="<?= h(field_class($createCustomerErrors, 'serviceType')) ?>">
                    <option value="single"<?= is_selected($createCustomerValues, 'serviceType', 'single') ?>>Engång</option>
                    <option value="maintenance"<?= is_selected($createCustomerValues, 'serviceType', 'maintenance') ?>>Underhåll</option>
                  </select>
                  <?= render_field_error($createCustomerErrors, 'serviceType') ?>
                </label>
                <div class="dynamic-fields dynamic-fields-visible" data-private-fields>
                  <div class="form-columns form-columns-2">
                    <label>
                      Förnamn
                      <input class="<?= h(field_class($createCustomerErrors, 'name')) ?>" type="text" name="firstName" value="<?= h(field_value($createCustomerValues, 'firstName')) ?>" data-customer-first-name />
                    </label>
                    <label>
                      Efternamn
                      <input class="<?= h(field_class($createCustomerErrors, 'name')) ?>" type="text" name="lastName" value="<?= h(field_value($createCustomerValues, 'lastName')) ?>" data-customer-last-name />
                    </label>
                  </div>
                  <?= render_field_error($createCustomerErrors, 'name') ?>
                </div>
                <div class="dynamic-fields" data-company-fields hidden>
                  <div class="lookup-block" data-company-lookup-scope data-lookup-customer-type="company">
                    <div class="lookup-block-header">
                      <strong>Hämta företagsuppgifter</strong>
                      <p>Hämta uppgifter via organisationsnummer. Namn och adress fylls normalt i automatiskt, medan telefon och e-post fortfarande kan behöva fyllas i manuellt.</p>
                      <?php if (use_mock_company_lookup()): ?><small class="lookup-demo-indicator">Demo-läge aktivt för företagslookup</small><?php endif; ?>
                    </div>
                    <div class="lookup-row">
                      <label>
                        Organisationsnummer
                        <input class="<?= h(field_class($createCustomerErrors, 'organizationNumber')) ?>" type="text" name="organizationNumber" value="<?= h(field_value($createCustomerValues, 'organizationNumber')) ?>" data-business-organization-number />
                        <?= render_field_error($createCustomerErrors, 'organizationNumber') ?>
                      </label>
                      <button class="button button-secondary lookup-button" type="button" data-company-org-lookup-button>Hämta via organisationsnummer</button>
                    </div>
                    <div class="lookup-row">
                      <label>
                        Företagsnamn
                        <input class="<?= h(field_class($createCustomerErrors, 'companyName')) ?>" type="text" name="companyName" value="<?= h(field_value($createCustomerValues, 'companyName')) ?>" data-business-name-input />
                        <?= render_field_error($createCustomerErrors, 'companyName') ?>
                      </label>
                      <button class="button button-secondary lookup-button" type="button" data-company-name-search-button>Sök företag via namn</button>
                    </div>
                    <div class="lookup-status" data-company-lookup-status hidden></div>
                    <div class="lookup-results" data-company-search-results hidden></div>
                  </div>
                  <label>
                    Kontaktperson
                    <input class="<?= h(field_class($createCustomerErrors, 'contactPerson')) ?>" type="text" name="contactPerson" value="<?= h(field_value($createCustomerValues, 'contactPerson')) ?>" />
                    <?= render_field_error($createCustomerErrors, 'contactPerson') ?>
                  </label>
                </div>
                <div class="dynamic-fields" data-association-fields hidden>
                  <div class="lookup-block" data-company-lookup-scope data-lookup-customer-type="association">
                    <div class="lookup-block-header">
                      <strong>Hämta företagsuppgifter</strong>
                      <p>Hämta uppgifter via organisationsnummer. Namn och adress fylls normalt i automatiskt, medan telefon och e-post fortfarande kan behöva fyllas i manuellt.</p>
                      <?php if (use_mock_company_lookup()): ?><small class="lookup-demo-indicator">Demo-läge aktivt för företagslookup</small><?php endif; ?>
                    </div>
                    <div class="lookup-row">
                      <label>
                        Organisationsnummer
                        <input class="<?= h(field_class($createCustomerErrors, 'organizationNumber')) ?>" type="text" name="organizationNumber" value="<?= h(field_value($createCustomerValues, 'organizationNumber')) ?>" data-business-organization-number />
                        <?= render_field_error($createCustomerErrors, 'organizationNumber') ?>
                      </label>
                      <button class="button button-secondary lookup-button" type="button" data-company-org-lookup-button>Hämta via organisationsnummer</button>
                    </div>
                    <div class="lookup-row">
                      <label>
                        Föreningsnamn
                        <input class="<?= h(field_class($createCustomerErrors, 'associationName')) ?>" type="text" name="associationName" value="<?= h(field_value($createCustomerValues, 'associationName')) ?>" data-business-name-input />
                        <?= render_field_error($createCustomerErrors, 'associationName') ?>
                      </label>
                      <button class="button button-secondary lookup-button" type="button" data-company-name-search-button>Sök företag via namn</button>
                    </div>
                    <div class="lookup-status" data-company-lookup-status hidden></div>
                    <div class="lookup-results" data-company-search-results hidden></div>
                  </div>
                  <label>
                    Kontaktperson
                    <input class="<?= h(field_class($createCustomerErrors, 'contactPerson')) ?>" type="text" name="contactPerson" value="<?= h(field_value($createCustomerValues, 'contactPerson')) ?>" />
                    <?= render_field_error($createCustomerErrors, 'contactPerson') ?>
                  </label>
                  <label>
                    Momsregistreringsnummer
                    <input class="<?= h(field_class($createCustomerErrors, 'vatNumber')) ?>" type="text" name="vatNumber" value="<?= h(field_value($createCustomerValues, 'vatNumber')) ?>" />
                    <?= render_field_error($createCustomerErrors, 'vatNumber') ?>
                  </label>
                  <label>
                    Faktureringsläge
                    <select name="billingVatMode" data-billing-vat-mode-select class="<?= h(field_class($createCustomerErrors, 'billingVatMode')) ?>">
                      <option value="standard_vat"<?= is_selected($createCustomerValues, 'billingVatMode', 'standard_vat') ?>>Vanlig moms</option>
                      <option value="reverse_charge"<?= is_selected($createCustomerValues, 'billingVatMode', 'reverse_charge') ?>>Omvänd moms</option>
                    </select>
                    <?= render_field_error($createCustomerErrors, 'billingVatMode') ?>
                  </label>
                </div>
                <label>
                  Telefon
                  <input class="<?= h(field_class($createCustomerErrors, 'phone')) ?>" type="text" name="phone" value="<?= h(field_value($createCustomerValues, 'phone')) ?>" required />
                  <?= render_field_error($createCustomerErrors, 'phone') ?>
                </label>
                <label>
                  E-post
                  <input class="<?= h(field_class($createCustomerErrors, 'email')) ?>" type="email" name="email" value="<?= h(field_value($createCustomerValues, 'email')) ?>" required />
                  <?= render_field_error($createCustomerErrors, 'email') ?>
                </label>
                <label>
                  Adress
                  <input class="<?= h(field_class($createCustomerErrors, 'serviceAddress')) ?>" type="text" name="serviceAddress" value="<?= h(field_value($createCustomerValues, 'serviceAddress')) ?>" required />
                  <?= render_field_error($createCustomerErrors, 'serviceAddress') ?>
                </label>
                <div class="form-columns">
                  <label>
                    Postnummer
                    <input class="<?= h(field_class($createCustomerErrors, 'servicePostalCode')) ?>" type="text" name="servicePostalCode" value="<?= h(field_value($createCustomerValues, 'servicePostalCode')) ?>" required />
                    <?= render_field_error($createCustomerErrors, 'servicePostalCode') ?>
                  </label>
                  <label>
                    Ort
                    <input class="<?= h(field_class($createCustomerErrors, 'serviceCity')) ?>" type="text" name="serviceCity" value="<?= h(field_value($createCustomerValues, 'serviceCity')) ?>" required />
                    <?= render_field_error($createCustomerErrors, 'serviceCity') ?>
                  </label>
                </div>
                <label>
                  Fastighetsbeteckning
                  <input type="text" name="propertyDesignation" value="<?= h(field_value($createCustomerValues, 'propertyDesignation')) ?>" />
                </label>
                <label class="checkbox-line checkbox-card">
                  <input type="checkbox" name="billingSameAsProperty" value="1"<?= field_value($createCustomerValues, 'billingSameAsProperty') === '1' ? ' checked' : '' ?> data-billing-same-as-property />
                  Fakturaadress samma som adress
                </label>
                <div class="dynamic-fields dynamic-fields-visible" data-billing-fields>
                  <label>
                    Fakturaadress
                    <input class="<?= h(field_class($createCustomerErrors, 'billingAddress')) ?>" type="text" name="billingAddress" value="<?= h(field_value($createCustomerValues, 'billingAddress')) ?>" />
                    <?= render_field_error($createCustomerErrors, 'billingAddress') ?>
                  </label>
                  <div class="form-columns">
                    <label>
                      Fakturapostnummer
                      <input class="<?= h(field_class($createCustomerErrors, 'billingPostalCode')) ?>" type="text" name="billingPostalCode" value="<?= h(field_value($createCustomerValues, 'billingPostalCode')) ?>" />
                      <?= render_field_error($createCustomerErrors, 'billingPostalCode') ?>
                    </label>
                    <label>
                      Fakturaort
                      <input class="<?= h(field_class($createCustomerErrors, 'billingCity')) ?>" type="text" name="billingCity" value="<?= h(field_value($createCustomerValues, 'billingCity')) ?>" />
                      <?= render_field_error($createCustomerErrors, 'billingCity') ?>
                    </label>
                  </div>
                </div>
                <div class="dynamic-fields dynamic-fields-visible" data-private-fields>
                  <label>
                    Personnummer
                    <input class="<?= h(field_class($createCustomerErrors, 'personalNumber')) ?>" type="text" name="personalNumber" value="<?= h(field_value($createCustomerValues, 'personalNumber')) ?>" />
                    <?= render_field_error($createCustomerErrors, 'personalNumber') ?>
                  </label>
                  <label>
                    Redan använt rot/rut i år
                    <input class="<?= h(field_class($createCustomerErrors, 'rutUsedAmountThisYear')) ?>" type="number" step="0.01" min="0" name="rutUsedAmountThisYear" value="<?= h(field_value($createCustomerValues, 'rutUsedAmountThisYear')) ?>" />
                    <?= render_field_error($createCustomerErrors, 'rutUsedAmountThisYear') ?>
                  </label>
                  <label>
                    RUT aktuellt
                    <select name="rutEnabled" class="<?= h(field_class($createCustomerErrors, 'rutEnabled')) ?>">
                      <option value="1"<?= is_selected($createCustomerValues, 'rutEnabled', '1') ?>>Ja</option>
                      <option value="0"<?= is_selected($createCustomerValues, 'rutEnabled', '0') ?>>Nej</option>
                    </select>
                    <?= render_field_error($createCustomerErrors, 'rutEnabled') ?>
                  </label>
                </div>
                <div class="dynamic-fields" data-company-fields-extra hidden>
                  <label>
                    Momsregistreringsnummer
                    <input class="<?= h(field_class($createCustomerErrors, 'vatNumber')) ?>" type="text" name="vatNumber" value="<?= h(field_value($createCustomerValues, 'vatNumber')) ?>" />
                    <?= render_field_error($createCustomerErrors, 'vatNumber') ?>
                  </label>
                  <label>
                    Faktureringsläge
                    <select name="billingVatMode" data-billing-vat-mode-select class="<?= h(field_class($createCustomerErrors, 'billingVatMode')) ?>">
                      <option value="standard_vat"<?= is_selected($createCustomerValues, 'billingVatMode', 'standard_vat') ?>>Vanlig moms</option>
                      <option value="reverse_charge"<?= is_selected($createCustomerValues, 'billingVatMode', 'reverse_charge') ?>>Omvänd moms</option>
                    </select>
                    <?= render_field_error($createCustomerErrors, 'billingVatMode') ?>
                  </label>
                </div>
                <label>
                  Anteckningar
                  <textarea name="notes" rows="4"><?= h(field_value($createCustomerValues, 'notes')) ?></textarea>
                </label>
                <div class="mobile-actionbar">
                  <button class="button button-secondary" type="submit" name="nextAction" value="create_quote">Spara och skapa offert</button>
                  <button class="button button-primary" type="submit">Spara kund</button>
                </div>
              </form>
            </article>
            <?php endif; ?>
          </section>
        <?php elseif ($page === 'customer' && $selectedCustomer): ?>
          <?php
          $selectedCustomerServiceAddress = trim((string)($selectedCustomer['service_address'] ?? $selectedCustomer['address'] ?? ''));
          $selectedCustomerServicePostalCode = trim((string)($selectedCustomer['service_postal_code'] ?? $selectedCustomer['postal_code'] ?? ''));
          $selectedCustomerServiceCity = trim((string)($selectedCustomer['service_city'] ?? $selectedCustomer['city'] ?? ''));
          $selectedCustomerBillingAddress = trim((string)($selectedCustomer['billing_address'] ?? $selectedCustomerServiceAddress));
          $selectedCustomerBillingPostalCode = trim((string)($selectedCustomer['billing_postal_code'] ?? $selectedCustomerServicePostalCode));
          $selectedCustomerBillingCity = trim((string)($selectedCustomer['billing_city'] ?? $selectedCustomerServiceCity));
          $selectedCustomerOrganization = ($selectedCustomer['organization_id'] ?? null) !== null ? find_organization_by_id($data, (int)$selectedCustomer['organization_id']) : null;
          $selectedCustomerRegion = ($selectedCustomer['region_id'] ?? null) !== null ? find_region_by_id($data, (int)$selectedCustomer['region_id']) : null;
          $selectedCustomerLatestCompletedJob = null;
          foreach ($customerJobs as $customerJobCandidate) {
              if ((string)($customerJobCandidate['status'] ?? '') !== 'completed') {
                  continue;
              }
              if ($selectedCustomerLatestCompletedJob === null || strcmp((string)($customerJobCandidate['completed_date'] ?? ''), (string)($selectedCustomerLatestCompletedJob['completed_date'] ?? '')) > 0) {
                  $selectedCustomerLatestCompletedJob = $customerJobCandidate;
              }
          }
          ?>
          <section class="admin-grid customer-detail-grid">
            <article class="panel">
              <div class="panel-heading"><h3>Kunduppgifter</h3><p>Senast uppdaterad: <?= h(format_datetime($selectedCustomer['updated_at'])) ?></p></div>
              <div class="stack-sm">
                <?php if ($selectedCustomerOrganization): ?><p><strong>Organisation:</strong> <?= h((string)($selectedCustomerOrganization['name'] ?? '')) ?></p><?php endif; ?>
                <?php if ($selectedCustomerRegion): ?><p><strong>Region:</strong> <?= h((string)($selectedCustomerRegion['name'] ?? '')) ?></p><?php endif; ?>
                <p><strong>Typ av tjänst:</strong> <?= h(customer_service_type_label((string)($selectedCustomer['service_type'] ?? 'single'))) ?></p>
                <p><strong>Senaste utförda jobb:</strong> <?php if ($selectedCustomerLatestCompletedJob): ?><?= h(job_display_number($selectedCustomerLatestCompletedJob)) ?> · <?= h((string)($selectedCustomerLatestCompletedJob['service_type'] ?? 'Jobb')) ?> · <?= h(format_date((string)($selectedCustomerLatestCompletedJob['completed_date'] ?? ''))) ?><?php else: ?>Ej registrerat<?php endif; ?></p>
                <p><strong>Nästa planerade underhåll:</strong> <?= h((string)($selectedCustomer['next_service_date'] ?? '') !== '' ? format_date((string)$selectedCustomer['next_service_date']) : 'Ej planerat') ?></p>
                <p><strong>Kundtyp:</strong> <?= h(customer_type_label($selectedCustomer['customer_type'])) ?></p>
                <p><strong>Fakturering:</strong> <?= h(vat_mode_label($selectedCustomer['billing_vat_mode'])) ?></p>
                <p><strong>RUT används:</strong> <?= h(yes_no_label((bool)$selectedCustomer['rut_enabled'])) ?></p>
                <p><strong>Redan använt rot/rut i år:</strong> <?= h(format_currency((float)($selectedCustomer['rut_used_amount_this_year'] ?? 0))) ?></p>
                <p><strong><?= h(customer_identifier_label($selectedCustomer)) ?>:</strong> <?= h(customer_identifier_value($selectedCustomer)) ?></p>
                <?php if ($selectedCustomer['customer_type'] === 'company'): ?>
                  <p><strong>Företagsnamn:</strong> <?= h($selectedCustomer['company_name']) ?></p>
                  <p><strong>Momsnummer:</strong> <?= h($selectedCustomer['vat_number']) ?></p>
                <?php elseif ($selectedCustomer['customer_type'] === 'association'): ?>
                  <p><strong>Föreningsnamn:</strong> <?= h($selectedCustomer['association_name']) ?></p>
                  <p><strong>Kontaktperson:</strong> <?= h($selectedCustomer['contact_person']) ?></p>
                  <p><strong>Momsnummer:</strong> <?= h($selectedCustomer['vat_number']) ?></p>
                <?php endif; ?>
                <p><strong>Telefon:</strong> <?= h($selectedCustomer['phone']) ?></p>
                <p><strong>E-post:</strong> <?= h($selectedCustomer['email']) ?></p>
                <p><strong>Adress:</strong> <?= render_google_maps_link($selectedCustomerServiceAddress, [$selectedCustomerServiceAddress, $selectedCustomerServicePostalCode, $selectedCustomerServiceCity], 'address-link') ?></p>
                <p><strong>Postnummer:</strong> <?= h($selectedCustomerServicePostalCode) ?></p>
                <p><strong>Ort:</strong> <?= h($selectedCustomerServiceCity) ?></p>
                <p><strong>Fastighetsbeteckning:</strong> <?= h($selectedCustomer['property_designation'] ?? '') ?></p>
                <p><strong>Fakturaadress:</strong> <?= render_google_maps_link($selectedCustomerBillingAddress, [$selectedCustomerBillingAddress, $selectedCustomerBillingPostalCode, $selectedCustomerBillingCity], 'address-link') ?></p>
                <p><strong>Fakturapostnummer:</strong> <?= h($selectedCustomerBillingPostalCode) ?></p>
                <p><strong>Fakturaort:</strong> <?= h($selectedCustomerBillingCity) ?></p>
              </div>
              <div class="panel-subsection">
                <h4>Anteckningar</h4>
                <p><?= nl2br(h($selectedCustomer['notes'])) ?></p>
              </div>
              <div class="header-actions">
                <a class="button button-secondary" href="<?= h(admin_url('customer', ['id' => (int)$selectedCustomer['id'], 'edit' => 1])) ?>#edit-customer">Redigera kund</a>
                <a class="button button-secondary" href="<?= h(admin_url('quotes', ['view' => 'create', 'customer_id' => $selectedCustomer['id']])) ?>">Skapa offert</a>
                <a class="button button-primary" href="<?= h(admin_url('jobs', ['view' => 'create', 'customer_id' => $selectedCustomer['id']])) ?>">Skapa jobb</a>
              </div>
            </article>
            <?php if ($editCustomer): ?>
            <article class="panel" id="edit-customer">
              <div class="panel-heading"><h3>Redigera kund</h3><p>Uppdatera kunduppgifter, fakturaadress och identifieringsfält.</p></div>
              <form method="post" class="stack-md mobile-form-shell" data-customer-form data-company-lookup-url="company_lookup_api.php">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_customer" />
                <input type="hidden" name="customerId" value="<?= (int)$selectedCustomer['id'] ?>" />
                <input type="hidden" name="name" value="<?= h(field_value($editCustomerValues, 'name')) ?>" data-customer-name-hidden />
                <label>
                  Kundtyp
                  <select name="customerType" data-customer-type-select class="<?= h(field_class($editCustomerErrors, 'customerType')) ?>">
                    <option value="private"<?= is_selected($editCustomerValues, 'customerType', 'private') ?>>Privatperson</option>
                    <option value="company"<?= is_selected($editCustomerValues, 'customerType', 'company') ?>>Företag</option>
                    <option value="association"<?= is_selected($editCustomerValues, 'customerType', 'association') ?>>Förening / BRF</option>
                  </select>
                  <?= render_field_error($editCustomerErrors, 'customerType') ?>
                </label>
                <?php if ($currentUserOrganizationId !== null && current_user_role() !== USER_ROLE_ADMIN): ?>
                <input type="hidden" name="organizationId" value="<?= h((string)$currentUserOrganizationId) ?>" />
                <label>
                  Organisation
                  <input type="text" value="<?= h((string)($currentUserOrganization['name'] ?? ($selectedCustomerOrganization['name'] ?? ''))) ?>" disabled />
                </label>
                <?php else: ?>
                <label>
                  Organisation
                  <select name="organizationId" class="<?= h(field_class($editCustomerErrors, 'organizationId')) ?>">
                    <option value="">Ingen organisation vald</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= field_value($editCustomerValues, 'organizationId') === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?= render_field_error($editCustomerErrors, 'organizationId') ?>
                </label>
                <?php endif; ?>
                <label>
                  Region
                  <select name="regionId" class="<?= h(field_class($editCustomerErrors, 'regionId')) ?>">
                    <option value="">Ingen region vald</option>
                    <?php foreach ($regions as $region): ?>
                      <option value="<?= (int)($region['id'] ?? 0) ?>"<?= field_value($editCustomerValues, 'regionId') === (string)($region['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h((string)($region['name'] ?? '')) ?><?= empty($region['is_active']) ? ' (inaktiv)' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?= render_field_error($editCustomerErrors, 'regionId') ?>
                </label>
                <label>
                  Typ av tjänst
                  <select name="serviceType" class="<?= h(field_class($editCustomerErrors, 'serviceType')) ?>">
                    <option value="single"<?= is_selected($editCustomerValues, 'serviceType', 'single') ?>>Engång</option>
                    <option value="maintenance"<?= is_selected($editCustomerValues, 'serviceType', 'maintenance') ?>>Underhåll</option>
                  </select>
                  <?= render_field_error($editCustomerErrors, 'serviceType') ?>
                </label>
                <div class="dynamic-fields dynamic-fields-visible" data-private-fields>
                  <div class="form-columns form-columns-2">
                    <label>
                      Förnamn
                      <input class="<?= h(field_class($editCustomerErrors, 'name')) ?>" type="text" name="firstName" value="<?= h(field_value($editCustomerValues, 'firstName')) ?>" data-customer-first-name />
                    </label>
                    <label>
                      Efternamn
                      <input class="<?= h(field_class($editCustomerErrors, 'name')) ?>" type="text" name="lastName" value="<?= h(field_value($editCustomerValues, 'lastName')) ?>" data-customer-last-name />
                    </label>
                  </div>
                  <?= render_field_error($editCustomerErrors, 'name') ?>
                </div>
                <div class="dynamic-fields" data-company-fields hidden>
                  <div class="form-columns form-columns-2">
                    <label>
                      Företagsnamn
                      <input class="<?= h(field_class($editCustomerErrors, 'name')) ?>" type="text" name="companyName" value="<?= h(field_value($editCustomerValues, 'companyName')) ?>" />
                    </label>
                    <label>
                      Kontaktperson
                      <input type="text" name="contactPerson" value="<?= h(field_value($editCustomerValues, 'contactPerson')) ?>" />
                    </label>
                  </div>
                  <?= render_field_error($editCustomerErrors, 'name') ?>
                </div>
                <div class="dynamic-fields" data-association-fields hidden>
                  <div class="form-columns form-columns-2">
                    <label>
                      Föreningsnamn
                      <input class="<?= h(field_class($editCustomerErrors, 'name')) ?>" type="text" name="associationName" value="<?= h(field_value($editCustomerValues, 'associationName')) ?>" />
                    </label>
                    <label>
                      Kontaktperson
                      <input type="text" name="contactPerson" value="<?= h(field_value($editCustomerValues, 'contactPerson')) ?>" />
                    </label>
                  </div>
                  <?= render_field_error($editCustomerErrors, 'name') ?>
                </div>
                <div class="form-columns form-columns-2">
                  <label>
                    Telefon
                    <input type="text" name="phone" value="<?= h(field_value($editCustomerValues, 'phone')) ?>" />
                  </label>
                  <label>
                    E-post
                    <input type="email" name="email" value="<?= h(field_value($editCustomerValues, 'email')) ?>" />
                  </label>
                </div>
                <div class="form-columns form-columns-2">
                  <label>
                    Adress
                    <input class="<?= h(field_class($editCustomerErrors, 'serviceAddress')) ?>" type="text" name="serviceAddress" value="<?= h(field_value($editCustomerValues, 'serviceAddress')) ?>" />
                    <?= render_field_error($editCustomerErrors, 'serviceAddress') ?>
                  </label>
                  <label>
                    Fastighetsbeteckning
                    <input type="text" name="propertyDesignation" value="<?= h(field_value($editCustomerValues, 'propertyDesignation')) ?>" />
                  </label>
                </div>
                <div class="form-columns form-columns-2">
                  <label>
                    Postnummer
                    <input class="<?= h(field_class($editCustomerErrors, 'servicePostalCode')) ?>" type="text" name="servicePostalCode" value="<?= h(field_value($editCustomerValues, 'servicePostalCode')) ?>" />
                    <?= render_field_error($editCustomerErrors, 'servicePostalCode') ?>
                  </label>
                  <label>
                    Ort
                    <input class="<?= h(field_class($editCustomerErrors, 'serviceCity')) ?>" type="text" name="serviceCity" value="<?= h(field_value($editCustomerValues, 'serviceCity')) ?>" />
                    <?= render_field_error($editCustomerErrors, 'serviceCity') ?>
                  </label>
                </div>
                <label class="checkbox-line"><input type="checkbox" name="billingSameAsProperty" value="1"<?= is_checked($editCustomerValues, 'billingSameAsProperty') ?> /> Fakturaadress samma som arbetsplats</label>
                <div class="form-columns form-columns-2">
                  <label>
                    Fakturaadress
                    <input class="<?= h(field_class($editCustomerErrors, 'billingAddress')) ?>" type="text" name="billingAddress" value="<?= h(field_value($editCustomerValues, 'billingAddress')) ?>" />
                    <?= render_field_error($editCustomerErrors, 'billingAddress') ?>
                  </label>
                  <label>
                    Fakturapostnummer
                    <input class="<?= h(field_class($editCustomerErrors, 'billingPostalCode')) ?>" type="text" name="billingPostalCode" value="<?= h(field_value($editCustomerValues, 'billingPostalCode')) ?>" />
                    <?= render_field_error($editCustomerErrors, 'billingPostalCode') ?>
                  </label>
                </div>
                <label>
                  Fakturaort
                  <input class="<?= h(field_class($editCustomerErrors, 'billingCity')) ?>" type="text" name="billingCity" value="<?= h(field_value($editCustomerValues, 'billingCity')) ?>" />
                  <?= render_field_error($editCustomerErrors, 'billingCity') ?>
                </label>
                <div class="dynamic-fields dynamic-fields-visible" data-private-fields>
                  <div class="form-columns form-columns-2">
                    <label>
                      Personnummer
                      <input class="<?= h(field_class($editCustomerErrors, 'personalNumber')) ?>" type="text" name="personalNumber" value="<?= h(field_value($editCustomerValues, 'personalNumber')) ?>" />
                      <?= render_field_error($editCustomerErrors, 'personalNumber') ?>
                    </label>
                    <label>
                      Redan använt rot/rut i år
                      <input class="<?= h(field_class($editCustomerErrors, 'rutUsedAmountThisYear')) ?>" type="number" step="0.01" min="0" name="rutUsedAmountThisYear" value="<?= h(field_value($editCustomerValues, 'rutUsedAmountThisYear')) ?>" />
                      <?= render_field_error($editCustomerErrors, 'rutUsedAmountThisYear') ?>
                    </label>
                  </div>
                  <label>
                    RUT aktuellt
                    <select name="rutEnabled" class="<?= h(field_class($editCustomerErrors, 'rutEnabled')) ?>">
                      <option value="1"<?= is_selected($editCustomerValues, 'rutEnabled', '1') ?>>Ja</option>
                      <option value="0"<?= is_selected($editCustomerValues, 'rutEnabled', '0') ?>>Nej</option>
                    </select>
                    <?= render_field_error($editCustomerErrors, 'rutEnabled') ?>
                  </label>
                </div>
                <div class="dynamic-fields" data-company-fields-extra hidden>
                  <div class="form-columns form-columns-2">
                    <label>
                      Organisationsnummer
                      <input class="<?= h(field_class($editCustomerErrors, 'organizationNumber')) ?>" type="text" name="organizationNumber" value="<?= h(field_value($editCustomerValues, 'organizationNumber')) ?>" />
                      <?= render_field_error($editCustomerErrors, 'organizationNumber') ?>
                    </label>
                    <label>
                      Momsregistreringsnummer
                      <input class="<?= h(field_class($editCustomerErrors, 'vatNumber')) ?>" type="text" name="vatNumber" value="<?= h(field_value($editCustomerValues, 'vatNumber')) ?>" />
                      <?= render_field_error($editCustomerErrors, 'vatNumber') ?>
                    </label>
                  </div>
                  <label>
                    Faktureringsläge
                    <select name="billingVatMode" data-billing-vat-mode-select class="<?= h(field_class($editCustomerErrors, 'billingVatMode')) ?>">
                      <option value="standard_vat"<?= is_selected($editCustomerValues, 'billingVatMode', 'standard_vat') ?>>Vanlig moms</option>
                      <option value="reverse_charge"<?= is_selected($editCustomerValues, 'billingVatMode', 'reverse_charge') ?>>Omvänd moms</option>
                    </select>
                    <?= render_field_error($editCustomerErrors, 'billingVatMode') ?>
                  </label>
                </div>
                <label>
                  Anteckningar
                  <textarea name="notes" rows="4"><?= h(field_value($editCustomerValues, 'notes')) ?></textarea>
                </label>
                <div class="form-actions">
                  <button class="button button-primary" type="submit">Spara kund</button>
                  <a class="button button-secondary" href="<?= h(admin_url('customer', ['id' => (int)$selectedCustomer['id']])) ?>">Avbryt</a>
                </div>
              </form>
            </article>
            <?php endif; ?>
            <article class="panel">
              <div class="panel-heading"><h3>Tidigare offerter</h3><p><?= count($customerQuotes) ?> st</p></div>
              <div class="stack-md">
                <?php foreach ($customerQuotes as $quote): ?>
                  <div class="list-card">
                    <div class="list-row">
                      <div>
                        <strong><?= h($quote['quote_number'] ?: ('Offert #' . (int)$quote['id'])) ?></strong>
                        <p><?= h($quote['service_type']) ?></p>
                      </div>
                      <span class="badge <?= h(badge_class($quote['status'])) ?>"><?= h(quote_status_label((string)$quote['status'])) ?></span>
                    </div>
                    <small>
                      Exkl moms <?= h(format_currency((float)$quote['total_amount_ex_vat'])) ?> ·
                      Moms <?= h(format_currency((float)$quote['vat_amount'])) ?> ·
                      Inkl moms <?= h(format_currency((float)$quote['total_amount_inc_vat'])) ?> ·
                      RUT <?= h(format_currency((float)$quote['rut_amount'])) ?> ·
                      Att betala <?= h(format_currency((float)$quote['amount_after_rut'])) ?>
                    </small>
                    <?php if ($quote['reverse_charge_text'] !== ''): ?><small><?= h($quote['reverse_charge_text']) ?></small><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </article>
            <article class="panel">
              <div class="panel-heading"><h3>Tidigare jobb</h3><p><?= count($customerJobs) ?> st</p></div>
              <div class="stack-md">
                <?php foreach ($customerJobs as $job): ?>
                  <?php $customerJobQuote = $job['quote_id'] ? find_by_id($quotes, (int)$job['quote_id']) : null; ?>
                  <?php $invoiceBasis = try_invoice_basis_for_job($invoiceBasesByJobId, $job, $selectedCustomer, $customerJobQuote); ?>
                  <?php $invoiceBasisError = invoice_basis_error_for_job($invoiceBasesByJobId, $job, $selectedCustomer, $customerJobQuote); ?>
                  <div class="list-card">
                    <div class="list-row">
                      <strong><?= h($job['service_type']) ?></strong>
                      <span class="badge <?= h(badge_class($job['status'])) ?>"><?= h(status_label($job['status'])) ?></span>
                    </div>
                    <?php if ($invoiceBasis !== null): ?>
                      <small>
                        Exkl moms <?= h(format_currency((float)$invoiceBasis['totalAmountExVat'])) ?> ·
                        Moms <?= h(format_currency((float)$invoiceBasis['vatAmount'])) ?> ·
                        Inkl moms <?= h(format_currency((float)$invoiceBasis['totalAmountIncVat'])) ?> ·
                        RUT <?= h(format_currency((float)$invoiceBasis['rutAmount'])) ?>
                      </small>
                      <?php if ($invoiceBasis['reverseChargeText'] !== ''): ?><small><?= h($invoiceBasis['reverseChargeText']) ?></small><?php endif; ?>
                    <?php elseif ($invoiceBasisError !== ''): ?>
                      <div class="flash flash-error"><?= h($invoiceBasisError) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </article>
          </section>
        <?php elseif ($page === 'quotes'): ?>
          <section class="workspace-stack">
            <?php if (in_array($view, ['all', 'sent', 'approved', 'rejected', 'expired', 'expiring', 'archived'], true)): ?>
            <article class="panel">
              <div class="panel-heading"><h3><?= h($headerTitle) ?></h3><p>Snabb överblick över offertnummer, kund och status.</p></div>
              <form method="get" class="inline-search inline-search-left" data-live-search-form data-auto-submit-form>
                <input type="hidden" name="page" value="quotes" />
                <input type="hidden" name="view" value="<?= h($view) ?>" />
                <input type="search" name="quote_q" value="<?= h($quoteSearch) ?>" placeholder="Sök offertnummer, kund eller tjänst" data-live-search-input />
                <?php if ($isAdminUser): ?>
                  <select name="quote_organization">
                    <option value="">Alla organisationer</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= $quoteOrganizationFilter === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <button class="button button-secondary" type="submit">Sök</button>
                <a class="button button-secondary button-compact button-tinted" href="<?= h(admin_url('quotes', ['view' => 'create'])) ?>#new-quote">Skapa offert</a>
                <?php if ($quoteSearch !== '' || ($isAdminUser && $quoteOrganizationFilter !== '')): ?>
                  <a class="button button-secondary" href="<?= h(admin_url('quotes', ['view' => $view])) ?>">Rensa</a>
                <?php endif; ?>
              </form>
              <div class="stack-md" data-live-search-list>
                <?php foreach ($filteredQuotesByView as $quote): ?>
                  <?php $customer = find_by_id($customers, (int)$quote['customer_id']); ?>
                  <?php $quoteLatestJob = latest_job_for_quote($jobs, (int)($quote['id'] ?? 0)); ?>
                  <?php $quoteHasCancelledJob = (string)($quoteLatestJob['status'] ?? '') === 'cancelled'; ?>
                  <?php $quoteCancelledJobIsBillable = $quoteHasCancelledJob && !empty($quoteLatestJob['ready_for_invoicing']); ?>
                  <?php
                  $quoteCustomerStreet = trim((string)($customer['service_address'] ?? $customer['address'] ?? ''));
                  $quoteCustomerPostalCode = trim((string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? ''));
                  $quoteCustomerCity = trim((string)($customer['service_city'] ?? $customer['city'] ?? ''));
                  $quoteCustomerAddress = trim(implode(', ', array_filter([
                      $quoteCustomerStreet,
                      trim(implode(' ', array_filter([$quoteCustomerPostalCode, $quoteCustomerCity], static fn(string $value): bool => $value !== ''))),
                  ], static fn(string $value): bool => $value !== '')));
                  $quoteCustomerAddressLink = $quoteCustomerAddress !== ''
                      ? render_google_maps_link(
                          $quoteCustomerAddress,
                          [$quoteCustomerStreet, $quoteCustomerPostalCode, $quoteCustomerCity],
                          'address-link'
                      )
                      : '';
                  $quoteDaysRemaining = quote_days_until_valid_until($quote, $today);
                  ?>
                  <?php $quoteSearchText = trim(implode(' ', array_filter([
                      (string)($quote['quote_number'] ?: ('Offert #' . (int)$quote['id'])),
                      customer_name($data, (int)$quote['customer_id']),
                      $quoteCustomerAddress,
                      (string)($quote['service_type'] ?? ''),
                      (string)($quote['description'] ?? ''),
                      customer_type_label((string)($customer['customer_type'] ?? 'private')),
                      quote_status_label((string)($quote['status'] ?? 'draft')),
                  ], static fn(string $value): bool => $value !== ''))); ?>
                  <div class="list-card list-card-compact" id="quote-<?= (int)$quote['id'] ?>" data-live-search-item data-search-text="<?= h($quoteSearchText) ?>">
                    <div class="list-row">
                      <div class="list-row-stack">
                        <a class="list-row-main-link list-row-main-link-inline" href="<?= h(admin_url('quotes', ['edit_id' => $quote['id']])) ?>#edit-quote">
                          <strong class="job-number-label"><?= h($quote['quote_number'] ?: ('Offert #' . (int)$quote['id'])) ?></strong>
                          <span class="list-inline-muted list-inline-truncate">
                            <?= h(customer_name($data, (int)$quote['customer_id'])) ?>
                          </span>
                        </a>
                        <?php if ($quoteCustomerAddressLink !== ''): ?>
                          <div class="list-inline-muted"><?= $quoteCustomerAddressLink ?></div>
                        <?php endif; ?>
                      </div>
                      <div class="badge-row">
                        <?php if ($quoteHasCancelledJob): ?>
                          <span class="badge <?= $quoteCancelledJobIsBillable ? 'badge-amber' : 'badge-red' ?>">
                            <?= $quoteCancelledJobIsBillable ? 'Avbrutet jobb debiteras' : 'Avbrutet jobb' ?>
                          </span>
                        <?php endif; ?>
                        <span class="badge <?= quote_is_expired($quote, $today) ? 'badge-red' : (quote_is_expiring_soon($quote, $today, $soonThreshold) ? 'badge-amber' : 'badge-green') ?>">
                        <?php if ($view === 'expiring' && $quoteDaysRemaining !== null): ?>
                          <?= h($quoteDaysRemaining === 0 ? 'Går ut idag' : ($quoteDaysRemaining . ' dagar kvar')) ?>
                        <?php else: ?>
                          Giltig till <?= h(format_date((string)($quote['valid_until'] ?? ''))) ?>
                        <?php endif; ?>
                        </span>
                      </div>
                      <form method="post" data-approve-quote-form>
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="create_job_from_quote" />
                        <input type="hidden" name="quoteId" value="<?= (int)$quote['id'] ?>" />
                        <button class="button button-secondary button-compact" type="submit" data-approve-quote-button>Skapa jobb</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
                <p data-live-search-empty<?= $filteredQuotesByView === [] ? '' : ' hidden' ?>><?= $quoteSearch !== '' ? 'Ingen offert matchade din sökning.' : 'Inga offerter i den här vyn.' ?></p>
              </div>
            </article>
            <?php elseif (in_array($view, ['create', 'edit'], true)): ?>
            <article class="panel" id="<?= $selectedQuote ? 'edit-quote' : 'new-quote' ?>">
              <?php
              $quoteFormValues = $selectedQuote ? $editQuoteValues : $createQuoteValues;
              $quoteFormErrors = $selectedQuote ? $editQuoteErrors : $createQuoteErrors;
              $quoteFormAction = $selectedQuote ? 'update_quote' : 'create_quote';
              $quoteIsEditable = $selectedQuote === null
                  || (string)($_GET['edit'] ?? '') === '1'
                  || $editQuoteState !== null;
              $quoteFormTitle = $selectedQuote ? ($quoteIsEditable ? 'Redigera offert' : 'Visa offert') : 'Skapa offert';
              $quoteFormButton = $selectedQuote ? 'Spara offert' : 'Skapa offert';
              $selectedQuoteLatestJob = $selectedQuote ? latest_job_for_quote($jobs, (int)($selectedQuote['id'] ?? 0)) : null;
              $selectedQuoteCancelledJobIsBillable = (string)($selectedQuoteLatestJob['status'] ?? '') === 'cancelled' && !empty($selectedQuoteLatestJob['ready_for_invoicing']);
              ?>
              <div class="panel-heading"><h3><?= h($quoteFormTitle) ?></h3></div>
              <?php if ($selectedQuote): ?>
                <div class="info-banner">
                  <strong><?= h($selectedQuote['quote_number'] ?: ('Offert #' . (int)$selectedQuote['id'])) ?></strong>
                  <span class="badge <?= h(badge_class($selectedQuote['status'])) ?>"><?= h(quote_status_label((string)$selectedQuote['status'])) ?></span>
                  <small>Skapad <?= h(format_datetime($selectedQuote['created_at'])) ?> · Giltig till <?= h(format_date($selectedQuote['valid_until'])) ?></small>
                </div>
                <?php if ((string)($selectedQuoteLatestJob['status'] ?? '') === 'cancelled'): ?>
                  <div class="info-banner">
                    <strong><?= h($selectedQuoteCancelledJobIsBillable ? 'Kopplat jobb avbrutet och debiteras' : 'Kopplat jobb avbrutet') ?></strong>
                    <small>
                      <?= h(job_display_number($selectedQuoteLatestJob)) ?>
                      <?php if (trim((string)($selectedQuoteLatestJob['updated_at'] ?? '')) !== ''): ?> · uppdaterat <?= h(format_datetime((string)$selectedQuoteLatestJob['updated_at'])) ?><?php endif; ?>
                    </small>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($selectedQuote && !$quoteIsEditable): ?>
                <?php $quoteDocumentIsPrivate = (($selectedQuoteCustomer['customer_type'] ?? 'private') === 'private'); ?>
                <section class="quote-document-preview">
                  <div class="quote-document-sheet">
                    <div class="quote-document-header">
                      <div class="quote-document-brand">
                        <img src="assets/nyskick-logo.jpeg" alt="Nyskick Sten & Altan" class="quote-document-logo" />
                        <h4>Offert</h4>
                        <div class="quote-document-company">
                          <strong>Nyskick Sten & Altan</strong>
                          <p>Myrgattu 7</p>
                          <p>785 63 Sifferbo</p>
                        </div>
                      </div>
                      <div class="quote-document-meta">
                        <div><span>Offertnummer</span><strong><?= h($selectedQuote['quote_number'] ?: ('#' . (int)$selectedQuote['id'])) ?></strong></div>
                        <div><span>Offertdatum</span><strong><?= h(format_date((string)($selectedQuote['issue_date'] ?? ''))) ?></strong></div>
                        <div><span>Giltig till</span><strong><?= h(format_date((string)($selectedQuote['valid_until'] ?? ''))) ?></strong></div>
                        <div><span>Status</span><strong><?= h(quote_status_label((string)($selectedQuote['status'] ?? 'draft'))) ?></strong></div>
                      </div>
                    </div>

                    <div class="quote-document-grid">
                      <div class="quote-document-card">
                        <span>Kund</span>
                        <strong><?= h($selectedQuoteCustomer ? customer_name($data, (int)$selectedQuoteCustomer['id']) : 'Ej vald kund') ?></strong>
                        <?php if ($selectedQuoteCustomer): ?>
                          <?php if (($selectedQuoteCustomer['customer_type'] ?? 'private') === 'private'): ?>
                            <p><?= h((string)($selectedQuoteCustomer['phone'] ?? '')) ?></p>
                            <p><?= h((string)($selectedQuoteCustomer['email'] ?? '')) ?></p>
                          <?php else: ?>
                            <p><?= h((string)($selectedQuoteCustomer['contact_person'] ?? '')) ?></p>
                            <p><?= h((string)($selectedQuoteCustomer['phone'] ?? '')) ?></p>
                            <p><?= h((string)($selectedQuoteCustomer['email'] ?? '')) ?></p>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                      <div class="quote-document-card">
                        <span>Arbetsplats</span>
                        <strong><?= h((string)($selectedQuoteCustomer['service_address'] ?? $selectedQuoteCustomer['address'] ?? '')) ?></strong>
                        <p><?= h(trim(((string)($selectedQuoteCustomer['service_postal_code'] ?? $selectedQuoteCustomer['postal_code'] ?? '')) . ' ' . ((string)($selectedQuoteCustomer['service_city'] ?? $selectedQuoteCustomer['city'] ?? '')))) ?></p>
                        <p><?= h((string)($selectedQuoteCustomer['property_designation'] ?? '')) ?></p>
                      </div>
                    </div>

                    <div class="quote-document-intro">
                      <strong><?= h((string)($selectedQuote['service_type'] ?? '')) ?></strong>
                      <p><?= nl2br(h(compact_quote_document_description(
                          (string)($selectedQuote['service_type'] ?? ''),
                          (string)($selectedQuote['description'] ?? ''),
                          $selectedQuoteItems !== []
                      ))) ?></p>
                    </div>

                    <div class="quote-document-rows">
                      <?php if ($quoteDocumentIsPrivate): ?>
                        <div class="quote-document-rows-head quote-document-rows-head-private">
                          <span>Paket</span>
                          <span>Omfattning</span>
                          <span>Pris</span>
                        </div>
                        <div class="quote-document-row quote-document-row-private">
                          <div>
                            <strong><?= h((string)($selectedQuote['service_type'] ?? 'Offert')) ?></strong>
                            <small>Paketpris inklusive arbete, material och komplett behandling - inga dolda avgifter.</small>
                          </div>
                          <div>
                            <div class="quote-document-package-details">
                              <?php if ($selectedQuoteItems !== []): ?>
                                <ul class="quote-document-package-list">
                                  <?php foreach (compact_quote_package_descriptions($selectedQuoteItems) as $itemDescription): ?>
                                    <li><?= h($itemDescription) ?></li>
                                  <?php endforeach; ?>
                                </ul>
                              <?php endif; ?>
                            </div>
                          </div>
                          <strong><?= h(format_currency((float)($selectedQuote['total_amount_inc_vat'] ?? 0))) ?></strong>
                        </div>
                      <?php else: ?>
                        <div class="quote-document-rows-head quote-document-rows-head-private">
                          <span>Beskrivning</span>
                          <span>Antal</span>
                          <span>Á-pris</span>
                          <span>Belopp</span>
                        </div>
                        <?php foreach ($selectedQuoteItems as $item): ?>
                          <?php
                          $lineAmount = (float)($item['line_total'] ?? ((float)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? 0)));
                          $lineMeta = trim((string)($item['quantity'] ?? 0) . ' ' . (string)($item['unit'] ?? 'st'));
                          ?>
                          <div class="quote-document-row">
                            <div>
                              <strong><?= h((string)($item['description'] ?? '')) ?></strong>
                              <small><?= h(match ((string)($item['item_type'] ?? 'service')) {
                                  'labor' => 'Arbetskostnad',
                                  'material' => 'Materialkostnad',
                                  'discount' => 'Rabatt',
                                  default => 'Övrig kostnad',
                              }) ?></small>
                            </div>
                            <span><?= h($lineMeta) ?></span>
                            <span><?= h(format_currency((float)($item['unit_price'] ?? 0))) ?></span>
                            <strong><?= h(format_currency($lineAmount)) ?></strong>
                          </div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>

                    <div class="quote-document-summary">
                      <?php if (!$quoteDocumentIsPrivate): ?>
                        <div><span>Arbete exkl moms</span><strong><?= h(format_currency((float)($selectedQuote['labor_amount_ex_vat'] ?? 0))) ?></strong></div>
                        <div><span>Material exkl moms</span><strong><?= h(format_currency((float)($selectedQuote['material_amount_ex_vat'] ?? 0))) ?></strong></div>
                        <div><span>Övrigt exkl moms</span><strong><?= h(format_currency((float)($selectedQuote['other_amount_ex_vat'] ?? 0))) ?></strong></div>
                        <div><span>Total exkl moms</span><strong><?= h(format_currency((float)($selectedQuote['total_amount_ex_vat'] ?? 0))) ?></strong></div>
                      <?php endif; ?>
                      <div><span>Moms</span><strong><?= h(format_currency((float)($selectedQuote['vat_amount'] ?? 0))) ?></strong></div>
                      <div><span>Total inkl moms</span><strong><?= h(format_currency((float)($selectedQuote['total_amount_inc_vat'] ?? 0))) ?></strong></div>
                      <div><span>RUT-avdrag</span><strong><?= h(format_currency((float)($selectedQuote['rut_amount'] ?? 0))) ?></strong></div>
                      <div><span>Att betala</span><strong><?= h(format_currency((float)($selectedQuote['amount_after_rut'] ?? 0))) ?></strong></div>
                    </div>

                    <div class="quote-document-footer">
                      <?php if ((string)($selectedQuote['reverse_charge_text'] ?? '') !== ''): ?>
                        <p><?= h((string)$selectedQuote['reverse_charge_text']) ?></p>
                      <?php endif; ?>
                      <p>Offerten är giltig till och med <?= h(format_date((string)($selectedQuote['valid_until'] ?? ''))) ?>.</p>
                      <?php if ($selectedQuoteSignature): ?>
                        <div class="quote-signature-display">
                          <span>Signerad på plats</span>
                          <strong><?= h((string)($selectedQuoteSignature['signed_by_name'] ?? '')) ?></strong>
                          <p><?= h(format_datetime((string)($selectedQuoteSignature['signed_at'] ?? ''))) ?></p>
                          <img src="<?= h((string)($selectedQuoteSignature['signature_path'] ?? '')) ?>" alt="Kundsignatur" class="quote-signature-image" />
                        </div>
                      <?php else: ?>
                        <div class="quote-signature-panel" data-quote-signature-panel>
                          <button class="button button-secondary" type="button" data-open-quote-signature>Signera på plats</button>
                          <div class="quote-signature-form" data-quote-signature-form hidden>
                            <label>
                              Namnförtydligande
                              <input type="text" value="<?= h($selectedQuoteCustomer ? customer_name($data, (int)$selectedQuoteCustomer['id']) : '') ?>" data-signature-name />
                            </label>
                            <div class="quote-signature-canvas-wrap">
                              <canvas class="quote-signature-canvas" width="640" height="220" data-signature-canvas></canvas>
                            </div>
                            <div class="mobile-inline-actions">
                              <button class="button button-secondary" type="button" data-clear-quote-signature>Rensa</button>
                              <button class="button button-primary" type="button" data-save-quote-signature data-quote-id="<?= (int)$selectedQuote['id'] ?>">Bekräfta signatur</button>
                            </div>
                          </div>
                        </div>
                      <?php endif; ?>
                      <div class="quote-document-approval">
                        <div class="quote-document-signature-line">
                          <span>Datum</span>
                        </div>
                        <div class="quote-document-signature-line">
                          <span>Kundens godkännande / signatur</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </section>
              <?php endif; ?>
              <?php if (!$selectedQuote || $quoteIsEditable): ?>
              <?php
                $editableQuoteItems = [];
                $editableQuoteItemsJson = (string)field_value($quoteFormValues, 'quoteItemsJson');
                if ($editableQuoteItemsJson !== '') {
                    $decodedQuoteItems = json_decode($editableQuoteItemsJson, true);
                    if (is_array($decodedQuoteItems)) {
                        $editableQuoteItems = array_values(array_filter(
                            $decodedQuoteItems,
                            static fn($item): bool => is_array($item)
                        ));
                    }
                }
                if ($editableQuoteItems === []) {
                    $editableQuoteItems = [[
                        'item_type' => 'labor',
                        'description' => '',
                        'quantity' => 1,
                        'unit' => 'st',
                        'unit_price' => 0,
                        'vat_rate' => 25,
                        'is_rut_eligible' => true,
                    ]];
                }
                $editableQuoteCustomerType = (string)field_value($quoteFormValues, 'customerType', 'private');
                $editableQuoteName = trim(
                    $editableQuoteCustomerType === 'private'
                        ? trim(field_value($quoteFormValues, 'firstName') . ' ' . field_value($quoteFormValues, 'lastName'))
                        : field_value($quoteFormValues, $editableQuoteCustomerType === 'association' ? 'associationName' : 'companyName')
                );
                $editableQuoteName = $editableQuoteName !== '' ? $editableQuoteName : field_value($quoteFormValues, 'name');
                $editableServiceAddress = trim(
                    field_value($quoteFormValues, 'serviceAddress') . ', ' .
                    trim(field_value($quoteFormValues, 'servicePostalCode') . ' ' . field_value($quoteFormValues, 'serviceCity'))
                , ', ');
                $editableLaborAmount = (float)field_value($quoteFormValues, 'laborAmountExVat', '0');
                $editableMaterialAmount = (float)field_value($quoteFormValues, 'materialAmountExVat', '0');
                $editableOtherAmount = (float)field_value($quoteFormValues, 'otherAmountExVat', '0');
                $editableTotalExVat = calculateTotalAmount($editableLaborAmount, $editableMaterialAmount, $editableOtherAmount);
                $editableVatAmount = 0.0;
                $editableRutBasis = 0.0;
                foreach ($editableQuoteItems as $editableItem) {
                    $lineExVat = (float)($editableItem['quantity'] ?? 0) * (float)($editableItem['unit_price'] ?? 0);
                    if (in_array($editableQuoteCustomerType, ['company', 'association'], true)
                        && (string)field_value($quoteFormValues, 'billingVatMode', 'standard_vat') === 'reverse_charge') {
                        $editableVatRate = 0.0;
                    } else {
                        $editableVatRate = (float)($editableItem['vat_rate'] ?? 0.25);
                        if ($editableVatRate > 1) {
                            $editableVatRate = round($editableVatRate / 100, 4);
                        }
                    }
                    $editableVatAmount += calculateVatAmount($lineExVat, $editableVatRate);
                    if (!empty($editableItem['is_rut_eligible'])) {
                        $editableRutBasis += max(0, $lineExVat);
                    }
                }
                $editableVatAmount = round($editableVatAmount, 2);
                $editableTotalIncVat = calculateTotalAmountIncVat($editableTotalExVat, $editableVatAmount);
                $editableRutAmount = calculateRutAmountWithUsedAmount(
                    $editableRutBasis,
                    field_value($quoteFormValues, 'rutEnabled') === '1',
                    $editableQuoteCustomerType,
                    (float)field_value($quoteFormValues, 'rutUsedAmountThisYear', '0')
                );
                $editableAmountAfterRut = calculateAmountAfterRut($editableTotalIncVat, $editableRutAmount);
                $editablePrivatePackageItems = array_values(array_filter(
                    $editableQuoteItems,
                    static fn(array $item): bool => trim((string)($item['description'] ?? '')) !== ''
                ));
              ?>
              <form method="post" class="stack-md mobile-form-shell" data-quote-form data-company-lookup-url="company_lookup_api.php">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="<?= h($quoteFormAction) ?>" />
                <input type="hidden" name="name" value="<?= h(field_value($quoteFormValues, 'name')) ?>" data-customer-name-hidden />
                <?php if ($selectedQuote): ?>
                  <input type="hidden" name="quoteId" value="<?= (int)$selectedQuote['id'] ?>" />
                <?php endif; ?>
                <?php if ($selectedQuote): ?>
                <section class="quote-document-preview quote-document-preview-edit" data-live-quote-document>
                  <div class="quote-document-sheet">
                    <div class="quote-document-header">
                      <div class="quote-document-brand">
                        <img src="assets/nyskick-logo.jpeg" alt="Nyskick Sten & Altan" class="quote-document-logo" />
                        <h4>Offert</h4>
                        <div class="quote-document-company">
                          <p>Myrgattu 7</p>
                          <p>785 63 Sifferbo</p>
                        </div>
                      </div>
                      <div class="quote-document-meta" data-live-quote-meta>
                        <div><span>Offertnummer</span><strong data-live-quote-number><?= h(field_value($quoteFormValues, 'quoteNumber') !== '' ? field_value($quoteFormValues, 'quoteNumber') : 'Skapas vid sparning') ?></strong></div>
                        <div><span>Offertdatum</span><strong data-live-quote-date><?= h(format_date(field_value($quoteFormValues, 'quoteDate'))) ?></strong></div>
                        <div><span>Giltig till</span><strong data-live-valid-until><?= h(format_date(field_value($quoteFormValues, 'validUntil'))) ?></strong></div>
                        <div><span>Status</span><strong data-live-quote-status><?= h(field_value($quoteFormValues, 'status') !== '' ? ucfirst((string)field_value($quoteFormValues, 'status')) : 'Utkast') ?></strong></div>
                      </div>
                    </div>
                    <div class="quote-document-grid">
                      <div class="quote-document-card">
                        <span>Kund</span>
                        <strong data-live-summary-name><?= h($editableQuoteName !== '' ? $editableQuoteName : 'Ej vald kund') ?></strong>
                        <p data-live-summary-phone><?= h(field_value($quoteFormValues, 'phone') !== '' ? field_value($quoteFormValues, 'phone') : 'Ej angivet') ?></p>
                        <p data-live-summary-email><?= h(field_value($quoteFormValues, 'email') !== '' ? field_value($quoteFormValues, 'email') : 'Ej angivet') ?></p>
                      </div>
                      <div class="quote-document-card">
                        <span>Fastighet / arbetsplats</span>
                        <strong data-live-summary-address><?= h($editableServiceAddress !== '' ? $editableServiceAddress : 'Ej angiven') ?></strong>
                        <p>
                          Fastighetsbeteckning:
                          <strong data-live-summary-designation><?= h(field_value($quoteFormValues, 'propertyDesignation') !== '' ? field_value($quoteFormValues, 'propertyDesignation') : 'Ej angiven') ?></strong>
                        </p>
                      </div>
                    </div>
                    <div class="quote-document-intro">
                      <p><strong data-live-service-type><?= h(field_value($quoteFormValues, 'serviceType') !== '' ? field_value($quoteFormValues, 'serviceType') : 'Tjänst ej vald ännu') ?></strong></p>
                      <p data-live-description><?= nl2br(h(field_value($quoteFormValues, 'description') !== '' ? field_value($quoteFormValues, 'description') : 'Beskrivning fylls på när du väljer tjänst eller bygger rader.')) ?></p>
                    </div>
                    <div class="quote-document-rows" data-live-quote-rows data-customer-type="<?= h($editableQuoteCustomerType) ?>">
                      <?php if ($editableQuoteCustomerType === 'private'): ?>
                        <div class="quote-document-rows-head">
                          <span>Paket</span>
                          <span>Omfattning</span>
                          <span>Pris</span>
                        </div>
                        <div class="quote-document-row quote-document-row-private">
                          <div>
                            <strong><?= h(field_value($quoteFormValues, 'serviceType') !== '' ? field_value($quoteFormValues, 'serviceType') : 'Offert') ?></strong>
                          </div>
                          <div>
                            <div class="quote-document-package-details">
                              <ul class="quote-document-package-list">
                                <?php foreach ($editablePrivatePackageItems as $editableItem): ?>
                                  <li><?= h((string)($editableItem['description'] ?? '')) ?></li>
                                <?php endforeach; ?>
                              </ul>
                            </div>
                          </div>
                          <strong><?= h(format_currency($editableTotalIncVat)) ?></strong>
                        </div>
                      <?php else: ?>
                        <div class="quote-document-rows-head">
                          <span>Beskrivning</span>
                          <span>Antal</span>
                          <span>Á-pris</span>
                          <span>Belopp</span>
                        </div>
                        <?php foreach ($editableQuoteItems as $editableItem): ?>
                          <?php
                            $editableLineAmount = (float)($editableItem['quantity'] ?? 0) * (float)($editableItem['unit_price'] ?? 0);
                          ?>
                          <div class="quote-document-row">
                            <div>
                              <strong><?= h((string)($editableItem['description'] ?? 'Rad utan beskrivning')) ?></strong>
                              <small><?= h(match ((string)($editableItem['item_type'] ?? 'service')) {
                                  'labor' => 'Arbete',
                                  'material' => 'Material',
                                  'discount' => 'Rabatt',
                                  'text' => 'Text',
                                  default => 'Övrigt',
                              }) ?></small>
                            </div>
                            <span><?= h(rtrim(rtrim(number_format((float)($editableItem['quantity'] ?? 0), 2, '.', ''), '0'), '.')) ?> <?= h((string)($editableItem['unit'] ?? 'st')) ?></span>
                            <span><?= h(format_currency((float)($editableItem['unit_price'] ?? 0))) ?></span>
                            <strong><?= h(format_currency($editableLineAmount)) ?></strong>
                          </div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                    <div class="quote-document-summary" data-live-quote-summary data-customer-type="<?= h($editableQuoteCustomerType) ?>">
                      <?php if ($editableQuoteCustomerType !== 'private'): ?>
                        <div><span>Arbete exkl moms</span><strong data-live-labor-amount><?= h(format_currency($editableLaborAmount)) ?></strong></div>
                        <div><span>Material exkl moms</span><strong data-live-material-amount><?= h(format_currency($editableMaterialAmount)) ?></strong></div>
                        <div><span>Övrigt exkl moms</span><strong data-live-other-amount><?= h(format_currency($editableOtherAmount)) ?></strong></div>
                        <div><span>Total exkl moms</span><strong data-live-total-ex-vat><?= h(format_currency($editableTotalExVat)) ?></strong></div>
                      <?php endif; ?>
                      <div><span>Moms</span><strong data-live-vat-amount><?= h(format_currency($editableVatAmount)) ?></strong></div>
                      <div><span>Total inkl moms</span><strong data-live-total-inc-vat><?= h(format_currency($editableTotalIncVat)) ?></strong></div>
                      <div><span>RUT-avdrag</span><strong data-live-rut-amount><?= h(format_currency($editableRutAmount)) ?></strong></div>
                      <div><span>Att betala</span><strong data-live-amount-after-rut><?= h(format_currency($editableAmountAfterRut)) ?></strong></div>
                    </div>
                    <div class="quote-document-footer">
                      <p data-live-valid-footer>Offerten är giltig till och med <?= h(format_date(field_value($quoteFormValues, 'validUntil'))) ?>.</p>
                      <p data-live-reverse-charge-footer hidden></p>
                    </div>
                  </div>
                </section>
                <?php endif; ?>
                <div class="quote-edit-fields">
                <fieldset class="form-readonly-shell"<?= $selectedQuote && !$quoteIsEditable ? ' disabled' : '' ?>>
                <div class="panel-subsection form-section-block">
                  <div data-quote-customer-editor>
                  <label>
                    Kundtyp
                    <select name="customerType" data-quote-customer-type-select class="<?= h(field_class($quoteFormErrors, 'customerType')) ?>">
                      <option value="private"<?= is_selected($quoteFormValues, 'customerType', 'private') ?>>Privatperson</option>
                      <option value="company"<?= is_selected($quoteFormValues, 'customerType', 'company') ?>>Företag</option>
                      <option value="association"<?= is_selected($quoteFormValues, 'customerType', 'association') ?>>Förening / BRF</option>
                    </select>
                    <?= render_field_error($quoteFormErrors, 'customerType') ?>
                  </label>
                  <label>
                    Region
                    <?php $quoteSelectedRegion = trim(field_value($quoteFormValues, 'regionId')) !== '' ? find_region_by_id($data, (int)field_value($quoteFormValues, 'regionId')) : null; ?>
                    <?php if (current_user_role() !== USER_ROLE_ADMIN): ?>
                      <input type="hidden" name="regionId" value="<?= h(field_value($quoteFormValues, 'regionId')) ?>" />
                      <input type="text" value="<?= h((string)($quoteSelectedRegion['name'] ?? ($currentUserRegion['name'] ?? 'Ingen region vald'))) ?>" disabled />
                      <small>regionen sätts automatiskt för din offert</small>
                    <?php else: ?>
                    <select name="regionId" class="<?= h(field_class($quoteFormErrors, 'regionId')) ?>">
                      <option value="">Ingen region vald</option>
                      <?php foreach ($regions as $region): ?>
                        <option value="<?= (int)($region['id'] ?? 0) ?>"<?= field_value($quoteFormValues, 'regionId') === (string)($region['id'] ?? '') ? ' selected' : '' ?>>
                          <?= h((string)($region['name'] ?? '')) ?><?= empty($region['is_active']) ? ' (inaktiv)' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?= render_field_error($quoteFormErrors, 'regionId') ?>
                  </label>
                  <?php $quoteSelectedOrganization = trim(field_value($quoteFormValues, 'organizationId')) !== '' ? find_organization_by_id($data, (int)field_value($quoteFormValues, 'organizationId')) : null; ?>
                  <?php if ($currentUserOrganizationId !== null && current_user_role() !== USER_ROLE_ADMIN): ?>
                  <input type="hidden" name="organizationId" value="<?= h((string)$currentUserOrganizationId) ?>" />
                  <label>
                    Organisation
                    <input type="text" value="<?= h((string)($currentUserOrganization['name'] ?? ($quoteSelectedOrganization['name'] ?? ''))) ?>" disabled />
                    <small>offerten sparas i din organisation</small>
                  </label>
                  <?php else: ?>
                  <label>
                    Organisation
                    <select name="organizationId" class="<?= h(field_class($quoteFormErrors, 'organizationId')) ?>">
                      <option value="">Ingen organisation vald</option>
                      <?php foreach ($activeOrganizations as $organization): ?>
                        <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= field_value($quoteFormValues, 'organizationId') === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                          <?= h(organization_tree_label($organization)) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?= render_field_error($quoteFormErrors, 'organizationId') ?>
                  </label>
                  <?php endif; ?>
                  <div class="lookup-row">
                    <label>
                      Kund
                      <select name="existingCustomerId" data-existing-customer-select>
                        <option value="">Välj befintlig kund</option>
                        <?php foreach ($customers as $customer): ?>
                          <option
                            value="<?= (int)$customer['id'] ?>"
                            data-customer-type="<?= h($customer['customer_type']) ?>"
                            data-billing-vat-mode="<?= h($customer['billing_vat_mode']) ?>"
                            data-rut-enabled="<?= !empty($customer['rut_enabled']) ? '1' : '0' ?>"
                            data-rut-used-amount-this-year="<?= h((string)($customer['rut_used_amount_this_year'] ?? 0)) ?>"
                            data-organization-id="<?= h((string)($customer['organization_id'] ?? '')) ?>"
                            data-region-id="<?= h((string)($customer['region_id'] ?? '')) ?>"
                            data-personal-number="<?= h($customer['personal_number']) ?>"
                            data-first-name="<?= h($customer['first_name'] ?? '') ?>"
                            data-last-name="<?= h($customer['last_name'] ?? '') ?>"
                            data-organization-number="<?= h($customer['organization_number']) ?>"
                            data-name="<?= h($customer['name']) ?>"
                            data-company-name="<?= h($customer['company_name']) ?>"
                            data-association-name="<?= h($customer['association_name'] ?? '') ?>"
                            data-contact-person="<?= h($customer['contact_person'] ?? '') ?>"
                            data-phone="<?= h($customer['phone']) ?>"
                            data-email="<?= h($customer['email']) ?>"
                            data-service-address="<?= h($customer['service_address'] ?? $customer['address']) ?>"
                            data-service-postal-code="<?= h($customer['service_postal_code'] ?? $customer['postal_code']) ?>"
                            data-service-city="<?= h($customer['service_city'] ?? $customer['city']) ?>"
                            data-property-designation="<?= h($customer['property_designation'] ?? '') ?>"
                            data-billing-address="<?= h($customer['billing_address'] ?? $customer['service_address'] ?? $customer['address']) ?>"
                            data-billing-postal-code="<?= h($customer['billing_postal_code'] ?? $customer['service_postal_code'] ?? $customer['postal_code']) ?>"
                            data-billing-city="<?= h($customer['billing_city'] ?? $customer['service_city'] ?? $customer['city']) ?>"
                            data-vat-number="<?= h($customer['vat_number']) ?>"
                            <?= field_value($quoteFormValues, 'existingCustomerId') === (string)$customer['id'] ? ' selected' : '' ?>
                          ><?= h(customer_name($data, (int)$customer['id'])) ?> · <?= h(customer_type_label($customer['customer_type'])) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <a class="button button-secondary lookup-button" href="<?= h(admin_url('customers', ['view' => 'create'])) ?>#new-customer">Ny kund</a>
                  </div>
                  <?php if ($selectedQuote): ?>
                  <div class="locked-customer-banner" data-locked-customer-banner hidden>
                    <div>
                      <strong>Kunduppgifter låsta i offertvyn</strong>
                      <p>Ändra kunduppgifter i kundkortet och gå sedan tillbaka till offerten.</p>
                    </div>
                    <a class="button button-secondary" href="<?= h(admin_url('customers')) ?>" data-edit-customer-link>Redigera kund</a>
                  </div>
                  <?php endif; ?>
                  <?php if ($selectedQuote): ?>
                  <div class="customer-summary-card">
                    <div><span>Kund</span><strong data-quote-summary-name><?= h(field_value($quoteFormValues, 'name') !== '' ? field_value($quoteFormValues, 'name') : field_value($quoteFormValues, 'companyName')) ?></strong></div>
                    <div><span>Organisation</span><strong data-quote-summary-organization><?= h((string)($quoteSelectedOrganization['name'] ?? '')) ?></strong></div>
                    <div><span>Telefon</span><strong data-quote-summary-phone><?= h(field_value($quoteFormValues, 'phone')) ?></strong></div>
                    <div><span>E-post</span><strong data-quote-summary-email><?= h(field_value($quoteFormValues, 'email')) ?></strong></div>
                    <div><span>Fastighet</span><strong data-quote-summary-address><?= h(trim(field_value($quoteFormValues, 'serviceAddress') . ', ' . field_value($quoteFormValues, 'servicePostalCode') . ' ' . field_value($quoteFormValues, 'serviceCity'), ', ')) ?></strong></div>
                    <div><span>Fastighetsbeteckning</span><strong data-quote-summary-designation><?= h(field_value($quoteFormValues, 'propertyDesignation')) ?></strong></div>
                  </div>
                  <?php endif; ?>
                  <div class="dynamic-fields dynamic-fields-visible" data-quote-private-fields>
                    <div class="lookup-row">
                      <label>
                        Personnummer
                        <input class="<?= h(field_class($quoteFormErrors, 'personalNumber')) ?>" type="text" name="personalNumber" value="<?= h(field_value($quoteFormValues, 'personalNumber')) ?>" data-quote-personal-number />
                        <?= render_field_error($quoteFormErrors, 'personalNumber') ?>
                      </label>
                      <button class="button button-secondary lookup-button" type="button" data-private-lookup-button>Hämta uppgifter</button>
                    </div>
                    <label>
                      Redan använt rot/rut i år
                      <input type="number" step="0.01" min="0" name="rutUsedAmountThisYear" value="<?= h(field_value($quoteFormValues, 'rutUsedAmountThisYear')) ?>" data-quote-rut-used />
                    </label>
                    <div class="form-columns form-columns-2">
                      <label>
                        Förnamn
                        <input class="<?= h(field_class($quoteFormErrors, 'name')) ?>" type="text" name="firstName" value="<?= h(field_value($quoteFormValues, 'firstName')) ?>" data-customer-first-name />
                      </label>
                      <label>
                        Efternamn
                        <input class="<?= h(field_class($quoteFormErrors, 'name')) ?>" type="text" name="lastName" value="<?= h(field_value($quoteFormValues, 'lastName')) ?>" data-customer-last-name />
                      </label>
                    </div>
                    <?= render_field_error($quoteFormErrors, 'name') ?>
                    <label>
                      RUT aktuellt
                      <select name="rutEnabled" data-quote-rut-select class="<?= h(field_class($quoteFormErrors, 'rutEnabled')) ?>">
                        <option value="1"<?= is_selected($quoteFormValues, 'rutEnabled', '1') ?>>Ja</option>
                        <option value="0"<?= is_selected($quoteFormValues, 'rutEnabled', '0') ?>>Nej</option>
                      </select>
                      <?= render_field_error($quoteFormErrors, 'rutEnabled') ?>
                    </label>
                  </div>
                  <div class="dynamic-fields" data-quote-company-fields hidden>
                    <div class="lookup-block" data-company-lookup-scope data-lookup-customer-type="company">
                      <div class="lookup-block-header">
                        <strong>Hämta företagsuppgifter</strong>
                        <p>Hämta uppgifter via organisationsnummer. Namn och adress fylls normalt i automatiskt, medan telefon och e-post fortfarande kan behöva fyllas i manuellt.</p>
                        <?php if (use_mock_company_lookup()): ?><small class="lookup-demo-indicator">Demo-läge aktivt för företagslookup</small><?php endif; ?>
                      </div>
                      <div class="lookup-row">
                        <label>
                          Organisationsnummer
                          <input class="<?= h(field_class($quoteFormErrors, 'organizationNumber')) ?>" type="text" name="organizationNumber" value="<?= h(field_value($quoteFormValues, 'organizationNumber')) ?>" data-quote-organization-number data-business-organization-number />
                          <?= render_field_error($quoteFormErrors, 'organizationNumber') ?>
                        </label>
                        <button class="button button-secondary lookup-button" type="button" data-company-org-lookup-button>Hämta via organisationsnummer</button>
                      </div>
                      <div class="lookup-row">
                        <label>
                          Företagsnamn
                          <input class="<?= h(field_class($quoteFormErrors, 'companyName')) ?>" type="text" name="companyName" value="<?= h(field_value($quoteFormValues, 'companyName')) ?>" data-business-name-input />
                          <?= render_field_error($quoteFormErrors, 'companyName') ?>
                        </label>
                        <button class="button button-secondary lookup-button" type="button" data-company-name-search-button>Sök företag via namn</button>
                      </div>
                      <div class="lookup-status" data-company-lookup-status hidden></div>
                      <div class="lookup-results" data-company-search-results hidden></div>
                    </div>
                    <label>
                      Momsmodell
                      <select name="billingVatMode" data-quote-billing-vat-mode-select class="<?= h(field_class($quoteFormErrors, 'billingVatMode')) ?>">
                        <option value="standard_vat"<?= is_selected($quoteFormValues, 'billingVatMode', 'standard_vat') ?>>Vanlig moms</option>
                        <option value="reverse_charge"<?= is_selected($quoteFormValues, 'billingVatMode', 'reverse_charge') ?>>Omvänd moms</option>
                      </select>
                      <?= render_field_error($quoteFormErrors, 'billingVatMode') ?>
                    </label>
                    <label>
                      Momsregistreringsnummer
                      <input class="<?= h(field_class($quoteFormErrors, 'vatNumber')) ?>" type="text" name="vatNumber" value="<?= h(field_value($quoteFormValues, 'vatNumber')) ?>" />
                      <?= render_field_error($quoteFormErrors, 'vatNumber') ?>
                    </label>
                  </div>
                  <div class="dynamic-fields" data-quote-association-fields hidden>
                    <div class="lookup-block" data-company-lookup-scope data-lookup-customer-type="association">
                      <div class="lookup-block-header">
                        <strong>Hämta företagsuppgifter</strong>
                        <p>Hämta uppgifter via organisationsnummer. Namn och adress fylls normalt i automatiskt, medan telefon och e-post fortfarande kan behöva fyllas i manuellt.</p>
                        <?php if (use_mock_company_lookup()): ?><small class="lookup-demo-indicator">Demo-läge aktivt för företagslookup</small><?php endif; ?>
                      </div>
                      <div class="lookup-row">
                        <label>
                          Organisationsnummer
                          <input class="<?= h(field_class($quoteFormErrors, 'organizationNumber')) ?>" type="text" name="organizationNumber" value="<?= h(field_value($quoteFormValues, 'organizationNumber')) ?>" data-quote-organization-number data-business-organization-number />
                          <?= render_field_error($quoteFormErrors, 'organizationNumber') ?>
                        </label>
                        <button class="button button-secondary lookup-button" type="button" data-company-org-lookup-button>Hämta via organisationsnummer</button>
                      </div>
                      <div class="lookup-row">
                        <label>
                          Föreningsnamn
                          <input class="<?= h(field_class($quoteFormErrors, 'associationName')) ?>" type="text" name="associationName" value="<?= h(field_value($quoteFormValues, 'associationName')) ?>" data-business-name-input />
                          <?= render_field_error($quoteFormErrors, 'associationName') ?>
                        </label>
                        <button class="button button-secondary lookup-button" type="button" data-company-name-search-button>Sök företag via namn</button>
                      </div>
                      <div class="lookup-status" data-company-lookup-status hidden></div>
                      <div class="lookup-results" data-company-search-results hidden></div>
                    </div>
                    <label>
                      Kontaktperson
                      <input class="<?= h(field_class($quoteFormErrors, 'contactPerson')) ?>" type="text" name="contactPerson" value="<?= h(field_value($quoteFormValues, 'contactPerson')) ?>" />
                      <?= render_field_error($quoteFormErrors, 'contactPerson') ?>
                    </label>
                    <label>
                      Momsmodell
                      <select name="billingVatMode" data-quote-billing-vat-mode-select class="<?= h(field_class($quoteFormErrors, 'billingVatMode')) ?>">
                        <option value="standard_vat"<?= is_selected($quoteFormValues, 'billingVatMode', 'standard_vat') ?>>Vanlig moms</option>
                        <option value="reverse_charge"<?= is_selected($quoteFormValues, 'billingVatMode', 'reverse_charge') ?>>Omvänd moms</option>
                      </select>
                      <?= render_field_error($quoteFormErrors, 'billingVatMode') ?>
                    </label>
                    <label>
                      Momsregistreringsnummer
                      <input class="<?= h(field_class($quoteFormErrors, 'vatNumber')) ?>" type="text" name="vatNumber" value="<?= h(field_value($quoteFormValues, 'vatNumber')) ?>" />
                      <?= render_field_error($quoteFormErrors, 'vatNumber') ?>
                    </label>
                  </div>
                  <div class="form-columns">
                    <label>
                      Telefon
                      <input class="<?= h(field_class($quoteFormErrors, 'phone')) ?>" type="text" name="phone" value="<?= h(field_value($quoteFormValues, 'phone')) ?>" />
                      <?= render_field_error($quoteFormErrors, 'phone') ?>
                    </label>
                    <label>
                      E-post
                      <input class="<?= h(field_class($quoteFormErrors, 'email')) ?>" type="email" name="email" value="<?= h(field_value($quoteFormValues, 'email')) ?>" />
                      <?= render_field_error($quoteFormErrors, 'email') ?>
                    </label>
                  </div>
                  <label>
                    Fastighet / arbetsplats
                    <input class="<?= h(field_class($quoteFormErrors, 'serviceAddress')) ?>" type="text" name="serviceAddress" value="<?= h(field_value($quoteFormValues, 'serviceAddress')) ?>" />
                    <?= render_field_error($quoteFormErrors, 'serviceAddress') ?>
                  </label>
                  <div class="form-columns">
                    <label>
                      Postnummer
                      <input class="<?= h(field_class($quoteFormErrors, 'servicePostalCode')) ?>" type="text" name="servicePostalCode" value="<?= h(field_value($quoteFormValues, 'servicePostalCode')) ?>" />
                      <?= render_field_error($quoteFormErrors, 'servicePostalCode') ?>
                    </label>
                    <label>
                      Ort
                      <input class="<?= h(field_class($quoteFormErrors, 'serviceCity')) ?>" type="text" name="serviceCity" value="<?= h(field_value($quoteFormValues, 'serviceCity')) ?>" />
                      <?= render_field_error($quoteFormErrors, 'serviceCity') ?>
                    </label>
                  </div>
                  <label>
                    Fastighetsbeteckning
                    <input type="text" name="propertyDesignation" value="<?= h(field_value($quoteFormValues, 'propertyDesignation')) ?>" />
                  </label>
                  <label class="checkbox-line checkbox-card">
                    <input type="checkbox" name="billingSameAsProperty" value="1"<?= field_value($quoteFormValues, 'billingSameAsProperty') === '1' ? ' checked' : '' ?> data-billing-same-as-property />
                    Fakturaadress samma som arbetsplats
                  </label>
                  <div class="dynamic-fields dynamic-fields-visible" data-billing-fields>
                    <label>
                      Fakturaadress
                      <input class="<?= h(field_class($quoteFormErrors, 'billingAddress')) ?>" type="text" name="billingAddress" value="<?= h(field_value($quoteFormValues, 'billingAddress')) ?>" />
                      <?= render_field_error($quoteFormErrors, 'billingAddress') ?>
                    </label>
                    <div class="form-columns">
                      <label>
                        Fakturapostnummer
                        <input class="<?= h(field_class($quoteFormErrors, 'billingPostalCode')) ?>" type="text" name="billingPostalCode" value="<?= h(field_value($quoteFormValues, 'billingPostalCode')) ?>" />
                        <?= render_field_error($quoteFormErrors, 'billingPostalCode') ?>
                      </label>
                      <label>
                        Fakturaort
                        <input class="<?= h(field_class($quoteFormErrors, 'billingCity')) ?>" type="text" name="billingCity" value="<?= h(field_value($quoteFormValues, 'billingCity')) ?>" />
                        <?= render_field_error($quoteFormErrors, 'billingCity') ?>
                      </label>
                    </div>
                  </div>
                  <label>
                    Kundanteckningar
                    <textarea name="customerNotes" rows="3"><?= h(field_value($quoteFormValues, 'customerNotes')) ?></textarea>
                  </label>
                  <?= render_field_error($quoteFormErrors, 'customerId') ?>
                  </div>
                </div>
                <div class="info-chip" data-billing-info></div>
                <div class="panel-subsection form-section-block">
                  <div class="section-heading">
                    <div>
                      <h4>Offertuppgifter</h4>
                      <p>Kostnader, moms, preliminärt RUT och belopp att betala räknas ut automatiskt.</p>
                    </div>
                  </div>
                <div class="form-columns form-columns-2">
                  <label>
                    Offertdatum
                    <input type="date" name="quoteDate" value="<?= h(field_value($quoteFormValues, 'quoteDate')) ?>" data-quote-date />
                  </label>
                  <label>
                    Giltig till
                    <input class="<?= h(field_class($quoteFormErrors, 'validUntil')) ?>" type="date" name="validUntil" value="<?= h(field_value($quoteFormValues, 'validUntil')) ?>" required data-valid-until />
                    <?= render_field_error($quoteFormErrors, 'validUntil') ?>
                  </label>
                </div>
                <?php if (!$selectedQuote || $quoteIsEditable): ?>
                  <label>
                    Kalkylverktyg
                    <select data-quote-calc-tool-select>
                      <option value="">Välj kalkylverktyg</option>
                      <option value="stone">Stenkalkyl</option>
                      <option value="deck">Altankalkyl</option>
                    </select>
                  </label>
                  <div class="panel-subsection mobile-calc-card" data-stone-calc-section hidden>
                    <div class="section-heading">
                      <div>
                        <h4>Stenkalkyl</h4>
                        <p>Beräkna ett stenpaket och skapa arbets-, material- och övriga rader direkt.</p>
                      </div>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label>
                        Paket
                        <select data-stone-package>
                          <?php if ($stoneCalcPackages !== []): ?>
                            <?php foreach ($stoneCalcPackages as $index => $package): ?>
                              <option value="<?= (int)($package['id'] ?? 0) ?>"<?= $index === 0 ? ' selected' : '' ?>>
                                <?= h((string)($package['name'] ?? 'Stenpaket')) ?>
                              </option>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <option value="bas">Stenrengöring Bas</option>
                            <option value="plus">Stenrengöring Plus</option>
                            <option value="premium">Stenrengöring Premium</option>
                            <option value="complete_premium">Stenrengöring Komplett Premium</option>
                          <?php endif; ?>
                        </select>
                      </label>
                      <label>
                        Yta (kvm)
                        <input type="number" min="0" step="0.1" value="" data-stone-area />
                      </label>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label>
                        Nedsmutsningsgrad
                        <select data-stone-soiling>
                          <option value="light">Lätt</option>
                          <option value="normal" selected>Normal</option>
                          <option value="heavy">Kraftig</option>
                        </select>
                      </label>
                      <label>
                        Avfall / bortforsling
                        <select data-stone-waste>
                          <option value="none">Ingen</option>
                          <option value="small">Liten</option>
                          <option value="normal" selected>Normal</option>
                          <option value="large">Stor</option>
                        </select>
                      </label>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label>
                        Avstånd enkel väg (mil)
                        <input type="number" min="0" step="0.1" value="" data-stone-distance />
                      </label>
                      <label>
                        Servicebilsavgift
                        <input type="number" min="0" step="0.01" value="395" data-stone-van-base />
                      </label>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label>
                        Resekostnad per mil
                        <input type="number" min="0" step="0.01" value="40" data-stone-mile-rate />
                      </label>
                      <label class="checkbox-line checkbox-card">
                        <input type="checkbox" value="1" checked data-stone-roundtrip />
                        Tur och retur
                      </label>
                    </div>
                    <div class="info-chip" data-stone-calc-preview>Fyll i yta, paket och avstånd för att skapa stenrader.</div>
                    <div class="mobile-inline-actions">
                      <button class="button button-secondary" type="button" data-apply-stone-calc>Skapa stenpaket</button>
                    </div>
                  </div>
                  <div class="panel-subsection mobile-calc-card" data-deck-calc-section hidden>
                    <div class="section-heading">
                      <div>
                        <h4>Altankalkyl</h4>
                        <p>Beräkna ett altanpaket och skapa arbets-, material- och övriga rader direkt.</p>
                      </div>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label>
                        Paket
                        <select data-deck-package>
                          <?php if ($deckCalcPackages !== []): ?>
                            <?php foreach ($deckCalcPackages as $index => $package): ?>
                              <option value="<?= (int)($package['id'] ?? 0) ?>"<?= $index === 0 ? ' selected' : '' ?>>
                                <?= h((string)($package['name'] ?? 'Altanpaket')) ?>
                              </option>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <option value="bas">BAS - Grundrengöring</option>
                            <option value="plus">PLUS - Rengöring + Långtidsskydd</option>
                            <option value="premium">PREMIUM - Totalbehandling &amp; Återställning</option>
                          <?php endif; ?>
                        </select>
                      </label>
                      <label>
                        Yta (kvm)
                        <input type="number" min="0" step="0.1" value="" data-deck-area />
                      </label>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label>
                        Nedsmutsningsgrad
                        <select data-deck-soiling>
                          <option value="light">Lätt</option>
                          <option value="normal" selected>Normal</option>
                          <option value="heavy">Kraftig</option>
                        </select>
                      </label>
                      <label>
                        Avstånd enkel väg (mil)
                        <input type="number" min="0" step="0.1" value="" data-deck-distance />
                      </label>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label>
                        Servicebilsavgift
                        <input type="number" min="0" step="0.01" value="395" data-deck-van-base />
                      </label>
                      <label>
                        Resekostnad per mil
                        <input type="number" min="0" step="0.01" value="40" data-deck-mile-rate />
                      </label>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label class="checkbox-line checkbox-card">
                        <input type="checkbox" value="1" checked data-deck-roundtrip />
                        Tur och retur
                      </label>
                    </div>
                    <div class="info-chip" data-deck-calc-preview>Fyll i yta, paket och avstånd för att skapa ett komplett altanpaket.</div>
                    <div class="mobile-inline-actions">
                      <button class="button button-secondary" type="button" data-apply-deck-calc>Skapa altanpaket</button>
                    </div>
                  </div>
                <?php endif; ?>
                <label>
                  Tjänst
                  <input class="<?= h(field_class($quoteFormErrors, 'serviceType')) ?>" type="text" name="serviceType" value="<?= h(field_value($quoteFormValues, 'serviceType')) ?>" required data-quote-service-type />
                  <?= render_field_error($quoteFormErrors, 'serviceType') ?>
                </label>
                <label>
                  Beskrivning
                  <textarea class="<?= h(field_class($quoteFormErrors, 'description')) ?>" name="description" rows="4" required data-quote-description><?= h(field_value($quoteFormValues, 'description')) ?></textarea>
                  <?= render_field_error($quoteFormErrors, 'description') ?>
                </label>
                <div class="quote-items-builder" data-quote-items-builder data-initial-items="<?= h(field_value($quoteFormValues, 'quoteItemsJson')) ?>">
                  <div class="section-heading">
                    <div>
                      <h4>Offertrader</h4>
                      <p>Varje rad visas som ett kort för snabb registrering på mobil.</p>
                    </div>
                    <button class="button button-secondary" type="button" data-add-quote-item>Lägg till rad</button>
                  </div>
                  <input type="hidden" name="quoteItemsJson" value="<?= h(field_value($quoteFormValues, 'quoteItemsJson')) ?>" data-quote-items-json />
                  <input type="hidden" name="laborAmountExVat" value="<?= h(field_value($quoteFormValues, 'laborAmountExVat')) ?>" data-labor-amount />
                  <input type="hidden" name="materialAmountExVat" value="<?= h(field_value($quoteFormValues, 'materialAmountExVat')) ?>" data-material-amount />
                  <input type="hidden" name="otherAmountExVat" value="<?= h(field_value($quoteFormValues, 'otherAmountExVat')) ?>" data-other-amount />
                  <div class="stack-md" data-quote-items-list></div>
                  <?= render_field_error($quoteFormErrors, 'quoteItems') ?>
                </div>
                <div class="cost-summary">
                  <div><span>Arbete exkl moms</span><strong data-labor-summary>0 kr</strong></div>
                  <div><span>Material exkl moms</span><strong data-material-summary>0 kr</strong></div>
                  <div><span>Övrigt exkl moms</span><strong data-other-summary>0 kr</strong></div>
                  <div><span>Total exkl moms</span><strong data-total-ex-vat>0 kr</strong></div>
                  <div><span>Moms</span><strong data-vat-amount>0 kr</strong></div>
                  <div><span>Total inkl moms</span><strong data-total-inc-vat>0 kr</strong></div>
                  <div><span>RUT-avdrag</span><strong data-rut-amount>0 kr</strong></div>
                  <div><span>Att betala</span><strong data-amount-after-rut>0 kr</strong></div>
                  <div><span>Omvänd moms</span><strong data-reverse-charge-text>Nej</strong></div>
                </div>
                <label>
                  Anteckningar
                  <textarea name="notes" rows="3"><?= h(field_value($quoteFormValues, 'notes')) ?></textarea>
                </label>
                <label>
                  Status
                  <select name="status">
                    <option value="draft"<?= is_selected($quoteFormValues, 'status', 'draft') ?>>Utkast</option>
                    <option value="sent"<?= is_selected($quoteFormValues, 'status', 'sent') ?>>Skickad</option>
                    <option value="approved"<?= is_selected($quoteFormValues, 'status', 'approved') ?>>Godkänd</option>
                    <?php if (current_user_role() === USER_ROLE_ADMIN): ?>
                      <option value="rejected"<?= is_selected($quoteFormValues, 'status', 'rejected') ?>>Nekad</option>
                      <option value="cancelled"<?= is_selected($quoteFormValues, 'status', 'cancelled') ?>>Makulerad</option>
                    <?php endif; ?>
                  </select>
                </label>
                </fieldset>
                <div class="mobile-actionbar">
                  <button class="button button-secondary" type="button" data-print-document>Skriv ut</button>
                  <?php if ($selectedQuote && $quoteIsEditable): ?>
                    <button class="button button-secondary" type="submit" name="action" value="send_quote">Skicka offert</button>
                  <?php endif; ?>
                  <?php if ($selectedQuote): ?>
                    <?php if ($quoteIsEditable): ?>
                      <button class="button button-primary" type="submit"><?= h($quoteFormButton) ?></button>
                    <?php else: ?>
                      <a class="button button-primary" href="<?= h(admin_url('quotes', ['edit_id' => (int)$selectedQuote['id'], 'edit' => 1])) ?>#edit-quote">Redigera offert</a>
                    <?php endif; ?>
                    <a class="button button-secondary" href="<?= h(admin_url('quotes')) ?>">Till listan</a>
                    <select name="assignedTo" class="button-select">
                      <option value="">Välj arbetare</option>
                      <?php foreach ($workerUsers as $workerUser): ?>
                        <?php $workerUsername = (string)($workerUser['username'] ?? ''); ?>
                        <option value="<?= h($workerUsername) ?>"><?= h((string)($workerUser['name'] ?? $workerUsername)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="button button-secondary" type="submit" name="action" value="create_job_from_quote">Godkänn offert / skapa jobb</button>
                  <?php else: ?>
                    <button class="button button-primary" type="submit"><?= h($quoteFormButton) ?></button>
                  <?php endif; ?>
                </div>
                </div>
              </form>
              <?php endif; ?>
              <?php if ($selectedQuote && !$quoteIsEditable): ?>
                <div class="mobile-actionbar">
                  <button class="button button-secondary" type="button" data-print-document>Skriv ut</button>
                  <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="send_quote" />
                    <input type="hidden" name="quoteId" value="<?= (int)$selectedQuote['id'] ?>" />
                    <button class="button button-secondary" type="submit">Skicka offert</button>
                  </form>
                  <a class="button button-primary" href="<?= h(admin_url('quotes', ['edit_id' => (int)$selectedQuote['id'], 'edit' => 1])) ?>#edit-quote">Redigera offert</a>
                  <a class="button button-secondary" href="<?= h(admin_url('quotes')) ?>">Till listan</a>
                  <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="create_job_from_quote" />
                    <input type="hidden" name="quoteId" value="<?= (int)$selectedQuote['id'] ?>" />
                    <select name="assignedTo" class="button-select">
                      <option value="">Välj arbetare</option>
                      <?php foreach ($workerUsers as $workerUser): ?>
                        <?php $workerUsername = (string)($workerUser['username'] ?? ''); ?>
                        <option value="<?= h($workerUsername) ?>"><?= h((string)($workerUser['name'] ?? $workerUsername)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="button button-secondary" type="submit">Godkänn offert / skapa jobb</button>
                  </form>
                </div>
              <?php endif; ?>
              <?php if ($selectedQuote && current_user_role() === USER_ROLE_ADMIN && !in_array((string)($selectedQuote['status'] ?? ''), ['cancelled', 'rejected'], true)): ?>
                <details class="panel-subsection panel-subsection-rare">
                  <summary>Avsluta offert</summary>
                  <div class="stack-md panel-subsection-rare-body">
                    <div class="panel-subsection panel-subsection-rare-card">
                      <div class="panel-heading">
                        <div>
                          <h3>Kunden tackade nej</h3>
                          <p>Använd när kunden väljer att inte gå vidare med offerten.</p>
                        </div>
                      </div>
                      <form method="post" class="stack-md">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="reject_quote" />
                        <input type="hidden" name="quoteId" value="<?= (int)$selectedQuote['id'] ?>" />
                        <label>
                          Orsak
                          <input type="text" name="rejectReason" placeholder="Till exempel pris, inte aktuellt eller valde annan leverantör" />
                        </label>
                        <div class="header-actions">
                          <button class="button button-secondary" type="submit">Markera som nekad</button>
                        </div>
                      </form>
                    </div>
                    <div class="panel-subsection panel-subsection-rare-card">
                      <div class="panel-heading">
                        <div>
                          <h3>Makulera offert</h3>
                          <p>Använd när offerten inte längre ska vara aktiv. Om ett jobb redan finns kopplat behöver det hanteras först.</p>
                        </div>
                      </div>
                      <form method="post" class="stack-md">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="cancel_quote" />
                        <input type="hidden" name="quoteId" value="<?= (int)$selectedQuote['id'] ?>" />
                        <label>
                          Orsak
                          <input type="text" name="cancelReason" placeholder="Till exempel felaktig offert eller kund avstod" />
                        </label>
                        <div class="header-actions">
                          <button class="button button-secondary" type="submit">Makulera offert</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </details>
              <?php endif; ?>
            </article>
            <?php endif; ?>
          </section>
        <?php elseif ($page === 'jobs'): ?>
          <section class="workspace-stack">
            <?php if (in_array($view, ['all', 'upcoming', 'in_progress', 'done', 'archived'], true)): ?>
            <article class="panel">
              <div class="panel-heading"><h3><?= h($headerTitle) ?></h3><p>Snabb överblick över kund, tjänst, status och nästa steg.</p></div>
              <form method="get" class="inline-search inline-search-left" data-live-search-form data-auto-submit-form>
                <input type="hidden" name="page" value="jobs" />
                <input type="hidden" name="view" value="<?= h($view) ?>" />
                <input type="search" name="job_q" value="<?= h($jobSearch) ?>" placeholder="Sök kund, tjänst eller ansvarig" data-live-search-input />
                <?php if ($isAdminUser): ?>
                  <select name="job_organization">
                    <option value="">Alla organisationer</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= $jobOrganizationFilter === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <button class="button button-secondary" type="submit">Sök</button>
                <?php if ($jobSearch !== '' || ($isAdminUser && $jobOrganizationFilter !== '')): ?>
                  <a class="button button-secondary" href="<?= h(admin_url('jobs', ['view' => $view])) ?>">Rensa</a>
                <?php endif; ?>
              </form>
              <div class="stack-md" data-live-search-list>
                <?php foreach ($filteredJobsByView as $job): ?>
                  <?php $customer = find_by_id($customers, (int)$job['customer_id']); ?>
                  <?php $quote = $job['quote_id'] ? find_by_id($quotes, (int)$job['quote_id']) : null; ?>
                  <?php $basis = try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote); ?>
                  <?php $basisError = invoice_basis_error_for_job($invoiceBasesByJobId, $job, $customer, $quote); ?>
                  <?php
                  $jobQuote = !empty($job['quote_id']) ? find_by_id($quotes, (int)$job['quote_id']) : null;
                  $jobInvoiceBasis = try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $jobQuote);
                  $jobInvoiceStatus = job_invoice_status($job, $jobInvoiceBasis);
                  $jobInvoiceBadgeClass = match ($jobInvoiceStatus) {
                      'exporting', 'exported' => 'badge-blue',
                      'invoiced', 'exported_invoiced' => 'badge-green',
                      'pending' => 'badge-green',
                      'failed' => 'badge-red',
                      default => 'badge-amber',
                  };
                  $jobInvoiceLabel = match ($jobInvoiceStatus) {
                      'pending' => 'Redo att fakturera',
                      'exporting' => 'Export pågår',
                      'exported' => 'Exporterad',
                      'invoiced', 'exported_invoiced' => 'Fakturerad',
                      'failed' => 'Exportfel',
                      default => 'Inte redo',
                  };
                  $jobCustomerStreet = trim((string)($customer['service_address'] ?? $customer['address'] ?? ''));
                  $jobCustomerPostalCode = trim((string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? ''));
                  $jobCustomerCity = trim((string)($customer['service_city'] ?? $customer['city'] ?? ''));
                  $jobCustomerAddress = trim(implode(', ', array_filter([
                      $jobCustomerStreet,
                      trim(implode(' ', array_filter([$jobCustomerPostalCode, $jobCustomerCity], static fn(string $value): bool => $value !== ''))),
                  ], static fn(string $value): bool => $value !== '')));
                  $jobCustomerAddressLink = $jobCustomerAddress !== ''
                      ? render_google_maps_link(
                          $jobCustomerAddress,
                          [$jobCustomerStreet, $jobCustomerPostalCode, $jobCustomerCity],
                          'address-link'
                      )
                      : '';
                  ?>
                  <?php $jobSearchText = trim(implode(' ', array_filter([
                      job_display_number($job),
                      customer_name($data, (int)$job['customer_id']),
                      $jobCustomerAddress,
                      (string)($job['service_type'] ?? ''),
                      (string)($job['description'] ?? ''),
                      (string)($job['assigned_to'] ?? ''),
                      status_label((string)($job['status'] ?? 'planned')),
                      customer_type_label((string)($customer['customer_type'] ?? 'private')),
                      vat_mode_label((string)($customer['billing_vat_mode'] ?? 'standard_vat')),
                      $jobInvoiceLabel,
                  ], static fn(string $value): bool => $value !== ''))); ?>
                  <div class="list-card list-card-compact" id="job-<?= (int)$job['id'] ?>" data-live-search-item data-search-text="<?= h($jobSearchText) ?>">
                    <div class="list-row">
                      <div class="list-row-stack">
                        <a class="list-row-main-link list-row-main-link-inline" href="<?= h(admin_url('jobs', [
                          'view' => 'edit',
                          'job_edit_id' => $job['id'],
                          'return_page' => 'jobs',
                          'return_view' => $view,
                        ])) ?>">
                          <strong class="job-number-label"><?= h(job_display_number($job)) ?></strong>
                          <span class="list-inline-muted list-inline-truncate">
                            <?= h(customer_name($data, (int)$job['customer_id'])) ?>
                            <?php if ((string)($job['service_type'] ?? '') !== ''): ?> · <?= h((string)($job['service_type'] ?? '')) ?><?php endif; ?>
                          </span>
                        </a>
                        <?php if ($jobCustomerAddressLink !== ''): ?>
                          <div class="list-inline-muted"><?= $jobCustomerAddressLink ?></div>
                        <?php endif; ?>
                      </div>
                      <div class="badge-row">
                        <span class="badge <?= h(badge_class($job['status'])) ?>"><?= h(status_label($job['status'])) ?></span>
                        <span class="badge <?= h($jobInvoiceBadgeClass) ?>"><?= h($jobInvoiceLabel) ?></span>
                      </div>
                      <?php $jobAssigneeLabel = job_assignee_label($job, $workerUsers); ?>
                      <?php if ($jobAssigneeLabel !== ''): ?>
                        <span class="list-inline-muted"><?= h($jobAssigneeLabel) ?></span>
                      <?php endif; ?>
                    </div>
                    <?php if ($basisError !== '' && $view === 'uninvoiced'): ?>
                      <div class="inline-alert inline-alert-error"><?= h($basisError) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <p data-live-search-empty<?= $filteredJobsByView === [] ? '' : ' hidden' ?>><?= $jobSearch !== '' ? 'Inget jobb matchade din sökning.' : 'Inga jobb i den här vyn.' ?></p>
              </div>
            </article>
            <?php elseif (in_array($view, ['create', 'edit'], true)): ?>
            <article class="panel" id="new-job">
              <?php
              $jobFormValues = $selectedJob ? $editJobValues : $createJobValues;
              $jobFormErrors = $selectedJob ? $editJobErrors : $createJobErrors;
              $jobFormAction = $selectedJob ? 'update_job' : 'create_job';
              $jobIsEditable = current_user_can('jobs.manage');
              $jobCanProgress = current_user_can('jobs.progress');
              $jobCanCompleteAndInvoice = current_user_can('jobs.complete_and_invoice');
              $jobCanAssign = current_user_can('jobs.assign');
              $jobIsWorkerView = $selectedJob && !$jobIsEditable && !$jobCanAssign && $jobCanProgress;
              $jobFormTitle = $selectedJob ? ($jobIsEditable ? 'Redigera jobb' : ($jobCanAssign ? 'Tilldela jobb' : ($jobIsWorkerView ? 'Jobböversikt' : 'Visa jobb'))) : 'Skapa jobb';
              $jobFormDisplayNumber = $selectedJob ? job_display_number($selectedJob) : '';
              $jobFormButton = $selectedJob ? 'Spara jobb' : 'Skapa jobb';
              $jobFormCustomer = $selectedJob ? find_by_id($customers, (int)$selectedJob['customer_id']) : ($prefillCustomerId > 0 ? find_by_id($customers, $prefillCustomerId) : null);
              $jobFormCustomerType = (string)($jobFormCustomer['customer_type'] ?? 'private');
              $jobFormBillingVatMode = (string)($jobFormCustomer['billing_vat_mode'] ?? 'standard_vat');
              $jobFormRutEnabled = !empty($jobFormCustomer['rut_enabled']);
              $jobFormQuote = trim(field_value($jobFormValues, 'quoteId')) !== '' ? find_by_id($quotes, (int)field_value($jobFormValues, 'quoteId')) : null;
              $jobQuoteItems = $data['quote_items'] ?? [];
              $jobFormQuoteItems = $jobFormQuote ? array_values(array_filter(
                  $jobQuoteItems,
                  static fn(array $item): bool => (int)($item['quote_id'] ?? 0) === (int)($jobFormQuote['id'] ?? 0)
              )) : [];
              $jobFormOrganization = trim(field_value($jobFormValues, 'organizationId')) !== '' ? find_organization_by_id($data, (int)field_value($jobFormValues, 'organizationId')) : null;
              $selectedJobInvoiceBasis = $selectedJob ? try_invoice_basis_for_job($invoiceBasesByJobId, $selectedJob, $jobFormCustomer, $jobFormQuote) : null;
              $selectedJobInvoiceStatus = $selectedJob ? job_invoice_status($selectedJob, $selectedJobInvoiceBasis) : '';
              $selectedJobFortnoxSummary = fortnox_reference_summary($selectedJobInvoiceBasis);
              $selectedJobHasPaymentPanel = is_array($selectedJobInvoiceBasis) && in_array($selectedJobInvoiceStatus, ['exported', 'invoiced', 'exported_invoiced'], true);
              $jobFormFortnoxLead = match ($selectedJobInvoiceStatus) {
                  'pending' => 'Jobbet är redo för fakturering i Fortnox',
                  'exporting' => 'Fakturaunderlaget håller på att exporteras till Fortnox',
                  'exported' => 'Jobbet är exporterat till Fortnox',
                  'invoiced', 'exported_invoiced' => 'Jobbet är fakturerat via Fortnox',
                  'failed' => 'Fortnox-exporten behöver åtgärdas innan fakturering',
                  default => 'Jobbet är förberett för senare fakturaunderlag till Fortnox',
              };
              if ($jobIsWorkerView) {
                  $jobFormFortnoxLead = 'Se vad som ska göras hos kunden och uppdatera arbetsstatus när arbetet går framåt.';
              }
              ?>
              <?php if ($selectedJob && !$jobIsEditable && $jobCanAssign): ?>
                <div class="panel-subsection panel-subsection-assign">
                  <div class="panel-heading">
                    <div>
                      <h3>Tilldela arbetare</h3>
                      <p>Välj vem som ska utföra jobbet. Resten av jobbdetaljerna visas längre ner som läsläge.</p>
                    </div>
                  </div>
                  <form method="post" class="stack-md">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="assign_job_worker" />
                    <input type="hidden" name="jobId" value="<?= (int)$selectedJob['id'] ?>" />
                    <?= render_job_return_hidden_inputs($jobReturnContext) ?>
                    <label>
                      Arbetare
                      <select name="assignedTo" required>
                        <option value="">Välj arbetare</option>
                        <?php foreach ($workerUsers as $workerUser): ?>
                          <?php $workerUsername = (string)($workerUser['username'] ?? ''); ?>
                          <option value="<?= h($workerUsername) ?>"<?= field_value($jobFormValues, 'assignedTo') === $workerUsername ? ' selected' : '' ?>>
                            <?= h((string)($workerUser['name'] ?? $workerUsername)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <div class="header-actions">
                      <button class="button button-primary" type="submit">Spara ansvarig</button>
                    </div>
                  </form>
                </div>
              <?php endif; ?>
              <div class="panel-heading">
                <div>
                  <h3><?= h($jobFormTitle) ?></h3>
                  <p>
                    <?php if ($jobFormDisplayNumber !== ''): ?><?= h($jobFormDisplayNumber) ?> · <?php endif; ?>
                    <?= h($jobFormFortnoxLead) ?>
                  </p>
                </div>
              </div>
              <?php if ($selectedJobFortnoxSummary !== ''): ?>
                <div class="inline-alert inline-alert-success"><?= h($selectedJobFortnoxSummary) ?></div>
              <?php endif; ?>
              <?php if ($selectedJob && !$jobIsEditable): ?>
                <?php
                $jobServiceAddress = trim((string)($jobFormCustomer['service_address'] ?? $jobFormCustomer['address'] ?? ''));
                $jobServicePostcode = trim((string)($jobFormCustomer['service_postal_code'] ?? $jobFormCustomer['postal_code'] ?? ''));
                $jobServiceCity = trim((string)($jobFormCustomer['service_city'] ?? $jobFormCustomer['city'] ?? ''));
                $jobTaskItems = array_values(array_filter(array_map(
                    static fn(array $item): string => trim((string)($item['description'] ?? '')),
                    $jobFormQuoteItems
                ), static fn(string $item): bool => $item !== ''));
                ?>
                <div class="panel-subsection">
                  <div class="panel-heading">
                    <div>
                      <h3>Det här ska göras</h3>
                      <p>Arbetsöversikt för utförandet hos kunden.</p>
                    </div>
                  </div>
                  <div class="stack-sm">
                    <?php if (trim(field_value($jobFormValues, 'serviceType')) !== ''): ?>
                      <p><strong>Tjänst:</strong> <?= h(field_value($jobFormValues, 'serviceType')) ?></p>
                    <?php endif; ?>
                    <?php if (trim(field_value($jobFormValues, 'description')) !== ''): ?>
                      <p><strong>Beskrivning:</strong> <?= nl2br(h(field_value($jobFormValues, 'description'))) ?></p>
                    <?php endif; ?>
                    <?php if ($jobTaskItems !== []): ?>
                      <div>
                        <strong>Moment:</strong>
                        <ul class="document-list">
                          <?php foreach ($jobTaskItems as $taskItem): ?>
                            <li><?= h($taskItem) ?></li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    <?php endif; ?>
                    <?php if ($jobServiceAddress !== '' || $jobServicePostcode !== '' || $jobServiceCity !== ''): ?>
                      <p><strong>Adress:</strong> <?= h(trim(implode(', ', array_filter([
                        $jobServiceAddress,
                        trim($jobServicePostcode . ' ' . $jobServiceCity),
                      ], static fn(string $value): bool => trim($value) !== '')))) ?></p>
                    <?php endif; ?>
                    <?php if (trim(field_value($jobFormValues, 'notes')) !== ''): ?>
                      <p><strong>Intern notering:</strong> <?= nl2br(h(field_value($jobFormValues, 'notes'))) ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
              <?php if ($jobIsWorkerView): ?>
                <div class="panel-subsection">
                  <div class="panel-heading">
                    <div>
                      <h3>Arbetsstatus</h3>
                      <p>Uppdatera jobbet när du börjar, arbetar eller är klar hos kunden.</p>
                    </div>
                  </div>
                  <form method="post" class="stack-md">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update_job_progress" />
                    <input type="hidden" name="jobId" value="<?= (int)$selectedJob['id'] ?>" />
                    <?= render_job_return_hidden_inputs($jobReturnContext) ?>
                    <div class="form-columns form-columns-2">
                      <label>
                        Planerat datum
                        <input type="date" name="scheduledDate" value="<?= h(field_value($jobFormValues, 'scheduledDate')) ?>" class="<?= h(field_class($jobFormErrors, 'scheduledDate')) ?>" />
                        <?= render_field_error($jobFormErrors, 'scheduledDate') ?>
                      </label>
                      <label>
                        Klockslag
                        <input type="time" name="scheduledTime" value="<?= h(field_value($jobFormValues, 'scheduledTime')) ?>" class="<?= h(field_class($jobFormErrors, 'scheduledTime')) ?>" />
                        <?= render_field_error($jobFormErrors, 'scheduledTime') ?>
                      </label>
                    </div>
                    <div class="form-columns form-columns-2">
                      <label>
                        Status
                        <select name="status" class="<?= h(field_class($jobFormErrors, 'status')) ?>">
                          <option value="planned"<?= is_selected($jobFormValues, 'status', 'planned') ?>>Planerat</option>
                          <option value="scheduled"<?= is_selected($jobFormValues, 'status', 'scheduled') ?>>Schemalagt</option>
                          <option value="in_progress"<?= is_selected($jobFormValues, 'status', 'in_progress') ?>>Pågående</option>
                          <option value="completed"<?= is_selected($jobFormValues, 'status', 'completed') ?>>Klart</option>
                        </select>
                        <?= render_field_error($jobFormErrors, 'status') ?>
                      </label>
                      <label>
                        Utfört datum
                        <input type="date" name="completedDate" value="<?= h(field_value($jobFormValues, 'completedDate')) ?>" />
                      </label>
                    </div>
                    <label>
                      Arbetsnotering
                      <textarea name="notes" rows="3"><?= h(field_value($jobFormValues, 'notes')) ?></textarea>
                    </label>
                    <div class="header-actions">
                      <button class="button button-primary" type="submit">Spara arbetsstatus</button>
                      <?php if ($jobCanCompleteAndInvoice && !in_array((string)($selectedJob['status'] ?? ''), ['cancelled', 'invoiced'], true) && !in_array($selectedJobInvoiceStatus, ['exporting', 'exported', 'invoiced', 'exported_invoiced'], true)): ?>
                        <button class="button button-secondary" type="submit" name="action" value="complete_job_and_invoice_now">Klar och fakturera nu</button>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              <?php endif; ?>
              <?php if ($selectedJobHasPaymentPanel): ?>
                <div class="payment-panel">
                  <div class="payment-panel-heading">
                    <h4>Betalinfo</h4>
                    <span class="badge <?= in_array($selectedJobInvoiceStatus, ['invoiced', 'exported_invoiced'], true) ? 'badge-green' : 'badge-blue' ?>">
                      <?= h(in_array($selectedJobInvoiceStatus, ['invoiced', 'exported_invoiced'], true) ? 'Fakturerad' : 'Klar för betalning') ?>
                    </span>
                  </div>
                  <div class="payment-panel-grid">
                    <div><span>Belopp</span><strong><?= h(format_currency((float)($selectedJobInvoiceBasis['amountToPay'] ?? 0))) ?></strong></div>
                    <div><span>Fakturanummer</span><strong><?= h((string)($selectedJobInvoiceBasis['fortnoxInvoiceNumber'] ?? 'Ej tilldelat')) ?></strong></div>
                    <div><span>Dokument</span><strong><?= h((string)($selectedJobInvoiceBasis['fortnoxDocumentNumber'] ?? 'Ej tilldelat')) ?></strong></div>
                    <div><span>Nästa steg</span><strong><?= h(in_array($selectedJobInvoiceStatus, ['invoiced', 'exported_invoiced'], true) ? 'Invänta betalning' : 'Visa kund betalinfo') ?></strong></div>
                  </div>
                </div>
              <?php endif; ?>
              <?php if (!$jobIsWorkerView): ?>
              <form method="post" class="stack-md" data-job-form data-customer-type="<?= h($jobFormCustomerType) ?>" data-billing-vat-mode="<?= h($jobFormBillingVatMode) ?>" data-rut-enabled="<?= $jobFormRutEnabled ? '1' : '0' ?>" data-rut-used-amount="<?= h((string)($jobFormCustomer['rut_used_amount_this_year'] ?? 0)) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="<?= h($jobFormAction) ?>" />
                <?= render_job_return_hidden_inputs($jobReturnContext) ?>
                <?php if ($selectedJob): ?>
                  <input type="hidden" name="jobId" value="<?= (int)$selectedJob['id'] ?>" />
                <?php endif; ?>
                <fieldset class="form-readonly-shell"<?= !$jobIsEditable ? ' disabled' : '' ?>>
                <label>
                  Kund
                  <select name="customerId" required<?= $selectedJob ? ' disabled' : '' ?>>
                    <?php foreach ($customers as $customer): ?>
                      <option value="<?= (int)$customer['id'] ?>"<?= field_value($jobFormValues, 'customerId') === (string)$customer['id'] ? ' selected' : '' ?>><?= h(customer_name($data, (int)$customer['id'])) ?> · <?= h(vat_mode_label($customer['billing_vat_mode'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if ($selectedJob): ?>
                    <input type="hidden" name="customerId" value="<?= h(field_value($jobFormValues, 'customerId')) ?>" />
                  <?php endif; ?>
                </label>
                <?php if ($currentUserOrganizationId !== null && current_user_role() !== USER_ROLE_ADMIN): ?>
                <input type="hidden" name="organizationId" value="<?= h((string)$currentUserOrganizationId) ?>" />
                <label>
                  Organisation
                  <input type="text" value="<?= h((string)($currentUserOrganization['name'] ?? ($jobFormOrganization['name'] ?? ''))) ?>" disabled />
                </label>
                <?php else: ?>
                <label>
                  Organisation
                  <select name="organizationId" class="<?= h(field_class($jobFormErrors, 'organizationId')) ?>">
                    <option value="">Ingen organisation vald</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= field_value($jobFormValues, 'organizationId') === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?= render_field_error($jobFormErrors, 'organizationId') ?>
                </label>
                <?php endif; ?>
                <label>Tjänst<input class="<?= h(field_class($jobFormErrors, 'serviceType')) ?>" type="text" name="serviceType" value="<?= h(field_value($jobFormValues, 'serviceType')) ?>" required /><?= render_field_error($jobFormErrors, 'serviceType') ?></label>
                <label>Beskrivning<textarea name="description" rows="3"><?= h(field_value($jobFormValues, 'description')) ?></textarea></label>
                <div class="form-columns form-columns-2">
                  <label>Planerat datum<input class="<?= h(field_class($jobFormErrors, 'scheduledDate')) ?>" type="date" name="scheduledDate" value="<?= h(field_value($jobFormValues, 'scheduledDate')) ?>" required /><?= render_field_error($jobFormErrors, 'scheduledDate') ?></label>
                  <label>Klockslag<input class="<?= h(field_class($jobFormErrors, 'scheduledTime')) ?>" type="time" name="scheduledTime" value="<?= h(field_value($jobFormValues, 'scheduledTime')) ?>" /><?= render_field_error($jobFormErrors, 'scheduledTime') ?></label>
                </div>
                <label>
                  Ansvarig
                  <select name="assignedTo" required>
                    <option value="">Välj arbetare</option>
                    <?php foreach ($workerUsers as $workerUser): ?>
                      <?php $workerUsername = (string)($workerUser['username'] ?? ''); ?>
                      <option value="<?= h($workerUsername) ?>"<?= field_value($jobFormValues, 'assignedTo') === $workerUsername ? ' selected' : '' ?>>
                        <?= h((string)($workerUser['name'] ?? $workerUsername)) ?>
                      </option>
                    <?php endforeach; ?>
                    <?php
                    $selectedAssignedTo = trim(field_value($jobFormValues, 'assignedTo'));
                    $workerUsernames = array_map(static fn(array $user): string => (string)($user['username'] ?? ''), $workerUsers);
                    ?>
                    <?php if ($selectedAssignedTo !== '' && !in_array($selectedAssignedTo, $workerUsernames, true)): ?>
                      <option value="<?= h($selectedAssignedTo) ?>" selected><?= h($selectedAssignedTo) ?></option>
                    <?php endif; ?>
                  </select>
                </label>
                <label>
                  Region
                  <select name="regionId" class="<?= h(field_class($jobFormErrors, 'regionId')) ?>">
                    <option value="">Ingen region vald</option>
                    <?php foreach ($regions as $region): ?>
                      <option value="<?= (int)($region['id'] ?? 0) ?>"<?= field_value($jobFormValues, 'regionId') === (string)($region['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h((string)($region['name'] ?? '')) ?><?= empty($region['is_active']) ? ' (inaktiv)' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?= render_field_error($jobFormErrors, 'regionId') ?>
                </label>
                <label>
                  Status
                  <select name="status" class="<?= h(field_class($jobFormErrors, 'status')) ?>">
                    <option value="planned"<?= is_selected($jobFormValues, 'status', 'planned') ?>>Planerat</option>
                    <option value="scheduled"<?= is_selected($jobFormValues, 'status', 'scheduled') ?>>Schemalagt</option>
                    <option value="in_progress"<?= is_selected($jobFormValues, 'status', 'in_progress') ?>>Pågående</option>
                    <option value="completed"<?= is_selected($jobFormValues, 'status', 'completed') ?>>Klart</option>
                    <option value="cancelled"<?= is_selected($jobFormValues, 'status', 'cancelled') ?>>Avbrutet</option>
                    <option value="invoiced"<?= is_selected($jobFormValues, 'status', 'invoiced') ?>>Fakturerat</option>
                  </select>
                  <?= render_field_error($jobFormErrors, 'status') ?>
                </label>
                <label>
                  Kopplad offert (valfritt)
                  <input type="number" name="quoteId" min="1" value="<?= h(field_value($jobFormValues, 'quoteId')) ?>" />
                  <?php if ($jobFormQuote !== null): ?>
                    <small class="field-message">Offertnummer: <?= h(quote_display_number($jobFormQuote)) ?></small>
                  <?php endif; ?>
                </label>
                <div class="form-columns">
                  <label>Utfört datum<input type="date" name="completedDate" value="<?= h(field_value($jobFormValues, 'completedDate')) ?>" /></label>
                  <label class="checkbox-line">
                    <input type="checkbox" name="readyForInvoicing" value="1"<?= field_value($jobFormValues, 'readyForInvoicing') === '1' ? ' checked' : '' ?> />
                    Jobbet är redo för fakturering
                  </label>
                </div>
                <div class="form-columns">
                  <label>Slutlig arbetskostnad exkl moms<input class="<?= h(field_class($jobFormErrors, 'finalLaborAmountExVat')) ?>" type="number" name="finalLaborAmountExVat" step="0.01" value="<?= h(field_value($jobFormValues, 'finalLaborAmountExVat')) ?>" data-job-labor /><?= render_field_error($jobFormErrors, 'finalLaborAmountExVat') ?></label>
                  <label>Slutlig materialkostnad exkl moms<input class="<?= h(field_class($jobFormErrors, 'finalMaterialAmountExVat')) ?>" type="number" name="finalMaterialAmountExVat" step="0.01" value="<?= h(field_value($jobFormValues, 'finalMaterialAmountExVat')) ?>" data-job-material /><?= render_field_error($jobFormErrors, 'finalMaterialAmountExVat') ?></label>
                  <label>Slutlig övrig kostnad exkl moms<input class="<?= h(field_class($jobFormErrors, 'finalOtherAmountExVat')) ?>" type="number" name="finalOtherAmountExVat" step="0.01" value="<?= h(field_value($jobFormValues, 'finalOtherAmountExVat')) ?>" data-job-other /><?= render_field_error($jobFormErrors, 'finalOtherAmountExVat') ?></label>
                </div>
                <div class="job-summary">
                  <div><span>Total exkl moms</span><strong data-job-total-ex-vat>0 kr</strong></div>
                  <div><span>Moms</span><strong data-job-vat>0 kr</strong></div>
                  <div><span>Total inkl moms</span><strong data-job-total-inc-vat>0 kr</strong></div>
                  <div><span>RUT-avdrag</span><strong data-job-rut>0 kr</strong></div>
                  <div><span>Att fakturera</span><strong data-job-after-rut>0 kr</strong></div>
                  <div><span>Omvänd moms</span><strong data-job-reverse-charge>Nej</strong></div>
                </div>
                <label>Anteckningar<textarea name="notes" rows="3"><?= h(field_value($jobFormValues, 'notes')) ?></textarea></label>
                </fieldset>
                <div class="header-actions">
                  <?php if ($jobIsEditable): ?>
                    <button class="button button-primary" type="submit"><?= h($jobFormButton) ?></button>
                    <?php if ($selectedJob && $jobCanCompleteAndInvoice && !in_array((string)($selectedJob['status'] ?? ''), ['cancelled', 'invoiced'], true) && !in_array($selectedJobInvoiceStatus, ['exporting', 'exported', 'invoiced', 'exported_invoiced'], true)): ?>
                      <button class="button button-secondary" type="submit" name="action" value="complete_job_and_invoice_now">Klar och fakturera nu</button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($selectedJob): ?>
                    <a class="button button-secondary" href="<?= h(
                      ($returnPage === 'calendar')
                        ? admin_url('calendar', array_filter([
                            'view' => $returnView !== '' ? $returnView : 'week',
                            'week' => (string)$returnWeek,
                            'calendar_organization' => $returnCalendarOrganization,
                            'calendar_region' => $returnCalendarRegion,
                            'calendar_worker' => $returnCalendarWorker,
                          ], static fn(string $value): bool => $value !== ''))
                        : admin_url($returnPage !== '' ? $returnPage : (current_user_can('jobs.manage') ? 'jobs' : 'calendar'), [
                            'view' => $returnView !== '' ? $returnView : 'all',
                          ])
                    ) ?>">Tillbaka</a>
                  <?php endif; ?>
                </div>
              </form>
              <?php endif; ?>
              <?php if ($selectedJob && $jobIsEditable && (string)($selectedJob['status'] ?? '') !== 'cancelled'): ?>
                <details class="panel-subsection panel-subsection-rare">
                  <summary>Avsluta jobb</summary>
                  <div class="panel-subsection panel-subsection-rare-card">
                    <div class="panel-heading">
                      <div>
                        <h3>Avbryt jobb</h3>
                        <p>Använd när jobbet inte ska utföras. Välj också om kunden ska debiteras något för avbrottet.</p>
                      </div>
                    </div>
                    <form method="post" class="stack-md" data-cancel-job-form>
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="cancel_job" />
                      <input type="hidden" name="jobId" value="<?= (int)$selectedJob['id'] ?>" />
                      <label>
                        Debitering vid avbrott
                        <select name="cancellationBillingMode" data-cancel-billing-mode>
                          <option value="none">Ingen debitering</option>
                          <option value="fee">Avbokningsavgift</option>
                          <option value="actual_cost">Faktisk kostnad</option>
                        </select>
                      </label>
                      <div class="form-columns" data-cancel-cost-fields hidden>
                        <label>Arbete exkl moms<input type="number" name="cancelLaborAmountExVat" step="0.01" min="0" value="0" /></label>
                        <label>Material exkl moms<input type="number" name="cancelMaterialAmountExVat" step="0.01" min="0" value="0" /></label>
                        <label>Övrigt exkl moms<input type="number" name="cancelOtherAmountExVat" step="0.01" min="0" value="0" /></label>
                      </div>
                      <label>
                        Orsak
                        <input type="text" name="cancelReason" placeholder="Till exempel kund avbokade eller felregistrerat jobb" />
                      </label>
                      <div class="header-actions">
                        <button class="button button-secondary" type="submit">Avbryt jobb</button>
                      </div>
                    </form>
                  </div>
                </details>
              <?php endif; ?>
            </article>
            <?php endif; ?>
          </section>
        <?php elseif ($page === 'invoices'): ?>
          <section class="workspace-stack">
            <article class="panel">
              <div class="panel-heading"><h3><?= h($headerTitle) ?></h3><p>Översikt över jobb kopplade till fakturaunderlag</p></div>
              <form method="get" class="inline-search inline-search-left" data-live-search-form data-auto-submit-form>
                <input type="hidden" name="page" value="invoices" />
                <input type="hidden" name="view" value="<?= h($view) ?>" />
                <input type="search" name="invoice_q" value="<?= h($invoiceSearch) ?>" placeholder="Sök kund, tjänst eller invoice-status" data-live-search-input />
                <?php if ($isAdminUser): ?>
                  <select name="invoice_organization">
                    <option value="">Alla organisationer</option>
                    <?php foreach ($activeOrganizations as $organization): ?>
                      <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= $invoiceOrganizationFilter === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                        <?= h(organization_tree_label($organization)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <button class="button button-secondary" type="submit">Sök</button>
                <?php if ($invoiceSearch !== '' || ($isAdminUser && $invoiceOrganizationFilter !== '')): ?>
                  <a class="button button-secondary" href="<?= h(admin_url('invoices', ['view' => $view])) ?>">Rensa</a>
                <?php endif; ?>
              </form>
              <div class="stack-md" data-live-search-list>
                <?php foreach ($invoiceJobsByView as $job): ?>
                  <?php $customer = find_by_id($customers, (int)$job['customer_id']); ?>
                  <?php $quote = $job['quote_id'] ? find_by_id($quotes, (int)$job['quote_id']) : null; ?>
                  <?php $basis = try_invoice_basis_for_job($invoiceBasesByJobId, $job, $customer, $quote); ?>
                  <?php $basisError = invoice_basis_error_for_job($invoiceBasesByJobId, $job, $customer, $quote); ?>
                  <?php $fortnoxExportError = fortnox_export_error_for_job($invoiceBasesByJobId, $job); ?>
                  <?php $fortnoxExportActionUrl = fortnox_export_error_action_link($fortnoxExportError, $customer); ?>
                  <?php $fortnoxReferenceSummary = fortnox_reference_summary($basis); ?>
                  <?php
                  $invoiceCustomerStreet = trim((string)($customer['service_address'] ?? $customer['address'] ?? ''));
                  $invoiceCustomerPostalCode = trim((string)($customer['service_postal_code'] ?? $customer['postal_code'] ?? ''));
                  $invoiceCustomerCity = trim((string)($customer['service_city'] ?? $customer['city'] ?? ''));
                  $invoiceCustomerAddress = trim(implode(', ', array_filter([
                      $invoiceCustomerStreet,
                      trim(implode(' ', array_filter([$invoiceCustomerPostalCode, $invoiceCustomerCity], static fn(string $value): bool => $value !== ''))),
                  ], static fn(string $value): bool => $value !== '')));
                  ?>
                  <?php $invoiceStatus = job_invoice_status($job, $basis); ?>
                  <?php $showPaymentPanel = is_array($basis) && in_array($invoiceStatus, ['exported', 'invoiced', 'exported_invoiced'], true); ?>
                  <?php $invoiceSearchText = trim(implode(' ', array_filter([
                      customer_name($data, (int)$job['customer_id']),
                      $invoiceCustomerAddress,
                      (string)($job['service_type'] ?? ''),
                      (string)($job['description'] ?? ''),
                      status_label((string)($job['status'] ?? 'planned')),
                      $invoiceStatus,
                      customer_type_label((string)($customer['customer_type'] ?? 'private')),
                  ], static fn(string $value): bool => $value !== ''))); ?>
                  <div class="list-card list-card-compact" data-live-search-item data-search-text="<?= h($invoiceSearchText) ?>">
                    <div class="list-row">
                      <a class="list-row-main-link list-row-main-link-inline" href="<?= h(admin_url('jobs', [
                        'view' => 'edit',
                        'job_edit_id' => $job['id'],
                        'return_page' => 'invoices',
                        'return_view' => $view,
                      ])) ?>">
                        <strong><?= h(customer_name($data, (int)$job['customer_id'])) ?></strong>
                        <span class="list-inline-muted list-inline-truncate">
                          <?= h((string)($job['service_type'] ?? '')) ?>
                          <?php if ($invoiceCustomerAddress !== ''): ?> · <?= h($invoiceCustomerAddress) ?><?php endif; ?>
                        </span>
                      </a>
                      <div class="badge-row">
                        <span class="badge <?= match ($invoiceStatus) {
                          'invoiced', 'exported_invoiced' => 'badge-green',
                          'exporting', 'exported' => 'badge-blue',
                          default => 'badge-amber',
                        } ?>"><?= h($invoiceStatus !== '' ? $invoiceStatus : 'ej satt') ?></span>
                      </div>
                    </div>
                    <p class="list-inline-muted">
                      Att fakturera <?= h(format_currency((float)($basis['amountToPay'] ?? 0))) ?>
                      <?php if (!empty($basis['fortnoxDocumentNumber'])): ?> · Dokument <?= h((string)$basis['fortnoxDocumentNumber']) ?><?php endif; ?>
                      <?php if (!empty($basis['fortnoxInvoiceNumber'])): ?> · Faktura <?= h((string)$basis['fortnoxInvoiceNumber']) ?><?php endif; ?>
                    </p>
                    <?php if ($fortnoxReferenceSummary !== '' && in_array($invoiceStatus, ['exported', 'invoiced', 'exported_invoiced'], true)): ?>
                      <p class="list-inline-muted"><?= h($fortnoxReferenceSummary) ?></p>
                    <?php endif; ?>
                    <?php if ($showPaymentPanel): ?>
                      <div class="payment-panel payment-panel-compact">
                        <div class="payment-panel-grid">
                          <div><span>Belopp</span><strong><?= h(format_currency((float)($basis['amountToPay'] ?? 0))) ?></strong></div>
                          <div><span>Faktura</span><strong><?= h((string)($basis['fortnoxInvoiceNumber'] ?? 'Ej tilldelat')) ?></strong></div>
                          <div><span>Nästa steg</span><strong><?= h(in_array($invoiceStatus, ['invoiced', 'exported_invoiced'], true) ? 'Invänta betalning' : 'Visa kund betalinfo') ?></strong></div>
                        </div>
                      </div>
                    <?php endif; ?>
                    <?php if ($basis !== null && current_user_can('invoices.manage') && !in_array($invoiceStatus, ['exporting', 'exported', 'invoiced', 'exported_invoiced'], true)): ?>
                      <div class="form-actions">
                        <form method="post" class="inline-form">
                          <?= csrf_input() ?>
                          <input type="hidden" name="action" value="export_invoice_basis" />
                          <input type="hidden" name="invoiceBaseId" value="<?= (int)($basis['id'] ?? 0) ?>" />
                          <input type="hidden" name="returnView" value="<?= h($view) ?>" />
                          <button class="button button-secondary" type="submit"<?= $fortnoxExportError !== '' ? ' disabled' : '' ?>>
                            <?= use_mock_fortnox() ? 'Mockexportera till Fortnox' : 'Exportera till Fortnox' ?>
                          </button>
                        </form>
                        <?php if (use_mock_fortnox()): ?><small class="list-inline-muted">Mockläge är aktivt, ingen skarp faktura skapas.</small><?php endif; ?>
                      </div>
                    <?php endif; ?>
                    <?php if ($basisError !== ''): ?>
                      <div class="inline-alert inline-alert-error"><?= h($basisError) ?></div>
                    <?php elseif ($fortnoxExportError !== ''): ?>
                      <div class="inline-alert inline-alert-error"><?= h($fortnoxExportError) ?></div>
                      <?php if ($fortnoxExportActionUrl !== ''): ?>
                        <p class="list-inline-muted"><a href="<?= h($fortnoxExportActionUrl) ?>">Öppna kund och rätta</a></p>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <p data-live-search-empty<?= $invoiceJobsByView === [] ? '' : ' hidden' ?>><?= $invoiceSearch !== '' ? 'Inget fakturaunderlag matchade din sökning.' : 'Inga jobb i den här vyn.' ?></p>
              </div>
            </article>
          </section>
        <?php elseif ($page === 'settings'): ?>
          <section class="workspace-stack">
            <?php if ($view === 'users'): ?>
              <article class="panel">
                <div class="panel-heading"><h3>Användare</h3><p>Hantera vem som får sälja, planera jobb eller administrera hela systemet.</p></div>
                <div class="stack-md">
                  <?php foreach ($users as $user): ?>
                    <?php $userRegion = ($user['region_id'] ?? null) !== null ? find_region_by_id($data, (int)$user['region_id']) : null; ?>
                    <?php $userOrganization = ($user['organization_id'] ?? null) !== null ? find_organization_by_id($data, (int)$user['organization_id']) : null; ?>
                    <?php $userRoles = normalize_role_list($user['effective_roles'] ?? ($user['role'] ?? USER_ROLE_WORKER)); ?>
                    <div class="list-row">
                      <div>
                        <strong><?= h((string)($user['name'] ?? '')) ?></strong>
                        <p><?= h((string)($user['username'] ?? '')) ?> · <?= h(implode(' / ', array_map('role_label', $userRoles))) ?><?php if ($userOrganization): ?> · <?= h((string)($userOrganization['name'] ?? '')) ?><?php endif; ?><?php if ($userRegion): ?> · <?= h((string)($userRegion['name'] ?? '')) ?><?php endif; ?> · <?= !empty($user['is_active']) ? 'Aktiv' : 'Blockerad' ?></p>
                      </div>
                      <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'users', 'edit_user_id' => (int)($user['id'] ?? 0)])) ?>">Redigera</a>
                    </div>
                  <?php endforeach; ?>
                </div>
              </article>

              <article class="panel">
                <div class="panel-heading">
                  <h3><?= $selectedUser ? 'Redigera användare' : 'Ny användare' ?></h3>
                  <p><?= $selectedUser ? 'Uppdatera namn, roll och lösenord.' : 'Skapa en ny inloggning för säljare, arbetare eller admin.' ?></p>
                </div>
                <?php $userValues = $selectedUser ? $editUserValues : $createUserValues; ?>
                <?php $userErrors = $selectedUser ? $editUserErrors : $createUserErrors; ?>
                <form method="post" class="stack-lg">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="<?= $selectedUser ? 'update_user' : 'create_user' ?>" />
                  <?php if ($selectedUser): ?>
                    <input type="hidden" name="userId" value="<?= (int)$selectedUser['id'] ?>" />
                  <?php endif; ?>
                  <div class="form-grid">
                    <label class="field<?= field_class($userErrors, 'name') ?>">
                      Namn
                      <input type="text" name="name" value="<?= h(field_value($userValues, 'name')) ?>" required />
                      <?= render_field_error($userErrors, 'name') ?>
                    </label>
                    <label class="field<?= field_class($userErrors, 'username') ?>">
                      Användarnamn
                      <input type="text" name="username" value="<?= h(field_value($userValues, 'username')) ?>" required />
                      <?= render_field_error($userErrors, 'username') ?>
                    </label>
                    <label class="field<?= field_class($userErrors, 'organizationId') ?>">
                      Organisation
                      <select name="organizationId">
                        <option value="">Ingen organisation vald</option>
                        <?php foreach ($activeOrganizations as $organization): ?>
                          <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= field_value($userValues, 'organizationId') === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                            <?= h(organization_tree_label($organization)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <?= render_field_error($userErrors, 'organizationId') ?>
                    </label>
                    <label class="field<?= field_class($userErrors, 'regionId') ?>">
                      Region
                      <select name="regionId">
                        <option value="">Ingen region vald</option>
                        <?php foreach ($activeRegions as $region): ?>
                          <option value="<?= (int)($region['id'] ?? 0) ?>"<?= field_value($userValues, 'regionId') === (string)($region['id'] ?? '') ? ' selected' : '' ?>>
                            <?= h((string)($region['name'] ?? '')) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <?= render_field_error($userErrors, 'regionId') ?>
                    </label>
                    <div class="field<?= field_class($userErrors, 'roles') ?><?= field_class($userErrors, 'role') ?>">
                      <span>Roller</span>
                      <input type="hidden" name="role" value="<?= h((string)($userValues['role'] ?? USER_ROLE_WORKER)) ?>" />
                      <label class="checkbox-line"><input type="checkbox" name="roles[]" value="<?= h(USER_ROLE_SALES) ?>"<?= in_array(USER_ROLE_SALES, normalize_role_list($userValues['roles'] ?? []), true) ? ' checked' : '' ?> /> Säljare</label>
                      <label class="checkbox-line"><input type="checkbox" name="roles[]" value="<?= h(USER_ROLE_WORKER) ?>"<?= in_array(USER_ROLE_WORKER, normalize_role_list($userValues['roles'] ?? []), true) ? ' checked' : '' ?> /> Arbetare</label>
                      <label class="checkbox-line"><input type="checkbox" name="roles[]" value="<?= h(USER_ROLE_ADMIN) ?>"<?= in_array(USER_ROLE_ADMIN, normalize_role_list($userValues['roles'] ?? []), true) ? ' checked' : '' ?> /> Admin</label>
                      <?= render_field_error($userErrors, 'roles') ?>
                      <?= render_field_error($userErrors, 'role') ?>
                    </div>
                    <label class="field<?= field_class($userErrors, 'isActive') ?>">
                      Status
                      <select name="isActive">
                        <option value="1"<?= is_selected($userValues, 'isActive', '1') ?>>Aktiv</option>
                        <option value="0"<?= is_selected($userValues, 'isActive', '0') ?>>Blockerad</option>
                      </select>
                      <?= render_field_error($userErrors, 'isActive') ?>
                    </label>
                    <label class="field<?= field_class($userErrors, 'password') ?>">
                      <?= $selectedUser ? 'Nytt lösenord (valfritt)' : 'Lösenord' ?>
                      <input type="password" name="password" />
                      <?= render_field_error($userErrors, 'password') ?>
                    </label>
                    <label class="field<?= field_class($userErrors, 'passwordConfirm') ?>">
                      Bekräfta lösenord
                      <input type="password" name="passwordConfirm" />
                      <?= render_field_error($userErrors, 'passwordConfirm') ?>
                    </label>
                  </div>
                  <div class="form-actions">
                    <button class="button button-primary" type="submit"><?= $selectedUser ? 'Spara användare' : 'Skapa användare' ?></button>
                    <?php if ($selectedUser): ?>
                      <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'users'])) ?>">Avbryt</a>
                    <?php endif; ?>
                  </div>
                </form>
                <?php if ($selectedUser): ?>
                  <div class="form-actions">
                    <form method="post" class="inline-form">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="toggle_user_active" />
                      <input type="hidden" name="userId" value="<?= (int)$selectedUser['id'] ?>" />
                      <input type="hidden" name="isActive" value="<?= !empty($selectedUser['is_active']) ? '0' : '1' ?>" />
                      <button class="button button-secondary" type="submit"><?= !empty($selectedUser['is_active']) ? 'Blockera' : 'Aktivera' ?></button>
                    </form>
                    <form method="post" class="inline-form" onsubmit="return confirm('Vill du verkligen radera användaren?');">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete_user" />
                      <input type="hidden" name="userId" value="<?= (int)$selectedUser['id'] ?>" />
                      <button class="button button-secondary" type="submit">Radera</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php elseif ($view === 'organizations'): ?>
              <article class="panel">
                <div class="panel-heading"><h3>Organisationer</h3><p>Bygg huvudbolag, regionbolag och franchiseenheter som äger kunder, offerter och jobb.</p></div>
                <div class="stack-md">
                  <?php foreach ($organizations as $organization): ?>
                    <?php $organizationRegion = ($organization['region_id'] ?? null) !== null ? find_region_by_id($data, (int)$organization['region_id']) : null; ?>
                    <?php $parentOrganization = ($organization['parent_organization_id'] ?? null) !== null ? find_organization_by_id($data, (int)$organization['parent_organization_id']) : null; ?>
                    <div class="list-row">
                      <div>
                        <strong><?= h(organization_tree_label($organization)) ?></strong>
                        <p><?= h(organization_type_label((string)($organization['organization_type'] ?? ORGANIZATION_TYPE_FRANCHISE_UNIT))) ?><?php if ($parentOrganization): ?> · Under <?= h((string)($parentOrganization['name'] ?? '')) ?><?php endif; ?><?php if ($organizationRegion): ?> · <?= h((string)($organizationRegion['name'] ?? '')) ?><?php endif; ?> · <?= !empty($organization['is_active']) ? 'Aktiv' : 'Inaktiv' ?></p>
                      </div>
                      <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'organizations', 'edit_organization_id' => (int)($organization['id'] ?? 0)])) ?>">Redigera</a>
                    </div>
                  <?php endforeach; ?>
                  <?php if ($organizations === []): ?>
                    <p>Inga organisationer upplagda ännu.</p>
                  <?php endif; ?>
                </div>
              </article>

              <article class="panel">
                <div class="panel-heading">
                  <h3><?= $selectedOrganization ? 'Redigera organisation' : 'Ny organisation' ?></h3>
                  <p><?= $selectedOrganization ? 'Uppdatera namn, struktur och kopplad region.' : 'Skapa en ny huvud-, region- eller franchiseenhet.' ?></p>
                </div>
                <?php $organizationValues = $selectedOrganization ? $editOrganizationValues : $createOrganizationValues; ?>
                <?php $organizationErrors = $selectedOrganization ? $editOrganizationErrors : $createOrganizationErrors; ?>
                <form method="post" class="stack-lg">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="<?= $selectedOrganization ? 'update_organization' : 'create_organization' ?>" />
                  <?php if ($selectedOrganization): ?>
                    <input type="hidden" name="organizationId" value="<?= (int)$selectedOrganization['id'] ?>" />
                  <?php endif; ?>
                  <div class="form-grid">
                    <label class="field<?= field_class($organizationErrors, 'name') ?>">
                      Organisationsnamn
                      <input type="text" name="name" value="<?= h(field_value($organizationValues, 'name')) ?>" required />
                      <?= render_field_error($organizationErrors, 'name') ?>
                    </label>
                    <label class="field<?= field_class($organizationErrors, 'organizationType') ?>">
                      Typ
                      <select name="organizationType">
                        <option value="<?= h(ORGANIZATION_TYPE_HQ) ?>"<?= is_selected($organizationValues, 'organizationType', ORGANIZATION_TYPE_HQ) ?>>Huvudbolag</option>
                        <option value="<?= h(ORGANIZATION_TYPE_REGIONAL_COMPANY) ?>"<?= is_selected($organizationValues, 'organizationType', ORGANIZATION_TYPE_REGIONAL_COMPANY) ?>>Regionbolag</option>
                        <option value="<?= h(ORGANIZATION_TYPE_FRANCHISE_UNIT) ?>"<?= is_selected($organizationValues, 'organizationType', ORGANIZATION_TYPE_FRANCHISE_UNIT) ?>>Franchiseenhet</option>
                      </select>
                      <?= render_field_error($organizationErrors, 'organizationType') ?>
                    </label>
                    <label class="field<?= field_class($organizationErrors, 'parentOrganizationId') ?>">
                      Överordnad organisation
                      <select name="parentOrganizationId">
                        <option value="">Ingen överordnad</option>
                        <?php foreach ($activeOrganizations as $organization): ?>
                          <?php if ($selectedOrganization && (int)($selectedOrganization['id'] ?? 0) === (int)($organization['id'] ?? 0)) { continue; } ?>
                          <option value="<?= (int)($organization['id'] ?? 0) ?>"<?= field_value($organizationValues, 'parentOrganizationId') === (string)($organization['id'] ?? '') ? ' selected' : '' ?>>
                            <?= h(organization_tree_label($organization)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <?= render_field_error($organizationErrors, 'parentOrganizationId') ?>
                    </label>
                    <label class="field<?= field_class($organizationErrors, 'regionId') ?>">
                      Region
                      <select name="regionId">
                        <option value="">Ingen region vald</option>
                        <?php foreach ($regions as $region): ?>
                          <option value="<?= (int)($region['id'] ?? 0) ?>"<?= field_value($organizationValues, 'regionId') === (string)($region['id'] ?? '') ? ' selected' : '' ?>>
                            <?= h((string)($region['name'] ?? '')) ?><?= empty($region['is_active']) ? ' (inaktiv)' : '' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <?= render_field_error($organizationErrors, 'regionId') ?>
                    </label>
                    <label class="field<?= field_class($organizationErrors, 'isActive') ?>">
                      Status
                      <select name="isActive">
                        <option value="1"<?= is_selected($organizationValues, 'isActive', '1') ?>>Aktiv</option>
                        <option value="0"<?= is_selected($organizationValues, 'isActive', '0') ?>>Inaktiv</option>
                      </select>
                      <?= render_field_error($organizationErrors, 'isActive') ?>
                    </label>
                  </div>
                  <div class="form-actions">
                    <button class="button button-primary" type="submit"><?= $selectedOrganization ? 'Spara organisation' : 'Skapa organisation' ?></button>
                    <?php if ($selectedOrganization): ?>
                      <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'organizations'])) ?>">Avbryt</a>
                    <?php endif; ?>
                  </div>
                </form>
                <?php if ($selectedOrganization): ?>
                  <div class="form-actions">
                    <form method="post" class="inline-form" onsubmit="return confirm('Vill du verkligen radera organisationen?');">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete_organization" />
                      <input type="hidden" name="organizationId" value="<?= (int)$selectedOrganization['id'] ?>" />
                      <button class="button button-secondary" type="submit">Radera</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php elseif ($view === 'regions'): ?>
              <article class="panel">
                <div class="panel-heading"><h3>Regioner</h3><p>Skapa regioner som senare kan användas för användare, kunder, jobb och kalenderplanering.</p></div>
                <div class="stack-md">
                  <?php foreach ($regions as $region): ?>
                    <div class="list-row">
                      <div>
                        <strong><?= h((string)($region['name'] ?? '')) ?></strong>
                        <p><?= h((string)($region['slug'] ?? '')) ?> · <?= !empty($region['is_active']) ? 'Aktiv' : 'Inaktiv' ?></p>
                      </div>
                      <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'regions', 'edit_region_id' => (int)($region['id'] ?? 0)])) ?>">Redigera</a>
                    </div>
                  <?php endforeach; ?>
                  <?php if ($regions === []): ?>
                    <p>Inga regioner upplagda ännu.</p>
                  <?php endif; ?>
                </div>
              </article>

              <article class="panel">
                <div class="panel-heading">
                  <h3><?= $selectedRegion ? 'Redigera region' : 'Ny region' ?></h3>
                  <p><?= $selectedRegion ? 'Uppdatera regionens namn och status.' : 'Skapa en ny region för framtida lokal planering.' ?></p>
                </div>
                <?php $regionValues = $selectedRegion ? $editRegionValues : $createRegionValues; ?>
                <?php $regionErrors = $selectedRegion ? $editRegionErrors : $createRegionErrors; ?>
                <form method="post" class="stack-lg">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="<?= $selectedRegion ? 'update_region' : 'create_region' ?>" />
                  <?php if ($selectedRegion): ?>
                    <input type="hidden" name="regionId" value="<?= (int)$selectedRegion['id'] ?>" />
                  <?php endif; ?>
                  <div class="form-grid">
                    <label class="field<?= field_class($regionErrors, 'name') ?>">
                      Regionsnamn
                      <input type="text" name="name" value="<?= h(field_value($regionValues, 'name')) ?>" required />
                      <?= render_field_error($regionErrors, 'name') ?>
                    </label>
                    <label class="field<?= field_class($regionErrors, 'isActive') ?>">
                      Status
                      <select name="isActive">
                        <option value="1"<?= is_selected($regionValues, 'isActive', '1') ?>>Aktiv</option>
                        <option value="0"<?= is_selected($regionValues, 'isActive', '0') ?>>Inaktiv</option>
                      </select>
                      <?= render_field_error($regionErrors, 'isActive') ?>
                    </label>
                  </div>
                  <div class="form-actions">
                    <button class="button button-primary" type="submit"><?= $selectedRegion ? 'Spara region' : 'Skapa region' ?></button>
                    <?php if ($selectedRegion): ?>
                      <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'regions'])) ?>">Avbryt</a>
                    <?php endif; ?>
                  </div>
                </form>
                <?php if ($selectedRegion): ?>
                  <div class="form-actions">
                    <form method="post" class="inline-form" onsubmit="return confirm('Vill du verkligen radera regionen?');">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete_region" />
                      <input type="hidden" name="regionId" value="<?= (int)$selectedRegion['id'] ?>" />
                      <button class="button button-secondary" type="submit">Radera</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php elseif ($view === 'packages'): ?>
              <article class="panel">
                <div class="panel-heading"><h3>Paket</h3><p>Bygg sten- och altanpaket av riktiga produkter och styr hur varje rad ska räknas.</p></div>
                <?php if (!$servicePackagesAvailable): ?>
                  <div class="flash flash-error">Paket-tabellerna finns inte i databasen ännu. Kör uppgraderingen <code>admin/schema/mysql_service_packages_upgrade.sql</code> innan du använder den här vyn.</div>
                <?php endif; ?>
                <form method="get" class="inline-search inline-search-left" data-live-search-form data-auto-submit-form>
                  <input type="hidden" name="page" value="settings" />
                  <input type="hidden" name="view" value="packages" />
                  <input type="search" name="package_q" value="<?= h($packageSearch) ?>" placeholder="Sök paketnamn eller tjänstetyp" data-live-search-input />
                  <button class="button button-secondary" type="submit">Sök</button>
                  <?php if ($packageSearch !== ''): ?>
                    <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'packages'])) ?>">Rensa</a>
                  <?php endif; ?>
                </form>
                <div class="stack-md" data-live-search-list>
                  <?php foreach ($servicePackages as $package): ?>
                    <?php $packageItems = package_items_for_package($data, (int)($package['id'] ?? 0)); ?>
                    <?php $packageSearchText = trim(implode(' ', array_filter([
                        (string)($package['name'] ?? ''),
                        service_family_label((string)($package['service_family'] ?? 'general')),
                        (string)($package['description'] ?? ''),
                        !empty($package['is_active']) ? 'aktivt' : 'inaktivt',
                    ], static fn(string $value): bool => $value !== ''))); ?>
                    <div class="list-card list-card-compact" data-live-search-item data-search-text="<?= h($packageSearchText) ?>">
                      <a class="list-row-main-link" href="<?= h(admin_url('settings', ['view' => 'packages', 'edit_package_id' => (int)($package['id'] ?? 0)]) . '#packages-editor') ?>">
                        <strong><?= h((string)($package['name'] ?? '')) ?></strong>
                        <div class="badge-row">
                          <span class="badge badge-neutral"><?= h(service_family_label((string)($package['service_family'] ?? 'general'))) ?></span>
                          <?php if (!empty($package['is_active']) === false): ?><span class="badge badge-red">Inaktivt</span><?php endif; ?>
                        </div>
                        <p><?= count($packageItems) ?> rader</p>
                        <?php if ((string)($package['description'] ?? '') !== ''): ?><p><?= h((string)$package['description']) ?></p><?php endif; ?>
                      </a>
                    </div>
                  <?php endforeach; ?>
                  <p data-live-search-empty<?= $servicePackages === [] ? '' : ' hidden' ?>><?= $packageSearch !== '' ? 'Inget paket matchade din sökning.' : 'Inga paket upplagda ännu.' ?></p>
                </div>
              </article>

              <article class="panel" id="packages-editor">
                <div class="panel-heading">
                  <h3><?= $selectedPackage ? 'Redigera paket' : 'Nytt paket' ?></h3>
                  <p><?= $selectedPackage ? 'Uppdatera paketets texter och vilka produkter som ska ingå.' : 'Skapa ett nytt tjänstepaket och koppla produkter till det.' ?></p>
                </div>
                <?php $packageValues = $selectedPackage ? $editPackageValues : $createPackageValues; ?>
                <?php $packageErrors = $selectedPackage ? $editPackageErrors : $createPackageErrors; ?>
                <?php $packageItemRows = $selectedPackage ? $editPackageItems : $createPackageItems; ?>
                <form method="post" class="stack-lg">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="<?= $selectedPackage ? 'update_package' : 'create_package' ?>" />
                  <?php if ($selectedPackage): ?>
                    <input type="hidden" name="packageId" value="<?= (int)$selectedPackage['id'] ?>" />
                  <?php endif; ?>
                  <div class="form-grid">
                    <label class="field<?= field_class($packageErrors, 'name') ?>">
                      Paketnamn
                      <input type="text" name="name" value="<?= h(field_value($packageValues, 'name')) ?>" required />
                      <?= render_field_error($packageErrors, 'name') ?>
                    </label>
                    <label class="field<?= field_class($packageErrors, 'serviceFamily') ?>">
                      Tjänstetyp
                      <select name="serviceFamily">
                        <option value="stone"<?= is_selected($packageValues, 'serviceFamily', 'stone') ?>>Sten</option>
                        <option value="deck"<?= is_selected($packageValues, 'serviceFamily', 'deck') ?>>Altan</option>
                        <option value="general"<?= is_selected($packageValues, 'serviceFamily', 'general') ?>>Allmänt</option>
                      </select>
                      <?= render_field_error($packageErrors, 'serviceFamily') ?>
                    </label>
                    <label class="field<?= field_class($packageErrors, 'sortOrder') ?>">
                      Sorteringsordning
                      <input type="number" min="0" step="1" name="sortOrder" value="<?= h(field_value($packageValues, 'sortOrder')) ?>" />
                      <?= render_field_error($packageErrors, 'sortOrder') ?>
                    </label>
                    <label class="field<?= field_class($packageErrors, 'isActive') ?>">
                      Status
                      <select name="isActive">
                        <option value="1"<?= is_selected($packageValues, 'isActive', '1') ?>>Aktivt</option>
                        <option value="0"<?= is_selected($packageValues, 'isActive', '0') ?>>Inaktivt</option>
                      </select>
                      <?= render_field_error($packageErrors, 'isActive') ?>
                    </label>
                    <label class="field field-span-2<?= field_class($packageErrors, 'description') ?>">
                      Beskrivning
                      <textarea name="description" rows="3" placeholder="Kort beskrivning som hjälper säljaren att förstå paketets syfte."><?= h(field_value($packageValues, 'description')) ?></textarea>
                      <?= render_field_error($packageErrors, 'description') ?>
                    </label>
                  </div>

                  <div class="stack-md">
                    <div class="panel-heading">
                      <h4>Paketrader</h4>
                      <p>Välj vilka produkter som ska ingå och hur de ska räknas i paketet.</p>
                    </div>
                    <?php if (isset($packageErrors['packageItems'])): ?>
                      <div class="field-message"><?= h((string)$packageErrors['packageItems']) ?></div>
                    <?php endif; ?>
                    <div class="table-wrap">
                      <table>
                        <thead>
                          <tr>
                            <th>Produkt</th>
                            <th>Modell</th>
                            <th>Antal / faktor</th>
                            <th>Prisjustering</th>
                            <th>Ordning</th>
                            <th>Notering</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($packageItemRows as $row): ?>
                            <tr>
                              <td>
                                <select name="packageItemProductId[]">
                                  <option value="">Välj produkt</option>
                                  <?php foreach ($data['products'] ?? [] as $productOption): ?>
                                    <option value="<?= (int)($productOption['id'] ?? 0) ?>"<?= (string)($row['productId'] ?? '') === (string)($productOption['id'] ?? '') ? ' selected' : '' ?>>
                                      <?= h((string)($productOption['name'] ?? '')) ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </td>
                              <td>
                                <select name="packageItemQuantityMode[]">
                                  <option value="product_default"<?= (string)($row['quantityMode'] ?? '') === 'product_default' ? ' selected' : '' ?>>Produktens standard</option>
                                  <option value="fixed"<?= (string)($row['quantityMode'] ?? '') === 'fixed' ? ' selected' : '' ?>>Fast antal</option>
                                  <option value="per_sqm"<?= (string)($row['quantityMode'] ?? '') === 'per_sqm' ? ' selected' : '' ?>>Per kvm</option>
                                  <option value="per_mil"<?= (string)($row['quantityMode'] ?? '') === 'per_mil' ? ' selected' : '' ?>>Per mil</option>
                                </select>
                              </td>
                              <td><input type="number" min="0.01" step="0.01" name="packageItemQuantityValue[]" value="<?= h((string)($row['quantityValue'] ?? '1')) ?>" /></td>
                              <td><input type="number" min="0" step="0.01" name="packageItemUnitPriceOverride[]" value="<?= h((string)($row['unitPriceOverride'] ?? '')) ?>" placeholder="Tomt = standardpris" /></td>
                              <td><input type="number" min="0" step="1" name="packageItemSortOrder[]" value="<?= h((string)($row['sortOrder'] ?? '0')) ?>" /></td>
                              <td><input type="text" name="packageItemNotes[]" value="<?= h((string)($row['notes'] ?? '')) ?>" placeholder="Valfri intern notering" /></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>

                  <div class="form-actions">
                    <button class="button button-primary" type="submit"<?= !($servicePackagesAvailable && $productsAvailable) ? ' disabled' : '' ?>><?= $selectedPackage ? 'Spara paket' : 'Skapa paket' ?></button>
                    <?php if ($selectedPackage): ?>
                      <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'packages'])) ?>">Avbryt</a>
                    <?php endif; ?>
                  </div>
                </form>
                <?php if ($selectedPackage): ?>
                  <div class="form-actions">
                    <form method="post" class="inline-form" onsubmit="return confirm('Vill du verkligen radera paketet?');">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete_package" />
                      <input type="hidden" name="packageId" value="<?= (int)$selectedPackage['id'] ?>" />
                      <button class="button button-secondary" type="submit"<?= !$servicePackagesAvailable ? ' disabled' : '' ?>>Radera</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php elseif ($view === 'products'): ?>
              <article class="panel">
                <div class="panel-heading"><h3>Produkter & priser</h3><p>Lägg upp produkter, välj prismodell och justera standardpriser direkt från adminen.</p></div>
                <?php if (!$productsAvailable): ?>
                  <div class="flash flash-error">Produkter-tabellen finns inte i databasen ännu. Kör uppgraderingen <code>admin/schema/mysql_products_upgrade.sql</code> innan du använder den här vyn.</div>
                <?php endif; ?>
                <form method="get" class="inline-search inline-search-left" data-live-search-form data-auto-submit-form>
                  <input type="hidden" name="page" value="settings" />
                  <input type="hidden" name="view" value="products" />
                  <input type="search" name="product_q" value="<?= h($productSearch) ?>" placeholder="Sök produkt, kategori eller beskrivning" data-live-search-input />
                  <button class="button button-secondary" type="submit">Sök</button>
                  <?php if ($productSearch !== ''): ?>
                    <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'products'])) ?>">Rensa</a>
                  <?php endif; ?>
                </form>
                <div class="stack-md" data-live-search-list>
                  <?php foreach ($products as $product): ?>
                    <?php $productSearchText = trim(implode(' ', array_filter([
                        (string)($product['name'] ?? ''),
                        (string)($product['category'] ?? ''),
                        (string)($product['description'] ?? ''),
                        product_item_type_label((string)($product['item_type'] ?? 'service')),
                        product_price_model_label((string)($product['price_model'] ?? 'fixed')),
                        !empty($product['is_active']) ? 'aktiv' : 'inaktiv',
                    ], static fn(string $value): bool => $value !== ''))); ?>
                    <div class="list-card list-card-compact" data-live-search-item data-search-text="<?= h($productSearchText) ?>">
                      <a class="list-row-main-link" href="<?= h(admin_url('settings', ['view' => 'products', 'edit_product_id' => (int)($product['id'] ?? 0)]) . '#products-editor') ?>">
                        <strong><?= h((string)($product['name'] ?? '')) ?></strong>
                        <div class="badge-row">
                          <span class="badge <?= h(match ((string)($product['item_type'] ?? 'service')) {
                              'labor' => 'badge-blue',
                              'material' => 'badge-amber',
                              default => 'badge-dark',
                          }) ?>"><?= h(product_item_type_label((string)($product['item_type'] ?? 'service'))) ?></span>
                          <span class="badge badge-neutral"><?= h(product_price_model_label((string)($product['price_model'] ?? 'fixed'))) ?></span>
                          <?php if (!empty($product['is_active']) === false): ?><span class="badge badge-red">Inaktiv</span><?php endif; ?>
                        </div>
                        <p>
                          <?= number_format((float)($product['default_unit_price'] ?? 0), 2, ',', ' ') ?> kr
                          <?php if ((string)($product['unit'] ?? '') !== ''): ?> / <?= h((string)($product['unit'] ?? '')) ?><?php endif; ?>
                          <?php if ((string)($product['category'] ?? '') !== ''): ?> · <?= h((string)$product['category']) ?><?php endif; ?>
                          · används i <?= (int)($productUsageCounts[(int)($product['id'] ?? 0)] ?? 0) ?> paketrad<?= ((int)($productUsageCounts[(int)($product['id'] ?? 0)] ?? 0) === 1) ? '' : 'er' ?>
                        </p>
                        <?php if ((string)($product['description'] ?? '') !== ''): ?><p><?= h((string)$product['description']) ?></p><?php endif; ?>
                      </a>
                    </div>
                  <?php endforeach; ?>
                  <p data-live-search-empty<?= $products === [] ? '' : ' hidden' ?>><?= $productSearch !== '' ? 'Ingen produkt matchade din sökning.' : 'Inga produkter upplagda ännu.' ?></p>
                </div>
              </article>

              <article class="panel" id="products-editor">
                <div class="panel-heading">
                  <h3><?= $selectedProduct ? 'Redigera produkt' : 'Ny produkt' ?></h3>
                  <p><?= $selectedProduct ? 'Uppdatera namn, prismodell och standardvärden.' : 'Skapa en produkt som senare kan användas i paket, kalkyler och offerter.' ?></p>
                </div>
                <?php $productValues = $selectedProduct ? $editProductValues : $createProductValues; ?>
                <?php $productErrors = $selectedProduct ? $editProductErrors : $createProductErrors; ?>
                <form method="post" class="stack-lg">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="<?= $selectedProduct ? 'update_product' : 'create_product' ?>" />
                  <?php if ($selectedProduct): ?>
                    <input type="hidden" name="productId" value="<?= (int)$selectedProduct['id'] ?>" />
                  <?php endif; ?>
                  <div class="form-grid">
                    <label class="field<?= field_class($productErrors, 'name') ?>">
                      Produktnamn
                      <input type="text" name="name" value="<?= h(field_value($productValues, 'name')) ?>" required />
                      <?= render_field_error($productErrors, 'name') ?>
                    </label>
                    <label class="field<?= field_class($productErrors, 'category') ?>">
                      Kategori
                      <input type="text" name="category" value="<?= h(field_value($productValues, 'category')) ?>" placeholder="Till exempel Sten, Altan eller Allmänt" />
                      <?= render_field_error($productErrors, 'category') ?>
                    </label>
                    <label class="field<?= field_class($productErrors, 'itemType') ?>">
                      Produkttyp
                      <select name="itemType">
                        <option value="labor"<?= is_selected($productValues, 'itemType', 'labor') ?>>Arbete</option>
                        <option value="material"<?= is_selected($productValues, 'itemType', 'material') ?>>Material</option>
                        <option value="service"<?= is_selected($productValues, 'itemType', 'service') ?>>Övrigt</option>
                      </select>
                      <?= render_field_error($productErrors, 'itemType') ?>
                    </label>
                    <label class="field<?= field_class($productErrors, 'priceModel') ?>">
                      Prismodell
                      <select name="priceModel">
                        <option value="fixed"<?= is_selected($productValues, 'priceModel', 'fixed') ?>>Fast pris</option>
                        <option value="per_sqm"<?= is_selected($productValues, 'priceModel', 'per_sqm') ?>>Per kvm</option>
                        <option value="per_mil"<?= is_selected($productValues, 'priceModel', 'per_mil') ?>>Per mil</option>
                        <option value="per_unit"<?= is_selected($productValues, 'priceModel', 'per_unit') ?>>Per styck</option>
                      </select>
                      <?= render_field_error($productErrors, 'priceModel') ?>
                    </label>
                    <label class="field<?= field_class($productErrors, 'defaultQuantity') ?>">
                      Standardantal
                      <input type="number" min="0.01" step="0.01" name="defaultQuantity" value="<?= h(field_value($productValues, 'defaultQuantity')) ?>" />
                      <?= render_field_error($productErrors, 'defaultQuantity') ?>
                    </label>
                    <label class="field<?= field_class($productErrors, 'unit') ?>">
                      Enhet
                      <input type="text" name="unit" value="<?= h(field_value($productValues, 'unit')) ?>" placeholder="st, kvm eller mil" />
                      <?= render_field_error($productErrors, 'unit') ?>
                    </label>
                    <label class="field<?= field_class($productErrors, 'defaultUnitPrice') ?>">
                      Standardpris exkl. moms
                      <input type="number" min="0" step="0.01" name="defaultUnitPrice" value="<?= h(field_value($productValues, 'defaultUnitPrice')) ?>" />
                      <?= render_field_error($productErrors, 'defaultUnitPrice') ?>
                    </label>
                    <label class="field<?= field_class($productErrors, 'vatRatePercent') ?>">
                      Moms (%)
                      <input type="number" min="0" max="100" step="0.01" name="vatRatePercent" value="<?= h(field_value($productValues, 'vatRatePercent')) ?>" />
                      <?= render_field_error($productErrors, 'vatRatePercent') ?>
                    </label>
                    <label class="field<?= field_class($productErrors, 'isActive') ?>">
                      Status
                      <select name="isActive">
                        <option value="1"<?= is_selected($productValues, 'isActive', '1') ?>>Aktiv</option>
                        <option value="0"<?= is_selected($productValues, 'isActive', '0') ?>>Inaktiv</option>
                      </select>
                      <?= render_field_error($productErrors, 'isActive') ?>
                    </label>
                    <label class="field field-checkbox<?= field_class($productErrors, 'isRutEligible') ?>">
                      <input type="checkbox" name="isRutEligible" value="1"<?= is_checked($productValues, 'isRutEligible', '1') ?> />
                      RUT-grundande arbete
                      <?= render_field_error($productErrors, 'isRutEligible') ?>
                    </label>
                    <label class="field field-span-2<?= field_class($productErrors, 'description') ?>">
                      Beskrivning
                      <textarea name="description" rows="4" placeholder="Kort intern beskrivning av vad produkten används till."><?= h(field_value($productValues, 'description')) ?></textarea>
                      <?= render_field_error($productErrors, 'description') ?>
                    </label>
                  </div>
                  <div class="form-actions">
                    <button class="button button-primary" type="submit"<?= !$productsAvailable ? ' disabled' : '' ?>><?= $selectedProduct ? 'Spara produkt' : 'Skapa produkt' ?></button>
                    <?php if ($selectedProduct): ?>
                      <a class="button button-secondary" href="<?= h(admin_url('settings', ['view' => 'products'])) ?>">Avbryt</a>
                    <?php endif; ?>
                  </div>
                </form>
                <?php if ($selectedProduct): ?>
                  <div class="form-actions">
                    <form method="post" class="inline-form" onsubmit="return confirm('Vill du verkligen radera produkten?');">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete_product" />
                      <input type="hidden" name="productId" value="<?= (int)$selectedProduct['id'] ?>" />
                      <button class="button button-secondary" type="submit"<?= !$productsAvailable ? ' disabled' : '' ?>>Radera</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php else: ?>
              <article class="panel">
                <div class="panel-heading"><h3>Inställningar</h3><p>Här samlar vi användare, produkter, priser och framtida integrationer.</p></div>
                <p>Välj <strong>Produkter & priser</strong>, <strong>Paket</strong>, <strong>Organisationer</strong>, <strong>Regioner</strong> eller <strong>Användare</strong> i menyn för att administrera systemet.</p>
              </article>
            <?php endif; ?>
          </section>
        <?php else: ?>
          <section class="panel">
            <div class="panel-heading"><h3>Okänd vy</h3><p>Vyn kunde inte hittas.</p></div>
          </section>
        <?php endif; ?>
      </main>
    </div>
    <script>
      window.ADMIN_CONFIG = {
        companyLookupProxyUrl: <?= json_encode('company_lookup_api.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        approveQuoteApiUrl: <?= json_encode('api/approve_quote.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        signQuoteApiUrl: <?= json_encode('api/sign_quote.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        csrfToken: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        mysqlConfigured: <?= mysql_is_configured() ? 'true' : 'false' ?>,
        products: <?= json_encode($data['products'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        servicePackages: <?= json_encode($activeCalcPackages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        servicePackageItems: <?= json_encode($data['service_package_items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      };
    </script>
    <script src="services/companyLookup/BolagsverketProvider.js"></script>
    <script src="services/companyLookup/index.js"></script>
    <script src="app.js"></script>
  </body>
</html>
