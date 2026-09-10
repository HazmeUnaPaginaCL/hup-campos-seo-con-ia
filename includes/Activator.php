<?php
/**
 * Acciones de activación del plugin.
 * Crea la tabla de log, establece opciones por defecto y agenda el cron.
 *
 * @package HUP\CamposSeoIA
 */

namespace HUP\CamposSeoIA;

defined( 'ABSPATH' ) || exit;

class Activator {

    /**
     * Ejecuta al activar el plugin.
     */
    public static function activate(): void {
        self::create_log_table();
        self::set_default_options();
        self::check_version_update();
        self::schedule_log_purge();
    }

    /**
     * Se ejecuta en admin_init, sin necesidad de reactivar el plugin.
     * Sincroniza la versión guardada tras una actualización del código.
     */
    public static function run_migrations(): void {
        self::check_version_update();
    }

    /**
     * Sincroniza la versión guardada y ejecuta lo que haga falta al cambiarla.
     *
     * Se compara con `!==` y no con `version_compare( …, '<' )` a propósito: el
     * plugin resetea su numeración a 1.0.0 para la primera publicación, así que
     * una instalación de desarrollo puede tener guardada una versión MAYOR que
     * la del código. Con "menor que", ese caso no entraría nunca y la opción
     * quedaría desincronizada para siempre.
     *
     * No hay migraciones de datos: el plugin no se ha publicado nunca, así que
     * no existe ninguna instalación anterior que migrar. Lo que necesita una
     * instalación nueva lo cubre set_default_options().
     */
    private static function check_version_update(): void {
        if ( (string) get_option( HCSAI_PREFIX . 'version', '' ) === HCSAI_VERSION ) {
            return;
        }

        // Asegurar que la tabla de log exista y esté al día.
        self::create_log_table();

        // Y que la purga diaria del log esté agendada.
        self::schedule_log_purge();

        update_option( HCSAI_PREFIX . 'version', HCSAI_VERSION );
    }

    /**
     * Agenda el evento diario que purga el log, si no está ya agendado.
     *
     * Idempotente: se llama tanto al activar como al cambiar de versión.
     */
    public static function schedule_log_purge(): void {
        if ( ! wp_next_scheduled( Logger::PURGE_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Logger::PURGE_HOOK );
        }
    }

    /**
     * Crea la tabla de log de generaciones.
     */
    private static function create_log_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'hcsai_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_type   VARCHAR(20)     NOT NULL DEFAULT '',
            action      VARCHAR(30)     NOT NULL DEFAULT '',
            tokens_used INT UNSIGNED    NOT NULL DEFAULT 0,
            cost_usd    DECIMAL(10,6)   NOT NULL DEFAULT 0.000000,
            model       VARCHAR(60)     NOT NULL DEFAULT '',
            provider    VARCHAR(30)     NOT NULL DEFAULT '',
            status      VARCHAR(20)     NOT NULL DEFAULT 'ok',
            error_msg   TEXT            NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post_id    (post_id),
            KEY idx_created_at (created_at),
            KEY idx_provider   (provider)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Establece opciones por defecto (solo si no existen).
     */
    private static function set_default_options(): void {
        $defaults = [
            HCSAI_PREFIX . 'provider'       => 'openai',
            // El modelo se guarda por proveedor en 'model_{provider}' (ej. model_openai),
            // no en una opción 'model' genérica. Se establece al guardar configuración.
            HCSAI_PREFIX . 'country'        => 'CL',
            HCSAI_PREFIX . 'language'       => 'es',
            HCSAI_PREFIX . 'overwrite'      => '0',
            // Al desinstalar, las API keys se conservan salvo consentimiento explícito.
            HCSAI_PREFIX . 'delete_api_keys' => '0',
            HCSAI_PREFIX . 'usd_to_clp'     => '960',
            // Días que se conserva el log de generaciones (0 = para siempre).
            HCSAI_PREFIX . 'log_retention_days' => (string) Logger::DEFAULT_RETENTION_DAYS,
            HCSAI_PREFIX . 'prompt_product' => self::default_prompt_product(),
            HCSAI_PREFIX . 'prompt_post'    => self::default_prompt_post(),
            HCSAI_PREFIX . 'prompt_post_seo' => self::default_prompt_post_seo(),
            HCSAI_PREFIX . 'prompt_page'    => self::default_prompt_page(),
            HCSAI_PREFIX . 'prompt_improve' => self::default_prompt_improve(),
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }

    // ── Prompts por defecto ──────────────────────────────────────────────────
    //
    // El bloque de instrucciones y el formato JSON se componen a partir de
    // Settings::generated_fields(). Así el prompt pide exactamente los campos que
    // esta instalación va a guardar: ni menos (faltarían datos) ni más (se
    // pagarían tokens por campos que se descartan al escribir). Si el registro
    // cambia, los prompts por defecto lo recogen solos.

    /**
     * Instrucción que se le da a la IA para cada campo.
     *
     * Pública y filtrable a propósito: el texto vive en un solo sitio, así que
     * quien recomponga un prompt usa exactamente el mismo que los prompts por
     * defecto en vez de mantener una copia que se desincroniza. El filtro
     * permite además describir campos que este plugin no conoce.
     *
     * @return array<string, string> campo => línea de instrucción
     */
    public static function field_instructions(): array {
        return apply_filters( 'hcsai_field_instructions', [
            'seo_title'           => '- seo_title: titulo SEO optimizado, maximo 60 caracteres. Incluye la keyword principal cerca del inicio. Formato natural.',
            'meta_description'    => '- meta_description: entre 120 y 160 caracteres. Persuasiva, incluye la keyword principal y una llamada a la accion implicita.',
            'focus_keyword'       => '- focus_keyword: palabra o frase clave principal, 2 a 4 palabras. Debe reflejar como lo buscaria una persona real en {{pais}}.',
            'og_title'            => '- og_title: titulo para redes sociales (Open Graph), maximo 60 caracteres. Atractivo.',
            'og_description'      => '- og_description: descripcion para redes sociales, entre 100 y 200 caracteres. Persuasiva, orientada a generar clics.',
            'tags'                => '- tags: entre 5 y 8 etiquetas relevantes para SEO. Array de strings en minusculas.',
            'alt_text'            => '- alt_text: texto alternativo para la imagen principal, maximo 120 caracteres. Sin HTML.',
            'product_description' => '- product_description: descripcion completa del producto entre 150 y 300 palabras. Destaca beneficios, caracteristicas y usos concretos. SOLO TEXTO PLANO, sin HTML. No inventes especificaciones que no esten en los datos.',
            'short_description'   => '- short_description: descripcion corta y persuasiva, maximo 160 caracteres. Sin HTML.',
            'post_title'          => '- post_title: titulo atractivo y optimizado para SEO. Maximo 60 caracteres.',
            'post_content'        => '- post_content: contenido completo con parrafos separados por saltos de linea dobles y subtitulos con ##Titulo##. Incluye introduccion, desarrollo y conclusion. Sin HTML.',
        ] );
    }

    /**
     * Bloque de instrucciones para una lista de campos.
     *
     * Pública para poder componer el mismo texto que usan los prompts por
     * defecto, en vez de mantener una segunda copia que se desincronice.
     *
     * @param array<string> $fields
     */
    public static function instructions_block( array $fields ): string {
        $map   = self::field_instructions();
        $lines = [];

        foreach ( $fields as $field ) {
            if ( isset( $map[ $field ] ) ) {
                $lines[] = $map[ $field ];
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Cierre común: reglas de formato y el JSON exacto que se espera de vuelta.
     *
     * Pública por el mismo motivo que instructions_block(): recomponer un
     * prompt exige rehacer esta parte con la lista completa de campos.
     *
     * @param array<string> $fields
     */
    public static function json_footer( array $fields ): string {
        $parts = [];

        foreach ( $fields as $field ) {
            $parts[] = ( 'tags' === $field )
                ? '"tags":["tag1","tag2"]'
                : sprintf( '"%s":"..."', $field );
        }

        return "\n\nIMPORTANTE: Responde UNICAMENTE con JSON valido. Sin HTML. Sin comillas dobles dentro de los valores de texto.\nFormato exacto:\n"
            . '{' . implode( ',', $parts ) . '}';
    }


    /**
     * Campos SEO que genera esta instalación.
     */
    private static function seo_fields( string $post_type ): array {
        return Settings::fields_for( $post_type );
    }

    public static function default_prompt_product(): string {
        $fields = self::seo_fields( 'product' );

        return 'Eres un experto en SEO para e-commerce en {{pais}}. Tu objetivo es optimizar productos para buscadores en {{idioma}}.

Contexto del negocio: {{contexto_sitio}}

Datos del producto:
- Nombre: {{nombre}}
- Categoria: {{categoria}}
- SKU: {{sku}}
- Precio: {{precio}}
- Descripcion actual: {{descripcion}}
- Atributos: {{atributos}}

INSTRUCCIONES:
' . self::instructions_block( $fields ) . '

REGLAS GENERALES:
- Si el nombre o SKU contiene especificaciones tecnicas (medidas, modelos, versiones), usalas como base de la keyword principal.
- No inventes datos que no esten en el nombre, categoria, SKU o atributos.
- Escribe en {{idioma}} con tono profesional y cercano, sin tecnicismos innecesarios.' . self::json_footer( $fields );
    }

    /**
     * Prompt para CREAR un post nuevo desde cero.
     *
     * Distinto de default_prompt_post_seo(), que optimiza uno que ya existe.
     */
    public static function default_prompt_post(): string {
        $fields = array_merge( [ 'post_title', 'post_content' ], self::seo_fields( 'post' ) );

        return 'Eres un experto en SEO y redaccion de contenido web en {{pais}}. Escribes en {{idioma}}.

Contexto del negocio: {{contexto_sitio}}

Crea un post de blog completo sobre: {{tema}}
Tono: {{tono}}
Extension: {{largo}}

INSTRUCCIONES:
' . self::instructions_block( $fields ) . '
- La extension del contenido debe ajustarse a {{largo}}.

REGLAS GENERALES:
- El contenido debe aportar valor real al lector, no ser relleno.
- Menciona el negocio de forma natural maximo 1 o 2 veces si es relevante.
- Escribe en {{idioma}} con el tono indicado en {{tono}}.' . self::json_footer( $fields );
    }

    /**
     * Prompt para OPTIMIZAR el SEO de un post ya existente.
     *
     * Distinto de default_prompt_post(), que sirve para CREAR un post nuevo y
     * usa {{tema}}/{{tono}}/{{largo}}. Aquí el post ya existe, así que las
     * variables disponibles son {{nombre}}, {{categoria}} y {{descripcion}}.
     */
    public static function default_prompt_post_seo(): string {
        $fields = self::seo_fields( 'post' );

        return 'Eres un experto en SEO de contenidos en {{pais}}. Optimizas articulos de blog para buscadores en {{idioma}}.

Contexto del negocio: {{contexto_sitio}}

Datos del post:
- Titulo: {{nombre}}
- Categoria: {{categoria}}
- Contenido actual: {{descripcion}}

INSTRUCCIONES:
' . self::instructions_block( $fields ) . '

REGLAS GENERALES:
- Trabaja SOBRE el contenido existente: no inventes un tema distinto ni contradigas lo que ya dice el post.
- No generes contenido nuevo para el post. Solo campos SEO.
- Si el contenido actual esta vacio o es insuficiente, infiere el tema desde el titulo, la categoria y el contexto del negocio.
- Escribe en {{idioma}}.' . self::json_footer( $fields );
    }

    public static function default_prompt_page(): string {
        $fields = self::seo_fields( 'page' );

        return 'Eres un experto en SEO en {{pais}}. Optimizas paginas web para buscadores en {{idioma}}.

Contexto del negocio: {{contexto_sitio}}

Datos de la pagina:
- Titulo: {{nombre}}
- Contenido actual: {{descripcion}}

INSTRUCCIONES:
' . self::instructions_block( $fields ) . '

REGLAS GENERALES:
- Si el contenido actual esta vacio o es insuficiente, infiere el proposito de la pagina desde su titulo y el contexto del negocio.
- No inventes informacion que contradiga el contenido existente.
- Escribe en {{idioma}}.' . self::json_footer( $fields );
    }

    public static function default_prompt_improve(): string {
        $fields = array_merge( [ 'post_title', 'post_content' ], self::seo_fields( 'post' ) );

        return 'Eres un experto en SEO y redaccion web en {{pais}}. Escribe en {{idioma}}.

Contexto del negocio: {{contexto_sitio}}

POST ACTUAL:
Titulo: {{titulo}}
Contenido: {{contenido}}

INSTRUCCIONES:
{{instrucciones}}' . self::json_footer( $fields );
    }
}
