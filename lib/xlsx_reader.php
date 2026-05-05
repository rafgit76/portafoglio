<?php

class SimpleXlsxFinecoReader
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function readFirstSheetRows(int $maxRows = 2000): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione ZipArchive non disponibile su PHP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            throw new RuntimeException('Impossibile aprire il file XLSX.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetPath = $this->resolveFirstSheetPath($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('Foglio Excel non trovato nel file.');
        }

        $xml = @simplexml_load_string($sheetXml);
        if (!$xml || !isset($xml->sheetData)) {
            throw new RuntimeException('Formato foglio Excel non valido.');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowNum = (int) $row['r'];
            if ($rowNum > $maxRows) {
                break;
            }

            $line = [];
            foreach ($row->c as $cell) {
                $cellRef = (string) $cell['r'];
                $col = preg_replace('/\d+/', '', $cellRef);
                $colIdx = $this->columnIndex($col);
                $value = $this->readCellValue($cell, $sharedStrings);
                $line[$colIdx] = $value;
            }

            if (!empty($line)) {
                ksort($line);
                $rows[$rowNum] = $line;
            }
        }

        return $rows;
    }

    private function resolveFirstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $wbXml = @simplexml_load_string($workbook);
        $relsXml = @simplexml_load_string($rels);
        if (!$wbXml || !$relsXml || !isset($wbXml->sheets->sheet[0])) {
            return 'xl/worksheets/sheet1.xml';
        }

        $sheet = $wbXml->sheets->sheet[0];
        $attrs = $sheet->attributes('r', true);
        $rid = (string) ($attrs['id'] ?? '');

        foreach ($relsXml->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = (string) $rel['Target'];
                if (strpos($target, 'xl/') === 0) {
                    return $target;
                }

                return 'xl/' . ltrim($target, '/');
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xmlStr = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlStr === false) {
            return [];
        }

        $xml = @simplexml_load_string($xmlStr);
        if (!$xml) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            $text = '';
            if (isset($si->r)) {
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private function readCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        $valueNode = $cell->v;

        if (!isset($valueNode)) {
            return '';
        }

        $value = (string) $valueNode;
        if ($type === 's') {
            $idx = (int) $value;
            return $sharedStrings[$idx] ?? '';
        }

        return $value;
    }

    private function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $len = strlen($letters);
        $idx = 0;

        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($letters[$i]) - 64);
        }

        return max(1, $idx);
    }
}
