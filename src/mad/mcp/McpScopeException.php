<?php

namespace Mad\Mcp;

/**
 * McpScopeException
 *
 * Lancada quando uma entidade exposta ao MCP nao tem politica de row-scope
 * resolvivel (fail-closed, FURO #2) ou quando uma operacao tenta tocar um
 * registro fora do escopo do usuario (FURO #3). Sinaliza negacao de acesso,
 * nao erro de programacao.
 */
final class McpScopeException extends \RuntimeException
{
}
