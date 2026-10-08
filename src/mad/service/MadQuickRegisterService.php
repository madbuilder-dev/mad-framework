<?php

namespace Mad\Service;

use Mad\Component\MadComponent;
use Mad\Http\MadRequest;
use Mad\Http\MadStateCrypt;

/**
 * MadQuickRegisterService — endpoint AJAX para o "quick register" dos
 * componentes de selecao MAD (dbcombo, dbunique-search, select-check, etc).
 *
 * Quando o usuario digita algo que nao existe no combo, o dropdown oferece um
 * input + botao que cadastra o registro inline e seleciona automaticamente no
 * combo — tudo sem sair da tela (ver MadNoResultsHelper).
 *
 * A config (classe/metodo do criador + field_name) e criptografada server-side
 * no render do Blade via MadStateCrypt::encrypt() e embutida no DOM como
 * token opaco. O cliente so envia { token, term }.
 *
 * Request (POST):
 *   token       string   Config criptografada (class, method, field_name, ...)
 *   term        string   Texto digitado no campo de busca do MAD Select
 *   field[name] mixed    Valores extras dos campos do quick-form (modo multi)
 *
 * Response:
 *   { ok: true, value: '12', label: 'Fulano', toast: 'Cadastrado!' }
 *   { ok: false, error: 'Mensagem de erro' }
 *
 * O callable configurado (ex: ClienteForm::quickRegister) recebe:
 *   $params = ['term' => ..., 'fields' => [...], 'field_name' => ..., ...]
 * e deve retornar ['value' => $id, 'label' => $texto, 'toast' => '...'?]
 * OU lancar Throwable em caso de erro.
 *
 * Estende MadComponent apenas pelo contrato do dispatcher.
 * Chamado via: /app/MadQuickRegisterService/onRun?static=1
 */
class MadQuickRegisterService extends MadComponent
{
    public static function onRun(array $param = []): void
    {
        header('Content-Type: application/json');

        try {
            $token = (string) ($param['token'] ?? '');
            $term  = trim((string) ($param['term'] ?? ''));

            if ($token === '') {
                throw new \Exception('Token ausente.');
            }

            $cfg = MadStateCrypt::decryptFor('quick-register', $token);
            if (!is_array($cfg) || empty($cfg['class']) || empty($cfg['method'])) {
                throw new \Exception('Configuracao invalida ou corrompida.');
            }

            // Campos extras enviados no modo multi-campo. Vem como `field[nome]=valor`
            // no FormData. Aplicamos allow-list (campos definidos no quick-fields)
            // pra evitar mass-assignment de qualquer chave POST.
            // allowedFields/nomes de campo podem vir embrulhados pelo gerador como
            // '{coluna}' (sintaxe template do MadBuilder). Para PERSISTENCIA isso e
            // um nome de coluna — desembrulha '{nome}' → 'nome'. Allow-list e key
            // normalizadas JUNTAS pra o in_array continuar batendo.
            $allowed = array_map(
                fn($a) => self::unwrapColumn((string) $a),
                (array)($cfg['allowedFields'] ?? [])
            );
            $fields  = [];
            $rawFields = $param['field'] ?? ($_POST['field'] ?? []);
            // Sem quick-fields (modo simples) NENHUM campo extra é aceito: o
            // navegador só manda o termo. Lista vazia valia "aceita tudo", e o
            // método gerado atribui cada campo direto no Model — bastava
            // acrescentar `field[coluna]` à requisição para gravar qualquer
            // coluna do registro criado, inclusive as que o `$fillable` barra.
            $refused = [];
            if (is_array($rawFields)) {
                foreach ($rawFields as $k => $v) {
                    $k = self::unwrapColumn((string) $k);
                    if (in_array($k, $allowed, true)) {
                        $fields[$k] = is_array($v) ? $v : (string) $v;
                    } elseif ($k !== '') {
                        $refused[] = $k;
                    }
                }
            }
            if ($refused) {
                sort($refused);
                $quem = null;
                try {
                    $quem = function_exists('session') ? session('userid') : null;
                } catch (\Throwable) {
                    // sem sessão: o aviso sai sem o usuário
                }
                $msg = sprintf(
                    '[MadQuickRegisterService] cadastro rápido de %s recebeu campos que o combo não tem (%s) — ignorados (usuário %s)',
                    (string) ($cfg['class'] ?? '') . '::' . (string) ($cfg['method'] ?? ''),
                    substr((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', implode(', ', $refused)), 0, 300),
                    is_scalar($quem) && $quem !== '' ? (string) $quem : '-',
                );
                function_exists('logger') ? logger()->warning($msg) : error_log($msg);
            }

            // Se nao e modo multi-campo, exige term preenchido (compatibilidade).
            if (empty($fields) && $term === '') {
                throw new \Exception(__('service.qr_empty_value'));
            }

            $class  = (string) $cfg['class'];
            $method = (string) $cfg['method'];

            if (!class_exists($class)) {
                throw new \Exception("Classe nao encontrada: {$class}");
            }
            if (!method_exists($class, $method)) {
                throw new \Exception("Metodo nao encontrado: {$class}::{$method}");
            }

            // Valida que e estatico (seguranca — so aceita metodos estaticos
            // declarados explicitamente pelo dev; nao criamos instancia do componente).
            $ref = new \ReflectionMethod($class, $method);
            if (!$ref->isStatic()) {
                throw new \Exception("O metodo {$class}::{$method} precisa ser estatico.");
            }
            if (!$ref->isPublic()) {
                throw new \Exception("O metodo {$class}::{$method} precisa ser publico.");
            }

            // Monta payload pro callable do dev
            $payload = [
                'term'       => $term,
                'fields'     => $fields, // [] em modo simples; [name => value] em multi
                'field_name' => (string)($cfg['field_name'] ?? ''),
                'model'      => (string)($cfg['model']      ?? ''),
                'database'   => (string)($cfg['database']   ?? ''),
                'key'        => (string)($cfg['key']        ?? 'id'),
                'display'    => self::unwrapColumn((string)($cfg['display'] ?? '')),
            ];

            $args = self::resolveArgs($ref, $payload);
            $result = $ref->invokeArgs(null, $args);

            if (!is_array($result) || !array_key_exists('value', $result) || !array_key_exists('label', $result)) {
                throw new \Exception('O callable deve retornar array com keys value e label.');
            }

            echo json_encode([
                'ok'    => true,
                'value' => (string) $result['value'],
                'label' => (string) $result['label'],
                'toast' => (string) ($result['toast'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            // O callable usa DB::transaction() (auto-rollback no throw) — sem cleanup manual.
            echo json_encode([
                'ok'    => false,
                // Erro técnico vira aviso amigável (detalhe no log); texto do
                // callable (regra do app) passa como está.
                'error' => \Mad\Ui\MadUserError::message($e, mad_t('mad.error.save_failed'), 'MadQuickRegisterService'),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Resolve argumentos do callable. Suporta:
     *  - array $params  (recebe $payload inteiro)
     *  - MadRequest $req (injeta MadRequest com $payload)
     *  - sem argumentos (nao passa nada)
     *
     * Fallback: passa $payload como primeiro argumento.
     */
    private static function resolveArgs(\ReflectionMethod $ref, array $payload): array
    {
        $params = $ref->getParameters();
        if (empty($params)) {
            return [];
        }
        if (count($params) === 1) {
            $type = $params[0]->getType();
            if ($type instanceof \ReflectionNamedType) {
                $name = $type->getName();
                if ($name === 'array') {
                    return [$payload];
                }
                if ($name === MadRequest::class) {
                    return [new MadRequest($payload)];
                }
            }
        }

        return [$payload];
    }

    /**
     * Desembrulha um nome de coluna emitido pelo gerador como template '{col}'.
     * '{nome}' → 'nome'. Mantem intactos nomes ja limpos ('nome') e templates
     * multi-coluna ('{a} {b}', que nao sao colunas graváveis): so casa UM par de
     * chaves envolvendo o identificador inteiro.
     */
    private static function unwrapColumn(string $name): string
    {
        $name = trim($name);
        return preg_match('/^\{([^{}]+)\}$/', $name, $m) ? trim($m[1]) : $name;
    }

    /** Nunca renderiza — endpoint estatico puro. */
    protected function view(): string|array
    {
        return '';
    }
}
