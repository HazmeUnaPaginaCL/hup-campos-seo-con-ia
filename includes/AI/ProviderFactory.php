<?php
/**
 * Factory para crear instancias de proveedores de IA.
 * Patrón Registry — permite registrar nuevos proveedores en tiempo de ejecución.
 *
 * Para agregar un nuevo proveedor:
 * 1. Crear la clase que extiende AbstractProvider
 * 2. Registrarla: ProviderFactory::register( 'slug', NuevoProvider::class )
 *
 * @package HUP\CamposSeoIA\AI
 */

namespace HUP\CamposSeoIA\AI;

defined( 'ABSPATH' ) || exit;

class ProviderFactory {

    /**
     * Registro de proveedores: slug => class name.
     *
     * @var array<string, string>
     */
    private static array $providers = [];

    /**
     * Registra un proveedor de IA.
     *
     * @param string $slug       Identificador único (ej: 'openai', 'google', 'claude').
     * @param string $class_name Nombre completo de la clase (debe implementar ProviderInterface).
     */
    public static function register( string $slug, string $class_name ): void {
        self::$providers[ $slug ] = $class_name;
    }

    /**
     * Crea una instancia del proveedor solicitado.
     *
     * @param string $slug Slug del proveedor.
     * @return ProviderInterface
     * @throws \InvalidArgumentException Si el proveedor no está registrado.
     */
    public static function create( string $slug ): ProviderInterface {
        if ( ! isset( self::$providers[ $slug ] ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Proveedor de IA "%s" no registrado. Disponibles: %s', esc_html( $slug ), esc_html( implode( ', ', array_keys( self::$providers ) ) ) )
            );
        }

        $class = self::$providers[ $slug ];
        $instance = new $class();

        if ( ! $instance instanceof ProviderInterface ) {
            throw new \InvalidArgumentException(
                sprintf( 'La clase "%s" no implementa ProviderInterface.', esc_html( $class ) )
            );
        }

        return $instance;
    }

    /**
     * Verifica si un proveedor está registrado.
     */
    public static function has( string $slug ): bool {
        return isset( self::$providers[ $slug ] );
    }

    /**
     * Crea el proveedor activo configurado en Settings.
     *
     * @return ProviderInterface
     */
    public static function create_active(): ProviderInterface {
        $slug = \HUP\CamposSeoIA\Settings::provider();
        return self::create( $slug );
    }
}
