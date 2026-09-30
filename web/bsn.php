<?php

// Deliberately separate from odata.php: no disk/session cache or response logging.
function bsn_private_headers(): void
{
    header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Referrer-Policy: no-referrer');
}

function bsn_config(): array
{
    global $bsnGraph;
    $config = [];
    foreach (['TENANT_ID', 'CLIENT_ID', 'CLIENT_SECRET', 'SITE_ID', 'LIST_ID', 'EMPLOYEE_FIELD', 'BSN_FIELD'] as $key) {
        $value = (string) ($bsnGraph[$key] ?? '');
        if (trim($value) === '') {
            throw new RuntimeException('BSN-koppeling is niet volledig geconfigureerd.');
        }
        $config[$key] = $value;
    }
    foreach (['EMPLOYEE_FIELD', 'BSN_FIELD'] as $key) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $config[$key])) {
            throw new RuntimeException('Ongeldige interne SharePoint-kolomnaam.');
        }
    }
    return $config;
}

function bsn_http_json(string $url, array $headers, ?array $form = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Cache-Control: no-store'], $headers),
    ]);
    if ($form !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
    }
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($body === false || $status < 200 || $status >= 300) {
        // Never include Graph responses, URLs, credentials or employee data in errors.
        throw new RuntimeException('BSN ophalen via Graph mislukt (HTTP ' . $status . ').');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Ongeldig antwoord van de BSN-koppeling.');
    }
    return $data;
}

/** Fetch each distinct Resource.No with an indexed equality filter; never scan the list. */
function bsn_lookup(array $resourceNos, ?array $config = null, ?callable $http = null): array
{
    $nos = array_values(array_unique(array_filter(array_map('strval', $resourceNos), fn($no) => $no !== '')));
    if (!$nos) {
        return [];
    }
    $config = $config ?? bsn_config();
    $http = $http ?? 'bsn_http_json';
    $token = $http('https://login.microsoftonline.com/' . rawurlencode($config['TENANT_ID']) . '/oauth2/v2.0/token', [], [
        'client_id' => $config['CLIENT_ID'],
        'client_secret' => $config['CLIENT_SECRET'],
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ]);
    if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '') {
        throw new RuntimeException('BSN-koppeling kon niet aanmelden.');
    }
    $endpoint = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($config['SITE_ID'])
        . '/lists/' . rawurlencode($config['LIST_ID']) . '/items';
    $employeeField = $config['EMPLOYEE_FIELD'];
    $bsnField = $config['BSN_FIELD'];
    $result = [];
    foreach ($nos as $no) {
        $query = http_build_query([
            '$filter' => 'fields/' . $employeeField . " eq '" . str_replace("'", "''", $no) . "'",
            '$expand' => 'fields($select=' . $employeeField . ',' . $bsnField . ')',
            '$select' => 'id',
            '$top' => 2,
        ], '', '&', PHP_QUERY_RFC3986);
        $data = $http($endpoint . '?' . $query, ['Authorization: Bearer ' . $token['access_token']]);
        if (!is_array($data['value'] ?? null)) {
            throw new RuntimeException('Ongeldig antwoord van de BSN-koppeling.');
        }
        // Ambiguous matches must not attach somebody else's BSN. No pagination necessary.
        if (count($data['value']) > 1 || !empty($data['@odata.nextLink'])) {
            throw new RuntimeException('Meerdere SharePoint-items voor hetzelfde personeelsnummer.');
        }
        $fields = $data['value'][0]['fields'] ?? [];
        if ($data['value'] && (string) ($fields[$employeeField] ?? '') !== $no) {
            throw new RuntimeException('SharePoint-personeelsnummer komt niet exact overeen.');
        }
        $value = $fields[$bsnField] ?? '';
        if (!is_string($value) || ($value !== '' && !preg_match('/^[0-9]{9}$/D', $value))) {
            throw new RuntimeException('BSN-kolom moet tekst met negen cijfers bevatten.');
        }
        $result[$no] = $value !== '' ? $value : 'Onbekend';
    }
    return $result;
}

function bsn_enrich_report(array &$report, ?callable $lookup = null): void
{
    $nos = [];
    foreach ($report['gridProject']['people'] as &$person) {
        if (!empty($person['isDeleted'])) {
            $person['bsn'] = '';
            continue;
        }
        if (!empty($person['isAdded'])) {
            $manual = trim((string) ($person['bsn'] ?? ''));
            $person['bsn'] = preg_match('/^[0-9]{9}$/D', $manual) ? $manual : 'Onbekend';
            continue;
        }
        $person['bsn'] = '';
        if ((string) ($person['resourceNo'] ?? '') !== '') {
            $nos[] = (string) $person['resourceNo'];
        }
    }
    unset($person);
    $values = ($lookup ?? 'bsn_lookup')(array_values(array_unique($nos)));
    foreach ($report['gridProject']['people'] as &$person) {
        if (!empty($person['isDeleted']) || !empty($person['isAdded'])) {
            continue;
        }
        $person['bsn'] = $values[$person['resourceNo'] ?? ''] ?? 'Onbekend';
    }
    unset($person);
}
