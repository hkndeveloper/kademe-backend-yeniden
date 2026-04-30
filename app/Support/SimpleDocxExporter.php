<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class SimpleDocxExporter
{
    public static function create(string $title, array $headings, array $rows): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('DOCX export icin ZipArchive uzantisi gerekli.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'kademe_docx_');
        if ($tmpFile === false) {
            throw new RuntimeException('Gecici DOCX dosyasi olusturulamadi.');
        }

        $docxPath = $tmpFile . '.docx';
        @unlink($tmpFile);

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('DOCX paketi olusturulamadi.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('word/_rels/document.xml.rels', self::wordRelsXml());
        $zip->addFromString('word/styles.xml', self::stylesXml());
        $zip->addFromString('word/document.xml', self::documentXml($title, $headings, $rows));
        $zip->close();

        return $docxPath;
    }

    private static function documentXml(string $title, array $headings, array $rows): string
    {
        $headingRow = self::tableRowXml($headings, true);
        $bodyRows = '';
        foreach ($rows as $row) {
            $bodyRows .= self::tableRowXml($row, false);
        }

        $safeTitle = self::escapeXml($title);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" mc:Ignorable="w14 wp14">
  <w:body>
    <w:p>
      <w:pPr><w:pStyle w:val="Title"/></w:pPr>
      <w:r><w:t>{$safeTitle}</w:t></w:r>
    </w:p>
    <w:p>
      <w:r><w:t>Olusturma Tarihi: {self::escapeXml(now()->format('d.m.Y H:i'))}</w:t></w:r>
    </w:p>
    <w:tbl>
      <w:tblPr>
        <w:tblW w:w="0" w:type="auto"/>
        <w:tblBorders>
          <w:top w:val="single" w:sz="8" w:space="0" w:color="auto"/>
          <w:left w:val="single" w:sz="8" w:space="0" w:color="auto"/>
          <w:bottom w:val="single" w:sz="8" w:space="0" w:color="auto"/>
          <w:right w:val="single" w:sz="8" w:space="0" w:color="auto"/>
          <w:insideH w:val="single" w:sz="6" w:space="0" w:color="auto"/>
          <w:insideV w:val="single" w:sz="6" w:space="0" w:color="auto"/>
        </w:tblBorders>
      </w:tblPr>
      {$headingRow}
      {$bodyRows}
    </w:tbl>
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1440" w:right="720" w:bottom="1440" w:left="720" w:header="708" w:footer="708" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>
XML;
    }

    private static function tableRowXml(array $cells, bool $isHeader): string
    {
        $rowXml = '<w:tr>';
        foreach ($cells as $cell) {
            $safe = self::escapeXml((string) $cell);
            $runProps = $isHeader ? '<w:rPr><w:b/></w:rPr>' : '';
            $rowXml .= <<<XML
<w:tc>
  <w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>
  <w:p><w:r>{$runProps}<w:t xml:space="preserve">{$safe}</w:t></w:r></w:p>
</w:tc>
XML;
        }
        $rowXml .= '</w:tr>';

        return $rowXml;
    }

    private static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private static function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>
XML;
    }

    private static function rootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML;
    }

    private static function wordRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private static function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
    <w:name w:val="Normal"/>
    <w:qFormat/>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Title">
    <w:name w:val="Title"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:spacing w:after="200"/></w:pPr>
    <w:rPr><w:b/><w:sz w:val="32"/></w:rPr>
  </w:style>
</w:styles>
XML;
    }
}
