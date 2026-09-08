<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Минимальный самодостаточный XLSX writer.
 * Не требует Composer/PhpSpreadsheet; использует только ZipArchive.
 */
class LV_XLSX_Writer {
    private $sheets = array();
    private $sheet_names = array();

    public function add_sheet( $name, $rows ) {
        $name = $this->unique_sheet_name( $name );
        $this->sheets[] = array(
            'name' => $name,
            'rows' => is_array( $rows ) ? $rows : array(),
            'factory' => null,
        );
    }

    /**
     * Adds a worksheet backed by a callable that returns an iterable of rows.
     * Rows are written to temporary XML incrementally, so large exports do not
     * need to keep the whole worksheet or XML document in PHP memory.
     */
    public function add_sheet_stream( $name, $row_factory ) {
        if ( ! is_callable( $row_factory ) ) {
            return;
        }
        $name = $this->unique_sheet_name( $name );
        $this->sheets[] = array(
            'name' => $name,
            'rows' => null,
            'factory' => $row_factory,
        );
    }

    public function download( $filename ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( 'Для экспорта XLSX требуется PHP-расширение ZipArchive.' );
        }

        if ( empty( $this->sheets ) ) {
            $this->add_sheet( 'Заявки', array( array( 'Нет данных' ) ) );
        }

        $tmp = wp_tempnam( $filename );
        if ( ! $tmp ) {
            wp_die( 'Не удалось создать временный файл для экспорта.' );
        }

        $zip = new ZipArchive();
        $opened = $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        if ( true !== $opened ) {
            @unlink( $tmp );
            wp_die( 'Не удалось создать XLSX-файл.' );
        }

        $zip->addFromString( '[Content_Types].xml', $this->content_types_xml() );
        $zip->addFromString( '_rels/.rels', $this->root_rels_xml() );
        $zip->addFromString( 'docProps/app.xml', $this->app_xml() );
        $zip->addFromString( 'docProps/core.xml', $this->core_xml() );
        $zip->addFromString( 'xl/workbook.xml', $this->workbook_xml() );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels_xml() );
        $zip->addFromString( 'xl/styles.xml', $this->styles_xml() );

        $sheet_temp_files = array();
        foreach ( $this->sheets as $index => $sheet ) {
            $sheet_number = $index + 1;
            $sheet_tmp = $this->build_sheet_temp_file( $sheet );
            if ( ! $sheet_tmp ) {
                $zip->close();
                foreach ( $sheet_temp_files as $file ) @unlink( $file );
                @unlink( $tmp );
                wp_die( 'Не удалось сформировать лист XLSX.' );
            }
            $sheet_temp_files[] = $sheet_tmp;
            $zip->addFile( $sheet_tmp, 'xl/worksheets/sheet' . $sheet_number . '.xml' );
        }

        $zip->close();
        foreach ( $sheet_temp_files as $file ) @unlink( $file );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
        header( 'Content-Length: ' . filesize( $tmp ) );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $tmp );
        @unlink( $tmp );
        exit;
    }

    private function unique_sheet_name( $name ) {
        $name = trim( (string) $name );
        if ( '' === $name ) {
            $name = 'Лист';
        }

        $name = preg_replace( '~[\\/?*\[\]:]~u', ' ', $name );
        $name = preg_replace( '/\s+/u', ' ', $name );
        $name = trim( $name, " '" );
        if ( '' === $name ) {
            $name = 'Лист';
        }

        $base = $this->mb_substr( $name, 0, 31 );
        $name = $base;
        $i = 2;

        while ( isset( $this->sheet_names[ $name ] ) ) {
            $suffix = ' (' . $i . ')';
            $name = $this->mb_substr( $base, 0, max( 1, 31 - $this->mb_strlen( $suffix ) ) ) . $suffix;
            $i++;
        }

        $this->sheet_names[ $name ] = true;
        return $name;
    }

    private function build_sheet_temp_file( $sheet ) {
        $rows_tmp = wp_tempnam( 'lv-xlsx-rows.xml' );
        $sheet_tmp = wp_tempnam( 'lv-xlsx-sheet.xml' );
        if ( ! $rows_tmp || ! $sheet_tmp ) {
            if ( $rows_tmp ) @unlink( $rows_tmp );
            if ( $sheet_tmp ) @unlink( $sheet_tmp );
            return false;
        }

        $rows_handle = fopen( $rows_tmp, 'wb' );
        if ( ! $rows_handle ) {
            @unlink( $rows_tmp ); @unlink( $sheet_tmp );
            return false;
        }

        $source = ! empty( $sheet['factory'] ) ? call_user_func( $sheet['factory'] ) : ( isset( $sheet['rows'] ) ? $sheet['rows'] : array() );
        if ( ! is_array( $source ) && ! $source instanceof Traversable ) {
            $source = array();
        }

        $max_columns = 1;
        $widths = array();
        $row_count = 0;
        foreach ( $source as $row ) {
            $row_count++;
            $excel_row = $row_count;
            $row = is_array( $row ) ? $row : array( $row );
            $max_columns = max( $max_columns, count( $row ) );
            fwrite( $rows_handle, '<row r="' . $excel_row . '">' );
            foreach ( $row as $col_index => $value ) {
                $text_value = $this->cell_text( $value );
                $length = $this->mb_strlen( preg_replace( '/\s+/u', ' ', $text_value ) );
                $length = min( 60, max( 8, $length + 2 ) );
                if ( ! isset( $widths[ $col_index ] ) || $length > $widths[ $col_index ] ) $widths[ $col_index ] = $length;
                $cell_ref = $this->column_name( $col_index + 1 ) . $excel_row;
                $style = 1 === $row_count ? ' s="1"' : ' s="0"';
                $text = $this->xml_escape( $text_value );
                fwrite( $rows_handle, '<c r="' . $cell_ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $text . '</t></is></c>' );
            }
            fwrite( $rows_handle, '</row>' );
        }
        fclose( $rows_handle );

        $out = fopen( $sheet_tmp, 'wb' );
        if ( ! $out ) {
            @unlink( $rows_tmp ); @unlink( $sheet_tmp );
            return false;
        }
        fwrite( $out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' );
        fwrite( $out, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' );
        fwrite( $out, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' );
        fwrite( $out, '<sheetFormatPr defaultRowHeight="15"/>' );
        if ( $widths ) {
            fwrite( $out, '<cols>' );
            for ( $i = 0; $i < $max_columns; $i++ ) {
                $width = isset( $widths[ $i ] ) ? $widths[ $i ] : 12;
                fwrite( $out, '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . number_format( (float) $width, 2, '.', '' ) . '" customWidth="1"/>' );
            }
            fwrite( $out, '</cols>' );
        }
        fwrite( $out, '<sheetData>' );
        $rows_in = fopen( $rows_tmp, 'rb' );
        if ( $rows_in ) { stream_copy_to_stream( $rows_in, $out ); fclose( $rows_in ); }
        fwrite( $out, '</sheetData>' );
        if ( $row_count > 1 && $max_columns > 0 ) {
            fwrite( $out, '<autoFilter ref="A1:' . $this->column_name( $max_columns ) . $row_count . '"/>' );
        }
        fwrite( $out, '</worksheet>' );
        fclose( $out );
        @unlink( $rows_tmp );
        return $sheet_tmp;
    }

    private function sheet_xml( $rows ) {
        $rows = is_array( $rows ) ? $rows : array();
        $max_columns = 1;
        $widths = array();

        foreach ( $rows as $row ) {
            $row = is_array( $row ) ? $row : array( $row );
            $max_columns = max( $max_columns, count( $row ) );
            foreach ( $row as $col_index => $value ) {
                $text = $this->cell_text( $value );
                $length = $this->mb_strlen( preg_replace( '/\s+/u', ' ', $text ) );
                $length = min( 60, max( 8, $length + 2 ) );
                if ( ! isset( $widths[ $col_index ] ) || $length > $widths[ $col_index ] ) {
                    $widths[ $col_index ] = $length;
                }
            }
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';

        if ( $widths ) {
            $xml .= '<cols>';
            for ( $i = 0; $i < $max_columns; $i++ ) {
                $width = isset( $widths[ $i ] ) ? $widths[ $i ] : 12;
                $xml .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . number_format( (float) $width, 2, '.', '' ) . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        foreach ( $rows as $row_index => $row ) {
            $excel_row = $row_index + 1;
            $row = is_array( $row ) ? $row : array( $row );
            $xml .= '<row r="' . $excel_row . '">';

            foreach ( $row as $col_index => $value ) {
                $cell_ref = $this->column_name( $col_index + 1 ) . $excel_row;
                $style = 0 === $row_index ? ' s="1"' : ' s="0"';
                $text = $this->xml_escape( $this->cell_text( $value ) );
                $xml .= '<c r="' . $cell_ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        if ( count( $rows ) > 1 && $max_columns > 0 ) {
            $last_col = $this->column_name( $max_columns );
            $xml .= '<autoFilter ref="A1:' . $last_col . count( $rows ) . '"/>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    private function content_types_xml() {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

        foreach ( $this->sheets as $index => $sheet ) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ( $index + 1 ) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $xml .= '</Types>';
        return $xml;
    }

    private function root_rels_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbook_xml() {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';

        foreach ( $this->sheets as $index => $sheet ) {
            $xml .= '<sheet name="' . $this->xml_escape_attr( $sheet['name'] ) . '" sheetId="' . ( $index + 1 ) . '" r:id="rId' . ( $index + 1 ) . '"/>';
        }

        $xml .= '</sheets></workbook>';
        return $xml;
    }

    private function workbook_rels_xml() {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach ( $this->sheets as $index => $sheet ) {
            $xml .= '<Relationship Id="rId' . ( $index + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ( $index + 1 ) . '.xml"/>';
        }

        $styles_id = count( $this->sheets ) + 1;
        $xml .= '<Relationship Id="rId' . $styles_id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '</Relationships>';
        return $xml;
    }

    private function styles_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/><color rgb="FFFFFFFF"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF123640"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function app_xml() {
        $titles = array();
        foreach ( $this->sheets as $sheet ) {
            $titles[] = '<vt:lpstr>' . $this->xml_escape( $sheet['name'] ) . '</vt:lpstr>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>WordPress</Application>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count( $this->sheets ) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count( $this->sheets ) . '" baseType="lpstr">' . implode( '', $titles ) . '</vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private function core_xml() {
        $now = gmdate( 'Y-m-d\TH:i:s\Z' );
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Заявки фонда</dc:title>'
            . '<dc:creator>WordPress</dc:creator>'
            . '<cp:lastModifiedBy>WordPress</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function column_name( $number ) {
        $name = '';
        while ( $number > 0 ) {
            $number--;
            $name = chr( 65 + ( $number % 26 ) ) . $name;
            $number = (int) floor( $number / 26 );
        }
        return $name;
    }

    private function cell_text( $value ) {
        if ( is_bool( $value ) ) {
            return $value ? 'Да' : 'Нет';
        }
        if ( null === $value ) {
            return '';
        }
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }
        return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    private function xml_escape( $value ) {
        $value = $this->clean_xml_string( (string) $value );
        return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    private function xml_escape_attr( $value ) {
        return $this->xml_escape( $value );
    }

    private function clean_xml_string( $value ) {
        return preg_replace( '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $value );
    }

    private function mb_strlen( $value ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
    }

    private function mb_substr( $value, $start, $length ) {
        return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length, 'UTF-8' ) : substr( (string) $value, $start, $length );
    }
}
