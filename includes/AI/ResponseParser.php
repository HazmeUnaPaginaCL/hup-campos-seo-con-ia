<?php
/**
 * Parser robusto de respuestas JSON de IA.
 * Implementa múltiples estrategias de extracción para manejar
 * respuestas malformadas, con markdown, o parcialmente válidas.
 *
 * @package HUP\CamposSeoIA\AI
 */

namespace HUP\CamposSeoIA\AI;

defined( 'ABSPATH' ) || exit;

class ResponseParser {

    /**
     * Intenta parsear una respuesta de texto a un array asociativo.
     * Aplica 6 estrategias en orden de prioridad.
     *
     * @param string $raw_text    Texto crudo de la respuesta.
     * @param array  $expected    Campos esperados (para validación).
     * @return array Datos parseados.
     * @throws \RuntimeException Si ninguna estrategia funciona.
     */
    public static function parse( string $raw_text, array $expected = [] ): array {
        // Orden por DAÑO CRECIENTE: primero las estrategias que no pierden nada,
        // luego las que descartan campos, y al final las que pueden alterar el
        // texto. Antes 'fix_common_issues' iba cuarta, por delante de
        // 'sanitize_inline_newlines', así que el fallo más habitual (saltos de
        // línea literales dentro de un string) lo resolvía la estrategia que
        // machaca comillas y aplana caracteres de control, en vez de la que
        // escapa los saltos correctamente.
        $strategies = [
            // Sin pérdida
            'direct_decode',
            'strip_markdown_fences',
            'extract_json_braces',
            'sanitize_inline_newlines',
            // Recuperan lo que pueden, descartando el resto
            'repair_truncated_json',
            'extract_partial_fields',
            // Último recurso: pueden alterar el contenido
            'fix_common_issues',
            'regex_extraction',
        ];

        foreach ( $strategies as $strategy ) {
            $result = self::$strategy( $raw_text );
            if ( null !== $result && is_array( $result ) ) {
                // Validar que tenga al menos un campo esperado
                if ( ! empty( $expected ) ) {
                    $found = array_intersect( array_keys( $result ), $expected );
                    if ( empty( $found ) ) {
                        continue;
                    }
                }
                return $result;
            }
        }

        throw new \RuntimeException(
            'La IA no devolvió JSON válido. Error JSON: ' . esc_html( json_last_error_msg() )
            . '. Respuesta: ' . esc_html( mb_substr( $raw_text, 0, 300 ) )
        );
    }

    /**
     * Estrategia 1: Decodificación directa.
     */
    private static function direct_decode( string $text ): ?array {
        $data = json_decode( $text, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Estrategia 2: Eliminar fences de markdown (```json ... ```).
     */
    private static function strip_markdown_fences( string $text ): ?array {
        $cleaned = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $cleaned = preg_replace( '/\s*```\s*$/', '', $cleaned );
        $data    = json_decode( trim( $cleaned ), true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Estrategia 3: Extraer contenido entre llaves más externas.
     */
    private static function extract_json_braces( string $text ): ?array {
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );

        if ( false === $start || false === $end || $end <= $start ) {
            return null;
        }

        $json = substr( $text, $start, $end - $start + 1 );
        $data = json_decode( $json, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Estrategia 5 (nueva): Sanitizar saltos de línea literales dentro de strings JSON.
     * Ocurre cuando la IA incluye HTML con \n sin escapar dentro de valores JSON.
     * Ej: {"product_description": "<p>texto\ncon\nsaltos</p>"}
     */
    private static function sanitize_inline_newlines( string $text ): ?array {
        // Extraer la zona entre la primera { y la última }
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( false === $start || false === $end ) {
            return null;
        }
        $json = substr( $text, $start, $end - $start + 1 );

        // Reemplazar saltos de línea y tabulaciones literales dentro de strings JSON
        // usando un estado de parser simple que sabe si estamos dentro de un string
        $result   = '';
        $in_str   = false;
        $escaped  = false;
        $len      = strlen( $json );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $json[ $i ];
            if ( $escaped ) {
                $result  .= $c;
                $escaped  = false;
                continue;
            }
            if ( '\\' === $c && $in_str ) {
                $result  .= $c;
                $escaped  = true;
                continue;
            }
            if ( '"' === $c ) {
                $in_str = ! $in_str;
                $result .= $c;
                continue;
            }
            if ( $in_str ) {
                // Dentro de string: escapar caracteres de control
                $ord = ord( $c );
                if ( 10 === $ord ) { $result .= '\\n';  continue; } // LF
                if ( 13 === $ord ) { $result .= '\\r';  continue; } // CR
                if ( 9 === $ord )  { $result .= '\\t';  continue; } // TAB
            }
            $result .= $c;
        }

        $data = json_decode( $result, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Último recurso estructural: arregla errores típicos de JSON mal formado.
     *
     * Puede alterar el contenido, así que va casi al final del orden.
     */
    private static function fix_common_issues( string $text ): ?array {
        // Extraer entre llaves primero
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );

        if ( false === $start || false === $end ) {
            return null;
        }

        $json = substr( $text, $start, $end - $start + 1 );

        // Comillas simples como delimitador (estilo dict de Python).
        //
        // Solo se convierten si NO hay ninguna comilla doble en el fragmento: ahí
        // las simples son claramente los delimitadores. Si ya hay comillas dobles,
        // las simples son apóstrofos del texto ("L'Oréal", "don't") y convertirlas
        // rompía el JSON, dejando esta estrategia inservible para cualquier
        // contenido en español o inglés con un apóstrofo.
        if ( false === strpos( $json, '"' ) ) {
            $json = str_replace( "'", '"', $json );
        }

        // Eliminar comas al final antes de }
        $json = preg_replace( '/,\s*}/', '}', $json );
        $json = preg_replace( '/,\s*]/', ']', $json );

        // Eliminar caracteres de control
        $json = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $json );

        $data = json_decode( $json, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Estrategia nueva: Reparar JSON truncado por límite de tokens.
     * Cuando finish_reason=length, el JSON queda cortado a la mitad.
     * Intenta cerrar el string abierto, luego el objeto, y decodifica.
     */
    private static function repair_truncated_json( string $text ): ?array {
        $start = strpos( $text, '{' );
        if ( false === $start ) {
            return null;
        }
        $json = substr( $text, $start );

        // Primero aplicar sanitize de caracteres de control
        $sanitized = '';
        $in_str    = false;
        $escaped   = false;
        $len       = strlen( $json );
        for ( $i = 0; $i < $len; $i++ ) {
            $c   = $json[ $i ];
            $ord = ord( $c );
            if ( $escaped ) {
                $sanitized .= $c;
                $escaped    = false;
                continue;
            }
            if ( '\\' === $c && $in_str ) {
                $sanitized .= $c;
                $escaped    = true;
                continue;
            }
            if ( '"' === $c ) {
                $in_str     = ! $in_str;
                $sanitized .= $c;
                continue;
            }
            if ( $in_str && ( 10 === $ord || 13 === $ord ) ) {
                $sanitized .= ( 10 === $ord ) ? '\\n' : '\\r';
                continue;
            }
            $sanitized .= $c;
        }

        // Si sigue sin llave de cierre, intentar reparar el truncado
        if ( null === json_decode( $sanitized, true ) ) {
            // Truncar en la última coma que separa un campo completo
            $last_comma = strrpos( $sanitized, ',' );
            if ( false !== $last_comma ) {
                $sanitized = substr( $sanitized, 0, $last_comma ) . '}';
            } else {
                // Añadir cierre de string y objeto si estaba dentro de un string
                $sanitized = rtrim( $sanitized ) . '"}';
            }
        }

        $data = json_decode( $sanitized, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Estrategia 5: Extraer campos parciales con regex.
     */
    private static function extract_partial_fields( string $text ): ?array {
        $fields = [
            'seo_title', 'meta_description', 'focus_keyword',
            'og_title', 'og_description', 'alt_text',
            'short_description', 'product_description',
            'post_title', 'post_content',
        ];

        $result = [];

        foreach ( $fields as $field ) {
            // Buscar "campo": "valor" o "campo":"valor"
            $pattern = '/"' . preg_quote( $field, '/' ) . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s';
            if ( preg_match( $pattern, $text, $match ) ) {
                $result[ $field ] = stripcslashes( $match[1] );
            }
        }

        // Buscar tags como array
        if ( preg_match( '/"tags"\s*:\s*\[(.*?)\]/s', $text, $match ) ) {
            preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $match[1], $tag_matches );
            if ( ! empty( $tag_matches[1] ) ) {
                $result['tags'] = array_map( 'stripcslashes', $tag_matches[1] );
            }
        }

        return ! empty( $result ) ? $result : null;
    }

    /**
     * Estrategia 6: Extracción con regex más agresiva.
     */
    private static function regex_extraction( string $text ): ?array {
        $result = [];

        // Buscar pares clave:valor con diferentes formatos
        $patterns = [
            '/(?:seo[_\s]?title|titulo?\s*seo)\s*[:=]\s*["\']?(.*?)["\']?\s*(?:,|\n|$)/i'          => 'seo_title',
            '/(?:meta[_\s]?desc(?:ription)?)\s*[:=]\s*["\']?(.*?)["\']?\s*(?:,|\n|$)/i'             => 'meta_description',
            '/(?:focus[_\s]?keyword|keyword|palabra\s*clave)\s*[:=]\s*["\']?(.*?)["\']?\s*(?:,|\n|$)/i' => 'focus_keyword',
        ];

        foreach ( $patterns as $pattern => $field ) {
            if ( preg_match( $pattern, $text, $match ) ) {
                $value = trim( $match[1], '"\' ' );
                if ( ! empty( $value ) ) {
                    $result[ $field ] = $value;
                }
            }
        }

        return ! empty( $result ) ? $result : null;
    }
}
