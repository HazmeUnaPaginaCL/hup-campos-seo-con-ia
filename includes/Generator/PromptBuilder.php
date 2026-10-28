<?php
/**
 * Constructor de prompts con variables dinámicas.
 * Reemplaza placeholders en los prompts con datos reales del post/producto.
 *
 * @package HUP\CamposSeoIA\Generator
 */

namespace HUP\CamposSeoIA\Generator;

use HUP\CamposSeoIA\Settings;

defined( 'ABSPATH' ) || exit;

class PromptBuilder {

    /**
     * Caracteres de texto del post que se mandan a la IA al mejorarlo.
     *
     * Con «Mejorar contenido» la respuesta sustituye el artículo entero, así que
     * la IA tiene que verlo completo: PostImprover rechaza los posts más largos
     * en vez de mandar solo el principio. Unos 3.000 tokens de entrada, y una
     * reescritura de largo parecido cabe holgada en los 8.192 de salida.
     */
    public const MAX_IMPROVE_CHARS = 12000;

    /** Caracteres de texto que se mandan como descripción al completar campos. */
    public const MAX_SEO_CHARS = 2000;

    /**
     * Construye el prompt para generación SEO de un post/producto existente.
     *
     * @param \WP_Post $post Post o producto.
     * @param string   $type 'product', 'post', 'page'.
     * @return string Prompt con variables reemplazadas.
     */
    public static function build_seo( \WP_Post $post, string $type = 'product' ): string {
        // Los posts existentes usan su propia plantilla: la de tipo 'post' sirve
        // para CREAR un post nuevo ({{tema}}/{{tono}}/{{largo}}) y no recibe el
        // título ni el contenido reales, así que optimizaba sobre la nada.
        $prompt_key = ( 'post' === $type ) ? 'post_seo' : $type;

        // Se respeta siempre el prompt guardado. Antes se descartaba en silencio
        // si no contenía 'og_title': era una heurística para migrar prompts
        // anteriores a la v1.1.4, pero quedó como comportamiento permanente y
        // pisaba la personalización de quien quitaba los campos OG a propósito.
        // Esa migración ya la hace Activator::check_version_update(), y
        // Settings::prompt() cae al default si la opción está vacía.
        $template = Settings::prompt( $prompt_key );

        $variables = self::gather_variables( $post, $type );

        return self::replace_variables( $template, $variables );
    }

    /**
     * Construye el prompt para crear un post nuevo.
     *
     * @param string $topic     Tema o keyword.
     * @param string $tone      Tono del contenido.
     * @param string $length    Extensión: 'short', 'medium' o 'long'.
     * @return string Prompt completo.
     */
    public static function build_create_post( string $topic, string $tone, string $length ): string {
        $template    = Settings::prompt( 'post' );
        $length_desc = self::length_description( $length );

        $variables = array_merge( self::base_variables(), [
            'tema'  => $topic,
            'tono'  => $tone,
            'largo' => $length_desc,
        ] );

        return self::replace_variables( $template, $variables );
    }

    /**
     * Construye el prompt para mejorar un post existente.
     *
     * @param \WP_Post $post    Post a mejorar.
     * @param array    $options Opciones: improve_content, improve_seo, add_cta, improve_tags.
     * @return string Prompt completo.
     */
    public static function build_improve( \WP_Post $post, array $options ): string {
        $instructions = [];

        if ( ! empty( $options['improve_content'] ) ) {
            $instructions[] = '- Mejora la redaccion y estructura del contenido.';
        }
        if ( ! empty( $options['improve_seo'] ) ) {
            $instructions[] = '- Optimiza los campos SEO: titulo SEO, meta descripcion, palabra clave, extracto, texto alternativo y Open Graph.';
        }
        if ( ! empty( $options['add_cta'] ) ) {
            $instructions[] = '- Agrega un llamado a la accion (CTA) al final del contenido.';
        }
        if ( ! empty( $options['improve_tags'] ) ) {
            $instructions[] = '- Sugiere etiquetas SEO relevantes.';
        }

        $template = Settings::prompt( 'improve' );

        $variables = array_merge( self::base_variables(), [
            'titulo'        => $post->post_title,
            'contenido'     => self::plain_text( $post->post_content, self::MAX_IMPROVE_CHARS ),
            'instrucciones' => implode( "\n", $instructions ),
        ] );

        return self::replace_variables( $template, $variables );
    }

    /**
     * Recopila variables dinámicas del post.
     */
    private static function gather_variables( \WP_Post $post, string $type ): array {
        $vars = array_merge( self::base_variables(), [
            'nombre'      => $post->post_title,
            'descripcion' => self::plain_text( $post->post_content, self::MAX_SEO_CHARS ),
        ] );

        if ( 'product' === $type && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $vars['sku']       = $product->get_sku();
                $vars['precio']    = $product->get_price();
                $vars['categoria'] = self::get_product_categories( $post->ID );
                $vars['atributos'] = self::get_product_attributes( $product );
            }
        }

        if ( 'post' === $type || 'page' === $type ) {
            $cats = get_the_category( $post->ID );
            $vars['categoria'] = ! empty( $cats ) ? $cats[0]->name : '';
        }

        return $vars;
    }

    /**
     * Texto legible de un contenido HTML, para mandárselo a la IA.
     *
     * Se limpia ANTES de recortar. Antes se cortaba el HTML crudo y luego se
     * quitaban las etiquetas, así que el límite se gastaba en marcado
     * —comentarios de bloque, atributos, shortcodes— y con Gutenberg o un
     * constructor visual a la IA le llegaba casi nada de texto.
     *
     * Los subtítulos se conservan como ##Título##, el mismo marcador que se le
     * pide de vuelta, y los párrafos como líneas en blanco.
     *
     * @param string $html Contenido del post.
     * @param int    $max  Caracteres máximos (0 = sin límite).
     */
    public static function plain_text( string $html, int $max = 0 ): string {
        $html = strip_shortcodes( $html );
        $html = preg_replace( '/<h[2-6][^>]*>(.*?)<\/h[2-6]>/is', "\n\n##$1##\n\n", $html );
        $html = preg_replace( '/(<\/(?:p|div|blockquote|ul|ol|table)>)/i', "$1\n\n", $html );
        $html = preg_replace( '/(<br\s*\/?>|<\/li>|<\/tr>)/i', "$1\n", $html );

        $text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = trim( preg_replace( '/\n\s*\n+/', "\n\n", $text ) );

        return $max > 0 ? mb_substr( $text, 0, $max ) : $text;
    }

    /**
     * Reemplaza {{variable}} en el template.
     */
    private static function replace_variables( string $template, array $variables ): string {
        foreach ( $variables as $key => $value ) {
            $template = str_replace( '{{' . $key . '}}', (string) $value, $template );
        }
        return $template;
    }

    /**
     * Obtiene categorías de producto como string.
     */
    private static function get_product_categories( int $post_id ): string {
        $terms = wp_get_post_terms( $post_id, 'product_cat', [ 'fields' => 'names' ] );
        return is_array( $terms ) ? implode( ', ', $terms ) : '';
    }

    /**
     * Obtiene atributos de producto como string.
     */
    private static function get_product_attributes( $product ): string {
        $attrs = $product->get_attributes();
        $parts = [];

        foreach ( $attrs as $attr ) {
            if ( is_object( $attr ) && method_exists( $attr, 'get_name' ) ) {
                $name    = wc_attribute_label( $attr->get_name() );
                $options = $attr->get_options();
                if ( $attr->is_taxonomy() ) {
                    $terms   = array_map( function ( $id ) { $t = get_term( $id ); return ( $t && ! is_wp_error( $t ) ) ? $t->name : ''; }, $options );
                    $options = array_filter( $terms );
                }
                $parts[] = $name . ': ' . implode( ', ', $options );
            }
        }

        return implode( ' | ', $parts );
    }

    /**
     * Descripción de longitud para el prompt de creación.
     *
     * El mapa es filtrable para que un addon pueda añadir extensiones propias
     * sin tocar este archivo. Una clave desconocida cae a la media en vez de
     * quedarse sin instrucción de longitud.
     */
    private static function length_description( string $length ): string {
        $map = apply_filters( 'hcsai_post_lengths', [
            'short'  => 'Corto: 400-600 palabras, 3-4 párrafos.',
            'medium' => 'Mediano: 800-1200 palabras, 5-7 párrafos.',
            'long'   => 'Largo: 1500-2000 palabras, 8-12 párrafos.',
        ] );

        return $map[ $length ] ?? ( $map['medium'] ?? '' );
    }

    /**
     * Variables comunes a todos los prompts: país, idioma y perfil del negocio.
     *
     * {{contexto_sitio}} pasa de ser el texto libre a ser el perfil completo,
     * con el enfoque de posicionamiento ya redactado. Así los prompts que el
     * usuario personalizó antes de existir el perfil lo reciben igual, sin
     * tener que editarlos.
     *
     * @return array<string, string>
     */
    public static function base_variables(): array {
        $perfil = Settings::business_profile();

        return [
            'pais'           => self::market_name(),
            'idioma'         => self::language_name(),
            'contexto_sitio' => self::business_context(),
            'rubro'          => $perfil['rubro'],
            'alcance'        => '' !== $perfil['alcance'] ? strtok( Settings::SCOPES[ $perfil['alcance'] ], ':' ) : '',
            'zona'           => $perfil['zona'],
            'publico'        => $perfil['publico'],
        ];
    }

    /**
     * El perfil del negocio en el formato en que lo lee la IA.
     *
     * Primero los datos y después el «enfoque de posicionamiento»: lo que esos
     * datos significan para los textos. Sin el enfoque, la IA recibía «Chile»
     * y «Concepción» y decidía sola si posicionar local o nacional.
     *
     * Solo aparecen las líneas con dato. Una instalación que solo tiene la
     * descripción recibe exactamente esa descripción, como antes.
     */
    public static function business_context(): string {
        $p      = Settings::business_profile();
        $pais   = self::country_name();
        $lineas = [];

        $datos = [
            'Tipo de sitio'     => '' !== $p['tipo'] ? Settings::SITE_TYPES[ $p['tipo'] ] : '',
            'Rubro o tematica'  => $p['rubro'],
            'A que se dedica'   => $p['descripcion'],
            'Publico objetivo'  => $p['publico'],
            'Diferenciadores'   => $p['diferenciadores'],
            'Alcance'           => '' !== $p['alcance'] ? Settings::SCOPES[ $p['alcance'] ] : '',
            'Zona'              => in_array( $p['alcance'], [ 'local', 'regional' ], true ) ? $p['zona'] : '',
            'Modalidad'         => '' !== $p['modalidad'] ? Settings::DELIVERY[ $p['modalidad'] ] : '',
        ];

        foreach ( $datos as $label => $valor ) {
            $valor = trim( preg_replace( '/\s+/', ' ', (string) $valor ) );
            if ( '' !== $valor ) {
                $lineas[] = '- ' . $label . ': ' . $valor;
            }
        }

        $enfoque = array_filter( [
            self::focus_for_type( $p['tipo'] ),
            self::focus_for_scope( $p['alcance'], trim( $p['zona'] ), $pais ),
            self::focus_for_delivery( $p['modalidad'] ),
        ] );

        if ( empty( $lineas ) ) {
            return 'Sin datos del negocio: deduce el rubro a partir del contenido y no inventes datos de la empresa.';
        }

        if ( ! empty( $enfoque ) ) {
            $lineas[] = '';
            $lineas[] = 'Enfoque de posicionamiento:';
            foreach ( $enfoque as $linea ) {
                $lineas[] = '- ' . $linea;
            }
        }

        return implode( "\n", $lineas );
    }

    /** Qué buscan los textos según el tipo de sitio. */
    private static function focus_for_type( string $tipo ): string {
        $map = [
            'tienda'    => 'Objetivo: vender productos. Prioriza busquedas de compra (tipo de producto, marca, modelo, "comprar", "precio") y llamadas a la accion de compra.',
            'servicios' => 'Objetivo: conseguir clientes. Prioriza las busquedas de quien necesita el servicio y llamadas a la accion de contacto, cotizacion o reserva.',
            'digital'   => 'Objetivo: vender un producto o servicio digital. Prioriza busquedas por el problema que resuelve o el resultado que entrega, y llamadas a la accion de registro, prueba o compra online.',
            'contenido' => 'Objetivo: atraer lectores. Prioriza busquedas informativas (que es, como, guia, ideas, ejemplos) y llamadas a la accion de seguir leyendo. No vendas ni inventes ofertas.',
        ];

        return $map[ $tipo ] ?? '';
    }

    /**
     * Si las keywords llevan ubicación, y cuál.
     *
     * Es lo que decide entre posicionar local o nacional: con alcance
     * nacional se le prohíbe expresamente atar los textos a la ciudad de la
     * sede, que era lo que dejaba a un negocio visible solo en su región.
     */
    private static function focus_for_scope( string $alcance, string $zona, string $pais ): string {
        switch ( $alcance ) {
            case 'local':
                return '' !== $zona
                    ? sprintf( 'Alcance local: el negocio atiende en %1$s. Usa "%1$s" de forma natural en el titulo SEO, la meta descripcion y, si la busqueda real la incluye, en la keyword principal (ej: "[servicio] en %1$s"). No nombres otras ciudades.', $zona )
                    : 'Alcance local, pero no se indico la ciudad: no inventes ninguna ubicacion.';
            case 'regional':
                return '' !== $zona
                    ? sprintf( 'Alcance regional: %1$s. Menciona "%1$s" cuando aporte, sobre todo en la meta descripcion, sin atar el texto a una sola ciudad.', $zona )
                    : 'Alcance regional, pero no se indico la region: no inventes ninguna ubicacion.';
            case 'nacional':
                return sprintf( 'Alcance nacional: clientes de todo %s. Usa keywords sin ciudades ni regiones, como las buscaria alguien de cualquier parte del pais. No nombres ciudades salvo que formen parte del producto o del tema.', $pais )
                    . ( '' !== $zona ? sprintf( ' La ubicacion de la sede (%s) no debe aparecer en titulos, keywords ni descripciones.', $zona ) : '' );
            case 'internacional':
                return sprintf( 'Alcance internacional: lectores o clientes de varios paises de habla %s. Usa vocabulario neutro, sin modismos, monedas, ciudades ni referencias legales de un solo pais.', self::language_name() );
        }

        return '';
    }

    /** Qué se puede prometer según cómo se entrega. */
    private static function focus_for_delivery( string $modalidad ): string {
        $map = [
            'presencial' => 'Atencion presencial: invita a visitar, llamar o agendar. No prometas envios ni atencion online.',
            'envio'      => 'Venta online con envio: puedes mencionar la compra online y la entrega, sin inventar plazos ni costos de envio.',
            'mixta'      => 'Local fisico y venta online: menciona la opcion que mejor encaje con cada texto.',
            'digital'    => 'Producto o servicio 100% digital: no menciones envios, despacho ni direccion fisica; destaca el acceso online.',
        ];

        return $map[ $modalidad ] ?? '';
    }

    /**
     * El mercado para el que se escribe, tal como se dice en el prompt.
     *
     * Con alcance internacional no tiene sentido decir "clientes de Chile":
     * el país configurado sigue siendo el de la empresa, pero el mercado son
     * varios.
     */
    private static function market_name(): string {
        if ( 'internacional' === Settings::business_profile()['alcance'] ) {
            return 'varios paises de habla ' . self::language_name();
        }

        return self::country_name();
    }

    /**
     * Nombre del país.
     */
    private static function country_name(): string {
        $countries = [ 'CL' => 'Chile', 'MX' => 'México', 'AR' => 'Argentina', 'CO' => 'Colombia', 'PE' => 'Perú', 'ES' => 'España', 'US' => 'Estados Unidos' ];
        $code = Settings::country();
        return $countries[ $code ] ?? $code;
    }

    /**
     * Nombre del idioma.
     */
    private static function language_name(): string {
        $languages = [ 'es' => 'español', 'en' => 'inglés', 'pt' => 'portugués', 'fr' => 'francés' ];
        $code = Settings::language();
        return $languages[ $code ] ?? $code;
    }

}
