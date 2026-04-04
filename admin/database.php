<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function mysql_is_configured(): bool
{
    return mysql_host() !== '' && mysql_database() !== '' && mysql_user() !== '';
}

function mysql_dsn(): string
{
    return sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        mysql_host(),
        mysql_port(),
        mysql_database(),
        mysql_charset()
    );
}

function admin_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!mysql_is_configured()) {
        throw new RuntimeException('MySQL är inte konfigurerat.');
    }

    $pdo = new PDO(mysql_dsn(), mysql_user(), mysql_password(), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function mysql_fetch_all_assoc(PDO $pdo, string $table): array
{
    $allowedTables = ['regions', 'organizations', 'organization_memberships', 'users', 'customers', 'quotes', 'quote_items', 'jobs', 'job_items', 'web_quote_requests', 'invoice_bases', 'invoice_base_items', 'products', 'service_packages', 'service_package_items'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Otillåten tabell: ' . $table);
    }

    return $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id ASC')->fetchAll() ?: [];
}

function mysql_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema_name AND table_name = :table_name'
    );
    $statement->execute([
        'schema_name' => mysql_database(),
        'table_name' => $table,
    ]);

    return (int)$statement->fetchColumn() > 0;
}

function mysql_rebuild_quote_sequences(PDO $pdo, array $quotes): void
{
    $pdo->exec('DELETE FROM quote_number_sequences');

    $sequences = [];
    foreach ($quotes as $quote) {
        $quoteNumber = (string)($quote['quote_number'] ?? '');
        if (preg_match('/^(\d{4})-(\d{4,})$/', $quoteNumber, $matches) !== 1) {
            continue;
        }

        $year = (int)$matches[1];
        $sequence = (int)$matches[2];
        $sequences[$year] = max($sequences[$year] ?? 0, $sequence);
    }

    if ($sequences === []) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO quote_number_sequences (year, last_number, updated_at) VALUES (:year, :last_number, NOW())'
    );

    foreach ($sequences as $year => $lastNumber) {
        $statement->execute([
            'year' => $year,
            'last_number' => $lastNumber,
        ]);
    }
}

function load_data_mysql(): array
{
    $pdo = admin_pdo();
    $regions = mysql_table_exists($pdo, 'regions') ? mysql_fetch_all_assoc($pdo, 'regions') : [];
    $organizations = mysql_table_exists($pdo, 'organizations') ? mysql_fetch_all_assoc($pdo, 'organizations') : [];
    $organizationMemberships = mysql_table_exists($pdo, 'organization_memberships') ? mysql_fetch_all_assoc($pdo, 'organization_memberships') : [];
    $products = mysql_table_exists($pdo, 'products') ? mysql_fetch_all_assoc($pdo, 'products') : [];
    $servicePackages = mysql_table_exists($pdo, 'service_packages') ? mysql_fetch_all_assoc($pdo, 'service_packages') : [];
    $servicePackageItems = mysql_table_exists($pdo, 'service_package_items') ? mysql_fetch_all_assoc($pdo, 'service_package_items') : [];

    $data = [
        'regions' => $regions,
        'organizations' => $organizations,
        'organization_memberships' => $organizationMemberships,
        'users' => mysql_fetch_all_assoc($pdo, 'users'),
        'customers' => mysql_fetch_all_assoc($pdo, 'customers'),
        'quotes' => mysql_fetch_all_assoc($pdo, 'quotes'),
        'quote_items' => mysql_fetch_all_assoc($pdo, 'quote_items'),
        'jobs' => mysql_fetch_all_assoc($pdo, 'jobs'),
        'job_items' => mysql_fetch_all_assoc($pdo, 'job_items'),
        'web_quote_requests' => mysql_table_exists($pdo, 'web_quote_requests') ? mysql_fetch_all_assoc($pdo, 'web_quote_requests') : [],
        'invoice_bases' => mysql_fetch_all_assoc($pdo, 'invoice_bases'),
        'invoice_base_items' => mysql_fetch_all_assoc($pdo, 'invoice_base_items'),
        'products' => $products,
        'service_packages' => $servicePackages,
        'service_package_items' => $servicePackageItems,
    ];

    return normalize_data($data);
}

function mysql_insert_rows(PDO $pdo, string $sql, array $rows): void
{
    if ($rows === []) {
        return;
    }

    $statement = $pdo->prepare($sql);
    foreach ($rows as $row) {
        $statement->execute($row);
    }
}

function mysql_split_name(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return ['', ''];
    }

    $parts = preg_split('/\s+/', $name) ?: [$name];
    if (count($parts) === 1) {
        return [$parts[0], ''];
    }

    $firstName = array_shift($parts) ?: '';
    $lastName = trim(implode(' ', $parts));

    return [$firstName, $lastName];
}

function mysql_split_address(string $address): array
{
    $parts = preg_split('/\r\n|\r|\n/', trim($address)) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $line): bool => $line !== ''));

    return [
        $parts[0] ?? trim($address),
        $parts[1] ?? '',
    ];
}

function mysql_bool_flag(mixed $value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value) || is_float($value)) {
        return ((float)$value) !== 0.0 ? 1 : 0;
    }

    $normalized = strtolower(trim((string)$value));

    if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
        return 0;
    }

    return 1;
}

function mysql_nullable_string(mixed $value): ?string
{
    $stringValue = trim((string)$value);

    return $stringValue === '' ? null : $stringValue;
}

function mysql_import_snapshot(array $data): void
{
    $normalized = normalize_data($data);
    $pdo = admin_pdo();

    $pdo->beginTransaction();

    try {
        $pdo->exec('DELETE FROM invoice_base_items');
        $pdo->exec('DELETE FROM invoice_bases');
        $pdo->exec('DELETE FROM web_quote_requests');
        $pdo->exec('DELETE FROM job_items');
        $pdo->exec('DELETE FROM jobs');
        $pdo->exec('DELETE FROM quote_items');
        $pdo->exec('DELETE FROM quotes');
        $pdo->exec('DELETE FROM organization_memberships');
        $pdo->exec('DELETE FROM customers');
        $pdo->exec('DELETE FROM users');
        $pdo->exec('DELETE FROM organizations');
        $pdo->exec('DELETE FROM regions');

        mysql_insert_rows(
            $pdo,
            'INSERT INTO regions (id, name, slug, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :is_active, :created_at, :updated_at)',
            $normalized['regions'] ?? []
        );

        mysql_insert_rows(
            $pdo,
            'INSERT INTO organizations (id, name, slug, organization_type, parent_organization_id, region_id, service_postcode_prefixes, service_cities, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :organization_type, :parent_organization_id, :region_id, :service_postcode_prefixes, :service_cities, :is_active, :created_at, :updated_at)',
            $normalized['organizations'] ?? []
        );

        mysql_insert_rows(
            $pdo,
            'INSERT INTO users (id, username, name, role, organization_id, region_id, is_active, password_hash, created_at, updated_at)
             VALUES (:id, :username, :name, :role, :organization_id, :region_id, :is_active, :password_hash, :created_at, :updated_at)',
            array_map(static function (array $user): array {
                $user['organization_id'] = ($user['organization_id'] ?? null) !== null ? (int)$user['organization_id'] : null;
                $user['region_id'] = ($user['region_id'] ?? null) !== null ? (int)$user['region_id'] : null;
                return $user;
            }, $normalized['users'])
        );

        mysql_insert_rows(
            $pdo,
            'INSERT INTO organization_memberships (id, user_id, organization_id, role, is_primary, created_at, updated_at)
             VALUES (:id, :user_id, :organization_id, :role, :is_primary, :created_at, :updated_at)',
            $normalized['organization_memberships'] ?? []
        );

        mysql_insert_rows(
            $pdo,
            'INSERT INTO customers (
                id, customer_type, billing_vat_mode, name, organization_id, region_id, first_name, last_name, company_name, association_name, contact_person,
                phone, email, billing_address_1, billing_address_2, billing_postcode, billing_city,
                property_address_1, property_address_2, property_postcode, property_city, property_designation,
                personal_number, organization_number, vat_number,
                rut_enabled, rut_used_amount_this_year, notes, created_at, updated_at, last_activity
             ) VALUES (
                :id, :customer_type, :billing_vat_mode, :name, :organization_id, :region_id, :first_name, :last_name, :company_name, :association_name, :contact_person,
                :phone, :email, :billing_address_1, :billing_address_2, :billing_postcode, :billing_city,
                :property_address_1, :property_address_2, :property_postcode, :property_city, :property_designation,
                :personal_number, :organization_number, :vat_number,
                :rut_enabled, :rut_used_amount_this_year, :notes, :created_at, :updated_at, :last_activity
             )',
            array_map(static function (array $customer): array {
                [$firstName, $lastName] = mysql_split_name((string)($customer['name'] ?? ''));
                [$billingAddress1, $billingAddress2] = mysql_split_address((string)($customer['billing_address'] ?? ''));
                [$propertyAddress1, $propertyAddress2] = mysql_split_address((string)($customer['service_address'] ?? $customer['address'] ?? ''));

                return [
                    'id' => $customer['id'],
                    'customer_type' => $customer['customer_type'],
                    'billing_vat_mode' => $customer['billing_vat_mode'],
                    'name' => $customer['name'],
                    'organization_id' => ($customer['organization_id'] ?? null) !== null ? (int)$customer['organization_id'] : null,
                    'region_id' => ($customer['region_id'] ?? null) !== null ? (int)$customer['region_id'] : null,
                    'first_name' => $customer['customer_type'] === 'private' ? $firstName : null,
                    'last_name' => $customer['customer_type'] === 'private' ? $lastName : null,
                    'company_name' => $customer['company_name'],
                    'association_name' => $customer['association_name'],
                    'contact_person' => $customer['contact_person'],
                    'phone' => $customer['phone'],
                    'email' => $customer['email'],
                    'billing_address_1' => $billingAddress1,
                    'billing_address_2' => $billingAddress2,
                    'billing_postcode' => $customer['billing_postal_code'],
                    'billing_city' => $customer['billing_city'],
                    'property_address_1' => $propertyAddress1,
                    'property_address_2' => $propertyAddress2,
                    'property_postcode' => $customer['service_postal_code'],
                    'property_city' => $customer['service_city'],
                    'property_designation' => (string)($customer['property_designation'] ?? ''),
                    'personal_number' => $customer['personal_number'],
                    'organization_number' => $customer['organization_number'],
                    'vat_number' => $customer['vat_number'],
                    'rut_enabled' => mysql_bool_flag($customer['rut_enabled'] ?? 0),
                    'rut_used_amount_this_year' => to_float($customer['rut_used_amount_this_year'] ?? 0),
                    'notes' => $customer['notes'],
                    'created_at' => $customer['created_at'],
                    'updated_at' => $customer['updated_at'],
                    'last_activity' => $customer['last_activity'],
                ];
            }, $normalized['customers'])
        );

        $quoteRows = array_map(static function (array $quote): array {
            $year = null;
            $sequence = null;
            if (preg_match('/^(\d{4})-(\d{4,})$/', (string)($quote['quote_number'] ?? ''), $matches) === 1) {
                $year = (int)$matches[1];
                $sequence = (int)$matches[2];
            }

	            $quote['quote_year'] = $year;
	            $quote['quote_sequence'] = $sequence;
                $quote['is_rut_job'] = mysql_bool_flag($quote['is_rut_job'] ?? 0);
                $quote['issue_date'] = mysql_nullable_string($quote['issue_date'] ?? null);
                $quote['valid_until'] = mysql_nullable_string($quote['valid_until'] ?? null);
                $quote['approved_at'] = mysql_nullable_string($quote['approved_at'] ?? null);
                $quote['converted_to_job_at'] = mysql_nullable_string($quote['converted_to_job_at'] ?? null);

	            return $quote;
	        }, $normalized['quotes']);

        mysql_insert_rows(
            $pdo,
            'INSERT INTO quotes (
                id, customer_id, organization_id, quote_year, quote_sequence, quote_number, created_by_username, status, issue_date, valid_until, work_description,
                service_type, description,
                labor_amount_ex_vat, material_amount_ex_vat, other_amount_ex_vat,
                subtotal, vat_rate, vat_amount, total_amount_ex_vat, total_amount_inc_vat,
                rut_amount, amount_after_rut, total_amount, is_rut_job, reverse_charge_text,
                approved_at, converted_to_job_at, notes, created_at, updated_at
             ) VALUES (
                :id, :customer_id, :organization_id, :quote_year, :quote_sequence, :quote_number, :created_by_username, :status, :issue_date, :valid_until, :work_description,
                :service_type, :description,
                :labor_amount_ex_vat, :material_amount_ex_vat, :other_amount_ex_vat,
                :subtotal, :vat_rate, :vat_amount, :total_amount_ex_vat, :total_amount_inc_vat,
                :rut_amount, :amount_after_rut, :total_amount, :is_rut_job, :reverse_charge_text,
                :approved_at, :converted_to_job_at, :notes, :created_at, :updated_at
             )',
            $quoteRows
        );

        $quoteItems = array_map(static function (array $item): array {
            return [
                'id' => $item['id'],
                'quote_id' => $item['quote_id'],
                'sort_order' => $item['sort_order'],
                'item_type' => $item['item_type'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'vat_rate' => $item['vat_rate'],
                'is_rut_eligible' => mysql_bool_flag($item['is_rut_eligible'] ?? 0),
                'line_total' => $item['line_total'],
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at'],
            ];
        }, $normalized['quote_items'] ?? []);

        mysql_insert_rows(
            $pdo,
            'INSERT INTO quote_items (
                id, quote_id, sort_order, item_type, description, quantity, unit,
                unit_price, vat_rate, is_rut_eligible, line_total, created_at, updated_at
             ) VALUES (
                :id, :quote_id, :sort_order, :item_type, :description, :quantity, :unit,
                :unit_price, :vat_rate, :is_rut_eligible, :line_total, :created_at, :updated_at
             )',
            $quoteItems
        );

        mysql_insert_rows(
            $pdo,
            'INSERT INTO jobs (
                id, customer_id, quote_id, organization_id, region_id, status, planned_start_date, planned_end_date, completed_at,
                work_address_1, work_address_2, work_postcode, work_city, internal_notes, customer_notes,
                service_type, description, scheduled_date, scheduled_time, completed_date,
                assigned_to, final_labor_amount_ex_vat, final_material_amount_ex_vat,
                final_other_amount_ex_vat, final_vat_rate, final_vat_amount,
                final_total_amount_ex_vat, final_total_amount_inc_vat, final_rut_amount,
                final_reverse_charge_text, ready_for_invoicing, invoice_status, notes, created_at, updated_at
             ) VALUES (
                :id, :customer_id, :quote_id, :organization_id, :region_id, :status, :planned_start_date, :planned_end_date, :completed_at,
                :work_address_1, :work_address_2, :work_postcode, :work_city, :internal_notes, :customer_notes,
                :service_type, :description, :scheduled_date, :scheduled_time, :completed_date,
                :assigned_to, :final_labor_amount_ex_vat, :final_material_amount_ex_vat,
                :final_other_amount_ex_vat, :final_vat_rate, :final_vat_amount,
                :final_total_amount_ex_vat, :final_total_amount_inc_vat, :final_rut_amount,
                :final_reverse_charge_text, :ready_for_invoicing, :invoice_status, :notes, :created_at, :updated_at
            )',
            array_map(static function (array $job): array {
                return [
                    'id' => $job['id'],
                    'customer_id' => $job['customer_id'],
                    'quote_id' => $job['quote_id'],
                    'organization_id' => ($job['organization_id'] ?? null) !== null ? (int)$job['organization_id'] : null,
                    'region_id' => ($job['region_id'] ?? null) !== null ? (int)$job['region_id'] : null,
                    'status' => $job['status'],
                    'planned_start_date' => mysql_nullable_string($job['planned_start_date'] ?? null),
                    'planned_end_date' => mysql_nullable_string($job['planned_end_date'] ?? null),
                    'completed_at' => mysql_nullable_string($job['completed_at'] ?? null),
                    'work_address_1' => $job['work_address_1'],
                    'work_address_2' => $job['work_address_2'],
                    'work_postcode' => $job['work_postcode'],
                    'work_city' => $job['work_city'],
                    'internal_notes' => $job['internal_notes'],
                    'customer_notes' => $job['customer_notes'],
                    'service_type' => $job['service_type'],
                    'description' => $job['description'],
                    'scheduled_date' => mysql_nullable_string($job['scheduled_date'] ?? null),
                    'scheduled_time' => mysql_nullable_string($job['scheduled_time'] ?? null),
                    'completed_date' => mysql_nullable_string($job['completed_date'] ?? null),
                    'assigned_to' => $job['assigned_to'],
                    'final_labor_amount_ex_vat' => $job['final_labor_amount_ex_vat'],
                    'final_material_amount_ex_vat' => $job['final_material_amount_ex_vat'],
                    'final_other_amount_ex_vat' => $job['final_other_amount_ex_vat'],
                    'final_vat_rate' => $job['final_vat_rate'],
                    'final_vat_amount' => $job['final_vat_amount'],
                    'final_total_amount_ex_vat' => $job['final_total_amount_ex_vat'],
                    'final_total_amount_inc_vat' => $job['final_total_amount_inc_vat'],
                    'final_rut_amount' => $job['final_rut_amount'],
                    'final_reverse_charge_text' => $job['final_reverse_charge_text'],
                    'ready_for_invoicing' => mysql_bool_flag($job['ready_for_invoicing'] ?? 0),
                    'invoice_status' => $job['invoice_status'] ?? null,
                    'notes' => $job['notes'],
                    'created_at' => $job['created_at'],
                    'updated_at' => $job['updated_at'],
                ];
            }, $normalized['jobs'])
        );

        $jobItems = array_map(static function (array $item): array {
            return [
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
                'is_rut_eligible' => mysql_bool_flag($item['is_rut_eligible'] ?? 0),
                'line_total' => $item['line_total'],
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at'],
            ];
        }, $normalized['job_items'] ?? []);

        mysql_insert_rows(
            $pdo,
            'INSERT INTO job_items (
                id, job_id, quote_item_id, sort_order, item_type, description, quantity, unit,
                unit_price, vat_rate, is_rut_eligible, line_total, created_at, updated_at
             ) VALUES (
                :id, :job_id, :quote_item_id, :sort_order, :item_type, :description, :quantity, :unit,
                :unit_price, :vat_rate, :is_rut_eligible, :line_total, :created_at, :updated_at
             )',
            $jobItems
        );

        $invoiceBases = array_map(static function (array $basis): array {
            return [
                'id' => $basis['id'],
                'customer_id' => $basis['customer_id'],
                'job_id' => $basis['job_id'],
                'quote_id' => $basis['quote_id'],
                'organization_id' => ($basis['organization_id'] ?? null) !== null ? (int)$basis['organization_id'] : null,
                'status' => $basis['status'],
                'quote_number' => $basis['quote_number'],
                'customer_type' => $basis['customer_type'],
                'billing_vat_mode' => $basis['billing_vat_mode'],
                'invoice_customer_name' => $basis['invoice_customer_name'],
                'contact_person' => $basis['contact_person'],
                'personal_number' => $basis['personal_number'],
                'organization_number' => $basis['organization_number'],
                'email' => $basis['email'],
                'phone' => $basis['phone'],
                'service_address' => $basis['service_address'],
                'service_postal_code' => $basis['service_postal_code'],
                'service_city' => $basis['service_city'],
                'invoice_address_1' => $basis['invoice_address_1'],
                'invoice_address_2' => $basis['invoice_address_2'],
                'invoice_postcode' => $basis['invoice_postcode'],
                'invoice_city' => $basis['invoice_city'],
                'invoice_date' => mysql_nullable_string($basis['invoice_date'] ?? null),
                'due_date' => mysql_nullable_string($basis['due_date'] ?? null),
                'service_type' => $basis['service_type'],
                'description' => $basis['description'],
                'subtotal' => $basis['subtotal'],
                'labor_amount_ex_vat' => $basis['labor_amount_ex_vat'],
                'material_amount_ex_vat' => $basis['material_amount_ex_vat'],
                'other_amount_ex_vat' => $basis['other_amount_ex_vat'],
                'total_amount_ex_vat' => $basis['total_amount_ex_vat'],
                'rut_enabled' => mysql_bool_flag($basis['rut_enabled'] ?? 0),
                'rut_basis_amount' => $basis['rut_basis_amount'],
                'rut_amount' => $basis['rut_amount'],
                'vat_amount' => $basis['vat_amount'],
                'total_amount_inc_vat' => $basis['total_amount_inc_vat'],
                'amount_to_pay' => $basis['amount_to_pay'],
                'reverse_charge_text' => $basis['reverse_charge_text'],
                'ready_for_invoicing' => mysql_bool_flag($basis['ready_for_invoicing'] ?? 0),
                'fortnox_customer_number' => mysql_nullable_string($basis['fortnox_customer_number'] ?? null),
                'fortnox_document_number' => mysql_nullable_string($basis['fortnox_document_number'] ?? null),
                'fortnox_invoice_number' => mysql_nullable_string($basis['fortnox_invoice_number'] ?? null),
                'fortnox_last_sync_at' => mysql_nullable_string($basis['fortnox_last_sync_at'] ?? null),
                'fortnox_sync_error' => mysql_nullable_string($basis['fortnox_sync_error'] ?? null),
                'export_error' => mysql_nullable_string($basis['export_error'] ?? null),
                'exported_at' => mysql_nullable_string($basis['exported_at'] ?? null),
                'created_at' => $basis['created_at'],
                'updated_at' => $basis['updated_at'],
            ];
        }, $normalized['invoice_bases'] ?? []);

        mysql_insert_rows(
            $pdo,
            'INSERT INTO web_quote_requests (
                id, name, phone, email, service_address, service_postcode, service_city, message, source_page,
                region_id, requested_region_name, organization_id, assignment_basis, status, handled_by_username, handled_at, created_at, updated_at
            ) VALUES (
                :id, :name, :phone, :email, :service_address, :service_postcode, :service_city, :message, :source_page,
                :region_id, :requested_region_name, :organization_id, :assignment_basis, :status, :handled_by_username, :handled_at, :created_at, :updated_at
            )',
            array_map(static function (array $request): array {
                $request['region_id'] = ($request['region_id'] ?? null) !== null ? (int)$request['region_id'] : null;
                $request['organization_id'] = ($request['organization_id'] ?? null) !== null ? (int)$request['organization_id'] : null;
                return $request;
            }, $normalized['web_quote_requests'] ?? [])
        );

        mysql_insert_rows(
            $pdo,
            'INSERT INTO invoice_bases (
                id, customer_id, job_id, quote_id, organization_id, status, quote_number, customer_type, billing_vat_mode,
                invoice_customer_name, contact_person, personal_number, organization_number, email, phone,
                service_address, service_postal_code, service_city,
                invoice_address_1, invoice_address_2, invoice_postcode, invoice_city,
                invoice_date, due_date, service_type, description,
                subtotal, labor_amount_ex_vat, material_amount_ex_vat, other_amount_ex_vat, total_amount_ex_vat,
                rut_enabled, rut_basis_amount, rut_amount, vat_amount, total_amount_inc_vat, amount_to_pay,
                reverse_charge_text, ready_for_invoicing, fortnox_customer_number, fortnox_document_number,
                fortnox_invoice_number, fortnox_last_sync_at, fortnox_sync_error, export_error, exported_at, created_at, updated_at
             ) VALUES (
                :id, :customer_id, :job_id, :quote_id, :organization_id, :status, :quote_number, :customer_type, :billing_vat_mode,
                :invoice_customer_name, :contact_person, :personal_number, :organization_number, :email, :phone,
                :service_address, :service_postal_code, :service_city,
                :invoice_address_1, :invoice_address_2, :invoice_postcode, :invoice_city,
                :invoice_date, :due_date, :service_type, :description,
                :subtotal, :labor_amount_ex_vat, :material_amount_ex_vat, :other_amount_ex_vat, :total_amount_ex_vat,
                :rut_enabled, :rut_basis_amount, :rut_amount, :vat_amount, :total_amount_inc_vat, :amount_to_pay,
                :reverse_charge_text, :ready_for_invoicing, :fortnox_customer_number, :fortnox_document_number,
                :fortnox_invoice_number, :fortnox_last_sync_at, :fortnox_sync_error, :export_error, :exported_at, :created_at, :updated_at
             )',
            $invoiceBases
        );

        $invoiceBaseItems = array_map(static function (array $item): array {
            return [
                'id' => $item['id'],
                'invoice_base_id' => $item['invoice_base_id'],
                'job_item_id' => $item['job_item_id'],
                'sort_order' => $item['sort_order'],
                'item_type' => $item['item_type'] ?? 'service',
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'vat_rate' => $item['vat_rate'],
                'is_rut_eligible' => mysql_bool_flag($item['is_rut_eligible'] ?? 0),
                'line_total' => $item['line_total'],
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at'],
            ];
        }, $normalized['invoice_base_items'] ?? []);

        mysql_insert_rows(
            $pdo,
            'INSERT INTO invoice_base_items (
                id, invoice_base_id, job_item_id, sort_order, item_type, description, quantity, unit,
                unit_price, vat_rate, is_rut_eligible, line_total, created_at, updated_at
             ) VALUES (
                :id, :invoice_base_id, :job_item_id, :sort_order, :item_type, :description, :quantity, :unit,
                :unit_price, :vat_rate, :is_rut_eligible, :line_total, :created_at, :updated_at
             )',
            $invoiceBaseItems
        );

        mysql_rebuild_quote_sequences($pdo, $quoteRows);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function save_data_mysql(array $data): void
{
    throw new RuntimeException('save_data_mysql() är avvecklad. Använd per-entity writes eller mysql_import_snapshot() för engångsmigrering.');
}
