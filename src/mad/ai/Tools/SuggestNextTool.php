<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\SseSink;

/**
 * SuggestNextTool — próximos passos como CHIPS clicáveis no fim do turno.
 *
 * Irmã leve da AskUserTool: SEM callout e SEM semântica de bloqueio — é a
 * oferta de aprofundamento ("perfil dos não convertidos?", "tendência mês a
 * mês?") que o modelo antes enterrava como pergunta no meio do texto, onde
 * ninguém via. Vira follow chips (SseSink::setFollow → message_end{follow}),
 * renderizados pelo widget acima do composer; clicar envia o prompt como a
 * próxima mensagem.
 *
 * Contrato de uso (renderPrompt): TODA resposta de dados fecha com uma
 * chamada desta tool no lugar de pergunta em prosa. Pergunta BLOQUEANTE
 * (falta parâmetro) continua sendo ask_user.
 */
final class SuggestNextTool implements Tool
{
    private const MAX_OPTIONS = 4;

    public function __construct(private SseSink $sink)
    {
    }

    public function name(): string
    {
        return 'suggest_next';
    }

    public function description(): string
    {
        return 'Ofereça 2-4 PRÓXIMOS PASSOS clicáveis (chips) ao final de uma resposta de dados — aprofundamentos, '
            . 'recortes ou comparações que você faria em seguida. Use SEMPRE no lugar de terminar com pergunta em '
            . 'prosa ("Quer que eu…?"). Chame como ÚLTIMA ação do turno; não repita as opções no texto. Para '
            . 'pergunta BLOQUEANTE (falta parâmetro essencial) use ask_user.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'options' => $schema->array()->description(
                '2 a 4 sugestões. Cada item: {label: rótulo curto do chip (ex.: "Perfil dos não convertidos"), '
                . 'prompt?: pergunta completa enviada ao clicar (default = label)}.'
            )->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $raw = $request->all()['options'] ?? [];
        $raw = is_array($raw) ? array_slice(array_values($raw), 0, self::MAX_OPTIONS) : [];

        $chips = [];
        foreach ($raw as $i => $opt) {
            if (is_string($opt)) {
                $opt = ['label' => $opt];
            }
            if (! is_array($opt)) {
                continue;
            }
            $label = trim((string) ($opt['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $chips[] = [
                'id'     => 'next-' . ($i + 1),
                'label'  => mb_substr($label, 0, 48),
                'prompt' => trim((string) ($opt['prompt'] ?? '')) ?: $label,
            ];
        }

        if (count($chips) < 2) {
            return 'suggest_next requer 2+ options.';
        }

        $this->sink->setFollow($chips);

        return 'Sugestões exibidas como chips clicáveis (' . count($chips) . '). Feche o turno com a leitura final '
            . 'SEM repetir as opções em texto e SEM pergunta em prosa.';
    }
}
