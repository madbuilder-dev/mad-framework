<?php
namespace Mad\Ui;
use Mad\Component\MadComponent;
use Mad\Http\MadResponse;


/**
 * MadMessage — factory para diálogos informativos e de confirmação.
 *
 * ┌─ Mensagens informativas ──────────────────────────────────────────────────┐
 * │                                                                           │
 * │   return MadMessage::success('Salvo!', 'Registro salvo com sucesso.');    │
 * │   return MadMessage::warning('Atenção', 'Verifique os campos.');          │
 * │   return MadMessage::error('Erro', 'Não foi possível salvar.');           │
 * │   return MadMessage::info('Dica', 'Use Ctrl+S para salvar.', 'lightbulb');│
 * │                                                                           │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ Confirmação com ação ─────────────────────────────────────────────────────┐
 * │                                                                            │
 * │   // Passa $this para que os botões chamem métodos deste componente        │
 * │   return MadMessage::confirm('Excluir', 'Confirmar exclusão #42?', $this) │
 * │       ->onConfirm('deletar', [42])                                        │
 * │       ->onCancel()                                                        │
 * │       ->build();                                                          │
 * │                                                                            │
 * │   // Múltiplos botões customizados                                        │
 * │   return MadMessage::confirm('Publicar', 'Escolha a ação:', $this)        │
 * │       ->button('Publicar agora',   'publicar',      [$id], 'primary')     │
 * │       ->button('Salvar rascunho',  'salvarRascunho', [$id])               │
 * │       ->onCancel()                                                        │
 * │       ->build();                                                          │
 * │                                                                            │
 * │   // Pedir valores antes da ação (TInputDialog): $this->form->get('data') │
 * │   return MadMessage::prompt('Baixar', 'Data do pagamento:', $this)        │
 * │       ->field('data', 'Data', 'date', required: true)                     │
 * │       ->onConfirm('onBaixar', [], 'Baixar')->onCancel()->build();         │
 * │                                                                            │
 * └────────────────────────────────────────────────────────────────────────────┘
 */
class MadMessage
{
    // ── Mensagens informativas ────────────────────────────────────────────────

    public static function info(string $title, string $content, string $icon = ''): MadResponse
    {
        return self::_show('info', $title, $content, $icon);
    }

    public static function success(string $title, string $content, string $icon = ''): MadResponse
    {
        return self::_show('success', $title, $content, $icon);
    }

    public static function warning(string $title, string $content, string $icon = ''): MadResponse
    {
        return self::_show('warning', $title, $content, $icon);
    }

    public static function error(string $title, string $content, string $icon = ''): MadResponse
    {
        return self::_show('error', $title, $content, $icon);
    }

    // ── Confirmação ───────────────────────────────────────────────────────────

    /**
     * Inicia um builder de confirmação com botões de ação.
     *
     * @param string                  $title     Título do dialog
     * @param string                  $content   Mensagem (HTML permitido)
     * @param MadComponent|string     $component Componente ou mad-id para os callbacks
     *                                           ($this na maioria dos casos)
     * @param string                  $icon      Ícone Lucide customizado
     */
    public static function confirm(
        string $title,
        string $content,
        MadComponent|string $component = '',
        string $icon = ''
    ): MadConfirm {
        $madId = match (true) {
            $component instanceof MadComponent => $component->_getId(),
            is_string($component)             => $component,
            default                           => '',
        };

        return new MadConfirm($title, $content, $madId, $icon ?: 'alert-triangle');
    }

    /**
     * Diálogo que PEDE valores antes de chamar a ação (o TInputDialog do
     * Adianti): mesmo builder do confirm(), com campos via field(). O valor
     * digitado chega ao método do botão no MadForm — `$this->form->get('campo')`.
     *
     *   return MadMessage::prompt('Reajuste', 'Percentual a aplicar nos marcados:', $this)
     *       ->field('percentual', 'Percentual (%)', 'number', required: true)
     *       ->onConfirm('onReajustar', [], 'Aplicar')
     *       ->onCancel()
     *       ->build();
     */
    public static function prompt(
        string $title,
        string $content = '',
        MadComponent|string $component = '',
        string $icon = ''
    ): MadConfirm {
        $madId = match (true) {
            $component instanceof MadComponent => $component->_getId(),
            is_string($component)             => $component,
            default                           => '',
        };

        return new MadConfirm($title, $content, $madId, $icon ?: 'pencil', 'info');
    }

    // ── Interno ───────────────────────────────────────────────────────────────

    private static function _show(string $type, string $title, string $content, string $icon): MadResponse
    {
        $opts = ['type' => $type, 'title' => $title, 'message' => $content];
        if ($icon) {
            $opts['icon'] = $icon;
        }

        $js = 'MadDialog.show(' . json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ')';
        return (new MadResponse())->script($js);
    }
}