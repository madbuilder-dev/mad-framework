<?php

namespace Mad\Service;

use Mad\Component\MadComponent;
use Mad\Http\MadStateCrypt;
use Mad\Support\BrazilianStates;
use Mad\Support\MadCnpj;

/**
 * MadCnpjService — endpoint AJAX para o <mad-cnpj-field>.
 *
 * Recebe o CNPJ digitado + token criptografado com fill_fields (e flag full)
 * e retorna um JSON com os valores ja mapeados para os campos do form.
 *
 * Consulta o lookup de CNPJ da plataforma (MadBuilderServiceClient →
 * /api/app/services/cnpj, auth Bearer MAD_PROJECT_TOKEN). RECURSO PAGO: sem
 * plano ativo o backend devolve 403 e a mensagem dele vai literal pro toast.
 *
 * Campos derivados (_deriveLocation, puro data transform): o payload do CNPJ é
 * normalizado para o MESMO vocabulário do CEP — cidade, cidade_cod_ibge, uf,
 * estado, estado_cod_ibge, rua — para que fill-fields e a resolução de
 * cidade/estado funcionem idênticos nos dois campos.
 *
 * Resolucao automatica (opt-in): mesmos props city-model/state-model/etc do
 * <mad-cep-field> — o token traz `resolve` e o MadLocationResolver injeta
 * cidade_id/estado_id (com auto-criacao opt-in, transaction, dedupe tenant-safe
 * e pre-flight NOT NULL). Roda DEPOIS do enriquecimento legacy (CEPService /
 * CNPJService do app gerado): quando o resolve esta declarado, ele vence.
 *
 * Request (POST):
 *   token  string  Config criptografada (fill_fields, full, resolve)
 *   value  string  CNPJ digitado (com ou sem mascara)
 *
 * Response:
 *   { ok: true, values: { "form_field": "...", ... },
 *     "_cascade": [...], "_labels": {...}, "_resolve_errors": [...] }  // so com
 *     // resolve ativo. `_labels` = rotulo humano do id resolvido, por campo do
 *     // form: sem ele o combo exibe o proprio ID quando a linha nao esta na
 *     // lista de options que veio com a pagina (ex.: cidade recem criada).
 *   { ok: false, error: "Mensagem" }
 *
 * Estende MadComponent apenas pelo contrato do dispatcher.
 * Chamado via: /app/MadCnpjService/onSearch?static=1
 */
class MadCnpjService extends MadComponent
{
    public static function onSearch(array $param = []): void
    {
        header('Content-Type: application/json');

        try {
            $token = (string) ($param['token'] ?? '');
            $raw   = (string) ($param['value'] ?? '');
            // sanitize preserva LETRAS: o CNPJ alfanumérico da Receita
            // (julho/2026) tem [0-9A-Z] nas 12 primeiras posições. Tirar
            // não-dígitos aqui recusava CNPJ válido como "inválido".
            $cnpj  = MadCnpj::sanitize($raw);

            if (!MadCnpj::hasValidShape($cnpj)) {
                throw new \Exception(__('service.cnpj_invalid'));
            }

            $config     = $token !== '' ? MadStateCrypt::decryptFor('cnpj', $token) : [];
            $fillFields = is_array($config) && !empty($config['fill_fields']) && is_array($config['fill_fields'])
                ? $config['fill_fields']
                : [];
            $full    = !empty($config['full']);
            $resolve = is_array($config) && !empty($config['resolve']) && is_array($config['resolve'])
                ? $config['resolve']
                : [];

            if ($full) {
                // Modo full: payload cru do provider + CEP manual p/ popular
                // estado_id/cidade_id quando o app gerado tem CEPService.
                $dados = MadBuilderServiceClient::cnpj($cnpj, true);
                if ($dados && !empty($dados->cep) && class_exists('CEPService')) {
                    $dadosCep = \CEPService::get($dados->cep);
                    if ($dadosCep) {
                        $dados->estado_id = $dadosCep->estado_id;
                        $dados->cidade_id = $dadosCep->cidade_id;
                    }
                }
            } elseif (class_exists('CNPJService')) {
                // Modo basico com app gerado: CNPJService resolve CEP internamente
                $dados = \CNPJService::get($cnpj);
            } else {
                // Sem CNPJService do app (models Cidade/Estado nao portados):
                // dados normalizados da API, sem estado_id/cidade_id.
                $dados = MadBuilderServiceClient::cnpj($cnpj, false);
            }

            if (!$dados) {
                echo json_encode(['ok' => false, 'error' => 'CNPJ não encontrado.']);
                return;
            }

            // Normaliza a localizacao pro vocabulario do CEP (cidade, uf,
            // *_cod_ibge, rua) — vale pros 3 branches acima.
            self::_deriveLocation($dados, $full);

            // Resolucao automatica cidade/estado — DEPOIS do enriquecimento
            // legacy: resolve declarado no token vence o CEPService/CNPJService.
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

            // Rotulo do combo (mesmo contrato do CEP): a <option> da cidade
            // recem criada nao existe na lista que veio com a pagina.
            $labels = MadLocationResolver::formLabels($fillFields, $targetLabels);

            $out = ['ok' => true, 'values' => $values];
            if ($labels !== []) {
                $out['_labels'] = $labels;
            }
            if (!empty($resolve['city']) || !empty($resolve['state'])) {
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
     * Deriva os campos de localizacao no vocabulario do CEP — puro data
     * transform, sem banco. Seta cada chave SO quando ausente/vazia (o
     * enriquecimento legacy e os dados crus vencem) e so com valor nao-vazio.
     *
     * basic (contrato normalizado da plataforma — 12 chaves):
     *   cidade           <- municipio
     *   cidade_cod_ibge  <- codigo_municipio_ibge
     *   estado           <- BrazilianStates::name(uf)
     *   estado_cod_ibge  <- BrazilianStates::ibge(uf)
     *   rua              <- logradouro (a plataforma ja junta tipo + nome)
     *
     * full (payload cru do cnpj.ws):
     *   cidade           <- estabelecimento->cidade->nome
     *   cidade_cod_ibge  <- (string) estabelecimento->cidade->ibge_id   [int no provider]
     *   uf               <- estabelecimento->estado->sigla
     *   estado           <- estabelecimento->estado->nome
     *   estado_cod_ibge  <- (string) estabelecimento->estado->ibge_id
     *   rua              <- tipo_logradouro + logradouro do estabelecimento
     *
     * Branch legacy (CNPJService do app gerado): shape desconhecido — os
     * guards de ausencia cobrem; o que nao existir fica sem derivar.
     */
    private static function _deriveLocation(\stdClass $dados, bool $full): void
    {
        $set = static function (string $key, $value) use ($dados): void {
            $value = is_scalar($value) ? (string) $value : '';
            if ($value === '') {
                return;
            }
            if (!isset($dados->{$key}) || $dados->{$key} === '' || $dados->{$key} === null) {
                $dados->{$key} = $value;
            }
        };

        if ($full) {
            $e      = is_object($dados->estabelecimento ?? null) ? $dados->estabelecimento : null;
            $cidade = is_object($e->cidade ?? null) ? $e->cidade : null;
            $estado = is_object($e->estado ?? null) ? $e->estado : null;

            $set('cidade', $cidade->nome ?? '');
            $set('cidade_cod_ibge', $cidade->ibge_id ?? '');
            $set('uf', $estado->sigla ?? '');
            $set('estado', $estado->nome ?? '');
            $set('estado_cod_ibge', $estado->ibge_id ?? '');
            $set('rua', trim(((string) ($e->tipo_logradouro ?? '')) . ' ' . ((string) ($e->logradouro ?? ''))));
            return;
        }

        $uf = (string) ($dados->uf ?? '');
        $set('cidade', $dados->municipio ?? '');
        $set('cidade_cod_ibge', $dados->codigo_municipio_ibge ?? '');
        $set('estado', BrazilianStates::name($uf));
        $set('estado_cod_ibge', BrazilianStates::ibge($uf));
        $set('rua', $dados->logradouro ?? '');
    }

    /** Nunca renderiza — endpoint estatico puro. */
    protected function view(): string|array
    {
        return '';
    }
}
