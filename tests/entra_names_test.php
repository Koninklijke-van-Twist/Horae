<?php
/**
 * Namen uit Entra met gemockte Graph. Run: php tests/entra_names_test.php
 */

require dirname(__DIR__) . '/web/grid.php';
ini_set('error_log', '/dev/null');

function check($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $message expected " . json_encode($expected) . ' got ' . json_encode($actual) . "\n");
        exit(1);
    }
}

$users = [
    "mail eq 'dholwerda@kvt.nl' or userPrincipalName eq 'dholwerda@kvt.nl'" => ['givenName' => 'Dirk', 'surname' => 'Holwerda'],
    "mail eq 'rdprenter@kvt.nl' or userPrincipalName eq 'rdprenter@kvt.nl'" => ['givenName' => 'Ronald', 'surname' => 'de Prenter'],
    "mail eq 'jnoordermeer@kvt.nl' or userPrincipalName eq 'jnoordermeer@kvt.nl'" => ['givenName' => 'Jan Cees', 'surname' => 'Noordermeer'],
    "onPremisesSamAccountName eq 'EVHERK'" => ['givenName' => 'Erik', 'surname' => 'van Herk'],
];
$calls = [];
$mock = function (string $url, array $headers, ?array $form = null) use (&$calls, $users) {
    if ($form !== null) {
        $calls[] = 'token:' . $form['client_id'];
        if ($form['client_id'] === 'denied') {
            throw new RuntimeException('BSN ophalen via Graph mislukt (HTTP 401).');
        }
        return ['access_token' => 'tok', 'expires_in' => 3600];
    }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $calls[] = $q['$filter'];
    check(true, in_array('ConsistencyLevel: eventual', $headers, true), 'ConsistencyLevel header');
    return ['value' => isset($users[$q['$filter']]) ? [$users[$q['$filter']]] : []];
};
$cfg = [['tenantId' => 't', 'clientId' => 'denied', 'clientSecret' => 's'], ['tenantId' => 't', 'clientId' => 'ok', 'clientSecret' => 's']];
$cache = tempnam(sys_get_temp_dir(), 'entra');
unlink($cache);
$name = function (array $res) use ($mock, $cfg, $cache) {
    $e = horae_entra_lookup($res, $mock, $cfg, $cache);
    return $e ? horae_display_name($e['surname'] . ', ' . $e['givenName']) : horae_display_name((string) $res['Name']);
};

check('Holwerda, D.', $name(['Name' => 'Holwerda, D.H.K.', 'E_Mail' => 'dholwerda@kvt.nl']), '1358 via E_Mail');
check(['token:denied', 'token:ok'], array_slice($calls, 0, 2), '401 op eerste config: tweede config');
check('Prenter de, R.', $name(['Name' => 'x', 'E_Mail' => 'RDPrenter@kvt.nl']), '1664 tussenvoegsel uit surname achteraan');
check('Noordermeer, J.C.', $name(['Name' => 'x', 'E_Mail' => 'jnoordermeer@kvt.nl']), '1606 meerdere voornamen');
check('Herk van, E.', $name(['Name' => 'Herk E. van', 'E_Mail' => '', 'KVT_User_ID' => 'KVT\\EVHERK']), '1332 via KVT_User_ID');
check('Herk van, E.', $name(['Name' => 'x', 'E_Mail' => 'onbekend@kvt.nl', 'KVT_User_ID' => 'KVT\\EVHERK']), 'geen mail-match: daarna KVT_User_ID');
$n = count($calls);
check('Jansen, P.', $name(['Name' => 'Piet Jansen', 'E_Mail' => 'piet@icloud.com']), '4947 extern adres: parser');
check($n, count($calls), 'extern adres: geen Graph-call');
check('Holwerda, D.', $name(['Name' => 'x', 'E_Mail' => 'dholwerda@kvt.nl']), 'uit cache');
check($n, count($calls), 'cache: geen Graph-call');
check(1, count(array_keys($calls, 'token:ok')), 'token hergebruikt');
check(true, isset(json_decode(file_get_contents($cache), true)['mail:dholwerda@kvt.nl']), 'cachebestand geschreven');
unlink($cache);

$c2 = $cache . '2';
check(null, horae_entra_lookup(['E_Mail' => 'dholwerda@kvt.nl'], $mock, [['tenantId' => 't', 'clientId' => 'denied', 'clientSecret' => 's']], $c2), 'alleen 401: stil null');
check(null, horae_entra_lookup(['E_Mail' => 'dholwerda@kvt.nl'], $mock, [], $c2 . 'x'), 'geen config: null');
check(false, is_file($c2), 'fout wordt niet gecachet');

// Zonder config in grid: parser, machines ongewijzigd.
check('Noordermeer, J.C.', horae_resource_name('1606', ['Name' => 'Noordermeer J.C.', 'E_Mail' => 'jnoordermeer@kvt.nl']), 'zonder config parser');
check('Load Bank 500 kW', horae_resource_name('LB500', ['Name' => 'Load Bank 500 kW', 'E_Mail' => 'x@kvt.nl']), 'machine ongewijzigd');

echo "OK entra_names_test\n";
