<?php
/**
 * Schema de la respuesta JSON que se le pide a la IA.
 *
 * Se construye en un solo formato —el subconjunto de OpenAPI que usa el
 * responseSchema de Gemini, con tipos en mayúsculas— y cada proveedor lo
 * adapta al suyo. Antes solo existía para Gemini, dentro de GeminiProvider,
 * y OpenAI pedía un JSON libre.
 *
 * @package HUP\CamposSeoIA\AI
 */

namespace HUP\CamposSeoIA\AI;

defined( 'ABSPATH' ) || exit;

class ResponseSchema {

    /**
     * Campos que, si se piden, la IA tiene que devolver siempre. El resto puede
     * omitirse (Gemini) o venir en null (OpenAI).
     */
    private const REQUIRED = [ 'seo_title', 'meta_description', 'focus_keyword', 'post_title', 'post_content' ];

    /**
     * Descripción de cada campo.
     *
     * @return array<string, array>
     */
    private static function field_schemas(): array {
        $texto = static function ( string $desc ): array {
            return [ 'type' => 'STRING', 'description' => $desc ];
        };

        return [
            'seo_title'           => $texto( 'Titulo SEO optimizado, maximo 60 caracteres' ),
            'meta_description'    => $texto( 'Meta descripcion, 120-160 caracteres' ),
            'focus_keyword'       => $texto( 'Palabra clave principal' ),
            'og_title'            => $texto( 'Titulo Open Graph' ),
            'og_description'      => $texto( 'Descripcion Open Graph' ),
            'alt_text'            => $texto( 'Texto alternativo para imagen, maximo 120 caracteres' ),
            'product_description' => $texto( 'Descripcion completa del producto, minimo 150 palabras' ),
            'short_description'   => $texto( 'Descripcion corta del producto, 1-2 oraciones' ),
            'post_title'          => $texto( 'Titulo del post' ),
            'post_content'        => $texto( 'Contenido completo del post' ),
            'tags'                => [
                'type'        => 'ARRAY',
                'items'       => [ 'type' => 'STRING' ],
                'description' => 'Etiquetas SEO relevantes',
            ],
        ];
    }

    /**
     * Construye el schema para una lista de campos.
     *
     * Recibe la lista en vez de deducirla de un "tipo": qué campos se piden lo
     * decide Settings::generated_fields(), que es filtrable.
     *
     * @param array<string> $fields Campos a pedir.
     * @return array Schema; vacío si ningún campo es conocido.
     */
    public static function for_fields( array $fields ): array {
        $catalogo   = self::field_schemas();
        $properties = [];

        foreach ( $fields as $field ) {
            if ( isset( $catalogo[ $field ] ) ) {
                $properties[ $field ] = $catalogo[ $field ];
            }
        }

        if ( empty( $properties ) ) {
            return [];
        }

        // Solo se marcan como obligatorios los campos que realmente se piden:
        // exigir uno que no está en properties hace que Gemini rechace el schema.
        $required = array_values( array_intersect( self::REQUIRED, array_keys( $properties ) ) );

        return [
            'type'       => 'OBJECT',
            'properties' => $properties,
            'required'   => $required,
        ];
    }
}
