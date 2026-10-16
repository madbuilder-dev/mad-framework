<?php

declare(strict_types=1);

namespace Mad\Ui\Search;

/**
 * Provedor de REGISTROS da busca ⌘K / Ctrl K: um por tipo de registro
 * (atividades, riscos, pessoas…). Registro em {@see MadSearch}.
 *
 * Roda dentro da requisição do app, na sessão do usuário: os escopos globais
 * dos models (isolamento por projeto, tenant) valem como em qualquer tela.
 * Regra de visibilidade que NÃO está num escopo global (registro "restrito",
 * só do responsável) é responsabilidade do próprio provedor, no `search()`.
 */
interface RecordSearchProvider
{
    /** Identificador do grupo (único entre os provedores). Ex.: "atividade". */
    public function key(): string;

    /** Título do grupo na busca. Ex.: "Atividades". */
    public function label(): string;

    /** Ícone padrão dos itens, no formato do menu ("lucide:list-checks", "fas:user"). */
    public function icon(): string;

    /**
     * Tela que o usuário precisa poder abrir para o grupo aparecer (o mesmo
     * gate das rotas). null = todo usuário logado.
     */
    public function program(): ?string;

    /**
     * Registros que casam com o termo, no máximo `$limit`.
     *
     * @return iterable<SearchResult|array<string, mixed>>
     */
    public function search(string $term, int $limit): iterable;
}
