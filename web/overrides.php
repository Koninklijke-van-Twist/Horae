<?php

function overrides_fmt_hours($n): string
{
    $f = (float) $n;
    if (abs($f) < 0.00001) {
        return '';
    }
    return str_replace('.', ',', rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.'));
}

function overrides_base_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'overrides';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function overrides_sanitize_project_no(string $projectNo): string
{
    $projectNo = trim($projectNo);
    if ($projectNo === '' || !preg_match('/^[A-Za-z0-9._\-\/]+$/', $projectNo)) {
        throw new InvalidArgumentException('Ongeldig projectnummer');
    }
    return $projectNo;
}

function overrides_sanitize_week_no(int $weekNo): int
{
    if ($weekNo < 1 || $weekNo > 53) {
        throw new InvalidArgumentException('Weeknummer moet tussen 1 en 53 liggen');
    }
    return $weekNo;
}

function overrides_sanitize_year(int $year): int
{
    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Jaar moet tussen 2000 en 2100 liggen');
    }
    return $year;
}

function overrides_file_path(string $projectNo, int $weekNo, int $year = 0): string
{
    $projectNo = overrides_sanitize_project_no($projectNo);
    $weekNo = overrides_sanitize_week_no($weekNo);
    $safeProject = str_replace(['/', '\\'], '_', $projectNo);
    if ($year > 0) {
        overrides_sanitize_year($year);
        return overrides_base_dir() . '/overrides-' . $safeProject . '-' . $year . '-' . $weekNo . '.json';
    }
    return overrides_base_dir() . '/overrides-' . $safeProject . '-' . $weekNo . '.json';
}

function overrides_synthetic_ts_no(string $projectNo, int $weekNo, int $year = 0): string
{
    $projectNo = overrides_sanitize_project_no($projectNo);
    $weekNo = overrides_sanitize_week_no($weekNo);
    if ($year > 0) {
        overrides_sanitize_year($year);
        return 'HORAE-' . $projectNo . '-Y' . $year . '-W' . $weekNo;
    }
    return 'HORAE-' . $projectNo . '-W' . $weekNo;
}

function overrides_parse_synthetic_ts_no(string $tsNo): ?array
{
    if (preg_match('/^HORAE-(.+)-Y(\d{4})-W(\d{1,2})$/', $tsNo, $m)) {
        return [
            'projectNo' => $m[1],
            'year' => (int) $m[2],
            'weekNo' => (int) $m[3],
        ];
    }
    if (preg_match('/^HORAE-(.+)-W(\d{1,2})$/', $tsNo, $m)) {
        return [
            'projectNo' => $m[1],
            'year' => 0,
            'weekNo' => (int) $m[2],
        ];
    }
    return null;
}

function overrides_is_synthetic_ts_no(string $tsNo): bool
{
    return overrides_parse_synthetic_ts_no($tsNo) !== null;
}

function overrides_default_payload(string $projectNo, int $weekNo, ?string $bcTsNo = null, int $year = 0): array
{
    $now = time();
    if ($year > 0) {
        overrides_sanitize_year($year);
    }
    return [
        'version' => 1,
        'projectNo' => $projectNo,
        'year' => $year,
        'weekNo' => $weekNo,
        'tsNo' => overrides_synthetic_ts_no($projectNo, $weekNo, $year),
        'bcTsNo' => $bcTsNo,
        'createdAt' => $now,
        'updatedAt' => $now,
        'overrides' => [],
    ];
}

function overrides_read(string $projectNo, int $weekNo, int $year = 0): ?array
{
    $paths = [];
    if ($year > 0) {
        $paths[] = overrides_file_path($projectNo, $weekNo, $year);
    }
    $paths[] = overrides_file_path($projectNo, $weekNo, 0);

    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }
        $payload = json_decode($raw, true);
        if (is_array($payload)) {
            return $payload;
        }
    }

    return null;
}

function overrides_write(array $payload): void
{
    $projectNo = overrides_sanitize_project_no((string) ($payload['projectNo'] ?? ''));
    $weekNo = overrides_sanitize_week_no((int) ($payload['weekNo'] ?? 0));
    $year = (int) ($payload['year'] ?? 0);
    $path = overrides_file_path($projectNo, $weekNo, $year);

    $payload['projectNo'] = $projectNo;
    $payload['weekNo'] = $weekNo;
    $payload['year'] = $year;
    $payload['tsNo'] = overrides_synthetic_ts_no($projectNo, $weekNo, $year);
    $payload['updatedAt'] = time();
    if (!isset($payload['createdAt'])) {
        $payload['createdAt'] = $payload['updatedAt'];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Overrides konden niet worden opgeslagen');
    }

    $tmp = $path . '.tmp';
    file_put_contents($tmp, $json, LOCK_EX);
    rename($tmp, $path);
}

function overrides_delete_week(string $projectNo, int $weekNo, int $year = 0): bool
{
    $deleted = false;
    $paths = [];
    if ($year > 0) {
        $paths[] = overrides_file_path($projectNo, $weekNo, $year);
    }
    $paths[] = overrides_file_path($projectNo, $weekNo, 0);

    foreach ($paths as $path) {
        if (is_file($path) && @unlink($path)) {
            $deleted = true;
        }
    }

    return $deleted;
}

function overrides_set_value(string $projectNo, int $weekNo, string $key, ?string $value, int $year = 0): array
{
    $payload = overrides_read($projectNo, $weekNo, $year);
    if ($payload === null) {
        $payloadYear = $year > 0 ? $year : (int) date('Y');
        $payload = overrides_default_payload($projectNo, $weekNo, null, $payloadYear);
    }

    if (!isset($payload['overrides']) || !is_array($payload['overrides'])) {
        $payload['overrides'] = [];
    }

    $key = trim($key);
    if ($key === '') {
        throw new InvalidArgumentException('Override-sleutel ontbreekt');
    }

    if ($value === null || $value === '') {
        unset($payload['overrides'][$key]);
    } else {
        $payload['overrides'][$key] = $value;
    }

    overrides_write($payload);
    return $payload;
}

function overrides_is_added_person_key(string $personKey, array $overrides): bool
{
    if (!str_starts_with($personKey, 'horae-add-')) {
        return false;
    }

    $addedKeys = overrides_get_added_row_keys($overrides);
    return in_array($personKey, $addedKeys, true);
}

function overrides_get_added_row_keys(array $overrides): array
{
    $raw = (string) ($overrides['rows.added'] ?? '');
    if ($raw === '') {
        return [];
    }

    $keys = json_decode($raw, true);
    return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
}

function overrides_set_added_row_keys(string $projectNo, int $weekNo, array $keys, int $year = 0): array
{
    $keys = array_values(array_unique(array_filter($keys, fn($k) => is_string($k) && $k !== '')));
    if (count($keys) === 0) {
        return overrides_set_value($projectNo, $weekNo, 'rows.added', null, $year);
    }

    return overrides_set_value($projectNo, $weekNo, 'rows.added', json_encode($keys, JSON_UNESCAPED_UNICODE), $year);
}

function overrides_purge_person_keys(string $projectNo, int $weekNo, string $personKey, int $year = 0): array
{
    $payload = overrides_read($projectNo, $weekNo, $year) ?? overrides_default_payload($projectNo, $weekNo, null, $year);
    $prefix = 'people.' . $personKey . '.';
    foreach (array_keys($payload['overrides'] ?? []) as $key) {
        if (str_starts_with((string) $key, $prefix)) {
            unset($payload['overrides'][$key]);
        }
    }
    overrides_write($payload);
    return $payload;
}

function overrides_build_person_from_overrides(string $personKey, array $overrides, array $report): array
{
    $weekNo = (int) ($report['weekNo'] ?? 0);
    $projectNo = (string) ($report['projectNo'] ?? '');
    $prefix = 'people.' . $personKey . '.';

    $person = [
        'project' => $projectNo,
        'startDate' => null,
        'endDate' => null,
        'key' => $personKey,
        'bsn' => '',
        'name' => '',
        'week' => $weekNo,
        'days' => array_fill(0, 7, 0.0),
        'total' => 0.0,
        'sortYear' => (int) date('Y'),
        'multiYear' => false,
        'isAdded' => true,
        'isDeleted' => false,
    ];

    foreach ($overrides as $key => $value) {
        if (!str_starts_with((string) $key, $prefix)) {
            continue;
        }
        $field = substr((string) $key, strlen($prefix));
        if ($field === '__deleted' || $field === '__added') {
            continue;
        }
        if (preg_match('/^days\.(\d)$/', $field, $m)) {
            $person['days'][(int) $m[1]] = overrides_parse_hours((string) $value);
        } elseif ($field === 'total') {
            $person['total'] = overrides_parse_hours((string) $value);
        } elseif ($field === 'week') {
            $person['week'] = (int) $value;
        } else {
            $person[$field] = (string) $value;
        }
    }

    $person['total'] = array_sum($person['days']);
    return $person;
}

function overrides_apply_row_state(array &$report, array $overrides): void
{
    $addedKeys = overrides_get_added_row_keys($overrides);
    $people = &$report['gridProject']['people'];

    foreach ($people as &$person) {
        $key = (string) ($person['key'] ?? '');
        $person['isAdded'] = in_array($key, $addedKeys, true);
        $person['isDeleted'] = ((string) ($overrides['people.' . $key . '.__deleted'] ?? '')) === '1';
    }
    unset($person);

    $existingKeys = array_map(fn($p) => (string) ($p['key'] ?? ''), $people);
    foreach ($addedKeys as $addKey) {
        if (in_array($addKey, $existingKeys, true)) {
            continue;
        }
        $people[] = overrides_build_person_from_overrides($addKey, $overrides, $report);
    }
}

function overrides_row_add(string $projectNo, int $weekNo, int $year = 0): array
{
    $payload = overrides_read($projectNo, $weekNo, $year) ?? overrides_default_payload($projectNo, $weekNo, null, $year);
    $overrides = is_array($payload['overrides'] ?? null) ? $payload['overrides'] : [];

    $personKey = 'horae-add-' . bin2hex(random_bytes(4));
    $addedKeys = overrides_get_added_row_keys($overrides);
    $addedKeys[] = $personKey;
    overrides_set_added_row_keys($projectNo, $weekNo, $addedKeys, (int) ($payload['year'] ?? $year));
    overrides_set_value($projectNo, $weekNo, 'people.' . $personKey . '.week', (string) $weekNo, (int) ($payload['year'] ?? $year));

    return ['personKey' => $personKey];
}

function overrides_row_delete(string $projectNo, int $weekNo, string $personKey, int $year = 0): array
{
    $personKey = trim($personKey);
    if ($personKey === '') {
        throw new InvalidArgumentException('Rij-sleutel ontbreekt');
    }

    $payload = overrides_read($projectNo, $weekNo, $year) ?? overrides_default_payload($projectNo, $weekNo, null, $year);
    $payloadYear = (int) ($payload['year'] ?? $year);
    $overrides = is_array($payload['overrides'] ?? null) ? $payload['overrides'] : [];

    if (overrides_is_added_person_key($personKey, $overrides)) {
        $addedKeys = array_values(array_filter(
            overrides_get_added_row_keys($overrides),
            fn($k) => $k !== $personKey
        ));
        overrides_set_added_row_keys($projectNo, $weekNo, $addedKeys, $payloadYear);
        overrides_purge_person_keys($projectNo, $weekNo, $personKey, $payloadYear);
        return ['removed' => true, 'restorable' => false];
    }

    overrides_set_value($projectNo, $weekNo, 'people.' . $personKey . '.__deleted', '1', $payloadYear);
    return ['removed' => false, 'restorable' => true];
}

function overrides_row_restore(string $projectNo, int $weekNo, string $personKey, int $year = 0): array
{
    $personKey = trim($personKey);
    if ($personKey === '') {
        throw new InvalidArgumentException('Rij-sleutel ontbreekt');
    }

    $payload = overrides_read($projectNo, $weekNo, $year);
    $payloadYear = (int) ($payload['year'] ?? $year);
    overrides_set_value($projectNo, $weekNo, 'people.' . $personKey . '.__deleted', null, $payloadYear);
    return ['restored' => true];
}

function overrides_list_for_projects(array $projectNos): array
{
    $projectSet = [];
    foreach ($projectNos as $p) {
        $p = trim((string) $p);
        if ($p !== '') {
            $projectSet[$p] = true;
        }
    }

    if (count($projectSet) === 0) {
        return [];
    }

    $dir = overrides_base_dir();
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return [];
    }

    $items = [];
    foreach ($entries as $entry) {
        if (!preg_match('/^overrides-.+-(\d{1,2})\.json$/', $entry, $m)) {
            continue;
        }

        $weekNo = (int) $m[1];
        $path = $dir . '/' . $entry;
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            continue;
        }

        $fileProjectNo = (string) ($payload['projectNo'] ?? '');
        if ($fileProjectNo === '' || !isset($projectSet[$fileProjectNo])) {
            continue;
        }

        $payloadWeek = (int) ($payload['weekNo'] ?? $weekNo);
        $payloadYear = (int) ($payload['year'] ?? 0);
        $items[] = [
            'projectNo' => $fileProjectNo,
            'year' => $payloadYear,
            'week' => $payloadWeek,
            'tsNo' => overrides_synthetic_ts_no($fileProjectNo, $payloadWeek, $payloadYear),
            'isOverrideOnly' => true,
            'start' => $payload['overrides']['weekInfo.start'] ?? null,
            'end' => $payload['overrides']['weekInfo.end'] ?? null,
            'desc' => 'Week ' . $payloadWeek . ($payloadYear > 0 ? ' (' . $payloadYear . ')' : '') . ' (Horae)',
        ];
    }

    return $items;
}

function overrides_apply_to_report(array &$report, array $overrides): void
{
    if (count($overrides) === 0) {
        return;
    }

    foreach ($overrides as $key => $value) {
        overrides_apply_key($report, (string) $key, (string) $value);
    }
}

function overrides_apply_key(array &$report, string $key, string $value): void
{
    if ($key === 'documentStatus') {
        $report['documentStatus'] = $value;
        return;
    }

    if (str_starts_with($key, 'weekInfo.')) {
        $field = substr($key, 9);
        if ($field !== '') {
            $report['weekInfo'][$field] = $value;
        }
        return;
    }

    if (str_starts_with($key, 'contractor.')) {
        $field = substr($key, 11);
        if ($field !== '') {
            $report['contractor'][$field] = $value;
        }
        return;
    }

    if (str_starts_with($key, 'project.')) {
        $field = substr($key, 8);
        if ($field !== '') {
            $report['project'][$field] = $value;
        }
        return;
    }

    if (str_starts_with($key, 'signatures.')) {
        $field = substr($key, 11);
        if ($field !== '') {
            if (!isset($report['signatures']) || !is_array($report['signatures'])) {
                $report['signatures'] = [
                    'hoofdaannemer' => '',
                    'onderaannemer' => '',
                    'uitvoerder' => '',
                ];
            }
            $report['signatures'][$field] = $value;
        }
        return;
    }

    if (str_starts_with($key, 'people.')) {
        $rest = substr($key, 7);
        $parts = explode('.', $rest, 2);
        if (count($parts) !== 2) {
            return;
        }

        [$personKey, $field] = $parts;
        if ($personKey === '' || $field === '') {
            return;
        }

        foreach ($report['gridProject']['people'] as &$person) {
            if (($person['key'] ?? '') !== $personKey) {
                continue;
            }

            if (preg_match('/^days\.(\d)$/', $field, $m)) {
                $dayIdx = (int) $m[1];
                $person['days'][$dayIdx] = overrides_parse_hours($value);
                $person['total'] = array_sum($person['days']);
            } elseif ($field === 'total') {
                $person['total'] = overrides_parse_hours($value);
            } elseif ($field === '__deleted' || $field === '__added') {
                return;
            } else {
                $person[$field] = $value;
            }
            break;
        }
        unset($person);
        return;
    }

    if (str_starts_with($key, 'totals.days.')) {
        if (preg_match('/^totals\.days\.(\d)$/', $key, $m)) {
            $report['totals']['days'][(int) $m[1]] = overrides_parse_hours($value);
        }
        return;
    }

    if ($key === 'totals.all') {
        $report['totals']['all'] = overrides_parse_hours($value);
    }
}

function overrides_parse_hours(string $value): float
{
    $value = trim(str_replace(',', '.', $value));
    if ($value === '') {
        return 0.0;
    }
    return (float) $value;
}

function overrides_collect_original_values(array $report): array
{
    $originals = [];

    $originals['documentStatus'] = (string) ($report['documentStatus'] ?? '');
    $originals['weekInfo.start'] = (string) ($report['weekInfo']['start'] ?? '');
    $originals['weekInfo.end'] = (string) ($report['weekInfo']['end'] ?? '');

    foreach ($report['contractor'] ?? [] as $field => $value) {
        $originals['contractor.' . $field] = (string) $value;
    }

    foreach ($report['projectDisplay'] ?? [] as $field => $value) {
        $originals['project.' . $field] = (string) $value;
    }

    $signatureDefaults = [
        'hoofdaannemer' => '',
        'onderaannemer' => '',
        'uitvoerder' => '',
    ];
    foreach ($signatureDefaults as $field => $default) {
        $originals['signatures.' . $field] = (string) (($report['signatures'] ?? [])[$field] ?? $default);
    }

    foreach ($report['gridProject']['people'] ?? [] as $person) {
        $personKey = (string) ($person['key'] ?? '');
        if ($personKey === '') {
            continue;
        }

        foreach (['bsn', 'name', 'week'] as $field) {
            $originals['people.' . $personKey . '.' . $field] = (string) ($person[$field] ?? '');
        }

        for ($i = 0; $i < 7; $i++) {
            $originals['people.' . $personKey . '.days.' . $i] = overrides_fmt_hours($person['days'][$i] ?? 0);
        }
        $originals['people.' . $personKey . '.total'] = overrides_fmt_hours($person['total'] ?? 0);
    }

    for ($i = 0; $i < 7; $i++) {
        $originals['totals.days.' . $i] = overrides_fmt_hours($report['totals']['days'][$i] ?? 0);
    }
    $originals['totals.all'] = overrides_fmt_hours($report['totals']['all'] ?? 0);

    return $originals;
}

function overrides_send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function overrides_handle_api(string $action): void
{
    if ($action === 'override_get') {
        $projectNo = trim((string) ($_GET['projectNo'] ?? ''));
        $weekNo = (int) ($_GET['weekNo'] ?? 0);
        try {
            overrides_sanitize_project_no($projectNo);
            overrides_sanitize_week_no($weekNo);
        } catch (InvalidArgumentException $e) {
            overrides_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $payload = overrides_read($projectNo, $weekNo);
        overrides_send_json(['ok' => true, 'data' => $payload]);
    }

    if ($action === 'override_save') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            overrides_send_json(['ok' => false, 'error' => 'Ongeldige JSON'], 400);
        }

        $projectNo = trim((string) ($input['projectNo'] ?? ''));
        $weekNo = (int) ($input['weekNo'] ?? 0);
        $year = (int) ($input['year'] ?? 0);
        $key = trim((string) ($input['key'] ?? ''));
        $value = $input['value'] ?? null;
        $reset = !empty($input['reset']);

        try {
            overrides_sanitize_project_no($projectNo);
            overrides_sanitize_week_no($weekNo);
            if ($key === '') {
                throw new InvalidArgumentException('Override-sleutel ontbreekt');
            }

            if ($reset) {
                $payload = overrides_set_value($projectNo, $weekNo, $key, null, $year);
            } else {
                $payload = overrides_set_value($projectNo, $weekNo, $key, $value === null ? null : (string) $value, $year);
            }
        } catch (Throwable $e) {
            overrides_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        overrides_send_json(['ok' => true, 'data' => $payload]);
    }

    if ($action === 'override_create_week') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            overrides_send_json(['ok' => false, 'error' => 'Ongeldige JSON'], 400);
        }

        $projectNo = trim((string) ($input['projectNo'] ?? ''));
        $weekNo = (int) ($input['weekNo'] ?? 0);
        $year = (int) ($input['year'] ?? 0);

        try {
            overrides_sanitize_project_no($projectNo);
            overrides_sanitize_week_no($weekNo);
            overrides_sanitize_year($year);
        } catch (InvalidArgumentException $e) {
            overrides_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $existing = overrides_read($projectNo, $weekNo, $year);
        if ($existing !== null) {
            overrides_send_json([
                'ok' => true,
                'created' => false,
                'tsNo' => overrides_synthetic_ts_no($projectNo, $weekNo, $year),
                'data' => $existing,
            ]);
        }

        $payload = overrides_default_payload($projectNo, $weekNo, null, $year);
        overrides_write($payload);

        overrides_send_json([
            'ok' => true,
            'created' => true,
            'tsNo' => overrides_synthetic_ts_no($projectNo, $weekNo, $year),
            'data' => $payload,
        ]);
    }

    if ($action === 'override_delete_week') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            overrides_send_json(['ok' => false, 'error' => 'Ongeldige JSON'], 400);
        }

        $projectNo = trim((string) ($input['projectNo'] ?? ''));
        $weekNo = (int) ($input['weekNo'] ?? 0);
        $year = (int) ($input['year'] ?? 0);

        try {
            overrides_sanitize_project_no($projectNo);
            overrides_sanitize_week_no($weekNo);
            if ($year > 0) {
                overrides_sanitize_year($year);
            }
            $deleted = overrides_delete_week($projectNo, $weekNo, $year);
            if (!$deleted) {
                throw new InvalidArgumentException('Horae-week niet gevonden');
            }
        } catch (Throwable $e) {
            overrides_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        overrides_send_json(['ok' => true, 'deleted' => true]);
    }

    if ($action === 'override_row_add') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            overrides_send_json(['ok' => false, 'error' => 'Ongeldige JSON'], 400);
        }

        $projectNo = trim((string) ($input['projectNo'] ?? ''));
        $weekNo = (int) ($input['weekNo'] ?? 0);
        $year = (int) ($input['year'] ?? 0);

        try {
            overrides_sanitize_project_no($projectNo);
            overrides_sanitize_week_no($weekNo);
            $result = overrides_row_add($projectNo, $weekNo, $year);
        } catch (Throwable $e) {
            overrides_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        overrides_send_json(['ok' => true, 'reload' => true] + $result);
    }

    if ($action === 'override_row_delete') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            overrides_send_json(['ok' => false, 'error' => 'Ongeldige JSON'], 400);
        }

        $projectNo = trim((string) ($input['projectNo'] ?? ''));
        $weekNo = (int) ($input['weekNo'] ?? 0);
        $year = (int) ($input['year'] ?? 0);
        $personKey = trim((string) ($input['personKey'] ?? ''));

        try {
            overrides_sanitize_project_no($projectNo);
            overrides_sanitize_week_no($weekNo);
            $result = overrides_row_delete($projectNo, $weekNo, $personKey, $year);
        } catch (Throwable $e) {
            overrides_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        overrides_send_json(['ok' => true, 'reload' => true] + $result);
    }

    if ($action === 'override_row_restore') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            overrides_send_json(['ok' => false, 'error' => 'Ongeldige JSON'], 400);
        }

        $projectNo = trim((string) ($input['projectNo'] ?? ''));
        $weekNo = (int) ($input['weekNo'] ?? 0);
        $year = (int) ($input['year'] ?? 0);
        $personKey = trim((string) ($input['personKey'] ?? ''));

        try {
            overrides_sanitize_project_no($projectNo);
            overrides_sanitize_week_no($weekNo);
            $result = overrides_row_restore($projectNo, $weekNo, $personKey, $year);
        } catch (Throwable $e) {
            overrides_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        overrides_send_json(['ok' => true, 'reload' => true] + $result);
    }
}
