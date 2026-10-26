<?php
declare(strict_types=1);

namespace Quasar\Ai;

/**
 * Proveedor de modelo de lenguaje. Recibe instrucciones fijas (cacheables), el mensaje del
 * usuario y un JSON Schema, y devuelve el JSON que cumple el esquema con el consumo de tokens.
 * Para añadir otro proveedor: una clase que implemente esta interfaz y su nombre en LlmFactory.
 */
interface LlmProvider
{
    /**
     * @param string $system instrucciones estables (el proveedor puede cachearlas)
     * @param string $user   mensaje variable (fecha, frase…)
     * @param array<string,mixed> $schema JSON Schema de la respuesta
     * @throws LlmException si no hay respuesta válida
     */
    public function complete(string $system, string $user, array $schema): LlmResponse;

    /** Identificador corto para el log ("anthropic"). */
    public function name(): string;

    /** Modelo que se usa. */
    public function model(): string;
}
