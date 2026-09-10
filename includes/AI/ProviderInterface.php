<?php
/**
 * Contrato para proveedores de IA.
 * Todo proveedor nuevo (Claude, DeepSeek, etc.) debe implementar esta interfaz.
 *
 * @package HUP\CamposSeoIA\AI
 */

namespace HUP\CamposSeoIA\AI;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

    /**
     * Identificador único del proveedor.
     *
     * @return string Ej: 'openai', 'google', 'claude'
     */
    public function get_slug(): string;

    /**
     * Nombre visible del proveedor.
     *
     * @return string Ej: 'OpenAI (ChatGPT)', 'Google (Gemini)'
     */
    public function get_display_name(): string;

    /**
     * Genera contenido SEO usando la IA.
     *
     * @param string $prompt     Prompt completo a enviar.
     * @param string $model      Modelo a usar.
     * @param string $api_key    API key desencriptada.
     * @param array  $schema     Schema JSON esperado en la respuesta (opcional).
     * @param int    $max_tokens Máximo de tokens de respuesta.
     * @param bool   $use_cache  Si es true, puede devolver una respuesta cacheada (transient 12h).
     *                           Las acciones individuales/regenerar deben pasar false para forzar
     *                           una respuesta fresca; el batch masivo usa true para evitar doble cobro.
     *
     * @return array{text: string, tokens_input: int, tokens_output: int}
     * @throws \RuntimeException Si hay error en la petición.
     */
    public function generate( string $prompt, string $model, string $api_key, array $schema = [], int $max_tokens = 2048, bool $use_cache = true ): array;

    /**
     * Prueba la conexión con la API.
     *
     * @param string $model   Modelo a probar.
     * @param string $api_key API key desencriptada.
     *
     * @return array{success: bool, message: string}
     */
    public function test_connection( string $model, string $api_key ): array;
}
