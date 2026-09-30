<?php
namespace Mad\Ui;
use Mad\Http\MadResponse;


/**
 * MadTransporter — Helper para controle de transporters via PHP.
 *
 * Inspirado no Star Trek: o transporter desmaterializa e rematerializa matéria.
 * Aqui, ele carrega e recarrega componentes via AJAX.
 *
 * Uso:
 *   return MadTransporter::energize('produtos');
 *   return MadTransporter::energize('produtos', ['status' => 1]);
 */
class MadTransporter
{
    /**
     * Header que o navegador manda quando a tela é pedida PARA DENTRO de um
     * <mad-transporter> (o GET do `Mad.get` e todo POST do wire de um
     * componente que mora nele). Valor = o `header` do transporter.
     */
    public const EMBED_HEADER = 'X-Mad-Embed';

    /** Modos do `header` do <mad-transporter>. */
    public const EMBED_MODES = ['compact', 'title', 'full'];

    /**
     * Recarrega um transporter por nome.
     * Retorna um MadResponse pronto para ser retornado de um action handler.
     */
    public static function energize(string $name, array $params = []): MadResponse
    {
        return (new MadResponse())->energize($name, $params);
    }

    /**
     * Como a tela deve desenhar o próprio cabeçalho, quando está embutida.
     *
     *   null      tela aberta sozinha (sem transporter) — cabeçalho normal;
     *   'compact' embutida: sem cartão de página, sem título/ícone/breadcrumb,
     *             só a barra de ações (o "Novo" da lista embutida) — padrão;
     *   'title'   embutida, com o título da tela em linha compacta;
     *   'full'    embutida, mas com o cabeçalho completo (como antes).
     *
     * Lido por page-container.blade.php e page-header.blade.php. O modo vem do
     * header da requisição, e não do estado do componente, para valer também
     * nos redesenhos (MadWire) da tela embutida: o mad-livewire.js repete o
     * header em todo POST de componente que está dentro de um transporter.
     */
    public static function embedHeader(): ?string
    {
        $raw = '';
        if (function_exists('request')) {
            try {
                $raw = (string) request()->header(self::EMBED_HEADER, '');
            } catch (\Throwable) {
                $raw = '';
            }
        }
        if ($raw === '') {
            $raw = (string) ($_SERVER['HTTP_X_MAD_EMBED'] ?? '');
        }
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }

        return in_array($raw, self::EMBED_MODES, true) ? $raw : 'compact';
    }

    /**
     * `:params` do <mad-transporter> → array de parâmetros da tela embutida.
     *
     * Aceita array PHP (`:params="['pedido_id' => $id]"`), coleção/objeto e
     * string JSON (`:params="json_encode([...])"`, a forma histórica). Antes
     * só a string JSON era lida: um array PHP sumia calado e a tela embutida
     * abria sem o filtro — a lista mostrava os itens de TODOS os registros.
     *
     * Vazio devolve []. Valor presente mas ilegível devolve null: o blade
     * avisa (log + console) em vez de engolir.
     */
    public static function decodeParams(mixed $params): ?array
    {
        if ($params === null || $params === '' || $params === false) {
            return [];
        }
        if (is_array($params)) {
            return $params;
        }
        if ($params instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $params->toArray();
        }
        if ($params instanceof \JsonSerializable) {
            $v = $params->jsonSerialize();

            return is_array($v) ? $v : null;
        }
        if (is_object($params)) {
            return get_object_vars($params);
        }
        if (! is_string($params)) {
            return null;
        }
        $s = trim($params);
        if ($s === '') {
            return [];
        }
        $v = json_decode($s, true);

        return is_array($v) ? $v : null;
    }
}
