<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\SseSink;

/**
 * AskUserTool — o agente PERGUNTA ao usuário com opções clicáveis.
 *
 * Emite a pergunta como bloco `callout` e registra as opções como follow
 * chips (SseSink::setFollow → saem no message_end do turno). Clicar num chip
 * envia o `prompt` (ou o label) como a próxima mensagem do usuário — o mesmo
 * trilho dos follow chips nativos do widget, zero mudança de frontend.
 *
 * Uso pelo modelo: quando faltar um parâmetro-chave e chutar seria pior que
 * perguntar (período ambíguo, qual unidade, qual recorte). O retorno instrui
 * a ENCERRAR o turno — a resposta chega como mensagem nova.
 */
final class AskUserTool implements Tool
{
    private const MAX_OPTIONS = 4;

    public function __construct(private SseSink $sink)
    {
    }

    public function name(): string
    {
        return 'ask_user';
    }

    public function description(): string
    {
        return 'Pergunte algo ao usuário com até 4 opções clicáveis (chips). Use quando faltar um parâmetro-chave '
            . '(período, unidade, recorte, métrica) e chutar seria pior que perguntar. Depois de chamar, ENCERRE o '
            . 'turno — a escolha do usuário chega como a próxima mensagem. NÃO use para confirmar escritas '
            . '(confirm_action) nem para perguntas abertas simples (faça em texto).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->description('A pergunta, curta e direta.')->required(),
            'options'  => $schema->array()->description(
                '2 a 4 opções. Cada item: {label: rótulo curto do chip, prompt?: texto enviado ao clicar '
                . '(default = label)}. A última pode ser um "Outro…" aberto.'
            )->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $all      = $request->all();
        $question = trim((string) ($all['question'] ?? ''));
        $raw      = $all['options'] ?? [];
        $raw      = is_array($raw) ? array_slice(array_values($raw), 0, self::MAX_OPTIONS) : [];

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
                'id'     => 'ask-' . ($i + 1),
                'label'  => mb_substr($label, 0, 48),
                'prompt' => trim((string) ($opt['prompt'] ?? '')) ?: $label,
            ];
        }

        if ($question === '' || count($chips) < 2) {
            return 'ask_user requer question e 2+ options — pergunte em texto simples.';
        }

        $this->sink->block(['type' => 'callout', 'intent' => 'info', 'title' => 'Escolha uma opção', 'text' => $question]);
        $this->sink->setFollow($chips);

        return 'Pergunta exibida com ' . count($chips) . ' opções clicáveis. ENCERRE o turno agora (sem chamar '
            . 'mais tools e sem repetir as opções em texto); a escolha do usuário chegará como a próxima mensagem.';
    }
}
