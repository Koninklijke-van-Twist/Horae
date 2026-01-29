<?php
require __DIR__ . "/odata.php"; // jouw curl helper (basic/ntlm)
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";

$service = "AppResource";
$xmlStr = odata_get_raw("https://kvtmd365.kvt.nl:7148/$environment/ODataV4/\$metadata", $auth);

$xml = simplexml_load_string($xmlStr);
if ($xml === false)
    die("Metadata XML niet leesbaar");

$xml->registerXPathNamespace('edmx', 'http://docs.oasis-open.org/odata/ns/edmx');
$xml->registerXPathNamespace('edm', 'http://docs.oasis-open.org/odata/ns/edm');

// 1) entity sets -> entity type mapping
$entitySets = $xml->xpath('//edm:EntityContainer/edm:EntitySet');
$map = [];
foreach ($entitySets as $es) {
    $name = (string) $es['Name'];       // bv "Urenstaatregels"
    $type = (string) $es['EntityType']; // bv "Microsoft.NAV.Urenstaatregels"
    $map[$name] = $type;
}

echo "<pre>";
echo "Entity sets:\n";
foreach ($map as $name => $type) {
    echo " - $name => $type\n";
}

// 2) properties per entity type
echo "\n\nProperties per entity set:\n";
foreach ($map as $setName => $typeFull) {
    // typeFull = Namespace.TypeName
    $typeName = substr($typeFull, strrpos($typeFull, '.') + 1);

    $props = $xml->xpath("//edm:EntityType[@Name='{$typeName}']/edm:Property");
    echo "\n[$setName]\n";
    foreach ($props as $p) {
        echo "  - " . (string) $p['Name'] . " : " . (string) $p['Type'] . "\n";
    }
}
echo "</pre>";

// ---- helper: raw xml ophalen
function odata_get_raw(string $url, array $auth): string
{
    var_dump($url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            "Accept: application/xml",
        ],
    ]);

    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ":" . $auth['pass']);
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ":" . $auth['pass']);
    }


    $raw = curl_exec($ch);
    if ($raw === false)
        throw new Exception("cURL error: " . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new Exception("HTTP $code: $raw");
    }
    return $raw;
}
