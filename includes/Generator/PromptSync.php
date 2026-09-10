<?php
/**
 * Sincronía entre los prompts guardados y los campos que la instalación rellena.
 *
 * Un prompt personalizado puede acabar pidiendo campos que aquí no se guardan:
 * al editarlo a mano, o al desactivar un addon que ampliaba el registro. La IA
 * los devuelve, el Writer los descarta, y se pagan tokens por texto que se tira
 * — en cada generación, indefinidamente.
 *
 * Esta clase detecta el desajuste y propone el prompt ya corregido. No toca
 * nada por su cuenta: quien decide es el usuario, desde Configuración.
 *
 * @package HUP\CamposSeoIA\Generator
 */

namespace HUP\CamposSeoIA\Generator;

use HUP\CamposSeoIA\Settings;
use HUP\CamposSeoIA\Activator;

defined( 'ABSPATH' ) || exit;

class PromptSync {

    /**
     * Qué campos espera cada tipo de prompt.
     *
     * 'post' e 'improve' escriben el artículo entero, así que además de los
     * campos del registro piden el título y el contenido.
     *
     * @return array<string, string[]>
     */
    public static function expected_fields(): array {
        $con_contenido = array_merge( [ 'post_title', 'post_content' ], Settings::fields_for( 'post' ) );

        return [
            'product'  => Settings::fields_for( 'product' ),
            'post_seo' => Settings::fields_for( 'post' ),
            'post'     => $con_contenido,
            'improve'  => $con_contenido,
        ];
    }

    /**
     * Campos que un prompt pide de más.
     *
     * Se buscan sólo nombres conocidos: si alguien inventa una clave propia en
     * su prompt, no es cosa nuestra tocarla.
     *
     * @param string $prompt Texto del prompt.
     * @param string $tipo   'product', 'post_seo', 'post' o 'improve'.
     *
     * @return string[] Nombres de campo sobrantes, en el orden en que aparecen.
     */
    public static function extra_fields( string $prompt, string $tipo ): array {
        $esperados = self::expected_fields()[ $tipo ] ?? [];
        $conocidos = array_keys( Activator::field_instructions() );
        $sobran    = [];

        foreach ( $conocidos as $campo ) {
            if ( in_array( $campo, $esperados, true ) ) {
                continue;
            }

            // Se exige el nombre exacto: 'og_title' no debe activarse por
            // 'og_title_extra', ni 'tags' por 'meta_tags'.
            if ( preg_match( '/\b' . preg_quote( $campo, '/' ) . '\b/', $prompt ) ) {
                $sobran[] = $campo;
            }
        }

        return $sobran;
    }

    /**
     * Devuelve el prompt sin los campos sobrantes.
     *
     * Se quitan dos cosas: la línea de instrucciones de cada campo y su clave
     * dentro del JSON de ejemplo. El resto del texto —incluidas las reglas que
     * haya escrito el usuario— se respeta tal cual.
     *
     * @param string   $prompt Prompt actual.
     * @param string[] $sobran Campos a retirar.
     */
    public static function clean( string $prompt, array $sobran ): string {
        if ( empty( $sobran ) ) {
            return $prompt;
        }

        // 1. Líneas de instrucción del tipo "- campo: explicación".
        $lineas = preg_split( '/\R/', $prompt );
        $fuera  = [];

        foreach ( $lineas as $linea ) {
            $quitar = false;
            foreach ( $sobran as $campo ) {
                if ( preg_match( '/^\s*-\s*' . preg_quote( $campo, '/' ) . '\s*:/', $linea ) ) {
                    $quitar = true;
                    break;
                }
            }
            if ( ! $quitar ) {
                $fuera[] = $linea;
            }
        }

        $limpio = implode( "\n", $fuera );

        // 2. Claves dentro del JSON de ejemplo, con su coma.
        foreach ( $sobran as $campo ) {
            $clave = preg_quote( $campo, '/' );
            // "campo":"..."  o  "campo":["a","b"], con la coma de delante o de detrás.
            $limpio = preg_replace( '/,?\s*"' . $clave . '"\s*:\s*(\[[^\]]*\]|"[^"]*")/', '', $limpio );
        }

        // Si al quitar la primera clave quedó '{,' se corrige.
        $limpio = preg_replace( '/\{\s*,/', '{', $limpio );

        return $limpio;
    }

    /**
     * Revisa los prompts guardados.
     *
     * Sólo mira los guardados: los que están en su valor por defecto se
     * componen desde el registro y no pueden desajustarse.
     *
     * @return array<string, array{extra: string[], limpio: string, actual: string}>
     */
    public static function audit(): array {
        $hallazgos = [];

        foreach ( array_keys( self::expected_fields() ) as $tipo ) {
            $guardado = get_option( 'hcsai_prompt_' . $tipo, '' );

            if ( '' === trim( (string) $guardado ) ) {
                continue;
            }

            $sobran = self::extra_fields( $guardado, $tipo );

            if ( empty( $sobran ) ) {
                continue;
            }

            $hallazgos[ $tipo ] = [
                'extra'  => $sobran,
                'actual' => $guardado,
                'limpio' => self::clean( $guardado, $sobran ),
            ];
        }

        return $hallazgos;
    }
}
