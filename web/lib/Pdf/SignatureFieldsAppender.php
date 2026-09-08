<?php

namespace Horae\Pdf;

use RuntimeException;

/**
 * Voegt lege PDF-handtekeningvelden (/Sig) toe via een incrementele PDF-update.
 * Posities in mm t.o.v. linksonder (A4 landscape).
 */
class SignatureFieldsAppender
{
    private const MM_TO_PT = 2.834645669291339;

    /** Posities in mm t.o.v. linksonder (A4 landscape). Afstemmen op .sign-signature-space in timesheet.php. */
    public static function defaultFields(): array
    {
        return self::fieldsFromLayout();
    }

    /**
     * Berekent veldposities uit dezelfde layoutconstanten als @page / footer in timesheet.php.
     *
     * @return list<array{name:string,xmm:float,ymm:float,wmm:float,hmm:float}>
     */
    public static function fieldsFromLayout(): array
    {
        $layout = self::layoutMetrics();
        $names = [
            'Handtekening_hoofdaannemer',
            'Handtekening_onderaannemer',
            'Handtekening_uitvoerder',
        ];

        $fields = [];
        foreach ($names as $index => $name) {
            $fields[] = ['name' => $name] + self::fieldRect($index, $layout);
        }

        return $fields;
    }

    /** @return array{
     *   pageWidthMm:float,
     *   marginMm:float,
     *   declarationsPct:float,
     *   signColPct:float,
     *   signatureSpaceHeightMm:float,
     *   signatureInsetMm:float,
     *   signatureBottomMm:float
     * }
     */
    private static function layoutMetrics(): array
    {
        return [
            'pageWidthMm' => 297.0,
            'marginMm' => 8.0,
            'declarationsPct' => 0.26,
            'signColPct' => 0.2466,
            'signatureSpaceHeightMm' => 15.0,
            'signatureInsetMm' => 2.0,
            // Onderkant wit handtekeningvak: paginamarge + onderrand .sign (2px)
            'signatureBottomMm' => 8.7,
        ];
    }

    /** @param array<string,float> $layout
     * @return array{xmm:float,ymm:float,wmm:float,hmm:float}
     */
    private static function fieldRect(int $columnIndex, array $layout): array
    {
        $contentWidth = $layout['pageWidthMm'] - (2 * $layout['marginMm']);
        $signColWidth = $contentWidth * $layout['signColPct'];
        $signLeft = $layout['marginMm']
            + ($contentWidth * $layout['declarationsPct'])
            + ($columnIndex * $signColWidth);

        return [
            'xmm' => round($signLeft + $layout['signatureInsetMm'], 1),
            'ymm' => round($layout['signatureBottomMm'], 1),
            'wmm' => round($signColWidth - (2 * $layout['signatureInsetMm']), 1),
            'hmm' => round($layout['signatureSpaceHeightMm'], 1),
        ];
    }

    public static function append(string $inputPath, string $outputPath, ?array $fields = null): void
    {
        if (!is_file($inputPath)) {
            throw new RuntimeException('Invoer-PDF niet gevonden');
        }

        $fields = $fields ?? self::defaultFields();
        $pdf = file_get_contents($inputPath);
        if ($pdf === false || $pdf === '') {
            throw new RuntimeException('Invoer-PDF lezen mislukt');
        }

        $trailer = self::parseTrailer($pdf);
        $pageRef = self::findFirstPageRef($pdf, $trailer['root']);
        $pageDict = self::readObjectDictionary($pdf, $pageRef[0], $pageRef[1]);
        $catalogDict = self::readObjectDictionary($pdf, $trailer['root'][0], $trailer['root'][1]);

        $fieldCount = count($fields);
        $base = $trailer['size'];
        $annotNums = range($base, $base + $fieldCount - 1);
        $fieldNums = range($base + $fieldCount, $base + (2 * $fieldCount) - 1);
        $acroNum = $base + (2 * $fieldCount);
        $newPageNum = $acroNum + 1;
        $newPagesNum = $newPageNum + 1;
        $newCatalogNum = $newPagesNum + 1;
        $nextObj = $newCatalogNum + 1;

        $pagesRef = self::findPagesRef($catalogDict);
        $pagesDict = self::readObjectDictionary($pdf, $pagesRef[0], $pagesRef[1]);

        $chunk = '';
        $offsets = [];

        foreach ($fields as $index => $field) {
            $annotNum = $annotNums[$index];
            $fieldNum = $fieldNums[$index];
            $rect = self::rectFromMm($field['xmm'], $field['ymm'], $field['wmm'], $field['hmm']);
            $name = self::pdfString($field['name']);

            $offsets[$fieldNum] = strlen($pdf) + strlen($chunk);
            $chunk .= $fieldNum . " 0 obj\n";
            $chunk .= '<< /FT /Sig /T (' . $name . ') /Kids [' . $annotNum . " 0 R] >>\nendobj\n";

            $offsets[$annotNum] = strlen($pdf) + strlen($chunk);
            $chunk .= $annotNum . " 0 obj\n";
            $chunk .= '<< /Type /Annot /Subtype /Widget /FT /Sig /T (' . $name . ') ';
            $chunk .= '/Rect [' . implode(' ', $rect) . '] /F 4 /P ' . $newPageNum . ' 0 R /Parent ' . $fieldNum . " 0 R >>\nendobj\n";
        }

        $offsets[$acroNum] = strlen($pdf) + strlen($chunk);
        $fieldRefList = implode(' ', array_map(fn($n) => $n . ' 0 R', $fieldNums));
        $chunk .= $acroNum . " 0 obj\n";
        $chunk .= '<< /Fields [' . $fieldRefList . '] /SigFlags 3 >>' . "\nendobj\n";

        $annotRefList = implode(' ', array_map(fn($n) => $n . ' 0 R', $annotNums));
        $newPageDict = self::mergeAnnots($pageDict, $annotRefList);
        $newPageDict = self::mergeDictionary($newPageDict, [
            'Parent' => $newPagesNum . ' 0 R',
        ]);
        $offsets[$newPageNum] = strlen($pdf) + strlen($chunk);
        $chunk .= $newPageNum . " 0 obj\n" . $newPageDict . "\nendobj\n";

        $newPagesDict = self::mergeDictionary($pagesDict, [
            'Kids' => '[' . $newPageNum . ' 0 R]',
            'Count' => '1',
        ]);
        $offsets[$newPagesNum] = strlen($pdf) + strlen($chunk);
        $chunk .= $newPagesNum . " 0 obj\n" . $newPagesDict . "\nendobj\n";

        $newCatalogDict = self::mergeDictionary($catalogDict, [
            'Pages' => $newPagesNum . ' 0 R',
            'AcroForm' => $acroNum . ' 0 R',
        ]);
        $offsets[$newCatalogNum] = strlen($pdf) + strlen($chunk);
        $chunk .= $newCatalogNum . " 0 obj\n" . $newCatalogDict . "\nendobj\n";

        $xrefOffset = strlen($pdf) + strlen($chunk);
        $xref = "xref\n0 1\n0000000000 65535 f \n";
        $xref .= $base . ' ' . ($nextObj - $base) . "\n";
        for ($objNum = $base; $objNum < $nextObj; $objNum++) {
            if (!isset($offsets[$objNum])) {
                throw new RuntimeException('Interne PDF-objectvolgorde ongeldig bij object ' . $objNum);
            }
            $xref .= sprintf("%010d 00000 n \n", $offsets[$objNum]);
        }

        $trailerDict = '<< /Size ' . $nextObj . ' /Root ' . $newCatalogNum . ' 0 R';
        if ($trailer['info'] !== null) {
            $trailerDict .= ' /Info ' . $trailer['info'][0] . ' ' . $trailer['info'][1] . ' R';
        }
        $trailerDict .= ' /Prev ' . $trailer['startxref'] . " >>\n";

        $trailerBlock = "trailer\n" . $trailerDict;
        $trailerBlock .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        if (file_put_contents($outputPath, $pdf . $chunk . $xref . $trailerBlock) === false) {
            throw new RuntimeException('PDF schrijven mislukt');
        }
    }

    /** @return array{startxref:int,size:int,root:array{0:int,1:int},info:?array{0:int,1:int}} */
    private static function parseTrailer(string $pdf): array
    {
        if (!preg_match('/startxref\s+(\d+)\s*%%EOF\s*$/s', $pdf, $m)) {
            throw new RuntimeException('PDF startxref niet gevonden');
        }

        $startxref = (int) $m[1];
        $tail = substr($pdf, max(0, $startxref), 8192);
        if (!preg_match('/\/Size\s+(\d+)/', $tail, $sm)) {
            $tail = substr($pdf, -4096);
            if (!preg_match('/\/Size\s+(\d+)/', $tail, $sm)) {
                throw new RuntimeException('PDF /Size niet gevonden');
            }
        }

        if (!preg_match('/\/Root\s+(\d+)\s+(\d+)\s+R/', $tail, $rm)) {
            throw new RuntimeException('PDF /Root niet gevonden');
        }

        $info = null;
        if (preg_match('/\/Info\s+(\d+)\s+(\d+)\s+R/', $tail, $im)) {
            $info = [(int) $im[1], (int) $im[2]];
        }

        return [
            'startxref' => $startxref,
            'size' => (int) $sm[1],
            'root' => [(int) $rm[1], (int) $rm[2]],
            'info' => $info,
        ];
    }

    /** @param array{0:int,1:int} $rootRef
     * @return array{0:int,1:int}
     */
    private static function findFirstPageRef(string $pdf, array $rootRef): array
    {
        $catalog = self::readObjectDictionary($pdf, $rootRef[0], $rootRef[1]);
        if (!preg_match('/\/Pages\s+(\d+)\s+(\d+)\s+R/', $catalog, $pm)) {
            throw new RuntimeException('PDF /Pages niet gevonden');
        }

        $pages = self::readObjectDictionary($pdf, (int) $pm[1], (int) $pm[2]);
        if (!preg_match('/\/Kids\s*\[\s*(\d+)\s+(\d+)\s+R/', $pages, $km)) {
            throw new RuntimeException('PDF page /Kids niet gevonden');
        }

        return [(int) $km[1], (int) $km[2]];
    }

    /** @return array{0:int,1:int} */
    private static function findPagesRef(string $catalogDict): array
    {
        if (!preg_match('/\/Pages\s+(\d+)\s+(\d+)\s+R/', $catalogDict, $pm)) {
            throw new RuntimeException('PDF /Pages niet gevonden in catalogus');
        }

        return [(int) $pm[1], (int) $pm[2]];
    }

    private static function readObjectDictionary(string $pdf, int $num, int $gen): string
    {
        $marker = $num . ' ' . $gen . ' obj';
        $pos = strpos($pdf, $marker);
        if ($pos === false) {
            throw new RuntimeException("PDF object {$num} {$gen} niet gevonden");
        }

        $start = strpos($pdf, '<<', $pos);
        if ($start === false) {
            throw new RuntimeException("PDF dictionary voor object {$num} {$gen} niet gevonden");
        }

        $depth = 0;
        $len = strlen($pdf);
        for ($i = $start; $i < $len - 1; $i++) {
            if ($pdf[$i] === '<' && $pdf[$i + 1] === '<') {
                $depth++;
                $i++;
                continue;
            }
            if ($pdf[$i] === '>' && $pdf[$i + 1] === '>') {
                $depth--;
                $i++;
                if ($depth === 0) {
                    return substr($pdf, $start, $i - $start + 1);
                }
            }
        }

        throw new RuntimeException("PDF dictionary voor object {$num} {$gen} incompleet");
    }

    private static function mergeAnnots(string $pageDict, string $newAnnotRefs): string
    {
        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $pageDict, $m)) {
            $existing = trim($m[1]);
            $merged = $existing !== '' ? $existing . ' ' . $newAnnotRefs : $newAnnotRefs;

            return self::mergeDictionary($pageDict, ['Annots' => '[' . $merged . ']']);
        }

        return self::mergeDictionary($pageDict, ['Annots' => '[' . $newAnnotRefs . ']']);
    }

    /** @return list<float> */
    private static function rectFromMm(float $xmm, float $ymm, float $wmm, float $hmm): array
    {
        $llx = round($xmm * self::MM_TO_PT, 2);
        $lly = round($ymm * self::MM_TO_PT, 2);
        $urx = round(($xmm + $wmm) * self::MM_TO_PT, 2);
        $ury = round(($ymm + $hmm) * self::MM_TO_PT, 2);

        return [$llx, $lly, $urx, $ury];
    }

    private static function mergeDictionary(string $dict, array $add): string
    {
        $dict = trim($dict);
        if (str_starts_with($dict, '<<') && str_ends_with($dict, '>>')) {
            $inner = trim(substr($dict, 2, -2));
        } else {
            $inner = trim($dict);
        }

        foreach ($add as $key => $value) {
            $pattern = '/\/' . preg_quote($key, '/') . '\s+(?:\[[^\]]*\]|\d+\s+\d+\s+R|<<(?:[^>]|>>(?!>))*>>|[^\s\/]+)/s';
            if (preg_match($pattern, $inner)) {
                $inner = preg_replace($pattern, '/' . $key . ' ' . $value, $inner, 1) ?? $inner;
            } else {
                $inner .= ' /' . $key . ' ' . $value;
            }
        }

        return '<< ' . trim($inner) . ' >>';
    }

    private static function pdfString(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
