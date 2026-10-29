<?php
require_once __DIR__ . '/kvt_protect_dirs.php';
require_once __DIR__ . '/bsn.php';

/** Graph-credentials voor de naamlookup: eerst $nameGraph (camelCase), dan $bsnGraph. */
function horae_entra_configs(): array
{
    $out = [];
    foreach ([[$GLOBALS['nameGraph'] ?? [], 'tenantId', 'clientId', 'clientSecret'], [$GLOBALS['bsnGraph'] ?? [], 'TENANT_ID', 'CLIENT_ID', 'CLIENT_SECRET']] as [$c, $t, $i, $s]) {
        if (is_array($c) && !empty($c[$t]) && !empty($c[$i]) && !empty($c[$s])) {
            $out[] = ['tenantId' => (string) $c[$t], 'clientId' => (string) $c[$i], 'clientSecret' => (string) $c[$s]];
        }
    }
    return $out;
}

/**
 * Voor- en achternaam uit Entra (Graph users: givenName/surname), zoals Asclepius:
 * client_credentials-token (Application-permissie User.Read.All), 24 uur filecache.
 * Volgorde: AppResource.E_Mail (@kvt.nl; mail of userPrincipalName), dan KVT_User_ID zonder 'KVT\'
 * als onPremisesSamAccountName. Geen config/rechten, Graph-fout of geen unieke match: null (parser).
 */
function horae_entra_lookup(array $res, ?callable $http = null, ?array $configs = null, ?string $cacheFile = null): ?array
{
    static $tokens = [], $failed = [], $mem = [];
    $cacheFile = $cacheFile ?? __DIR__ . '/cache/entra_names.json';
    $keys = [];
    $mail = strtolower(trim((string) ($res['E_Mail'] ?? '')));
    if (filter_var($mail, FILTER_VALIDATE_EMAIL) && str_ends_with($mail, '@kvt.nl')) {
        $q = str_replace("'", "''", $mail);
        $keys['mail:' . $mail] = "mail eq '$q' or userPrincipalName eq '$q'";
    }
    $sam = preg_replace('/^KVT\\\\/i', '', trim((string) ($res['KVT_User_ID'] ?? '')));
    if (preg_match('/^[A-Za-z0-9._-]+$/D', $sam)) {
        $keys['sam:' . strtolower($sam)] = "onPremisesSamAccountName eq '$sam'";
    }
    if (!$keys || isset($failed[$cacheFile])) {
        return null;
    }
    $http = $http ?? 'bsn_http_json';
    $configs = $configs ?? horae_entra_configs();
    if (!isset($mem[$cacheFile])) {
        $mem[$cacheFile] = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];
    }
    $cache = &$mem[$cacheFile];
    foreach ($keys as $key => $filter) {
        if (!isset($cache[$key]) || $cache[$key]['t'] < time() - 86400) {
            $data = null;
            foreach ($configs as $c) {
                $id = $c['tenantId'] . '|' . $c['clientId'];
                try {
                    if (($tokens[$id]['exp'] ?? 0) <= time() + 60) {
                        $t = $http('https://login.microsoftonline.com/' . rawurlencode($c['tenantId']) . '/oauth2/v2.0/token', [], [
                            'client_id' => $c['clientId'],
                            'client_secret' => $c['clientSecret'],
                            'scope' => 'https://graph.microsoft.com/.default',
                            'grant_type' => 'client_credentials',
                        ]);
                        $tokens[$id] = ['token' => (string) ($t['access_token'] ?? ''), 'exp' => time() + (int) ($t['expires_in'] ?? 3600)];
                    }
                    $data = $http('https://graph.microsoft.com/v1.0/users?' . http_build_query(['$filter' => $filter, '$select' => 'givenName,surname', '$count' => 'true', '$top' => 2], '', '&', PHP_QUERY_RFC3986),
                        ['Authorization: Bearer ' . $tokens[$id]['token'], 'ConsistencyLevel: eventual']);
                    break;
                } catch (Throwable $e) {
                    unset($tokens[$id]);
                    error_log('Horae Entra-naamlookup: ' . $e->getMessage());
                    if (!preg_match('/HTTP 40[013]\b/', $e->getMessage())) {
                        break;
                    }
                }
            }
            if ($data === null) {
                $configs ?: error_log('Horae Entra-naamlookup: geen Graph-config, parser gebruikt.');
                $failed[$cacheFile] = true;
                return null;
            }
            $u = count($data['value'] ?? []) === 1 ? $data['value'][0] : [];
            $cache[$key] = ['t' => time(), 'g' => trim((string) ($u['givenName'] ?? '')), 's' => trim((string) ($u['surname'] ?? ''))];
            @file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
        }
        if ($cache[$key]['g'] !== '' && $cache[$key]['s'] !== '') {
            return ['givenName' => $cache[$key]['g'], 'surname' => $cache[$key]['s']];
        }
    }
    return null;
}
