<?php

namespace Mad\Service;

use Mad\Component\MadComponent;
use Mad\Http\MadStateCrypt;

/**
 * MadCepService — endpoint AJAX para o <mad-cep-field>.
 *
 * Recebe o CEP digitado + token criptografado com fill_fields e retorna
 * um JSON com os valores ja mapeados para os campos do form.
 *
 * Consulta o lookup de CEP da plataforma (MadBuilderServiceClient →
 * /api/app/services/cep, auth Bearer MAD_PROJECT_TOKEN) e devolve os dados
 * crus. NAO usa CEPService/cache/persistencia local.
 *
 * RECURSO PAGO: app sem plano ativo recebe 403 e a mensagem do backend vai
 * literal pro toast; app sem MAD_PROJECT_TOKEN recebe erro claro (nao ha
 * fallback publico, por design).
 *
 * Resolucao automatica (opt-in): se o token trouxer `resolve.city`/`resolve.state`
 * (declarados via city-model/state-model no <mad-cep-field>), o MadLocationResolver
 * busca os registros via Eloquent (casando codigo_ibge por padrao) e injeta
 * cidade_id / estado_id na resposta. So nesse caso ha acesso a banco. A
 * auto-criacao (city-create/state-create ou o fallback de projeto
 * config('mad.cep.auto_create')) roda em transaction, com dedupe tenant-safe e
 * pre-flight de colunas NOT NULL — falhas viram Log::warning('mad.location.*')
 * + `_resolve_errors` na resposta, nunca quebram o preenchimento do endereco.
 *
 * Request (POST):
 *   token  string  Config criptografada (fill_fields, resolve)
 *   value  string  CEP digitado (com ou sem mascara)
 *
 * Response:
 *   { ok: true, values: { "form_field_1": "...", ... },
 *     "_cascade": ["cidade_id"],          // campos que o JS deve setar com delay
 *                                         // (esperam o cascade estado->cidade)
 *     "_labels": { "cidade_id": "Belo Horizonte" },  // rotulo do combo: a linha
 *                                         // resolvida pode nao ter <option> na
 *                                         // lista carregada com a pagina (recem
 *                                         // criada) — sem isso a tela mostra o ID
 *     "_resolve_errors": [{code, side}] } // presentes so quando ha resolucao/erros
 *   { ok: false, error: "Mensagem" }
 *
 * Campos retornados pela API (usaveis em fill-fields):
 *   bairro, cep, cidade, cidade_cod_ibge, estado, estado_cod_ibge,
 *   logradouro, tipo_logradouro, uf, rua (= tipo_logradouro + logradouro)
 *   + cidade_id / estado_id quando a resolucao automatica esta ativa.
 *
 * Estende MadComponent apenas pelo contrato do dispatcher.
 * Chamado via: /app/MadCepService/onSearch?static=1
 */
class MadCepService extends MadComponent
{
    public static function onSearch(array $param = []): void
    {
        header('Content-Type: application/json');

        try {
            $token = (string) ($param['token'] ?? '');
            $raw   = (string) ($param['value'] ?? '');
            $cep   = preg_replace('/\D/', '', $raw) ?? '';

            if (strlen($cep) !== 8) {
                throw new \Exception(__('service.cep_invalid'));
            }

            $config     = $token !== '' ? MadStateCrypt::decryptFor('cep', $token) : [];
            $fillFields = is_array($config) && !empty($config['fill_fields']) && is_array($config['fill_fields'])
                ? $config['fill_fields']
                : [];
            $resolve = is_array($config) && !empty($config['resolve']) && is_array($config['resolve'])
                ? $config['resolve']
                : [];

            $dados = self::_fetch($cep);

            if (!$dados) {
                echo json_encode(['ok' => false, 'error' => 'CEP não encontrado.']);
                return;
            }

            // Resolucao automatica cidade/estado (opt-in — unico ponto com acesso a banco).
            $resolveErrors = [];
            $targetLabels  = [];
            if (!empty($resolve['city']) || !empty($resolve['state'])) {
                $resolver      = MadLocationResolver::make();
                $resolveErrors = $resolver->apply($dados, $resolve);
                $targetLabels  = $resolver->targetLabels();
            }

            $values = [];
            if (!empty($fillFields)) {
                foreach ($fillFields as $formField => $apiField) {
                    $values[(string)$formField] = MadLocationResolver::path($dados, (string)$apiField);
                }
            }

            // Rotulo do combo por campo do form: o JS injeta a <option> antes
            // de setar o valor — a cidade recem criada nao esta na lista que
            // veio com a pagina, e sem rotulo o widget exibe o proprio id.
            $labels = MadLocationResolver::formLabels($fillFields, $targetLabels);

            $out = ['ok' => true, 'values' => $values];
            if ($labels !== []) {
                $out['_labels'] = $labels;
            }
            if (!empty($resolve['city']) || !empty($resolve['state'])) {
                // Campos que recebem o id da cidade: o JS espera o cascade
                // estado->cidade recarregar o combo antes de setar (substitui a
                // heuristica /cidade/i sobre o name do campo).
                $cascade = MadLocationResolver::cascadeTargets($fillFields, $resolve);
                if ($cascade !== []) {
                    $out['_cascade'] = $cascade;
                }
                if ($resolveErrors !== []) {
                    $out['_resolve_errors'] = MadLocationResolver::publicErrors($resolveErrors);
                }
            }

            echo json_encode($out);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Consulta o lookup de CEP da plataforma e devolve o objeto (stdClass).
     * Retorna null quando o CEP nao existe; lanca com mensagem pronta pro
     * toast quando o app nao esta vinculado / sem plano / servico fora.
     *
     * Endpoint: {builder.code_url}/api/app/services/cep/{cep}
     * Auth:     Bearer MAD_PROJECT_TOKEN (o mesmo do sync de codigo)
     */
    private static function _fetch(string $cep): ?\stdClass
    {
        $dados = MadBuilderServiceClient::cep($cep);

        if (!$dados) {
            return null;
        }

        // Campos derivados (puro data transform — sem banco). Calculados AQUI
        // de proposito: o contrato de fill-fields nao depende do backend mandar
        // `rua`, nem do CEP vir normalizado na resposta.
        $dados->cep = $cep;
        $dados->rua = trim(($dados->tipo_logradouro ?? '') . ' ' . ($dados->logradouro ?? ''));

        return $dados;
    }

    /** Nunca renderiza — endpoint estatico puro. */
    protected function view(): string|array
    {
        return '';
    }
}
