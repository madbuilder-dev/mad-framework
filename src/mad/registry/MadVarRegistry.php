<?php
namespace Mad\Registry;
use Mad\Component\MadComponentHandler;
use Mad\Http\MadResponse;


/**
 * MadVarRegistry — Registry de variáveis do template para auto-bind inteligente.
 *
 * Durante o render, os templates registram quais variáveis existem e seu tipo:
 *   - 'val'        → campo de formulário (<input name="X">)
 *   - 'completion'  → source de autocomplete (data-mad-autocomplete="X")
 *
 * O MadComponentHandler consulta o registry para gerar ops granulares
 * quando uma action retorna void (sem MadResponse explícito):
 *   - Escalar mudou + tipo 'val' → gera val op (atualiza input)
 *   - Array mudou + tipo 'completion' → gera reload_completion op
 *   - Array mudou sem registro → full re-render (comportamento padrão)
 */
class MadVarRegistry
{
    /** @var array<string, string> nome → tipo ('completion', 'val', ...) */
    private static array $vars = [];

    /**
     * Registra uma variável com seu tipo.
     */
    public static function register(string $name, string $type): void
    {
        self::$vars[$name] = $type;
    }

    /**
     * Retorna o tipo registrado ou null se não registrado.
     */
    public static function getType(string $name): ?string
    {
        return self::$vars[$name] ?? null;
    }

    /**
     * Limpa o registry. Chamado antes de cada render.
     */
    public static function reset(): void
    {
        self::$vars = [];
    }
}